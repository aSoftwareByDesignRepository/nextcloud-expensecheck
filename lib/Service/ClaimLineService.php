<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Db\CategoryMapper;
use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimAllocation;
use OCA\ExpenseCheck\Db\ClaimAllocationMapper;
use OCA\ExpenseCheck\Db\ClaimLine;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Claim lines (spec §6): receipt | mileage | per_diem | flat. Editable only
 * while the claim is draft or changes_requested. Money is computed
 * server-side: receipts carry an explicit amount; rated types derive
 * amount = qty × snapshotted rate (RATE_MISSING when no effective rate on
 * occurred_on). Each rated type uses its own qty column:
 * mileage→distance_km, per_diem→days, flat→quantity.
 * Allocations resolve to exact minor-unit sums (percent or amount, not both).
 */
class ClaimLineService
{
	public const LINE_TYPES = ['receipt', 'mileage', 'per_diem', 'flat'];
	public const DISTANCE_SOURCES = ['manual', 'gps', 'mc_segment'];

	public function __construct(
		private readonly ClaimLineMapper $lines,
		private readonly ClaimMapper $claims,
		private readonly ClaimAllocationMapper $allocations,
		private readonly CategoryMapper $categories,
		private readonly AttachmentMapper $attachments,
		private readonly RateCatalogService $rates,
		private readonly PeriodService $periods,
		private readonly MoneyMathService $money,
		private readonly ExpChainService $chain,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	private function assertEditable(Claim $claim): void
	{
		if (!in_array($claim->getStatus(), [ClaimLifecycleService::STATUS_DRAFT, ClaimLifecycleService::STATUS_CHANGES_REQUESTED], true)) {
			throw new DomainException('CLAIM_READ_ONLY', 'Lines can only change while the claim is a draft or changes were requested.', 409);
		}
	}

	/**
	 * @param array<string,mixed> $p controller input
	 * @param list<array<string,mixed>> $allocs
	 */
	public function addLine(Claim $claim, array $p, array $allocs, string $actorUid, bool $lateSyncAccept = false): ClaimLine
	{
		$this->assertEditable($claim);
		$line = $this->buildLine($claim, new ClaimLine(), $p, $lateSyncAccept);
		$resolved = $this->money->resolveAllocations($allocs, $line->getAmountMinor());
		$this->assertSourceUnclaimed($line);

		$this->db->beginTransaction();
		try {
			$max = 0;
			foreach ($this->lines->findByClaim($claim->getId()) as $l) {
				$max = max($max, $l->getSort());
			}
			$line->setSort($max + 1);
			$line = $this->lines->insert($line);
			$this->writeAllocations($line->getId(), $resolved);
			$this->refreshClaimTotals($claim);
			$this->chain->append($claim->getId(), 'line_added', $actorUid, null, null,
				['line_id' => $line->getId(), 'line_type' => $line->getLineType()]);
			$this->db->commit();
			return $line;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			// TOCTOU: uniq(source_app, source_ref) can still fire between the
			// pre-check and the insert — surface the same 409, never a 500.
			if ($this->isUniqueViolation($e)) {
				$this->throwAlreadyClaimed($line);
			}
			throw $e;
		}
	}

	public function updateLine(Claim $claim, ClaimLine $line, array $p, array $allocs, string $actorUid, bool $lateSyncAccept = false): ClaimLine
	{
		$this->assertEditable($claim);
		if ($line->getClaimId() !== $claim->getId()) {
			throw new DomainException('CLAIM_NOT_FOUND', 'Line does not belong to claim.', 404);
		}
		$line = $this->buildLine($claim, $line, $p, $lateSyncAccept);
		$resolved = $this->money->resolveAllocations($allocs, $line->getAmountMinor());
		$this->assertSourceUnclaimed($line);
		$this->db->beginTransaction();
		try {
			$line->setUpdatedAt($this->timeFactory->getDateTime());
			$line = $this->lines->update($line);
			$this->allocations->deleteByLine($line->getId());
			$this->writeAllocations($line->getId(), $resolved);
			$this->refreshClaimTotals($claim);
			$this->chain->append($claim->getId(), 'line_updated', $actorUid, null, null, ['line_id' => $line->getId()]);
			$this->db->commit();
			return $line;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			if ($this->isUniqueViolation($e)) {
				$this->throwAlreadyClaimed($line);
			}
			throw $e;
		}
	}

	public function deleteLine(Claim $claim, ClaimLine $line, string $actorUid): void
	{
		$this->assertEditable($claim);
		if ($line->getClaimId() !== $claim->getId()) {
			throw new DomainException('CLAIM_NOT_FOUND', 'Line does not belong to claim.', 404);
		}
		$this->db->beginTransaction();
		try {
			$this->allocations->deleteByLine($line->getId());
			$this->lines->delete($line);
			$this->refreshClaimTotals($claim);
			$this->chain->append($claim->getId(), 'line_removed', $actorUid, null, null, ['line_id' => $line->getId()]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Edge 37: a sibling source ref can only ever fund ONE line globally —
	 * two lines on the same claim still double-count the km, so the
	 * exclusion is the line's own id, never its claim. The uniq index is
	 * the backstop, this check is the honest 409.
	 */
	private function assertSourceUnclaimed(ClaimLine $line): void
	{
		if ($line->getSourceApp() === null || $line->getSourceRef() === null) {
			return;
		}
		$existing = $this->lines->findClaimBySource((string)$line->getSourceApp(), (string)$line->getSourceRef());
		if ($existing !== null && $existing['line_id'] !== $line->getId()) {
			throw new DomainException('ALREADY_CLAIMED',
				'This ' . $line->getSourceApp() . ' entry was already claimed in ' . $existing['claim_no'] . '.', 409);
		}
	}

	private function throwAlreadyClaimed(ClaimLine $line): void
	{
		$this->assertSourceUnclaimed($line);
	}

	private function isUniqueViolation(\Throwable $e): bool
	{
		$msg = strtolower($e->getMessage());
		return str_contains($msg, 'unique') || str_contains($msg, 'duplicate');
	}

	private function buildLine(Claim $claim, ClaimLine $line, array $p, bool $lateSyncAccept = false): ClaimLine
	{
		$fields = [];
		$type = (string)($p['line_type'] ?? '');
		if (!in_array($type, self::LINE_TYPES, true)) {
			$fields['line_type'] = 'invalid';
		}
		$occurredOn = null;
		try {
			$occurredOn = new \DateTimeImmutable((string)($p['occurred_on'] ?? 'invalid'));
			if ($occurredOn->format('Y-m-d') !== (string)($p['occurred_on'] ?? '')) {
				throw new \Exception();
			}
		} catch (\Throwable) {
			$fields['occurred_on'] = 'invalid_date';
		}
		$categoryId = (int)($p['category_id'] ?? 0);
		$category = $categoryId > 0 ? $this->categories->findById($categoryId) : null;
		if ($category === null) {
			$fields['category_id'] = 'invalid';
		}
		$merchant = mb_substr(trim((string)($p['merchant'] ?? '')), 0, 191) ?: null;
		$description = mb_substr(trim((string)($p['description'] ?? '')), 0, 2000) ?: null;
		$justification = mb_substr(trim((string)($p['justification'] ?? '')), 0, 2000) ?: null;
		// MH-06c: categories flagged requires_attendees (Bewirtung-class) need
		// the participant list AND the business occasion — both are the
		// compliance surface, missing either is a hard field error.
		if ($category !== null && $category->getRequiresAttendees() === 1) {
			$attendeesRaw = trim((string)($p['attendees'] ?? ''));
			$occasionRaw = trim((string)($p['occasion'] ?? ''));
			if ($attendeesRaw === '') {
				$fields['attendees'] = 'required';
			}
			if ($occasionRaw === '') {
				$fields['occasion'] = 'required';
			}
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		// Edge 41 / §13.3 late-sync acceptance: on the sync channel a draft
		// whose occurred_on fell into a since-closed period (or outside a
		// rate window, below) is accepted as draft data and stamped
		// late_sync — submit re-validates and surfaces PERIOD_CLOSED /
		// RATE_MISSING. Interactive creates keep the hard block.
		$lateSync = false;
		try {
			$this->periods->assertLineDateAllowed($occurredOn);
		} catch (DomainException $e) {
			if (!$lateSyncAccept || $e->getErrorCode() !== 'PERIOD_CLOSED') {
				throw $e;
			}
			$lateSync = true;
		}

		$line->setClaimId($claim->getId());
		$line->setLineType($type);
		$line->setCategoryId($categoryId);
		$line->setOccurredOn(\DateTime::createFromFormat('Y-m-d', $occurredOn->format('Y-m-d')));
		$line->setMerchant($merchant);
		$line->setDescription($description);
		$line->setJustification($justification);
		$line->setReimbursable($this->truthy($p['reimbursable'] ?? true) ? 1 : 0);
		$line->setAttendees(mb_substr(trim((string)($p['attendees'] ?? '')), 0, 2000) ?: null);
		$line->setOccasion(mb_substr(trim((string)($p['occasion'] ?? '')), 0, 191) ?: null);
		$line->setSourceApp(mb_substr(trim((string)($p['source_app'] ?? '')), 0, 32) ?: null);
		$line->setSourceRef(mb_substr(trim((string)($p['source_ref'] ?? '')), 0, 64) ?: null);

		// Money: receipt = explicit amount; rated types = qty × snapshot rate.
		if ($type === 'receipt') {
			$amount = MoneyMathService::toMinor((string)($p['amount'] ?? ''), 2);
			// Edge 20: negative lines exist only on explicit correction claims
			// (storno, corrects_claim_no) and F26 deduction claims — never on
			// a standard claim where they would be an unsigned give-away.
			$negativeAllowed = in_array($claim->getKind(), ['correction', 'deduction'], true);
			if ($amount === 0 || ($amount < 0 && !$negativeAllowed)) {
				throw new ValidationException(['amount' => 'invalid_amount']);
			}
			$line->setAmountMinor($amount);
			$line->setDistanceKm(null);
			$line->setDays(null);
			$line->setQuantity(null);
			$line->setRateId(null);
			$line->setRateCode(null);
			$line->setRateMinor(null);
			$line->setRateName(null);
			$tip = trim((string)($p['tip'] ?? ''));
			$line->setTipMinor($tip === '' ? null : MoneyMathService::toMinor($tip, 2));
		} else {
			$qtyRaw = (string)($p['qty'] ?? '');
			$qtyScaled = MoneyMathService::quantityToScaled($qtyRaw);
			if ($qtyScaled <= 0) {
				throw new ValidationException(['qty' => 'invalid_amount']);
			}
			$qtyStr = number_format($qtyScaled / 100, 2, '.', '');
			$line->setDistanceKm($type === 'mileage' ? $qtyStr : null);
			$line->setDays($type === 'per_diem' ? $qtyStr : null);
			$line->setQuantity($type === 'flat' ? $qtyStr : null);
			$line->setTipMinor(null);

			$rateCode = trim((string)($p['rate_code'] ?? ''));
			if ($rateCode === '') {
				throw new ValidationException(['rate_code' => 'required']);
			}
			try {
				$rate = $this->rates->resolve($type, $rateCode, $occurredOn); // RATE_MISSING thrown inside
				$snap = RateCatalogService::snapshot($rate);
				$line->setRateId($snap['rate_id']);
				$line->setRateCode($snap['rate_code']);
				$line->setRateMinor($snap['rate_minor']);
				$line->setRateName($snap['rate_name']);
				$line->setAmountMinor(MoneyMathService::quantityTimesRate($qtyScaled, $snap['rate_minor']));
			} catch (DomainException $e) {
				if (!$lateSyncAccept || $e->getErrorCode() !== 'RATE_MISSING') {
					throw $e;
				}
				// Late sync: keep the requested code + qty so submit can
				// re-resolve; amount stays 0 and submit fails closed on the
				// missing rate snapshot (RATE_MISSING, fail-closed above).
				$line->setRateCode($rateCode);
				$line->setAmountMinor(0);
				$lateSync = true;
			}
			// §10.1 enumerates the provenance values — anything else is a
			// contract violation, not a free-form label.
			$distanceSource = mb_substr(trim((string)($p['distance_source'] ?? 'manual')), 0, 16) ?: 'manual';
			if (!in_array($distanceSource, self::DISTANCE_SOURCES, true)) {
				throw new ValidationException(['distance_source' => 'invalid']);
			}
			// mc_segment provenance is only honest when the line carries the
			// sibling evidence link it claims to derive from.
			if ($distanceSource === 'mc_segment'
				&& (($p['source_app'] ?? '') !== 'mobilitycheck' || trim((string)($p['source_ref'] ?? '')) === '')) {
				throw new ValidationException(['source_ref' => 'required']);
			}
			$line->setDistanceSource($distanceSource);
			// Edge 32: GPS gaps (tunnel, permission revoked mid-ride) — the
			// companion flags an estimate; preserved through submit like
			// late_sync so the approver keeps seeing the provenance.
			if ($this->truthy($p['distance_estimated'] ?? false)) {
				$existing = $line->getPolicyFlags() !== null ? (json_decode($line->getPolicyFlags(), true) ?: []) : [];
				if (!in_array('distance_estimated', $existing, true)) {
					$existing[] = 'distance_estimated';
				}
				$line->setPolicyFlags(json_encode($existing));
			}
		}

		// Optional foreign-currency evidence (spec §9): stored as presented;
		// the claim-currency amount above is authoritative for totals.
		$foreignAmount = trim((string)($p['foreign_amount'] ?? ''));
		if ($foreignAmount !== '') {
			$fCur = strtoupper(trim((string)($p['foreign_currency'] ?? '')));
			if (!preg_match('/^[A-Z]{3}$/', $fCur)) {
				throw new ValidationException(['foreign_currency' => 'invalid']);
			}
			$fAmt = MoneyMathService::toMinor($foreignAmount, 2);
			if ($fAmt <= 0) {
				throw new ValidationException(['foreign_amount' => 'invalid_amount']);
			}
			$fx = trim((string)($p['foreign_fx'] ?? ''));
			if ($fx === '' || MoneyMathService::decimalToScaledInt($fx, 1000000) <= 0) {
				throw new ValidationException(['foreign_fx' => 'invalid_fx']);
			}
			$line->setForeignAmountMinor($fAmt);
			$line->setForeignCurrency($fCur);
			$line->setForeignFx($fx);
		} else {
			$line->setForeignAmountMinor(null);
			$line->setForeignCurrency(null);
			$line->setForeignFx(null);
		}
		// Category-gated rules that need the resolved amount (§10.4 dictionary):
		// allow_lines fence, justification threshold, hard cap.
		if ($category !== null) {
			$allowed = array_map('trim', explode(',', $category->getAllowLines()));
			if (!in_array($type, $allowed, true)) {
				throw new ValidationException(['line_type' => 'not_allowed']);
			}
			$threshold = $category->getJustificationThresholdMinor();
			if ($threshold !== null && $line->getAmountMinor() >= $threshold) {
				$minLen = max(1, $category->getJustificationMinLength());
				if (mb_strlen((string)$line->getJustification()) < $minLen) {
					throw new DomainException('JUSTIFICATION_REQUIRED',
						'This category needs a justification (min ' . $minLen . ' characters) above the threshold.', 422);
				}
			}
			$max = $category->getMaxAmountMinor();
			if ($max !== null && $line->getAmountMinor() > $max && $category->getMaxIsHard() === 1) {
				throw new DomainException('CAP_EXCEEDED', 'The amount exceeds this category\'s hard limit.', 422);
			}
		}
		if ($lateSync) {
			$existing = $line->getPolicyFlags() !== null ? (json_decode($line->getPolicyFlags(), true) ?: []) : [];
			if (!in_array('late_sync', $existing, true)) {
				$existing[] = 'late_sync';
			}
			$line->setPolicyFlags(json_encode($existing));
		}
		if ($line->getCreatedAt() === null) {
			$line->setCreatedAt($this->timeFactory->getDateTime());
		}
		$line->setUpdatedAt($this->timeFactory->getDateTime());
		return $line;
	}

	/**
	 * Edge 41: submit-time period re-validation. A late-synced draft may
	 * carry occurred_on inside a since-closed period — draft acceptance is
	 * lenient, submit is not.
	 */
	public function assertLineDatesAllowed(Claim $claim): void
	{
		foreach ($this->lines->findByClaim($claim->getId()) as $l) {
			if ($l->getOccurredOn() !== null) {
				$this->periods->assertLineDateAllowed($l->getOccurredOn());
			}
		}
	}

	/**
	 * MH-06b — server-computed policy_flags, written at submit (never
	 * client-supplied): over_threshold (amount above category cap — soft or
	 * hard), deviates_default (Standardkosten prefill changed),
	 * dup_receipt_hash (same sha256 filed on another claim), weekend_date.
	 * Rendered as chips on the approver card — advisory, never a verdict.
	 */
	public function computePolicyFlags(Claim $claim): void
	{
		$claimHashes = [];
		foreach ($this->attachments->findByClaim($claim->getId()) as $att) {
			if ($att->getSha256() !== '') {
				$claimHashes[] = $att->getSha256();
			}
		}
		foreach ($this->lines->findByClaim($claim->getId()) as $line) {
			$flags = [];
			// late_sync + distance_estimated are stamped at write time and
			// survive re-computation (edge 41 + edge 32 provenance).
			$existing = $line->getPolicyFlags() !== null ? (json_decode($line->getPolicyFlags(), true) ?: []) : [];
			foreach (['late_sync', 'distance_estimated'] as $preserved) {
				if (in_array($preserved, $existing, true)) {
					$flags[] = $preserved;
				}
			}
			$cat = $this->categories->findById($line->getCategoryId());
			if ($cat !== null) {
				$max = $cat->getMaxAmountMinor();
				if ($max !== null && $line->getAmountMinor() > $max) {
					$flags[] = 'over_threshold';
				}
				$def = $cat->getDefaultAmountMinor();
				if ($def !== null && $line->getAmountMinor() !== $def) {
					$flags[] = 'deviates_default';
				}
			}
			$dow = $line->getOccurredOn() !== null ? (int)$line->getOccurredOn()->format('N') : 0;
			if ($dow >= 6) {
				$flags[] = 'weekend_date';
			}
			if ($claimHashes !== []) {
				foreach ($claimHashes as $sha) {
					if ($this->attachments->findOtherClaimsByHash($sha, $claim->getId()) !== []) {
						$flags[] = 'dup_receipt_hash';
						break;
					}
				}
			}
			$line->setPolicyFlags($flags === [] ? null : json_encode(array_values(array_unique($flags))));
			$this->lines->update($line);
		}
	}

	/**
	 * RECEIPT_REQUIRED gate for submit: a receipt-required category line must
	 * have evidence — a line-level attachment or any filed claim-level
	 * attachment (combined invoices cover several lines legitimately).
	 */
	public function assertReceiptEvidence(Claim $claim): void
	{
		$claimAttachments = $this->attachments->findByClaim($claim->getId());
		foreach ($this->lines->findByClaim($claim->getId()) as $line) {
			$cat = $this->categories->findById($line->getCategoryId());
			if ($cat === null || $cat->getReceiptRequired() !== 1) {
				continue;
			}
			if ($claimAttachments !== []) {
				continue;
			}
			throw new DomainException('RECEIPT_REQUIRED',
				'Category ' . $cat->getCode() . ' requires a receipt attachment.', 422);
		}
	}

	/** Form-encoded booleans arrive as strings — "false"/"0" must not be truthy. */
	private function truthy(mixed $v): bool
	{
		if (is_bool($v)) {
			return $v;
		}
		return !in_array((string)$v, ['', '0', 'false', 'no'], true);
	}

	/**
	 * Recompute the claim's totals inside the current transaction. The claim
	 * row is CAS-updated — a stale in-memory entity fails closed (409) rather
	 * than clobbering a concurrent write.
	 */
	private function refreshClaimTotals(Claim $claim): void
	{
		$lines = $this->lines->findByClaim($claim->getId());
		$totals = MoneyMathService::claimTotals(array_map(fn ($l) => [
			'amount_minor' => $l->getAmountMinor(),
			'reimbursable' => $l->getReimbursable(),
		], $lines));
		$claim->setTotalMinor($totals['total']);
		$claim->setReimbursableTotalMinor($totals['reimbursable']);
		$claim->setNonreimbursableTotalMinor($totals['nonreimbursable']);
		$claim->setOrgTotalMinor($totals['total']);
		$claim->setUpdatedAt($this->timeFactory->getDateTime());
		$expected = $claim->getVersion();
		$claim->setVersion($expected + 1);
		if (!$this->claims->updateWithVersion($claim, $expected)) {
			throw new DomainException('VERSION_CONFLICT', 'Claim was changed concurrently. Reload and retry.', 409);
		}
	}

	/** @param list<array{cost_center_id:int,amount_minor:int,pc_project_id:?int}> $resolved */
	private function writeAllocations(int $lineId, array $resolved): void
	{
		foreach ($resolved as $a) {
			$row = new ClaimAllocation();
			$row->setLineId($lineId);
			$row->setCostCenterId($a['cost_center_id']);
			$row->setAmountMinor($a['amount_minor']);
			$row->setPcProjectId($a['pc_project_id']);
			$this->allocations->insert($row);
		}
	}
}
