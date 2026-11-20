<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\Delegate;
use OCA\ExpenseCheck\Db\DelegateMapper;
use OCA\ExpenseCheck\Db\NotificationLogMapper;
use OCA\ExpenseCheck\Service\ChainRoutingService;
use OCA\ExpenseCheck\Service\DigestMailService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Spec §10.6/§12.7 approver digest — cadence gating, per-approver
 * grouping, atomic dedupe, delegate fan-out and the content rule
 * (claim_no + counts only) all pinned here.
 */
final class DigestMailServiceTest extends TestCase
{
	private SettingsService&MockObject $settings;
	private ClaimMapper&MockObject $claims;
	private DelegateMapper&MockObject $delegates;
	private ChainRoutingService&MockObject $routing;
	private NotificationLogMapper&MockObject $log;
	private IMailer&MockObject $mailer;
	private IUserManager&MockObject $users;
	private DigestMailService $svc;

	protected function setUp(): void
	{
		$this->settings = $this->createMock(SettingsService::class);
		$this->claims = $this->createMock(ClaimMapper::class);
		$this->delegates = $this->createMock(DelegateMapper::class);
		$this->routing = $this->createMock(ChainRoutingService::class);
		$this->log = $this->createMock(NotificationLogMapper::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->users = $this->createMock(IUserManager::class);
		$url = $this->createStub(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc/exp');
		$config = $this->createStub(IConfig::class);
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			fn (string $s, array $p = []) => vsprintf(str_replace('%1$s', '%s', $s), $p));
		$l10n->method('n')->willReturnCallback(
			fn (string $sg, string $pl, int $n) => str_replace('%n', (string)$n, $n === 1 ? $sg : $pl));
		$l10nFactory = $this->createStub(IL10NFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);
		$l10nFactory->method('getUserLanguage')->willReturn('en');
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-23 09:00:00')); // Monday
		$this->svc = new DigestMailService(
			$this->settings, $this->claims, $this->delegates, $this->routing,
			$this->log, $this->mailer, $this->users, $url, $config,
			$l10nFactory, $time, $this->createStub(LoggerInterface::class),
		);
	}

	private function cadence(string $v): void
	{
		$this->settings->method('get')->with(SettingsService::KEY_DIGEST_CADENCE)->willReturn($v);
	}

	private function claim(string $no, string $snapshot): Claim
	{
		$c = new Claim();
		$c->setClaimNo($no);
		$c->setChainSnapshot($snapshot);
		$c->setCurrentStep(1);
		return $c;
	}

	private function user(string $uid, ?string $email): IUser&MockObject
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('getEMailAddress')->willReturn($email);
		$u->method('getDisplayName')->willReturn($uid);
		return $u;
	}

	public function testOffShortCircuits(): void
	{
		$this->cadence('off');
		$this->claims->expects($this->never())->method('findByStatus');
		$this->assertSame(['eligible' => false, 'sent' => 0, 'skipped' => 0, 'claims' => 0],
			$this->svc->sendApproverDigests());
	}

	public function testWeeklyOnlyOnMonday(): void
	{
		$this->cadence('weekly');
		$this->claims->expects($this->never())->method('findByStatus');
		$this->assertFalse($this->svc->sendApproverDigests(new \DateTime('2026-11-24'))['eligible']); // Tuesday
	}

	public function testNoPendingClaimsSendsNothing(): void
	{
		$this->cadence('daily');
		$this->claims->method('findByStatus')->willReturn([]);
		$this->mailer->expects($this->never())->method('send');
		$this->assertSame(['eligible' => true, 'sent' => 0, 'skipped' => 0, 'claims' => 0],
			$this->svc->sendApproverDigests());
	}

	public function testApproverGetsOneMailWithClaimNos(): void
	{
		$this->cadence('daily');
		$snap = json_encode(['steps' => [['step_no' => 1]]]);
		$this->claims->method('findByStatus')->willReturn([
			$this->claim('EXP-2026-00001', $snap), $this->claim('EXP-2026-00002', $snap),
		]);
		$this->routing->method('approversForPendingStep')->willReturn(['boss']);
		$this->delegates->method('findActiveForManagers')->willReturn([]);
		$this->users->method('get')->with('boss')->willReturn($this->user('boss', 'boss@x.test'));
		$this->log->method('hasDedupeKey')->willReturn(false);
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$message = $this->createMock(IMessage::class);
		$this->mailer->method('createMessage')->willReturn($message);
		$body = '';
		$message->method('setPlainBody')->willReturnCallback(
			function (string $b) use (&$body, $message) { $body = $b; return $message; });
		$this->mailer->expects($this->once())->method('send');
		$this->log->expects($this->once())->method('insert');

		$r = $this->svc->sendApproverDigests();
		$this->assertSame(1, $r['sent']);
		$this->assertStringContainsString('EXP-2026-00001', $body);
		$this->assertStringContainsString('EXP-2026-00002', $body);
		$this->assertStringNotContainsString('EUR', $body); // content rule: no amounts
	}

