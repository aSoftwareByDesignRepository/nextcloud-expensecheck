<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\Chain;
use OCA\ExpenseCheck\Db\ChainMapper;
use OCA\ExpenseCheck\Db\ChainStep;
use OCA\ExpenseCheck\Db\ChainStepMapper;
use OCA\ExpenseCheck\Db\StepDecisionMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Approval-chain routing (spec §10.2/MH-05).
 *
 * Resolution: first matching active chain (ordered by sort,id) wins — a chain
 * matches when its min_total_minor threshold is met (or NULL) and its
 * category_id matches the claim's predominant category (or NULL = all).
 *
 * chain_snapshot frozen at submit: {chain_id, name, steps:[{step_no,type,
 * group_id,user_id,resolved_uids, unroutable}]} — resolved uids are frozen so
 * mid-flight org changes never re-route a pending claim (edges 22/46).
 *
 * Unroutable steps fail closed to the admin-review queue (NN-09), never
 * auto-approve.
 */
class ChainRoutingService
{
	public const STEP_TYPES = ['group', 'user', 'line_manager'];
	public const ADMIN_REVIEW_UID = '__admin_review__';

	public function __construct(
		private readonly ChainMapper $chains,
		private readonly ChainStepMapper $steps,
		private readonly StepDecisionMapper $decisions,
		private readonly OrgResolutionService $org,
		private readonly AccessControlService $access,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * Pick the routing chain for a claim. $lineCategoryIds = category ids on
	 * the claim's lines; a category-scoped chain matches when ANY line's
	 * category equals it (claim may be mixed — first-match order decides).
	 * @param list<int> $lineCategoryIds
	 */
	public function resolveChain(int $totalMinor, array $lineCategoryIds): ?Chain
	{
		foreach ($this->chains->findActive() as $chain) {
			$min = $chain->getMinTotalMinor();
			if ($min !== null && $totalMinor < $min) {
				continue;
			}
			$catId = $chain->getCategoryId();
			if ($catId !== null && !in_array($catId, $lineCategoryIds, true)) {
				continue;
			}
			return $chain;
		}
		return null;
	}

	/**
	 * Freeze routing at submit: resolves every step's approver uids NOW.
	 * Steps with no resolvable approver are marked unroutable → admin-review.
	 *
	 * @return array{chain_id:int,name:string,steps:list<array{step_no:int,step_type:string,group_id:?string,user_id:?string,resolved_uids:list<string>,unroutable:bool}>}|null
	 */
	public function snapshot(Chain $chain, string $claimantUid): ?array
	{
		$steps = $this->steps->findByChain($chain->getId());
		if ($steps === []) {
			return null; // Chain without steps routes nothing — CHAIN_UNROUTABLE
		}
		$out = ['chain_id' => $chain->getId(), 'name' => $chain->getName(), 'steps' => []];
		foreach ($steps as $step) {
			$resolved = $this->resolveStepApprovers($step, $claimantUid);
			$out['steps'][] = [
				'step_no' => $step->getStepNo(),
				'step_type' => $step->getStepType(),
				'group_id' => $step->getGroupId(),
				'user_id' => $step->getUserId(),
				'resolved_uids' => $resolved,
				'unroutable' => $resolved === [],
			];
		}
		return $out;
	}

	/**
	 * Approver uids for one step (live resolution — used at submit; decisions
	 * always read the frozen snapshot).
	 * @return list<string>
	 */
	public function resolveStepApprovers(ChainStep $step, string $claimantUid): array
	{
		$type = $step->getStepType();
		if ($type === 'group') {
			$gid = $step->getGroupId() ?? '';
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				return [];
			}
			return array_values(array_map(fn ($u) => $u->getUID(), $group->getUsers()));
		}
		if ($type === 'user') {
			$uid = $step->getUserId() ?? '';
			return ($uid !== '' && $this->userManager->userExists($uid)) ? [$uid] : [];
		}
		if ($type === 'line_manager') {
			return $this->org->managersFor($claimantUid);
		}
		return [];
	}

	/**
	 * The uids allowed to decide a pending step — frozen resolved set plus the
	 * admin-review pool when unroutable. Delegates act via on_behalf_of (edge 25).
	 *
	 * @param array<string,mixed> $snapshot
	 * @return list<string>
	 */
	public function approversForPendingStep(array $snapshot, int $stepNo): array
	{
		foreach ($snapshot['steps'] ?? [] as $s) {
			if ((int)$s['step_no'] === $stepNo) {
				if (!empty($s['unroutable'])) {
					return array_merge($s['resolved_uids'] ?? [], $this->access->getAppAdminIds());
				}
				return $s['resolved_uids'] ?? [];
			}
		}
		return [];
	}

	/**
	 * May $uid decide this pending step? Direct membership, or an active
	 * delegation window whose manager is in the resolved set (on_behalf_of).
	 * @param array<string,mixed> $snapshot
	 * @return array{allowed:bool,on_behalf_of:?string}
	 */
	public function decisionRights(array $snapshot, int $stepNo, string $uid, \DateTimeInterface $today, \OCA\ExpenseCheck\Db\DelegateMapper $delegates): array
	{
		$approvers = $this->approversForPendingStep($snapshot, $stepNo);
		if (in_array($uid, $approvers, true)) {
			return ['allowed' => true, 'on_behalf_of' => null];
		}
		foreach ($delegates->findActiveForDelegate($uid, $today) as $d) {
			if (in_array($d->getManagerUid(), $approvers, true)) {
				return ['allowed' => true, 'on_behalf_of' => $d->getManagerUid()];
			}
		}
		return ['allowed' => false, 'on_behalf_of' => null];
	}

	/** @return list<int> step numbers of an N-step chain */
	public static function stepNos(array $snapshot): array
	{
		return array_map(fn ($s) => (int)$s['step_no'], $snapshot['steps'] ?? []);
	}

	/** Next pending step after $currentStep, or null when chain is complete. */
	public static function nextStep(array $snapshot, int $currentStep): ?int
	{
		$nos = self::stepNos($snapshot);
		sort($nos);
		foreach ($nos as $n) {
			if ($n > $currentStep) {
				return $n;
			}
		}
		return null;
	}

	/**
	 * Self-approval check (MH-05/edge 15): when allow_self_approve is off, a
	 * resolved approver set containing ONLY the claimant routes to admin-review.
	 * @param array<string,mixed> $snapshot
	 */
	public function applySelfApprovalRule(array $snapshot, string $claimantUid, bool $allowSelfApprove): array
	{
		if ($allowSelfApprove) {
			return $snapshot;
		}
		foreach ($snapshot['steps'] as &$s) {
			$resolved = $s['resolved_uids'] ?? [];
			if ($resolved !== [] && $resolved === [$claimantUid]) {
				$s['resolved_uids'] = [];
				$s['unroutable'] = true;
				$s['self_approval_blocked'] = true;
			}
		}
		unset($s);
		return $snapshot;
	}
}
