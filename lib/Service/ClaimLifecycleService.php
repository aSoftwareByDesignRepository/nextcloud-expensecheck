<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimLine;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\DelegateMapper;
use OCA\ExpenseCheck\Db\StepDecisionMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Claim state machine (spec §5):
 *
 *   draft ── submit ──► submitted ── approve ──► approved ── settle ──► settled
 *   submitted ── request_changes ──► changes_requested ── resubmit ──► submitted
 *   submitted ── reject ──► rejected | submitted/changes ── withdraw ──► draft
 *   approved ── admin revert ──► submitted   rejected/settled ── clone ──► new draft
 *
 * approved/settled are immutable to the claimant (revert/un-settle are
 * admin/finance paths). Every transition journals from_status/to_status and
 * persists with version-CAS — a lost update throws VERSION_CONFLICT.
 */
class ClaimLifecycleService
{
	public const STATUS_DRAFT = 'draft';
	public const STATUS_SUBMITTED = 'submitted';
	public const STATUS_CHANGES_REQUESTED = 'changes_requested';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_SETTLED = 'settled';

	public const STATUSES = [
		self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_CHANGES_REQUESTED,
		self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_SETTLED,
	];

	/** Census mirrored in tests — every status declares its legal targets. */
	public const TRANSITIONS = [
		self::STATUS_DRAFT => [self::STATUS_SUBMITTED],
		self::STATUS_SUBMITTED => [self::STATUS_DRAFT, self::STATUS_CHANGES_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED],
		self::STATUS_CHANGES_REQUESTED => [self::STATUS_SUBMITTED, self::STATUS_DRAFT, self::STATUS_REJECTED],
		self::STATUS_APPROVED => [self::STATUS_SETTLED, self::STATUS_SUBMITTED], // settle | admin revert
		self::STATUS_REJECTED => [], // terminal — clone creates a NEW claim
		self::STATUS_SETTLED => [],  // terminal
	];

	public function __construct(
		private readonly ClaimMapper $claims,
		private readonly ClaimLineMapper $lines,
		private readonly ClaimLineService $lineService,
		private readonly StepDecisionMapper $decisions,
		private readonly DelegateMapper $delegates,
		private readonly ClaimNumberService $numbers,
		private readonly ChainRoutingService $routing,
		private readonly SettingsService $settings,
		private readonly ExpChainService $chain,
		private readonly AccessControlService $access,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly PostCommitEffectQueue $postCommit,
		private readonly NotificationService $notify,
	) {
	}

	/**
	 * Visibility-scoped load — uniform 404 for "nonexistent" and "exists but
	 * not visible to this uid" (NN-04, no existence oracle).
	 */
	public function loadVisible(int $id, string $uid): Claim
	{
		$claim = $this->claims->findByIdRaw($id);
		if ($claim === null || !$this->canView($claim, $uid)) {
			throw new ClaimNotFoundException();
		}
		return $claim;
	}

	/**
	 * Who may see a claim: owner, app admin, finance/auditor roles, any uid
	 * holding decision rights on the pending step, and (for history) any uid
	 * that already decided a step of this claim.
	 */
	public function canView(Claim $claim, string $uid): bool
	{
		if ($claim->getUserId() === $uid) {
			return true;
		}
		if ($this->access->isAppAdmin($uid) || $this->access->isFinance($uid) || $this->access->isAuditor($uid)) {
			return true;
		}
		foreach ($this->decisions->findByClaim($claim->getId()) as $d) {
			if ($d->getDecidedBy() === $uid) {
				return true;
			}
		}
		if ($claim->getStatus() === self::STATUS_SUBMITTED) {
			$snapshot = json_decode((string)$claim->getChainSnapshot(), true);
			if (is_array($snapshot)) {
				$rights = $this->routing->decisionRights($snapshot, $claim->getCurrentStep(), $uid, $this->timeFactory->getDateTime(), $this->delegates);
				if ($rights['allowed']) {
					return true;
				}
			}
		}
		return false;
	}

