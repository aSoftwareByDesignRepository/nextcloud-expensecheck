<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\DelegateMapper;
use OCA\ExpenseCheck\Db\StepDecisionMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\ChainRoutingService;
use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCA\ExpenseCheck\Service\ClaimLineService;
use OCA\ExpenseCheck\Service\ClaimNumberService;
use OCA\ExpenseCheck\Service\ExpChainService;
use OCA\ExpenseCheck\Service\NotificationService;
use OCA\ExpenseCheck\Service\PostCommitEffectQueue;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ClaimLifecycleServiceTest extends TestCase
{
	private ClaimMapper&MockObject $claims;
	private StepDecisionMapper&MockObject $decisions;
	private AccessControlService&MockObject $access;
	private ChainRoutingService&MockObject $routing;
	private ClaimLifecycleService $svc;

	protected function setUp(): void
	{
		$this->claims = $this->createStub(ClaimMapper::class);
		$this->decisions = $this->createStub(StepDecisionMapper::class);
		$this->access = $this->createStub(AccessControlService::class);
		$this->routing = $this->createStub(ChainRoutingService::class);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-01-01 00:00:00'));
		$this->svc = new ClaimLifecycleService(
			$this->claims,
			$this->createStub(ClaimLineMapper::class),
			$this->createStub(ClaimLineService::class),
			$this->decisions,
			$this->createStub(DelegateMapper::class),
			$this->createStub(ClaimNumberService::class),
			$this->routing,
			$this->createStub(SettingsService::class),
			$this->createStub(ExpChainService::class),
			$this->access,
			$this->createStub(IDBConnection::class),
			$time,
			$this->createStub(LoggerInterface::class),
			new PostCommitEffectQueue($this->createStub(IDBConnection::class), $this->createStub(LoggerInterface::class)),
			$this->createStub(NotificationService::class),
		);
	}

	private function claim(string $owner = 'alice', string $status = 'draft'): Claim
	{
		$c = new Claim();
		$c->setId(7);
		$c->setUserId($owner);
		$c->setStatus($status);
		return $c;
	}

	/* ---------- state-machine census ---------- */

	public function testTransitionCensusIsComplete(): void
	{
		// Every status is a key; every target is a known status.
		foreach (ClaimLifecycleService::STATUSES as $s) {
			$this->assertArrayHasKey($s, ClaimLifecycleService::TRANSITIONS);
			foreach (ClaimLifecycleService::TRANSITIONS[$s] as $target) {
				$this->assertContains($target, ClaimLifecycleService::STATUSES);
			}
		}
	}

	public function testTerminalStatusesHaveNoOutEdges(): void
	{
		$this->assertSame([], ClaimLifecycleService::TRANSITIONS['rejected']);
		$this->assertSame([], ClaimLifecycleService::TRANSITIONS['settled']);
	}

	public function testApprovedCanOnlySettleOrRevert(): void
	{
		$this->assertSame(
			['settled', 'submitted'],
			ClaimLifecycleService::TRANSITIONS['approved']
		);
	}

	/* ---------- visibility (uniform 404, no oracle) ---------- */

	public function testLoadVisibleThrowsForMissing(): void
	{
		$this->claims->method('findByIdRaw')->willReturn(null);
		$this->expectException(ClaimNotFoundException::class);
		$this->svc->loadVisible(42, 'alice');
	}

	public function testLoadVisibleThrowsForInvisible(): void
	{
		// exists but not visible → SAME exception class as missing (no oracle).
		$this->claims->method('findByIdRaw')->willReturn($this->claim('bob'));
		$this->decisions->method('findByClaim')->willReturn([]);
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->access->method('isFinance')->willReturn(false);
		$this->access->method('isAuditor')->willReturn(false);
		$this->expectException(ClaimNotFoundException::class);
		$this->svc->loadVisible(7, 'alice');
	}

	public function testOwnerCanView(): void
	{
		$this->assertTrue($this->svc->canView($this->claim('alice'), 'alice'));
	}

	public function testAdminFinanceAuditorCanView(): void
	{
		$this->access->method('isAppAdmin')->willReturnCallback(fn ($u) => $u === 'root');
		$this->access->method('isFinance')->willReturnCallback(fn ($u) => $u === 'fin');
		$this->access->method('isAuditor')->willReturnCallback(fn ($u) => $u === 'aud');
		$this->assertTrue($this->svc->canView($this->claim('bob'), 'root'));
		$this->assertTrue($this->svc->canView($this->claim('bob'), 'fin'));
		$this->assertTrue($this->svc->canView($this->claim('bob'), 'aud'));
	}

	public function testStrangerCannotView(): void
	{
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->access->method('isFinance')->willReturn(false);
		$this->access->method('isAuditor')->willReturn(false);
		$this->decisions->method('findByClaim')->willReturn([]);
		$this->assertFalse($this->svc->canView($this->claim('bob'), 'eve'));
	}

	public function testPendingApproverCanViewSubmitted(): void
	{
		$claim = $this->claim('bob', 'submitted');
		$claim->setChainSnapshot(json_encode(['steps' => []]));
		$claim->setCurrentStep(1);
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->access->method('isFinance')->willReturn(false);
		$this->access->method('isAuditor')->willReturn(false);
		$this->decisions->method('findByClaim')->willReturn([]);
		$this->routing->method('decisionRights')->willReturn(['allowed' => true]);
		$this->assertTrue($this->svc->canView($claim, 'carol'));
	}

	public function testMalformedSnapshotDoesNotGrantView(): void
	{
		$claim = $this->claim('bob', 'submitted');
		$claim->setChainSnapshot('{broken');
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->access->method('isFinance')->willReturn(false);
		$this->access->method('isAuditor')->willReturn(false);
		$this->decisions->method('findByClaim')->willReturn([]);
		$this->assertFalse($this->svc->canView($claim, 'eve'));
	}

	/**
	 * Kills Foreach_ removal: a user who already decided an earlier step
	 * keeps read visibility even after the claim moves on.
	 */
	public function testPastDeciderRetainsView(): void
	{
		$claim = $this->claim('bob', 'approved');
		$d = new \OCA\ExpenseCheck\Db\StepDecision();
		$d->setDecidedBy('carol');
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->access->method('isFinance')->willReturn(false);
		$this->access->method('isAuditor')->willReturn(false);
		$this->decisions->method('findByClaim')->willReturn([$d]);
		$this->assertTrue($this->svc->canView($claim, 'carol'));
		$this->assertFalse($this->svc->canView($claim, 'dave'));
	}

	/**
	 * Kills sort-removal + CastInt mutants in ChainRoutingService:
	 * unsorted/string step numbers must still yield the smallest next step.
	 */
	public function testNextStepPicksSmallestRegardlessOfOrder(): void
	{
		$snapshot = ['steps' => [['step_no' => '3'], ['step_no' => '2'], ['step_no' => '1']]];
		$this->assertSame(2, \OCA\ExpenseCheck\Service\ChainRoutingService::nextStep($snapshot, 1));
		$this->assertSame(1, \OCA\ExpenseCheck\Service\ChainRoutingService::nextStep($snapshot, 0));
		$this->assertNull(\OCA\ExpenseCheck\Service\ChainRoutingService::nextStep($snapshot, 3));
		$this->assertSame([3, 2, 1], \OCA\ExpenseCheck\Service\ChainRoutingService::stepNos($snapshot));
	}
}
