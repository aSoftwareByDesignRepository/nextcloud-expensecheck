<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\SettlementBatch;
use OCA\ExpenseCheck\Db\SettlementBatchMapper;
use OCA\ExpenseCheck\Db\SettlementRow;
use OCA\ExpenseCheck\Db\SettlementRowMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Settlement (spec §7): finance settles approved claims in one batch. The
 * payment reference is RECORDED per row, never executed (out of scope: bank
 * APIs, SEPA, payroll posting). The whole batch is one transaction — a
 * failure anywhere rolls back every status flip, so partial batches cannot
 * exist.
 */
class SettlementService
{
	public function __construct(
		private readonly ClaimMapper $claims,
		private readonly ClaimLineMapper $lines,
		private readonly SettlementBatchMapper $batches,
		private readonly SettlementRowMapper $rows,
		private readonly ExpChainService $chain,
		private readonly AccessControlService $access,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly PostCommitEffectQueue $postCommit,
		private readonly NotificationService $notify,
	) {
	}

	/**
	 * @param list<int> $claimIds approved claims to settle in one batch
	 * @param string $paidRef external payment reference (free text, recorded)
	 * @param string $format default export format for this batch (csv|ods)
	 */
	public function settle(array $claimIds, string $paidRef, string $actorUid, string $format = 'csv'): SettlementBatch
	{
		if (!$this->access->isFinance($actorUid)) {
			throw new ClaimNotFoundException();
		}
		if (!in_array($format, ExportService::FORMATS, true)) {
			throw new ValidationException(['format' => 'invalid']);
		}
		if ($claimIds === [] || count($claimIds) > 500) {
			throw new ValidationException(['claims' => 'invalid']);
		}
		$claimIds = array_values(array_unique(array_map('intval', $claimIds)));
		$paidRef = trim($paidRef);
		if ($paidRef === '' || mb_strlen($paidRef) > 191) {
			throw new ValidationException(['paid_ref' => 'required']);
		}
		$now = $this->timeFactory->getDateTime();

		$this->db->beginTransaction();
		try {
			$batch = new SettlementBatch();
			$batch->setCreatedBy($actorUid);
			$batch->setCreatedAt($now);
			$batch->setFormat($format);
			$batch->setRowCount(0);
			$batch = $this->batches->insert($batch);

			foreach ($claimIds as $cid) {
				$claim = $this->claims->findByIdRaw($cid);
				if ($claim === null || $claim->getStatus() !== ClaimLifecycleService::STATUS_APPROVED) {
					throw new DomainException('CLAIM_NOT_SETTLEABLE', 'Claim ' . $cid . ' is not approved.', 409);
				}
				$row = new SettlementRow();
				$row->setBatchId($batch->getId());
				$row->setClaimId($cid);
				$row->setPaidRef($paidRef);
				$row->setPaidAt($now);
				$this->rows->insert($row);

				$claim->setStatus(ClaimLifecycleService::STATUS_SETTLED);
				$claim->setSettledAt($now);
				$claim->setSettledBy($actorUid);
				$claim->setSettlementRef($paidRef);
				$claim->setBatchId($batch->getId());
				$claim->setUpdatedAt($now);
				$expected = $claim->getVersion();
				$claim->setVersion($expected + 1);
				if (!$this->claims->updateWithVersion($claim, $expected)) {
					throw new DomainException('VERSION_CONFLICT', 'Claim ' . $claim->getClaimNo() . ' changed concurrently.', 409);
				}
				$this->chain->append($cid, 'settled', $actorUid, ClaimLifecycleService::STATUS_APPROVED,
					ClaimLifecycleService::STATUS_SETTLED, [
						'batch_id' => $batch->getId(), 'paid_ref' => $paidRef,
					]);
				// Deferred until the batch commit is durable — a rolled-back
				// batch must never announce settlement.
				$this->postCommit->runAfterCurrentCommit(function () use ($claim): void {
					$this->notify->claimSettled($claim);
				});
			}
			$batch->setRowCount(count($claimIds));
			$batch->setFileHash(hash('sha256', $batch->getId() . '|' . $paidRef . '|' . implode(',', $claimIds)));
			$this->batches->update($batch);
			$this->db->commit();
			$this->postCommit->flush();
			return $batch;
		} catch (\Throwable $e) {
			$this->postCommit->discard();
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Export rows for a batch, split by payout channel (spec §7):
	 * reimbursable → payroll/bank reimbursement artifact; non-reimbursable →
	 * card-reconciliation artifact. Ordered by claim_no for byte-stable
	 * regeneration.
	 *
	 * @return array{reimbursable:list<array>,nonreimbursable:list<array>}
	 */
	public function exportRows(int $batchId): array
	{
		$out = ['reimbursable' => [], 'nonreimbursable' => []];
		foreach ($this->rows->findByBatch($batchId) as $row) {
			$claim = $this->claims->findByIdRaw($row->getClaimId());
			if ($claim === null) {
				continue;
			}
			$reimb = 0;
			$nonreimb = 0;
			foreach ($this->lines->findByClaim($claim->getId()) as $l) {
				if ($l->getReimbursable() === 1) {
					$reimb += $l->getAmountMinor();
				} else {
					$nonreimb += $l->getAmountMinor();
				}
			}
			$base = [
				'claim_no' => $claim->getClaimNo(),
				'owner' => $claim->getUserId(),
				'title' => $claim->getTitle(),
				'currency' => $claim->getCurrency(),
				'submitted' => $claim->getSubmittedAt()?->format('Y-m-d'),
				'decided' => $claim->getDecidedAt()?->format('Y-m-d'),
				'paid_ref' => $row->getPaidRef(),
				'paid_at' => $row->getPaidAt()->format('Y-m-d'),
			];
			if ($reimb > 0) {
				$out['reimbursable'][] = $base + ['amount_minor' => $reimb];
			}
			if ($nonreimb > 0) {
				$out['nonreimbursable'][] = $base + ['amount_minor' => $nonreimb];
			}
		}
		foreach ($out as &$list) {
			usort($list, fn ($a, $b) => strcmp($a['claim_no'], $b['claim_no']));
		}
		unset($list);
		return $out;
	}

	/** Approved claims awaiting settlement (finance queue). @return list<Claim> */
	public function settleable(): array
	{
		return $this->claims->findByStatus(ClaimLifecycleService::STATUS_APPROVED);
	}
}
