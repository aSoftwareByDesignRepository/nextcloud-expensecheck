<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Db\SettlementBatch;
use OCA\ExpenseCheck\Db\SettlementBatchMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Service\ExportService;
use OCA\ExpenseCheck\Service\SettlementService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Spec §11.10 + edge 44: threshold→async switch, EXPORT_NOT_READY poll,
 * single-use 15-min token, regenerate-on-serve (bytes never persisted),
 * finance/requester binding.
 */
final class ExportServiceTest extends TestCase
{
	private SettlementService&MockObject $settlement;
	private SettlementBatchMapper&MockObject $batches;
	private SettingsService&MockObject $settings;
	private int $now;
	private ExportService $svc;

	protected function setUp(): void
	{
		$this->settlement = $this->createMock(SettlementService::class);
		$this->batches = $this->createMock(SettlementBatchMapper::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->now = 1_800_000_000;
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$this->svc = new ExportService(
			$this->settlement,
			$this->batches,
			$this->settings,
			$this->createStub(IConfig::class),
			$time,
			$this->createStub(ILockingProvider::class),
			$this->createStub(LoggerInterface::class),
		);
	}

	private function batch(int $id = 7): SettlementBatch
	{
		$b = new SettlementBatch();
		$b->setId($id);
		$b->setCreatedAt(new \DateTime('2026-11-20 10:00:00'));
		return $b;
	}

	private function rows(int $n): array
	{
		$row = ['claim_no' => 'EXP-2026-00001', 'owner' => 'u1', 'title' => 't',
			'currency' => 'EUR', 'amount_minor' => 12345, 'submitted' => '2026-11-01',
			'decided' => '2026-11-02', 'paid_ref' => 'P1', 'paid_at' => '2026-11-03'];
		return ['reimbursable' => array_fill(0, $n, $row), 'nonreimbursable' => []];
	}

	public function testSyncBelowThreshold(): void
	{
		$this->settings->method('getInt')->willReturn(5000);
		$this->settlement->method('exportRows')->willReturn($this->rows(3));
		$r = $this->svc->requestExport($this->batch(), 'reimbursable', 'csv', 'fin');
		$this->assertFalse($r['async']);
		$this->assertSame('settlement-7-reimbursable.csv', $r['filename']);
		$this->assertSame('text/csv', $r['mime']);
		$this->assertStringContainsString('claim_no', $r['payload']);
		$this->assertSame(hash('sha256', $r['payload']), $r['file_hash']);
	}

	public function testAsyncAboveThresholdFullCycle(): void
	{
		$this->settings->method('getInt')->willReturn(2);
		$this->settlement->method('exportRows')->willReturn($this->rows(5));
		$batch = $this->batch();
		$this->batches->method('findById')->willReturn($batch);
		$state = '';
		$this->settings->method('get')->willReturnCallback(function () use (&$state) { return $state; });
		$this->settings->method('set')->willReturnCallback(function ($k, $v) use (&$state) { $state = $v; });

		$req = $this->svc->requestExport($batch, 'reimbursable', 'csv', 'fin');
		$this->assertTrue($req['async']);
		$this->assertMatchesRegularExpression('/^exr-[a-f0-9]{24}$/', $req['request_id']);
		$this->assertMatchesRegularExpression('/^expt-[a-f0-9]{48}$/', $req['token']);

		// pending → EXPORT_NOT_READY
		try {
			$this->svc->poll($req['request_id']);
			$this->fail('expected EXPORT_NOT_READY');
		} catch (DomainException $e) {
			$this->assertSame('EXPORT_NOT_READY', $e->getErrorCode());
		}

		// job finishes → ready + file_hash stamped on batch + requester notified
		$r = $this->svc->runPending();
		$this->assertSame(1, $r['finished']);
		$this->assertSame(['fin'], $r['ready_uids']);
		$expectedBytes = \OCA\ExpenseCheck\Service\CsvExporter::render(
			['claim_no', 'owner', 'title', 'currency', 'amount_minor', 'submitted', 'decided', 'paid_ref', 'paid_at'],
			array_fill(0, 5, ['EXP-2026-00001', 'u1', 't', 'EUR', '123.45', '2026-11-01', '2026-11-02', 'P1', '2026-11-03']));
		$this->assertSame(hash('sha256', $expectedBytes), $batch->getFileHash());

		$poll = $this->svc->poll($req['request_id']);
		$this->assertSame('ready', $poll['state']);
		$this->assertSame($this->now + 900, $poll['expires_at']);

		// download regenerates bytes, consumes the entry
		$dl = $this->svc->download($req['request_id'], $req['token'], 'fin');
		$this->assertStringContainsString('EXP-2026-00001', $dl['payload']);
		try {
			$this->svc->download($req['request_id'], $req['token'], 'fin');
			$this->fail('single-use violated');
		} catch (DomainException $e) {
			$this->assertSame('NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testOdsProducesZipBytes(): void
	{
		$this->settings->method('getInt')->willReturn(5000);
		$this->settlement->method('exportRows')->willReturn($this->rows(1));
		$r = $this->svc->requestExport($this->batch(), 'reimbursable', 'ods', 'fin');
		$this->assertSame('application/vnd.oasis.opendocument.spreadsheet', $r['mime']);
		$this->assertSame('settlement-7-reimbursable.ods', $r['filename']);
		$this->assertSame('PK', substr($r['payload'], 0, 2));
		// mimetype must be first, stored, for ODF readers
		$this->assertStringStartsWith('mimetype', substr($r['payload'], 30, 8));
	}

	public function testDownloadRejectsWrongTokenAndRequester(): void
	{
		$this->settings->method('getInt')->willReturn(1);
		$this->settlement->method('exportRows')->willReturn($this->rows(3));
		$batch = $this->batch();
		$this->batches->method('findById')->willReturn($batch);
		$state = '';
		$this->settings->method('get')->willReturnCallback(function () use (&$state) { return $state; });
		$this->settings->method('set')->willReturnCallback(function ($k, $v) use (&$state) { $state = $v; });
		$req = $this->svc->requestExport($batch, 'reimbursable', 'csv', 'fin');
		$this->svc->runPending();

		foreach ([['bad-token', 'fin'], [$req['token'], 'other']] as [$tok, $uid]) {
			try {
				$this->svc->download($req['request_id'], $tok, $uid);
				$this->fail('expected 404');
			} catch (DomainException $e) {
				$this->assertSame('NOT_FOUND', $e->getErrorCode());
			}
		}
	}

	public function testReadyEntryExpiresAfter15Min(): void
	{
		$this->settings->method('getInt')->willReturn(1);
		$this->settlement->method('exportRows')->willReturn($this->rows(3));
		$batch = $this->batch();
		$this->batches->method('findById')->willReturn($batch);
		$state = '';
		$this->settings->method('get')->willReturnCallback(function () use (&$state) { return $state; });
		$this->settings->method('set')->willReturnCallback(function ($k, $v) use (&$state) { $state = $v; });
		$req = $this->svc->requestExport($batch, 'reimbursable', 'csv', 'fin');
		$this->svc->runPending();
		$this->now += 901;
		try {
			$this->svc->poll($req['request_id']);
			$this->fail('expected expiry');
		} catch (DomainException $e) {
			$this->assertSame('NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testInvalidFormatRejected(): void
	{
		$this->settlement->method('exportRows')->willReturn($this->rows(0));
		try {
			$this->svc->requestExport($this->batch(), 'reimbursable', 'xlsx', 'fin');
			$this->fail('expected 422');
		} catch (DomainException $e) {
			$this->assertSame('VALIDATION_FAILED', $e->getErrorCode());
		}
	}
}
