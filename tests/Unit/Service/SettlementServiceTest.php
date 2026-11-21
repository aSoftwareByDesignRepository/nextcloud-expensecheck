<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimLine;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\SettlementBatch;
use OCA\ExpenseCheck\Db\SettlementBatchMapper;
use OCA\ExpenseCheck\Db\SettlementRow;
use OCA\ExpenseCheck\Db\SettlementRowMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\ExpChainService;
use OCA\ExpenseCheck\Service\NotificationService;
use OCA\ExpenseCheck\Service\PostCommitEffectQueue;
use OCA\ExpenseCheck\Service\SettlementService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * §7 settlement: finance-only, atomic batch, CAS per claim, channel split.
 */
final class SettlementServiceTest extends TestCase
{
	private ClaimMapper&MockObject $claims;
	private ClaimLineMapper&MockObject $lines;
	private SettlementBatchMapper&MockObject $batches;
	private SettlementRowMapper&MockObject $rows;
	private AccessControlService&MockObject $access;
	private ExpChainService&MockObject $chain;
	private SettlementService $svc;

	protected function setUp(): void
	{
		$this->claims = $this->createMock(ClaimMapper::class);
		$this->lines = $this->createMock(ClaimLineMapper::class);
		$this->batches = $this->createMock(SettlementBatchMapper::class);
		$this->rows = $this->createMock(SettlementRowMapper::class);
		$this->access = $this->createMock(AccessControlService::class);
		$this->chain = $this->createMock(ExpChainService::class);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-12-01 10:00:00'));
		$this->svc = new SettlementService(
			$this->claims, $this->lines, $this->batches, $this->rows,
			$this->chain, $this->access, $this->createStub(IDBConnection::class),
			$time,
			new PostCommitEffectQueue($this->createStub(IDBConnection::class), $this->createStub(LoggerInterface::class)),
			$this->createStub(NotificationService::class),
		);
	}

	private function approvedClaim(int $id, string $no = 'EXP-2026-00001', int $version = 3): Claim
	{
		$c = new Claim();
		$c->setId($id);
		$c->setClaimNo($no);
		$c->setUserId('alice');
		$c->setStatus('approved');
		$c->setVersion($version);
		$c->setCurrency('EUR');
		$c->setTitle('Trip');
		return $c;
	}

	public function testSettleRequiresFinanceRole(): void
	{
		$this->access->method('isFinance')->willReturn(false);
		$this->expectException(ClaimNotFoundException::class);
		$this->svc->settle([1], 'REF-1', 'mallory');
	}

	public function testSettleRejectsBadFormat(): void
	{
		$this->access->method('isFinance')->willReturn(true);
		try {
			$this->svc->settle([1], 'REF-1', 'fin', 'xlsx');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('invalid', $e->getFields()['format']);
		}
	}

	public function testSettleRejectsEmptyClaims(): void
	{
		$this->access->method('isFinance')->willReturn(true);
		try {
			$this->svc->settle([], 'REF-1', 'fin');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('invalid', $e->getFields()['claims']);
		}
	}

	public function testSettleRejectsOver500Claims(): void
	{
		$this->access->method('isFinance')->willReturn(true);
		try {
			$this->svc->settle(range(1, 501), 'REF-1', 'fin');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('invalid', $e->getFields()['claims']);
		}
	}

