<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Db\SettlementBatchMapper;
use OCA\ExpenseCheck\Db\SettlementRowMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\ClaimSerializer;
use OCA\ExpenseCheck\Service\CsvExporter;
use OCA\ExpenseCheck\Service\PeriodService;
use OCA\ExpenseCheck\Service\SettlementService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Settlement queue + batches + periods — finance-role gated. */
class SettlementApiController extends AApiController
{
	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly SettlementService $settlement,
		private readonly SettlementBatchMapper $batches,
		private readonly SettlementRowMapper $rows,
		private readonly PeriodService $periods,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	private function assertFinance(): void
	{
		if (!$this->access->isFinance($this->uid())) {
			throw new ClaimNotFoundException(); // uniform 404 — no role oracle
		}
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function queue(): JSONResponse
	{
		$this->assertFinance();
		return new JSONResponse([
			'ok' => true,
			'claims' => array_map([ClaimSerializer::class, 'summary'], $this->settlement->settleable()),
		]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function settle(): JSONResponse
	{
		$this->assertFinance();
		$b = $this->body();
		$ids = $b['claim_ids'] ?? [];
		if (!is_array($ids)) {
			throw new ValidationException(['claim_ids' => 'invalid']);
		}
		$batch = $this->settlement->settle($ids, $this->str($b, 'paid_ref', 191), $this->uid());
		return new JSONResponse(['ok' => true, 'batch' => $this->batchDto($batch)], 201);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function listBatches(): JSONResponse
	{
		$this->assertFinance();
		return new JSONResponse([
			'ok' => true,
			'batches' => array_map([$this, 'batchDto'], $this->batches->findAll()),
		]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function showBatch(): JSONResponse
	{
		$this->assertFinance();
		$batch = $this->batches->findById($this->routeId());
		if ($batch === null) {
			throw new DomainException('BATCH_NOT_FOUND', 'Batch not found.', 404);
		}
		$claims = [];
		foreach ($this->rows->findByBatch($batch->getId()) as $r) {
			$claims[] = ['claim_id' => $r->getClaimId(), 'paid_ref' => $r->getPaidRef(), 'paid_at' => $r->getPaidAt()?->format('Y-m-d H:i:s')];
		}
		return new JSONResponse(['ok' => true, 'batch' => $this->batchDto($batch), 'rows' => $claims]);
	}

	/**
	 * CSV export — two artifacts via `?channel=reimbursable|nonreimbursable`.
	 * Generated on the fly, never persisted (the batch + journal are the book
	 * of record). Deterministic: same batch → same bytes.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function exportBatch()
	{
		$this->assertFinance();
		$batch = $this->batches->findById($this->routeId());
		if ($batch === null) {
			throw new DomainException('BATCH_NOT_FOUND', 'Batch not found.', 404);
		}
		$channel = (string)$this->request->getParam('channel', 'reimbursable');
		if (!in_array($channel, ['reimbursable', 'nonreimbursable'], true)) {
			throw new ValidationException(['channel' => 'invalid']);
		}
		$rows = $this->settlement->exportRows($batch->getId())[$channel];
		$header = ['claim_no', 'owner', 'title', 'currency', 'amount_minor', 'submitted', 'decided', 'paid_ref', 'paid_at'];
		$body = array_map(fn ($r) => [
			$r['claim_no'], $r['owner'], $r['title'], $r['currency'],
			number_format($r['amount_minor'] / 100, 2, '.', ''),
			$r['submitted'], $r['decided'], $r['paid_ref'], $r['paid_at'],
		], $rows);
		$csv = CsvExporter::render($header, $body);
		$resp = new DataDownloadResponse($csv, 'settlement-' . $batch->getId() . '-' . $channel . '.csv', 'text/csv');
		return $resp;
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function listPeriods(): JSONResponse
	{
		$this->assertFinance();
		return new JSONResponse([
			'ok' => true,
			'periods' => array_map(fn ($p) => [
				'id' => $p->getId(), 'name' => $p->getName(),
				'start_on' => $p->getStartOn()?->format('Y-m-d'),
				'end_on' => $p->getEndOn()?->format('Y-m-d'),
				'status' => $p->getStatus(),
				'closed_by' => $p->getClosedBy(),
				'closed_at' => $p->getClosedAt()?->format('Y-m-d H:i:s'),
			], $this->periods->list()),
		]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function createPeriod(): JSONResponse
	{
		$this->assertFinance();
		$b = $this->body();
		$carry = in_array((string)($b['carry_over'] ?? ''), ['1', 'true'], true);
		try {
			$from = new \DateTimeImmutable($this->str($b, 'start_on', 10));
			$to = new \DateTimeImmutable($this->str($b, 'end_on', 10));
		} catch (\Throwable) {
			throw new ValidationException(['start_on' => 'invalid_date']);
		}
		$period = $this->periods->close($this->str($b, 'name', 191), $from, $to, $this->uid(), $carry);
		return new JSONResponse(['ok' => true, 'period' => [
			'id' => $period->getId(), 'name' => $period->getName(),
			'start_on' => $period->getStartOn()?->format('Y-m-d'),
			'end_on' => $period->getEndOn()?->format('Y-m-d'),
			'status' => $period->getStatus(),
		]], 201);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function closePeriod(): JSONResponse
	{
		// Creating a period already IS a close — kept for route parity; reopen
		// is the only meaningful second action.
		throw new DomainException('PERIOD_NOT_FOUND', 'Periods are closed at creation.', 404);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function reopenPeriod(): JSONResponse
	{
		$this->assertFinance();
		$period = $this->periods->reopen($this->routeId(), $this->str($this->body(), 'reason', 2000), $this->uid());
		return new JSONResponse(['ok' => true, 'period' => [
			'id' => $period->getId(), 'status' => $period->getStatus(),
		]]);
	}

	private function batchDto(\OCA\ExpenseCheck\Db\SettlementBatch $b): array
	{
		return [
			'id' => $b->getId(),
			'created_by' => $b->getCreatedBy(),
			'created_at' => $b->getCreatedAt()?->format('Y-m-d H:i:s'),
			'row_count' => $b->getRowCount(),
			'file_hash' => $b->getFileHash(),
			'period_id' => $b->getPeriodId(),
		];
	}
}
