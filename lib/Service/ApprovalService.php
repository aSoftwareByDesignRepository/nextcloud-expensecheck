<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\DelegateMapper;
use OCA\ExpenseCheck\Db\StepDecision;
use OCA\ExpenseCheck\Db\StepDecisionMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Approval decisions (spec §5/MH-05): CAS-protected — exp_step_decisions has
 * UNIQUE(claim_id, step_no) AND claims.version bumps in the same transaction.
 * A second approver racing gets STEP_ALREADY_DECIDED, never a silent
 * double-approve. Note mandatory for reject/request_changes.
 */
class ApprovalService
{
	public const DECISION_APPROVE = 'approved';
	public const DECISION_REJECT = 'rejected';
	public const DECISION_REQUEST_CHANGES = 'changes_requested';
	public const DECISIONS = [self::DECISION_APPROVE, self::DECISION_REJECT, self::DECISION_REQUEST_CHANGES];

	public function __construct(
		private readonly StepDecisionMapper $decisions,
		private readonly ClaimMapper $claims,
		private readonly DelegateMapper $delegates,
		private readonly ChainRoutingService $routing,
		private readonly ExpChainService $chain,
		private readonly AccessControlService $access,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly PostCommitEffectQueue $postCommit,
		private readonly NotificationService $notify,
	) {
	}

	public function decide(Claim $claim, string $decision, string $actorUid, ?string $note, ?int $expectedVersion = null): Claim
	{
		if ($expectedVersion !== null && $expectedVersion !== $claim->getVersion()) {
			throw new DomainException('VERSION_CONFLICT', 'Claim changed since you loaded it — reload and retry.', 409);
		}
		if ($claim->getStatus() !== ClaimLifecycleService::STATUS_SUBMITTED) {
			throw new DomainException('CLAIM_NOT_PENDING', 'Claim is not awaiting a decision.', 409);
		}
		if (!in_array($decision, self::DECISIONS, true)) {
			throw new ValidationException(['decision' => 'invalid']);
		}
		$note = trim((string)$note);
		if ($decision !== self::DECISION_APPROVE && mb_strlen($note) < 3) {
			throw new ValidationException(['note' => 'required']);
		}
		$snapshot = json_decode((string)$claim->getChainSnapshot(), true);
		if (!is_array($snapshot) || empty($snapshot['steps'])) {
			throw new DomainException('CHAIN_UNROUTABLE', 'Claim has no frozen routing.', 500);
		}
		$stepNo = $claim->getCurrentStep();
		$rights = $this->routing->decisionRights($snapshot, $stepNo, $actorUid, $this->timeFactory->getDateTime(), $this->delegates);
		if (!$rights['allowed'] || $actorUid === $claim->getUserId()) {
			throw new ClaimNotFoundException(); // uniform 404 — no existence oracle, no self-approve
		}

		$this->db->beginTransaction();
		try {
			if ($this->decisions->findForStep($claim->getId(), $stepNo) !== null) {
				throw new DomainException('STEP_ALREADY_DECIDED', 'Step was already decided.', 409);
			}
			$d = new StepDecision();
			$d->setClaimId($claim->getId());
			$d->setStepNo($stepNo);
			$d->setDecidedBy($actorUid);
			$d->setDecision($decision);
			$d->setNote($note === '' ? null : $note);
			$d->setCreatedAt($this->timeFactory->getDateTime());
			try {
				$this->decisions->insert($d);
			} catch (\OCP\DB\Exception) {
				// UNIQUE(claim_id, step_no) race → the other approver won.
				throw new DomainException('STEP_ALREADY_DECIDED', 'Step was already decided.', 409);
			}

			$from = $claim->getStatus();
			$nextStep = ChainRoutingService::nextStep($snapshot, $stepNo);
			if ($decision === self::DECISION_APPROVE) {
				$final = $nextStep === null;
				$claim->setStatus($final ? ClaimLifecycleService::STATUS_APPROVED : ClaimLifecycleService::STATUS_SUBMITTED);
				$claim->setCurrentStep($final ? 0 : $nextStep);
				if ($final) {
					$claim->setDecidedAt($this->timeFactory->getDateTime());
					$claim->setDecidedBy($actorUid);
					$claim->setDecisionNote($note === '' ? null : $note);
				}
			} else {
				$claim->setStatus($decision === self::DECISION_REJECT
					? ClaimLifecycleService::STATUS_REJECTED
					: ClaimLifecycleService::STATUS_CHANGES_REQUESTED);
				$claim->setCurrentStep(0);
				$claim->setDecidedAt($this->timeFactory->getDateTime());
				$claim->setDecidedBy($actorUid);
				$claim->setDecisionNote($note);
			}
			$claim->setUpdatedAt($this->timeFactory->getDateTime());
			$expected = $claim->getVersion();
			$claim->setVersion($expected + 1);
			if (!$this->claims->updateWithVersion($claim, $expected)) {
				throw new DomainException('VERSION_CONFLICT', 'Claim changed concurrently — reload and retry.', 409);
			}
			$this->chain->append($claim->getId(), 'decision', $actorUid, $from, $claim->getStatus(), [
				'step_no' => $stepNo,
				'decision' => $decision,
				'on_behalf_of' => $rights['on_behalf_of'],
				'has_note' => $note !== '',
			]);
			$this->db->commit();
			$this->postCommit->runAfterCurrentCommit(function () use ($claim, $decision, $snapshot, $nextStep): void {
				$this->notify->decisionOnClaim($claim, $decision);
				// Intermediate approve → the claim now waits on the next
				// step's approvers; they get the same submitted notification.
				if ($decision === self::DECISION_APPROVE && $nextStep !== null) {
					foreach ($snapshot['steps'] as $s) {
						if ((int)$s['step_no'] === $nextStep) {
							$this->notify->claimSubmitted($claim, $s['resolved_uids'] ?? []);
							break;
						}
					}
				}
			});
			return $claim;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Approver inbox: submitted claims whose pending step this uid may
	 * decide — direct, delegated, or admin-review fallback.
	 * @return list<Claim>
	 */
	public function inboxFor(string $uid): array
	{
		$out = [];
		foreach ($this->claims->findByStatus(ClaimLifecycleService::STATUS_SUBMITTED) as $claim) {
			if ($claim->getUserId() === $uid) {
				continue; // never your own claim
			}
			$snapshot = json_decode((string)$claim->getChainSnapshot(), true);
			if (!is_array($snapshot)) {
				continue;
			}
			$rights = $this->routing->decisionRights($snapshot, $claim->getCurrentStep(), $uid, $this->timeFactory->getDateTime(), $this->delegates);
			if ($rights['allowed']) {
				$out[] = $claim;
			}
		}
		return $out;
	}
}
