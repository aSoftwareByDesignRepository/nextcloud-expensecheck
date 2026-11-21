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

	private function makeSvc(array $over = []): ClaimLifecycleService
	{
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-01-01 00:00:00'));
		return new ClaimLifecycleService(
			$this->claims,
			$over['lines'] ?? $this->createStub(ClaimLineMapper::class),
			$over['lineService'] ?? $this->createStub(ClaimLineService::class),
			$this->decisions,
			$this->createStub(DelegateMapper::class),
			$over['numbers'] ?? $this->createStub(ClaimNumberService::class),
			$over['routing'] ?? $this->routing,
			$over['settings'] ?? $this->createStub(SettingsService::class),
			$over['chain'] ?? $this->createStub(ExpChainService::class),
			$this->access,
			$this->createStub(IDBConnection::class),
			$time,
			$this->createStub(LoggerInterface::class),
			new PostCommitEffectQueue($this->createStub(IDBConnection::class), $this->createStub(LoggerInterface::class)),
			$this->createStub(NotificationService::class),
		);
	}

	private function line(int $id, string $type = 'receipt', int $catId = 3, int $amount = 1000): \OCA\ExpenseCheck\Db\ClaimLine
	{
		$l = new \OCA\ExpenseCheck\Db\ClaimLine();
		$l->setId($id);
		$l->setLineType($type);
		$l->setCategoryId($catId);
		$l->setAmountMinor($amount);
		$l->setReimbursable(1);
		return $l;
	}

	/* ---------- createDraft validation ---------- */

	public function testCreateDraftRejectsEmptyTitle(): void
	{
		$this->expectException(\OCA\ExpenseCheck\Exception\ValidationException::class);
		$this->makeSvc()->createDraft('u1', '', 'EUR');
	}

	public function testCreateDraftRejectsBadCurrency(): void
	{
		try {
			$this->makeSvc()->createDraft('u1', 'T', 'eur'); // lowercase
			$this->fail('expected ValidationException');
		} catch (\OCA\ExpenseCheck\Exception\ValidationException $e) {
			$this->assertSame('invalid', $e->getFields()['currency']);
		}
	}

	public function testCreateDraftRejectsUnknownKind(): void
	{
		try {
			$this->makeSvc()->createDraft('u1', 'T', 'EUR', null, ['kind' => 'nonsense']);
			$this->fail('expected ValidationException');
		} catch (\OCA\ExpenseCheck\Exception\ValidationException $e) {
			$this->assertSame('invalid', $e->getFields()['kind']);
		}
	}

	public function testCorrectionKindRequiresCorrectsClaimNo(): void
	{
		try {
			$this->makeSvc()->createDraft('u1', 'T', 'EUR', null, ['kind' => 'correction']);
			$this->fail('expected ValidationException');
		} catch (\OCA\ExpenseCheck\Exception\ValidationException $e) {
			$this->assertSame('required', $e->getFields()['corrects_claim_no']);
		}
	}

	public function testCorrectsClaimNoMustExist(): void
	{
		$claims = $this->createMock(ClaimMapper::class);
		$claims->method('findByClaimNo')->willReturn(null);
		$this->claims = $claims;
		try {
			$this->makeSvc()->createDraft('u1', 'T', 'EUR', null,
				['corrects_claim_no' => 'EXP-2026-99999']);
			$this->fail('expected ValidationException');
		} catch (\OCA\ExpenseCheck\Exception\ValidationException $e) {
			$this->assertSame('unknown', $e->getFields()['corrects_claim_no']);
		}
	}

	public function testCreateDraftPersistsAllFields(): void
	{
		$numbers = $this->createStub(ClaimNumberService::class);
		$numbers->method('next')->willReturn('EXP-2026-00042');
		$claims = $this->createMock(ClaimMapper::class);
		$claims->method('insert')->willReturnCallback(function (Claim $c) {
			$c->setId(99); return $c;
		});
		$this->claims = $claims;
		$chain = $this->createMock(ExpChainService::class);
		$chain->expects($this->once())->method('append')
			->with(99, 'created', 'u1', null, 'draft', $this->anything());

		$c = $this->makeSvc(['numbers' => $numbers, 'chain' => $chain])
			->createDraft('u1', 'Trip to Berlin', 'EUR', 'req-1', ['azc_trip_id' => 55]);
		$this->assertSame('EXP-2026-00042', $c->getClaimNo());
		$this->assertSame('u1', $c->getUserId());
		$this->assertSame('draft', $c->getStatus());
		$this->assertSame('Trip to Berlin', $c->getTitle());
		$this->assertSame('EUR', $c->getCurrency());
		$this->assertSame('standard', $c->getKind());
		$this->assertSame(55, $c->getAzcTripId());
		$this->assertSame(1, $c->getVersion());
		$this->assertSame('req-1', $c->getRequestKey());
	}

	public function testCreateDraftRemainingFieldsAndTimestamps(): void
	{
		$claims = $this->createMock(ClaimMapper::class);
		$claims->method('findByClaimNo')->willReturn(new Claim());
		$claims->method('insert')->willReturnCallback(function (Claim $c) {
			$c->setId(99); return $c;
		});
		$this->claims = $claims;
		$c = $this->makeSvc()->createDraft('u1', 'T', 'EUR', null,
			['kind' => 'correction', 'corrects_claim_no' => '  EXP-2026-00001  ']);
		$this->assertSame('1.000000', $c->getFxRate());
		$this->assertSame('correction', $c->getKind());
		// whitespace-trimmed before existence check + storage
		$this->assertSame('EXP-2026-00001', $c->getCorrectsClaimNo());
		$this->assertNotNull($c->getCreatedAt());
		$this->assertNotNull($c->getUpdatedAt());
		$this->assertNull($c->getAzcTripId());
	}

	public function testCreateDraftTitleBoundaryAndMultibyte(): void
	{
		$claims = $this->createMock(ClaimMapper::class);
		$claims->method('insert')->willReturnCallback(function (Claim $c) {
			$c->setId(99); return $c;
		});
		$this->claims = $claims;
		// exactly 191 chars is legal (mutant: >191 → >=191 must stay green)
		$c = $this->makeSvc()->createDraft('u1', str_repeat('a', 191), 'EUR');
		$this->assertSame(191, mb_strlen($c->getTitle()));
		// 191 multibyte chars: mb_strlen is the contract, byte length irrelevant
		$c = $this->makeSvc()->createDraft('u1', str_repeat('ä', 191), 'EUR');
		$this->assertSame(191, mb_strlen($c->getTitle()));
		try {
			$this->makeSvc()->createDraft('u1', str_repeat('a', 192), 'EUR');
			$this->fail('192-char title accepted');
		} catch (\OCA\ExpenseCheck\Exception\ValidationException $e) {
			$this->assertSame('required', $e->getFields()['title']);
		}
	}

	public function testCreateDraftCurrencyAnchors(): void
	{
		foreach (['XEUR', 'EURX', 'EU', 'EURO', 'E1R', 'eUR'] as $bad) {
			try {
				$this->makeSvc()->createDraft('u1', 'T', $bad);
				$this->fail("currency {$bad} accepted");
			} catch (\OCA\ExpenseCheck\Exception\ValidationException $e) {
				$this->assertSame('invalid', $e->getFields()['currency']);
			}
		}
	}

	public function testCreateDraftAzcTripIdNonPositiveIsNull(): void
	{
		$claims = $this->createMock(ClaimMapper::class);
		$claims->method('insert')->willReturnCallback(function (Claim $c) {
			$c->setId(99); return $c;
		});
		$this->claims = $claims;
		foreach ([0, -5] as $v) {
			$c = $this->makeSvc()->createDraft('u1', 'T', 'EUR', null, ['azc_trip_id' => $v]);
			$this->assertNull($c->getAzcTripId(), "azc_trip_id {$v} must normalise to null");
		}
	}

	/* ---------- submit gates ---------- */

	public function testSubmitVersionConflict(): void
	{
		$c = $this->claim('alice', 'draft');
		$c->setVersion(3);
		try {
			$this->makeSvc()->submit($c, 'alice', 2); // client thinks 2, server 3
			$this->fail('expected VERSION_CONFLICT');
		} catch (\OCA\ExpenseCheck\Exception\DomainException $e) {
			$this->assertSame('VERSION_CONFLICT', $e->getErrorCode());
		}
	}

	public function testSubmitWrongStatusRejected(): void
	{
		$c = $this->claim('alice', 'submitted');
		try {
			$this->makeSvc()->submit($c, 'alice');
			$this->fail('expected CLAIM_NOT_SUBMITTABLE');
		} catch (\OCA\ExpenseCheck\Exception\DomainException $e) {
			$this->assertSame('CLAIM_NOT_SUBMITTABLE', $e->getErrorCode());
		}
	}

	public function testSubmitByNonOwnerIsUniform404(): void
	{
		$c = $this->claim('alice', 'draft');
		$this->expectException(ClaimNotFoundException::class);
		$this->makeSvc()->submit($c, 'mallory'); // not the owner
	}

	public function testSubmitEmptyClaimRejected(): void
	{
		$lines = $this->createStub(ClaimLineMapper::class);
		$lines->method('findByClaim')->willReturn([]);
		$c = $this->claim('alice', 'draft');
		try {
			$this->makeSvc(['lines' => $lines])->submit($c, 'alice');
			$this->fail('expected CLAIM_EMPTY');
		} catch (\OCA\ExpenseCheck\Exception\DomainException $e) {
			$this->assertSame('CLAIM_EMPTY', $e->getErrorCode());
		}
	}

	public function testSubmitRatedLineWithoutRateRejected(): void
	{
		$lines = $this->createStub(ClaimLineMapper::class);
		$l = $this->line(1, 'mileage'); // no rate resolved
		$lines->method('findByClaim')->willReturn([$l]);
		$c = $this->claim('alice', 'draft');
		try {
			$this->makeSvc(['lines' => $lines])->submit($c, 'alice');
			$this->fail('expected RATE_MISSING');
		} catch (\OCA\ExpenseCheck\Exception\DomainException $e) {
			$this->assertSame('RATE_MISSING', $e->getErrorCode());
		}
	}

	public function testSubmitLineWithoutCategoryRejected(): void
	{
		$lines = $this->createStub(ClaimLineMapper::class);
		$l = $this->line(1, 'receipt', 0); // category_id = 0
		$lines->method('findByClaim')->willReturn([$l]);
		$c = $this->claim('alice', 'draft');
		try {
			$this->makeSvc(['lines' => $lines])->submit($c, 'alice');
			$this->fail('expected CLAIM_EMPTY');
		} catch (\OCA\ExpenseCheck\Exception\DomainException $e) {
			$this->assertSame('CLAIM_EMPTY', $e->getErrorCode());
		}
	}

	public function testSubmitHappyPathFreezesChainAndTotals(): void
	{
		$lines = $this->createStub(ClaimLineMapper::class);
		$l = $this->line(1, 'receipt', 3, 1234);
		$lines->method('findByClaim')->willReturn([$l]);
		$routing = $this->createMock(ChainRoutingService::class);
		$chainEnt = new \OCA\ExpenseCheck\Db\Chain();
		$chainEnt->setId(5);
		$routing->method('resolveChain')->willReturn($chainEnt);
		$snap = ['chain_id' => 5, 'name' => 'std', 'steps' => [[
			'step_no' => 1, 'resolved_uids' => ['boss'], 'unroutable' => false,
		]]];
		$routing->method('snapshot')->willReturn($snap);
		$routing->method('applySelfApprovalRule')->willReturn($snap);
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getBool')->willReturn(false);
		$claims = $this->createMock(ClaimMapper::class);
		$claims->method('updateWithVersion')->willReturnCallback(function (Claim $c, int $v) {
			return true;
		});
		$this->claims = $claims;
		$chain = $this->createMock(ExpChainService::class);
		$chain->expects($this->once())->method('append')
			->with(7, 'submitted', 'alice', 'draft', 'submitted', $this->anything());
		$lineSvc = $this->createMock(ClaimLineService::class);
		$lineSvc->expects($this->once())->method('assertLineDatesAllowed');
		$lineSvc->expects($this->once())->method('assertReceiptEvidence');
		$lineSvc->expects($this->once())->method('computePolicyFlags');

		$c = $this->claim('alice', 'draft');
		$c->setVersion(1);
		$out = $this->makeSvc([
			'lines' => $lines, 'routing' => $routing, 'settings' => $settings,
			'chain' => $chain, 'lineService' => $lineSvc,
		])->submit($c, 'alice');

		$this->assertSame('submitted', $out->getStatus());
		$this->assertSame(1234, $out->getTotalMinor());
		$this->assertSame(1234, $out->getReimbursableTotalMinor());
		$this->assertSame(5, $out->getChainId());
		$this->assertSame(1, $out->getCurrentStep());
		$this->assertSame(2, $out->getVersion()); // CAS bump
		$this->assertNotNull($out->getChainSnapshot());
		$this->assertNotNull($out->getSubmittedAt());
	}

	public function testSubmitUnroutableFallsBackToAdminReview(): void
	{
		$lines = $this->createStub(ClaimLineMapper::class);
		$lines->method('findByClaim')->willReturn([$this->line(1)]);
		$routing = $this->createMock(ChainRoutingService::class);
		$routing->method('resolveChain')->willReturn(null); // no chain matches
		$access = $this->createStub(AccessControlService::class);
		$access->method('getAppAdminIds')->willReturn(['root']);
		$this->access = $access;
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getBool')->willReturn(false);
		$claims = $this->createMock(ClaimMapper::class);
		$claims->method('updateWithVersion')->willReturn(true);
		$this->claims = $claims;

		$c = $this->claim('alice', 'draft');
		$out = $this->makeSvc([
			'lines' => $lines, 'routing' => $routing, 'settings' => $settings,
		])->submit($c, 'alice');
		$snap = json_decode($out->getChainSnapshot(), true);
		$this->assertTrue($snap['steps'][0]['unroutable']);
		$this->assertSame(['root'], $snap['steps'][0]['resolved_uids']);
		$this->assertSame('admin-review', $snap['name']);
	}

	/* ---------- withdraw ---------- */

	public function testWithdrawSubmittedBackToDraft(): void
	{
		$claims = $this->createMock(ClaimMapper::class);
		$claims->method('updateWithVersion')->willReturn(true);
		$this->claims = $claims;
		$chain = $this->createMock(ExpChainService::class);
		$chain->expects($this->once())->method('append')
			->with(7, 'withdrawn', 'alice', 'submitted', 'draft');
		$c = $this->claim('alice', 'submitted');
		$c->setVersion(1);
		$out = $this->makeSvc(['chain' => $chain])->withdraw($c, 'alice');
		$this->assertSame('draft', $out->getStatus());
		$this->assertSame(0, $out->getCurrentStep());
		$this->assertNull($out->getSubmittedAt());
	}

	public function testWithdrawWrongStatusRejected(): void
	{
		$c = $this->claim('alice', 'draft');
		try {
			$this->makeSvc()->withdraw($c, 'alice');
			$this->fail('expected CLAIM_NOT_WITHDRAWABLE');
		} catch (\OCA\ExpenseCheck\Exception\DomainException $e) {
			$this->assertSame('CLAIM_NOT_WITHDRAWABLE', $e->getErrorCode());
		}
	}

	public function testWithdrawByNonOwnerIsUniform404(): void
	{
		$c = $this->claim('alice', 'submitted');
		$this->expectException(ClaimNotFoundException::class);
		$this->makeSvc()->withdraw($c, 'mallory');
	}

	/* ---------- revert ---------- */

	public function testRevertRequiresAppAdmin(): void
	{
		$access = $this->createStub(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(false);
		$this->access = $access;
		$c = $this->claim('alice', 'approved');
		$this->expectException(ClaimNotFoundException::class);
		$this->makeSvc()->revert($c, 'mallory', 'oops');
	}

	public function testRevertRequiresReason(): void
	{
		$access = $this->createStub(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(true);
		$this->access = $access;
		$c = $this->claim('alice', 'approved');
		try {
			$this->makeSvc()->revert($c, 'root', '  ');
			$this->fail('expected ValidationException');
		} catch (\OCA\ExpenseCheck\Exception\ValidationException $e) {
			$this->assertSame('required', $e->getFields()['reason']);
		}
	}

	public function testRevertApprovedBackToSubmittedAndWipesDecision(): void
	{
		$access = $this->createStub(AccessControlService::class);
		$access->method('isAppAdmin')->willReturn(true);
		$this->access = $access;
		$claims = $this->createMock(ClaimMapper::class);
		$claims->method('updateWithVersion')->willReturn(true);
		$this->claims = $claims;
		$decisions = $this->createMock(StepDecisionMapper::class);
		$decisions->expects($this->once())->method('deleteByClaim')->with(7);
		$this->decisions = $decisions;
		$chain = $this->createMock(ExpChainService::class);
		$chain->expects($this->once())->method('append')
			->with(7, 'reverted', 'root', 'approved', 'submitted', $this->anything());
		$c = $this->claim('alice', 'approved');
		$c->setDecidedAt(new \DateTime());
		$c->setDecidedBy('boss');
		$c->setDecisionNote('ok');
		$c->setChainSnapshot(json_encode(['steps' => [['step_no' => 3],['step_no' => 2]]]));
		$out = $this->makeSvc(['chain' => $chain])->revert($c, 'root', 'wrong chain');
		$this->assertSame('submitted', $out->getStatus());
		$this->assertNull($out->getDecidedAt());
		$this->assertNull($out->getDecidedBy());
		$this->assertNull($out->getDecisionNote());
		$this->assertSame(2, $out->getCurrentStep()); // min of snapshot steps
	}
}