	public function testSettleRejectsEmptyPaidRef(): void
	{
		$this->access->method('isFinance')->willReturn(true);
		try {
			$this->svc->settle([1], '   ', 'fin');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['paid_ref']);
		}
	}

	public function testSettleRejectsNonApprovedClaim(): void
	{
		$this->access->method('isFinance')->willReturn(true);
		$this->batches->method('insert')->willReturnCallback(function (SettlementBatch $b) {
			$b->setId(9); return $b;
		});
		$this->claims->method('findByIdRaw')->willReturn($this->approvedClaim(7, 'EXP-2026-00001', 3));
		// flip to draft — not settleable
		$c = $this->approvedClaim(7);
		$c->setStatus('draft');
		$claims = $this->createMock(ClaimMapper::class);
		$claims->method('findByIdRaw')->willReturn($c);
		$svc = new SettlementService(
			$claims, $this->lines, $this->batches, $this->rows, $this->chain,
			$this->access, $this->createStub(IDBConnection::class),
			$this->createStub(ITimeFactory::class),
			new PostCommitEffectQueue($this->createStub(IDBConnection::class), $this->createStub(LoggerInterface::class)),
			$this->createStub(NotificationService::class),
		);
		try {
			$svc->settle([7], 'REF-1', 'fin');
			$this->fail('expected CLAIM_NOT_SETTLEABLE');
		} catch (DomainException $e) {
			$this->assertSame('CLAIM_NOT_SETTLEABLE', $e->getErrorCode());
		}
	}

	public function testSettleRejectsMissingClaim(): void
	{
		$this->access->method('isFinance')->willReturn(true);
		$this->batches->method('insert')->willReturnCallback(function (SettlementBatch $b) {
			$b->setId(9); return $b;
		});
		$this->claims->method('findByIdRaw')->willReturn(null);
		try {
			$this->svc->settle([7], 'REF-1', 'fin');
			$this->fail('expected CLAIM_NOT_SETTLEABLE');
		} catch (DomainException $e) {
			$this->assertSame('CLAIM_NOT_SETTLEABLE', $e->getErrorCode());
		}
	}

	public function testSettleHappyPathBatchAndCas(): void
	{
		$this->access->method('isFinance')->willReturn(true);
		$this->batches->method('insert')->willReturnCallback(function (SettlementBatch $b) {
			$b->setId(9); return $b;
		});
		$this->batches->expects($this->once())->method('update')
			->with($this->callback(function (SettlementBatch $b) {
				return $b->getRowCount() === 2 && $b->getFileHash() !== '';
			}));
		$claim1 = $this->approvedClaim(7, 'EXP-2026-00001', 3);
		$claim2 = $this->approvedClaim(8, 'EXP-2026-00002', 5);
		$this->claims->method('findByIdRaw')->willReturnMap([[7, $claim1], [8, $claim2]]);
		$this->claims->expects($this->exactly(2))->method('updateWithVersion')
			->willReturnCallback(function (Claim $c, int $expected) {
				// CAS: version was bumped by service before update
				return $c->getVersion() === $expected + 1;
			});
		$this->rows->expects($this->exactly(2))->method('insert')
			->with($this->callback(function (SettlementRow $r) {
				return $r->getBatchId() === 9 && $r->getPaidRef() === 'REF-1';
			}));
		$this->chain->expects($this->exactly(2))->method('append')
			->with($this->logicalOr(7, 8), 'settled', 'fin', 'approved', 'settled', $this->anything());

		$batch = $this->svc->settle([7, 8], ' REF-1 ', 'fin');
		$this->assertSame(9, $batch->getId());
		$this->assertSame('settled', $claim1->getStatus());
		$this->assertSame('settled', $claim2->getStatus());
		$this->assertSame(4, $claim1->getVersion()); // 3+1
		$this->assertSame(6, $claim2->getVersion()); // 5+1
		$this->assertSame('REF-1', $claim1->getSettlementRef());
		$this->assertSame(9, $claim1->getBatchId());
		$this->assertSame('fin', $claim1->getSettledBy());
	}

	public function testSettleCasConflictRollsBack(): void
	{
		$this->access->method('isFinance')->willReturn(true);
		$this->batches->method('insert')->willReturnCallback(function (SettlementBatch $b) {
			$b->setId(9); return $b;
		});
		$this->claims->method('findByIdRaw')->willReturn($this->approvedClaim(7));
		$this->claims->method('updateWithVersion')->willReturn(false); // CAS lost
		try {
			$this->svc->settle([7], 'REF-1', 'fin');
			$this->fail('expected VERSION_CONFLICT');
		} catch (DomainException $e) {
			$this->assertSame('VERSION_CONFLICT', $e->getErrorCode());
		}
	}

	public function testExportRowsSplitsChannelsAndSorts(): void
	{
		$row = new SettlementRow();
		$row->setClaimId(7); $row->setPaidRef('REF-1');
		$row->setPaidAt(new \DateTime('2026-12-01'));
		$this->rows->method('findByBatch')->willReturn([$row]);
		$c = $this->approvedClaim(7, 'EXP-2026-00002');
		$this->claims->method('findByIdRaw')->willReturn($c);
		$l1 = new ClaimLine(); $l1->setReimbursable(1); $l1->setAmountMinor(1000);
		$l2 = new ClaimLine(); $l2->setReimbursable(0); $l2->setAmountMinor(500);
		$this->lines->method('findByClaim')->willReturn([$l1, $l2]);
		$out = $this->svc->exportRows(9);
		$this->assertCount(1, $out['reimbursable']);
		$this->assertCount(1, $out['nonreimbursable']);
		$this->assertSame(1000, $out['reimbursable'][0]['amount_minor']);
		$this->assertSame(500, $out['nonreimbursable'][0]['amount_minor']);
		$this->assertSame('EXP-2026-00002', $out['reimbursable'][0]['claim_no']);
		$this->assertSame('REF-1', $out['reimbursable'][0]['paid_ref']);
	}

	public function testExportRowsSkipsMissingClaims(): void
	{
		$row = new SettlementRow(); $row->setClaimId(99);
		$this->rows->method('findByBatch')->willReturn([$row]);
		$this->claims->method('findByIdRaw')->willReturn(null);
		$out = $this->svc->exportRows(9);
		$this->assertSame(['reimbursable' => [], 'nonreimbursable' => []], $out);
	}

	public function testSettleableReturnsApprovedClaims(): void
	{
		$this->claims->method('findByStatus')->with('approved')->willReturn(['x']);
		$this->assertSame(['x'], $this->svc->settleable());
	}
}
