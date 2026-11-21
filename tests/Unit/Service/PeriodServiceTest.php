<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\Period;
use OCA\ExpenseCheck\Db\PeriodMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\AuditLogService;
use OCA\ExpenseCheck\Service\NotificationService;
use OCA\ExpenseCheck\Service\PeriodService;
use OCA\ExpenseCheck\Service\PostCommitEffectQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PeriodServiceTest extends TestCase
{
	private PeriodMapper&MockObject $periods;
	private ClaimLineMapper&MockObject $lines;
	private AuditLogService&MockObject $audit;
	private PeriodService $svc;

	protected function setUp(): void
	{
		$this->periods = $this->createMock(PeriodMapper::class);
		$this->lines = $this->createMock(ClaimLineMapper::class);
		$this->audit = $this->createMock(AuditLogService::class);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-12-01 10:00:00'));
		$this->svc = new PeriodService(
			$this->periods, $this->lines, $this->audit,
			$this->createStub(AccessControlService::class),
			$this->createStub(IDBConnection::class), $time,
			new PostCommitEffectQueue($this->createStub(IDBConnection::class), new NullLogger()),
			$this->createStub(NotificationService::class),
		);
	}

	private function closedPeriod(int $id = 3): Period
	{
		$p = new Period();
		$p->setId($id);
		$p->setStatus('closed');
		$p->setName('Nov 2026');
		return $p;
	}

	public function testCloseRejectsEmptyName(): void
	{
		try {
			$this->svc->close('  ', new \DateTime('2026-11-01'), new \DateTime('2026-11-30'), 'fin');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['name']);
		}
	}

	public function testCloseRejectsInvertedRange(): void
	{
		try {
			$this->svc->close('Q4', new \DateTime('2026-11-30'), new \DateTime('2026-11-01'), 'fin');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('invalid_range', $e->getFields()['to']);
		}
	}

	public function testCloseBlockedByOpenClaims(): void
	{
		$this->lines->method('countOpenClaimsInRange')->willReturn(2);
		$this->periods->expects($this->never())->method('insert');
		try {
			$this->svc->close('Nov', new \DateTime('2026-11-01'), new \DateTime('2026-11-30'), 'fin');
			$this->fail('expected PERIOD_BLOCKED');
		} catch (DomainException $e) {
			$this->assertSame('PERIOD_BLOCKED', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testCloseWithCarryOverInsertsAndAudits(): void
	{
		$this->lines->method('countOpenClaimsInRange')->willReturn(2);
		$this->lines->method('draftClaimIdsInRange')->willReturn([11, 12]);
		$this->periods->expects($this->once())->method('insert')->willReturnCallback(function (Period $p) {
			$p->setId(9);
			$this->assertSame('closed', $p->getStatus());
			$this->assertSame('Nov', $p->getName());
			$this->assertSame('fin', $p->getClosedBy());
			$this->assertSame('2026-11-01', $p->getStartOn()->format('Y-m-d'));
			$this->assertSame('2026-11-30', $p->getEndOn()->format('Y-m-d'));
			return $p;
		});
		$this->audit->expects($this->once())->method('record')
			->with('fin', 'period_closed', $this->callback(fn ($d) =>
				$d['period_id'] === 9 && $d['open_carried'] === 2 && $d['drafts'] === [11, 12]));
		$p = $this->svc->close(' Nov ', new \DateTime('2026-11-01'), new \DateTime('2026-11-30'), 'fin', true);
		$this->assertSame(9, $p->getId());
	}

	public function testCloseNoOpenClaimsStillAuditsDrafts(): void
	{
		$this->lines->method('countOpenClaimsInRange')->willReturn(0);
		$this->lines->method('draftClaimIdsInRange')->willReturn([5]);
		$this->periods->method('insert')->willReturnCallback(function (Period $p) { $p->setId(1); return $p; });
		$this->audit->expects($this->once())->method('record')
			->with('fin', 'period_closed', $this->callback(fn ($d) => $d['open_carried'] === 0 && $d['drafts'] === [5]));
		$this->svc->close('Dec', new \DateTime('2026-12-01'), new \DateTime('2026-12-31'), 'fin');
	}

	public function testReopenMissingPeriod(): void
	{
		$this->periods->method('findById')->willReturn(null);
		try {
			$this->svc->reopen(9, 'mistake', 'fin');
			$this->fail('expected PERIOD_NOT_FOUND');
		} catch (DomainException $e) {
			$this->assertSame('PERIOD_NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testReopenRequiresClosedStatus(): void
	{
		$p = $this->closedPeriod();
		$p->setStatus('open');
		$this->periods->method('findById')->willReturn($p);
		try {
			$this->svc->reopen(3, 'mistake', 'fin');
			$this->fail('expected PERIOD_NOT_CLOSED');
		} catch (DomainException $e) {
			$this->assertSame('PERIOD_NOT_CLOSED', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testReopenRequiresReasonMinLength(): void
	{
		$this->periods->method('findById')->willReturn($this->closedPeriod());
		try {
			$this->svc->reopen(3, 'ab', 'fin');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['reason']);
		}
	}

	public function testReopenSuccessUpdatesAndAudits(): void
	{
		$p = $this->closedPeriod();
		$this->periods->method('findById')->willReturn($p);
		$this->periods->expects($this->once())->method('update')->willReturnCallback(function (Period $p) {
			$this->assertSame('open', $p->getStatus());
			$this->assertSame('bookkeeping error', $p->getReopenReason());
			return $p;
		});
		$this->audit->expects($this->once())->method('record')
			->with('fin', 'period_reopened', $this->callback(fn ($d) =>
				$d['period_id'] === 3 && $d['reason'] === 'bookkeeping error'));
		$this->svc->reopen(3, ' bookkeeping error ', 'fin');
	}

	public function testAssertLineDateAllowedRejectsClosedPeriod(): void
	{
		$this->periods->method('isDateClosed')->willReturn(true);
		try {
			$this->svc->assertLineDateAllowed(new \DateTime('2026-11-15'));
			$this->fail('expected PERIOD_CLOSED');
		} catch (DomainException $e) {
			$this->assertSame('PERIOD_CLOSED', $e->getErrorCode());
			$this->assertSame(422, $e->getHttpStatus());
		}
	}

	public function testAssertLineDateAllowedPassesOpenDate(): void
	{
		$this->periods->method('isDateClosed')->willReturn(false);
		$this->svc->assertLineDateAllowed(new \DateTime('2026-11-15'));
		$this->addToAssertionCount(1);
	}

	public function testListDelegatesToMapper(): void
	{
		$this->periods->method('findAll')->willReturn(['p1']);
		$this->assertSame(['p1'], $this->svc->list());
	}
}
