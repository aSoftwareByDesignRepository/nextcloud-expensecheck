<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

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

	public function __construct(
		private readonly ClaimLineMapper $lines,
		private readonly ClaimMapper $claims,
		private readonly ClaimAllocationMapper $allocations,
		private readonly CategoryMapper $categories,
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
	public function addLine(Claim $claim, array $p, array $allocs, string $actorUid): ClaimLine
	{
		$this->assertEditable($claim);
		$line = $this->buildLine($claim, new ClaimLine(), $p);
		$resolved = $this->money->resolveAllocations($allocs, $line->getAmountMinor());

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
			throw $e;
		}
	}

	public function updateLine(Claim $claim, ClaimLine $line, array $p, array $allocs, string $actorUid): ClaimLine
	{
		$this->assertEditable($claim);
		if ($line->getClaimId() !== $claim->getId()) {
			throw new DomainException('CLAIM_NOT_FOUND', 'Line does not belong to claim.', 404);
		}
		$line = $this->buildLine($claim, $line, $p);
		$resolved = $this->money->resolveAllocations($allocs, $line->getAmountMinor());
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

	private function buildLine(Claim $claim, ClaimLine $line, array $p): ClaimLine
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
		if ($categoryId <= 0 || $this->categories->findById($categoryId) === null) {
			$fields['category_id'] = 'invalid';
		}
		$merchant = mb_substr(trim((string)($p['merchant'] ?? '')), 0, 191) ?: null;
		$description = mb_substr(trim((string)($p['description'] ?? '')), 0, 2000) ?: null;
		$justification = mb_substr(trim((string)($p['justification'] ?? '')), 0, 2000) ?: null;
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		$this->periods->assertLineDateAllowed($occurredOn);

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
			if ($amount <= 0) {
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
			$rate = $this->rates->resolve($type, $rateCode, $occurredOn); // RATE_MISSING thrown inside
			$snap = RateCatalogService::snapshot($rate);
			$line->setRateId($snap['rate_id']);
			$line->setRateCode($snap['rate_code']);
			$line->setRateMinor($snap['rate_minor']);
			$line->setRateName($snap['rate_name']);
			$line->setAmountMinor(MoneyMathService::quantityTimesRate($qtyScaled, $snap['rate_minor']));
			$line->setDistanceSource(mb_substr(trim((string)($p['distance_source'] ?? 'manual')), 0, 16) ?: 'manual');
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
		if ($line->getCreatedAt() === null) {
			$line->setCreatedAt($this->timeFactory->getDateTime());
		}
		$line->setUpdatedAt($this->timeFactory->getDateTime());
		return $line;
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