	public function createDraft(string $uid, string $title, string $currency, ?string $requestKey = null): Claim
	{
		$fields = [];
		if ($title === '' || mb_strlen($title) > 191) {
			$fields['title'] = 'required';
		}
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			$fields['currency'] = 'invalid';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		$now = $this->timeFactory->getDateTime();
		$claim = new Claim();
		$claim->setClaimNo($this->numbers->next());
		$claim->setUserId($uid);
		$claim->setStatus(self::STATUS_DRAFT);
		$claim->setTitle($title);
		$claim->setCurrency($currency);
		$claim->setFxRate('1.000000');
		$claim->setVersion(1);
		$claim->setRequestKey($requestKey);
		$claim->setCreatedAt($now);
		$claim->setUpdatedAt($now);
		$claim = $this->claims->insert($claim);
		$this->chain->append($claim->getId(), 'created', $uid, null, self::STATUS_DRAFT, ['claim_no' => $claim->getClaimNo()]);
		return $claim;
	}

	/**
	 * Submit: recompute totals, resolve + freeze the approval chain, bump to
	 * submitted — atomically, CAS on version. RATE_MISSING / CHAIN_UNROUTABLE
	 * fail closed before the status flips.
	 */
	public function submit(Claim $claim, string $actorUid, ?int $expectedVersion = null): Claim
	{
		$this->assertClientVersion($claim, $expectedVersion);
		$this->assertStatus($claim, [self::STATUS_DRAFT, self::STATUS_CHANGES_REQUESTED], 'CLAIM_NOT_SUBMITTABLE');
		if ($claim->getUserId() !== $actorUid) {
			throw new ClaimNotFoundException();
		}
		$lineRows = $this->lines->findByClaim($claim->getId());
		if ($lineRows === []) {
			throw new DomainException('CLAIM_EMPTY', 'A claim without lines cannot be submitted.', 422);
		}
		foreach ($lineRows as $l) {
			if ($l->getRateMinor() === null && in_array($l->getLineType(), ['mileage', 'per_diem', 'flat'], true)) {
				throw new DomainException('RATE_MISSING', 'Line ' . $l->getId() . ' has no resolved rate.', 422);
			}
			if ($l->getCategoryId() === 0) {
				throw new DomainException('CLAIM_EMPTY', 'Line ' . $l->getId() . ' has no category.', 422);
			}
		}
		// Edge 41: drafts accepted late-sync lenient are re-validated here —
		// a since-closed period surfaces PERIOD_CLOSED instead of slipping through.
		$this->lineService->assertLineDatesAllowed($claim);
		// MH-02/MH-06b: receipt-required categories need evidence; advisory
		// policy flags are computed server-side, never client-supplied.
		$this->lineService->assertReceiptEvidence($claim);
		$this->lineService->computePolicyFlags($claim);
		$totals = MoneyMathService::claimTotals(array_map(fn ($l) => [
			'amount_minor' => $l->getAmountMinor(),
			'reimbursable' => $l->getReimbursable(),
		], $lineRows));
		$categoryIds = array_values(array_unique(array_map(fn ($l) => $l->getCategoryId(), $lineRows)));

		$this->db->beginTransaction();
		try {
			$chain = $this->routing->resolveChain($totals['total'], $categoryIds);
			if ($chain !== null) {
				$snapshot = $this->routing->snapshot($chain, $claim->getUserId());
				if ($snapshot === null) {
					throw new DomainException('CHAIN_UNROUTABLE', 'Approval chain has no steps.', 409);
				}
				$snapshot = $this->routing->applySelfApprovalRule(
					$snapshot, $claim->getUserId(), $this->settings->getBool(SettingsService::KEY_ALLOW_SELF_APPROVE)
				);
			} else {
				// No matching chain → admin-review fallback (fail closed, NN-09).
				$snapshot = ['chain_id' => null, 'name' => 'admin-review', 'steps' => [[
					'step_no' => 1, 'step_type' => 'admin_review', 'group_id' => null,
					'user_id' => null, 'resolved_uids' => $this->access->getAppAdminIds(),
					'unroutable' => true,
				]]];
			}
			$firstStep = (int)min(array_map(fn ($s) => (int)$s['step_no'], $snapshot['steps']));

			$from = $claim->getStatus();
			$claim->setStatus(self::STATUS_SUBMITTED);
			$claim->setSubmittedAt($this->timeFactory->getDateTime());
			$claim->setTotalMinor($totals['total']);
			$claim->setReimbursableTotalMinor($totals['reimbursable']);
			$claim->setNonreimbursableTotalMinor($totals['nonreimbursable']);
			$claim->setOrgTotalMinor($totals['total']); // claim-currency; org fx applied at export
			$claim->setChainId($snapshot['chain_id']);
			$claim->setChainSnapshot(json_encode($snapshot, JSON_UNESCAPED_UNICODE));
			$claim->setCurrentStep($firstStep);
			$claim->setUpdatedAt($this->timeFactory->getDateTime());
			$claim = $this->casUpdate($claim);
			$this->chain->append($claim->getId(), 'submitted', $actorUid, $from, self::STATUS_SUBMITTED, [
				'chain_id' => $snapshot['chain_id'], 'step' => $firstStep,
			]);
			$this->db->commit();
			// §12.7: notify whoever holds decision rights on the pending step.
			// Unroutable claims alert app admins instead (NN-09 admin-review).
			$this->postCommit->runAfterCurrentCommit(function () use ($claim, $snapshot, $firstStep): void {
				$pending = null;
				foreach ($snapshot['steps'] as $s) {
					if ((int)$s['step_no'] === $firstStep) {
						$pending = $s;
						break;
					}
				}
				if (!empty($pending['unroutable'])) {
					$this->notify->routedToAdminReview($claim);
				} else {
					$this->notify->claimSubmitted($claim, $pending['resolved_uids'] ?? []);
				}
			});
			return $claim;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function withdraw(Claim $claim, string $actorUid, ?int $expectedVersion = null): Claim
	{
		$this->assertClientVersion($claim, $expectedVersion);
		$this->assertStatus($claim, [self::STATUS_SUBMITTED, self::STATUS_CHANGES_REQUESTED], 'CLAIM_NOT_WITHDRAWABLE');
		if ($claim->getUserId() !== $actorUid) {
			throw new ClaimNotFoundException();
		}
		$from = $claim->getStatus();
		$claim->setStatus(self::STATUS_DRAFT);
		$claim->setCurrentStep(0);
		$claim->setSubmittedAt(null);
		$claim->setUpdatedAt($this->timeFactory->getDateTime());
		$claim = $this->casUpdate($claim);
		$this->chain->append($claim->getId(), 'withdrawn', $actorUid, $from, self::STATUS_DRAFT);
		return $claim;
	}

	/**
	 * Admin revert approved → submitted before settlement (spec §5). Requires
	 * a reason; clears decided_* and restarts the frozen chain at step 1.
	 */
	public function revert(Claim $claim, string $actorUid, string $reason, ?int $expectedVersion = null): Claim
	{
		$this->assertClientVersion($claim, $expectedVersion);
		if (!$this->access->isAppAdmin($actorUid)) {
			throw new ClaimNotFoundException();
		}
		$this->assertStatus($claim, [self::STATUS_APPROVED], 'CLAIM_NOT_REVERTIBLE');
		if (mb_strlen(trim($reason)) < 3) {
			throw new ValidationException(['reason' => 'required']);
		}
		$this->db->beginTransaction();
		try {
			// Decisions become history only — delete so the replayed chain can
			// be decided again (UNIQUE(claim_id,step_no) would block otherwise).
			$this->decisions->deleteByClaim($claim->getId());
			$snapshot = json_decode((string)$claim->getChainSnapshot(), true);
			$claim->setStatus(self::STATUS_SUBMITTED);
			$claim->setDecidedAt(null);
			$claim->setDecidedBy(null);
			$claim->setDecisionNote(null);
			$claim->setCurrentStep(is_array($snapshot) && $snapshot['steps'] !== []
				? (int)min(array_map(fn ($s) => (int)$s['step_no'], $snapshot['steps']))
				: 1);
			$claim->setUpdatedAt($this->timeFactory->getDateTime());
			$claim = $this->casUpdate($claim);
			$this->chain->append($claim->getId(), 'reverted', $actorUid, self::STATUS_APPROVED, self::STATUS_SUBMITTED, ['reason' => $reason]);
			$this->db->commit();
			return $claim;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Clone a terminal (rejected|settled) claim to a NEW draft — fresh id,
	 * fresh journal, lines + allocations copied. Never mutates the source.
	 */
	public function cloneToNewDraft(Claim $claim, string $actorUid): Claim
	{
		if (!in_array($claim->getStatus(), [self::STATUS_REJECTED, self::STATUS_SETTLED], true)) {
			throw new DomainException('CLAIM_NOT_CLONEABLE', 'Only rejected or settled claims can be cloned.', 409);
		}
		$new = $this->createDraft($actorUid, $claim->getTitle(), $claim->getCurrency());
		$new->setPurpose($claim->getPurpose());
		$this->claims->update($new);
		foreach ($this->lines->findByClaim($claim->getId()) as $l) {
			$copy = new ClaimLine();
			$copy->setClaimId($new->getId());
			$copy->setLineType($l->getLineType());
			$copy->setOccurredOn($l->getOccurredOn());
			$copy->setCategoryId($l->getCategoryId());
			$copy->setMerchant($l->getMerchant());
			$copy->setDescription($l->getDescription());
			$copy->setAmountMinor($l->getAmountMinor());
			$copy->setTaxMinor($l->getTaxMinor());
			$copy->setTaxRate($l->getTaxRate());
			$copy->setForeignAmountMinor($l->getForeignAmountMinor());
			$copy->setForeignCurrency($l->getForeignCurrency());
			$copy->setForeignFx($l->getForeignFx());
			$copy->setDistanceKm($l->getDistanceKm());
			$copy->setDays($l->getDays());
			$copy->setQuantity($l->getQuantity());
			$copy->setRateId($l->getRateId());
			$copy->setRateCode($l->getRateCode());
			$copy->setRateMinor($l->getRateMinor());
			$copy->setRateName($l->getRateName());
			$copy->setJustification($l->getJustification());
			$copy->setReimbursable($l->getReimbursable());
			$copy->setDistanceSource($l->getDistanceSource());
			$copy->setAttendees($l->getAttendees());
			$copy->setOccasion($l->getOccasion());
			$copy->setTipMinor($l->getTipMinor());
			$copy->setSort($l->getSort());
			$copy->setCreatedAt($this->timeFactory->getDateTime());
			$copy->setUpdatedAt($this->timeFactory->getDateTime());
			$this->lines->insert($copy);
		}
		$this->chain->append($new->getId(), 'cloned', $actorUid, null, self::STATUS_DRAFT, ['from_claim' => $claim->getClaimNo()]);
		return $new;
	}

	/**
	 * Persist a mutated claim with optimistic locking. Callers must bump
	 * version themselves via this helper — it reads expected=current, sets
	 * version+1, and fails VERSION_CONFLICT when the row moved.
	 */
	public function casUpdate(Claim $claim): Claim
	{
		$expected = $claim->getVersion();
		$claim->setVersion($expected + 1);
		if (!$this->claims->updateWithVersion($claim, $expected)) {
			throw new DomainException('VERSION_CONFLICT', 'Claim changed concurrently — reload and retry.', 409);
		}
		return $claim;
	}

	private function assertClientVersion(Claim $claim, ?int $expectedVersion): void
	{
		if ($expectedVersion !== null && $expectedVersion !== $claim->getVersion()) {
			throw new DomainException('VERSION_CONFLICT', 'Claim changed since you loaded it — reload and retry.', 409);
		}
	}

	private function assertStatus(Claim $claim, array $allowed, string $code): void
	{
		if (!in_array($claim->getStatus(), $allowed, true)) {
			throw new DomainException($code, 'Claim is ' . $claim->getStatus() . '.', 409);
		}
	}
}
