<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\Attachment;
use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimAllocationMapper;
use OCA\ExpenseCheck\Db\ClaimEvent;
use OCA\ExpenseCheck\Db\ClaimEventMapper;
use OCA\ExpenseCheck\Db\ClaimLine;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\CostCenterMapper;
use OCA\ExpenseCheck\Db\DelegateMapper;
use OCA\ExpenseCheck\Db\StepDecisionMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Wire DTOs — the single place claim entities become JSON. Web and mobile
 * APIs MUST emit identical shapes (spec §13.4 contract parity).
 */
class ClaimSerializer
{
	public function __construct(
		private readonly ClaimLineMapper $lines,
		private readonly ClaimAllocationMapper $allocations,
		private readonly AttachmentMapper $attachments,
		private readonly StepDecisionMapper $decisions,
		private readonly ClaimEventMapper $events,
		private readonly CostCenterMapper $costCenters,
		private readonly DelegateMapper $delegates,
		private readonly ChainRoutingService $routing,
		private readonly AccessControlService $access,
		private readonly AzcBridge $azc,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/** Summary row for lists — no line detail. */
	public static function summary(Claim $c): array
	{
		return [
			'id' => $c->getId(),
			'claim_no' => $c->getClaimNo(),
			'title' => $c->getTitle(),
			'status' => $c->getStatus(),
			'currency' => $c->getCurrency(),
			'total_minor' => $c->getTotalMinor(),
			'reimbursable_minor' => $c->getReimbursableTotalMinor(),
			'nonreimbursable_minor' => $c->getNonreimbursableTotalMinor(),
			'owner' => $c->getUserId(),
			'kind' => $c->getKind(),
			'corrects_claim_no' => $c->getCorrectsClaimNo(),
			'azc_trip_id' => $c->getAzcTripId(),
			'submitted_at' => $c->getSubmittedAt()?->format('Y-m-d H:i:s'),
			'decided_at' => $c->getDecidedAt()?->format('Y-m-d H:i:s'),
			'decided_by' => $c->getDecidedBy(),
			'settled_at' => $c->getSettledAt()?->format('Y-m-d H:i:s'),
			'version' => $c->getVersion(),
			'updated_at' => $c->getUpdatedAt()?->format('Y-m-d H:i:s'),
		];
	}

	/** Summary row plus caller-scoped capabilities (spec §10.6). */
	public function summaryFor(Claim $c, string $uid): array
	{
		$out = self::summary($c);
		$out['can'] = $this->canFor($c, $uid, null);
		return $out;
	}

	/** Full detail: lines + allocations + attachments + decisions + events. */
	public function detail(Claim $c, ?string $uid = null): array
	{
		$out = self::summary($c);
		if ($uid !== null) {
			$out['can'] = $this->canFor($c, $uid, count($this->lines->findByClaim($c->getId())));
			$out['resolved_label'] = $this->resolvedLabel($c);
		}
		$out['purpose'] = $c->getPurpose();
		$out['current_step'] = $c->getCurrentStep();
		$out['decision_note'] = $c->getDecisionNote();
		$out['settlement_ref'] = $c->getSettlementRef();
		$out['batch_id'] = $c->getBatchId();
		$out['chain_snapshot'] = $c->getChainSnapshot() !== null
			? json_decode($c->getChainSnapshot(), true)
			: null;
		$out['lines'] = array_map([$this, 'lineDto'], $this->lines->findByClaim($c->getId()));
		$out['attachments'] = array_map([self::class, 'attachmentDto'], $this->attachments->findByClaim($c->getId()));
		$out['decisions'] = array_map(fn ($d) => [
			'step_no' => $d->getStepNo(),
			'decided_by' => $d->getDecidedBy(),
			'decision' => $d->getDecision(),
			'note' => $d->getNote(),
			'created_at' => $d->getCreatedAt()?->format('Y-m-d H:i:s'),
		], $this->decisions->findByClaim($c->getId()));
		$out['events'] = array_map([self::class, 'eventDto'], $this->events->findAllForClaim($c->getId()));
		return $out;
	}

	public function lineDto(ClaimLine $l): array
	{
		return [
			'id' => $l->getId(),
			'line_type' => $l->getLineType(),
			'occurred_on' => $l->getOccurredOn()?->format('Y-m-d'),
			'category_id' => $l->getCategoryId(),
			'merchant' => $l->getMerchant(),
			'description' => $l->getDescription(),
			'amount_minor' => $l->getAmountMinor(),
			'tax_minor' => $l->getTaxMinor(),
			'tax_rate' => $l->getTaxRate(),
			'foreign_amount_minor' => $l->getForeignAmountMinor(),
			'foreign_currency' => $l->getForeignCurrency(),
			'foreign_fx' => $l->getForeignFx(),
			'distance_km' => $l->getDistanceKm(),
			'days' => $l->getDays(),
			'quantity' => $l->getQuantity(),
			'rate_code' => $l->getRateCode(),
			'rate_minor' => $l->getRateMinor(),
			'rate_name' => $l->getRateName(),
			'justification' => $l->getJustification(),
			'reimbursable' => $l->getReimbursable() === 1,
			'distance_source' => $l->getDistanceSource(),
			'attendees' => $l->getAttendees(),
			'occasion' => $l->getOccasion(),
			'tip_minor' => $l->getTipMinor(),
			'policy_flags' => $l->getPolicyFlags() !== null ? json_decode($l->getPolicyFlags(), true) : [],
			'source_app' => $l->getSourceApp(),
			'source_ref' => $l->getSourceRef(),
			'allocations' => array_map(fn ($a) => [
				'cost_center_id' => $a->getCostCenterId(),
				'share_percent' => $a->getSharePercent(),
				'amount_minor' => $a->getAmountMinor(),
				'pc_project_id' => $a->getPcProjectId(),
			], $this->allocations->findByLine($l->getId())),
			'attachment_count' => count($this->attachments->findByLine($l->getId())),
		];
	}

	public static function attachmentDto(Attachment $a): array
	{
		return [
			'id' => $a->getId(),
			'claim_id' => $a->getClaimId(),
			'line_id' => $a->getLineId(),
			'inbox' => $a->getInbox() === 1,
			'name' => $a->getName(),
			'mime' => $a->getMime(),
			'size' => $a->getSize(),
			'sha256' => $a->getSha256(),
		];
	}

	public static function eventDto(ClaimEvent $e): array
	{
		return [
			'seq' => $e->getSeq(),
			'event' => $e->getEvent(),
			'actor' => $e->getActor(),
			'from_status' => $e->getFromStatus(),
			'to_status' => $e->getToStatus(),
			'tombstoned' => $e->getTombstoned() === 1,
			'payload' => $e->getTombstoned() === 1 ? null
				: ($e->getPayload() !== null ? json_decode($e->getPayload(), true) : null),
			'created_at' => $e->getCreatedAt()?->format('Y-m-d H:i:s'),
		];
	}

	/**
	 * Caller-scoped capabilities (spec §10.6) — the ONLY permission authority
	 * clients may render affordances from. Mirrors the server-side guards:
	 * edit/attach = owner + editable status, withdraw = owner + submitted or
	 * changes_requested (ClaimLifecycleService::withdraw), decide = routed
	 * rights on the pending step (ChainRoutingService::decisionRights),
	 * settle/export = finance/auditor roles. Missing caps must read as false
	 * on clients — never re-derive locally.
	 */
	public function canFor(Claim $c, string $uid, ?int $lineCount): array
	{
		$owner = $c->getUserId() === $uid;
		$editable = in_array($c->getStatus(), [
			ClaimLifecycleService::STATUS_DRAFT,
			ClaimLifecycleService::STATUS_CHANGES_REQUESTED,
		], true);
		$canDecide = false;
		if ($c->getStatus() === ClaimLifecycleService::STATUS_SUBMITTED) {
			$snapshot = json_decode((string)$c->getChainSnapshot(), true);
			if (is_array($snapshot) && $c->getCurrentStep() > 0) {
				$canDecide = $this->routing->decisionRights(
					$snapshot, $c->getCurrentStep(), $uid,
					$this->timeFactory->getDateTime(), $this->delegates,
				)['allowed'];
			}
		}
		$finance = $this->access->isFinance($uid);
		return [
			'canEdit' => $owner && $editable,
			'canSubmit' => $owner && $editable && ($lineCount === null || $lineCount > 0),
			'canWithdraw' => $owner && in_array($c->getStatus(), [
				ClaimLifecycleService::STATUS_SUBMITTED,
				ClaimLifecycleService::STATUS_CHANGES_REQUESTED,
			], true),
			'canDecide' => $canDecide,
			'canSettle' => $finance && $c->getStatus() === ClaimLifecycleService::STATUS_APPROVED,
			'canAttach' => $owner && $editable,
			'canExport' => $finance || $this->access->isAuditor($uid),
		];
	}

	/**
	 * Display labels for soft links (spec §10.6) — resolved server-side so a
	 * broken link renders muted, never raw ids (S4 degrade, edge 19).
	 */
	private function resolvedLabel(Claim $c): array
	{
		$label = ['cost_center' => null, 'azc_trip' => null];
		if ($c->getCostCenterId() !== null) {
			$cc = $this->costCenters->findById($c->getCostCenterId());
			$label['cost_center'] = $cc !== null ? $cc->getCode() : null;
		}
		if ($c->getAzcTripId() !== null) {
			$label['azc_trip'] = $this->azc->facadeAvailable()
				? 'AZC #' . $c->getAzcTripId()
				: null;
		}
		return $label;
	}
}