	public function testDedupeHitSkips(): void
	{
		$this->cadence('daily');
		$snap = json_encode(['steps' => [['step_no' => 1]]]);
		$this->claims->method('findByStatus')->willReturn([$this->claim('EXP-2026-00001', $snap)]);
		$this->routing->method('approversForPendingStep')->willReturn(['boss']);
		$this->delegates->method('findActiveForManagers')->willReturn([]);
		$this->log->method('hasDedupeKey')->willReturn(true);
		$this->mailer->expects($this->never())->method('send');
		$this->assertSame(1, $this->svc->sendApproverDigests()['skipped']);
	}

	public function testConcurrentClaimLossSkips(): void
	{
		$this->cadence('daily');
		$snap = json_encode(['steps' => [['step_no' => 1]]]);
		$this->claims->method('findByStatus')->willReturn([$this->claim('EXP-2026-00001', $snap)]);
		$this->routing->method('approversForPendingStep')->willReturn(['boss']);
		$this->delegates->method('findActiveForManagers')->willReturn([]);
		$this->log->method('hasDedupeKey')->willReturn(false);
		$this->log->method('insert')->willThrowException(new \Exception('duplicate key'));
		$this->mailer->expects($this->never())->method('send');
		$this->assertSame(1, $this->svc->sendApproverDigests()['skipped']);
	}

	public function testMissingEmailSkips(): void
	{
		$this->cadence('daily');
		$snap = json_encode(['steps' => [['step_no' => 1]]]);
		$this->claims->method('findByStatus')->willReturn([$this->claim('EXP-2026-00001', $snap)]);
		$this->routing->method('approversForPendingStep')->willReturn(['ghost']);
		$this->delegates->method('findActiveForManagers')->willReturn([]);
		$this->users->method('get')->willReturn($this->user('ghost', null));
		$this->log->method('hasDedupeKey')->willReturn(false);
		$this->mailer->expects($this->never())->method('send');
		$this->assertSame(1, $this->svc->sendApproverDigests()['skipped']);
	}

	public function testSendFailureKeepsSlotAndContinues(): void
	{
		$this->cadence('daily');
		$snap = json_encode(['steps' => [['step_no' => 1]]]);
		$this->claims->method('findByStatus')->willReturn([$this->claim('EXP-2026-00001', $snap)]);
		$this->routing->method('approversForPendingStep')->willReturn(['a', 'b']);
		$this->delegates->method('findActiveForManagers')->willReturn([]);
		$this->users->method('get')->willReturnMap([
			['a', $this->user('a', 'a@x.test')], ['b', $this->user('b', 'b@x.test')],
		]);
		$this->log->method('hasDedupeKey')->willReturn(false);
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$msg = $this->createStub(IMessage::class);
		$this->mailer->method('createMessage')->willReturn($msg);
		$this->mailer->expects($this->exactly(2))->method('send')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new \Exception('smtp down')), []);
		$this->log->expects($this->exactly(2))->method('insert'); // both slots claimed
		$r = $this->svc->sendApproverDigests();
		$this->assertSame(1, $r['sent']);
		$this->assertSame(1, $r['skipped']);
	}

	public function testDelegatesFanOut(): void
	{
		$this->cadence('daily');
		$snap = json_encode(['steps' => [['step_no' => 1]]]);
		$this->claims->method('findByStatus')->willReturn([$this->claim('EXP-2026-00001', $snap)]);
		$this->routing->method('approversForPendingStep')->willReturn(['boss']);
		$del = new Delegate();
		$del->setDelegateUid('assistant');
		$this->delegates->method('findActiveForManagers')->with(['boss'])->willReturn([$del]);
		$this->users->method('get')->willReturnMap([
			['boss', $this->user('boss', 'b@x.test')], ['assistant', $this->user('assistant', 'a@x.test')],
		]);
		$this->log->method('hasDedupeKey')->willReturn(false);
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('createMessage')->willReturn($this->createStub(IMessage::class));
		$this->mailer->expects($this->exactly(2))->method('send');
		$this->assertSame(2, $this->svc->sendApproverDigests()['sent']);
	}
}
