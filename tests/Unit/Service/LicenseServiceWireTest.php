<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Config\VendorPublicKey;
use OCA\ExpenseCheck\Db\MobileSeat;
use OCA\ExpenseCheck\Db\MobileSeatMapper;
use OCA\ExpenseCheck\Exception\CompanionException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Service\AuditLogService;
use OCA\ExpenseCheck\Service\LicenseService;
use OCA\ExpenseCheck\Service\NotificationService;
use OCA\ExpenseCheck\Service\PostCommitEffectQueue;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Signed-wire paths: mints real EXP2 keys via the PHPUnit env override —
 * exercises the production verify path end to end.
 */
final class LicenseServiceWireTest extends TestCase
{
	private string $keypair;
	private MobileSeatMapper&MockObject $seats;
	private SettingsService&MockObject $settings;

	protected function setUp(): void
	{
		$this->keypair = sodium_crypto_sign_keypair();
		putenv('EXP_VENDOR_PUBLIC_KEY_B64='
			. VendorPublicKey::base64urlEncode(sodium_crypto_sign_publickey($this->keypair)));
		$this->seats = $this->createMock(MobileSeatMapper::class);
		$this->settings = $this->createMock(SettingsService::class);
	}

	protected function tearDown(): void
	{
		putenv('EXP_VENDOR_PUBLIC_KEY_B64');
	}

	private function wire(array $overrides = []): string
	{
		$payload = json_encode(array_replace([
			'v' => 2, 'product' => 'expensecheck', 'customerId' => 'acme-corp',
			'issuedAt' => '2026-01-01', 'validUntil' => '2099-12-31', 'mobileSeats' => 5,
		], $overrides), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$sig = sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($this->keypair));
		return 'EXP2.' . VendorPublicKey::base64urlEncode($payload)
			. '.' . VendorPublicKey::base64urlEncode($sig);
	}

	private function svc(string $stored = ''): LicenseService
	{
		$this->settings->method('get')->with(SettingsService::KEY_LICENSE_KEY)->willReturn($stored);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-12-01'));
		return new LicenseService(
			$this->createStub(IConfig::class), $this->seats, $this->settings, $time,
			$this->createStub(AuditLogService::class), $this->createStub(ILockingProvider::class),
			new PostCommitEffectQueue($this->createStub(IDBConnection::class), new NullLogger()),
			$this->createStub(NotificationService::class),
		);
	}

	public function testStateNullWithoutKey(): void
	{
		$this->assertNull($this->svc('')->state());
	}

	public function testStateNullOnForgedKey(): void
	{
		$this->assertNull($this->svc('EXP2.aaaa.bbbb')->state());
	}

	public function testStateParsesRealKey(): void
	{
		$s = $this->svc($this->wire())->state();
		$this->assertTrue($s['valid']);
		$this->assertSame(5, $s['mobileSeats']);
		$this->assertSame('acme-corp', $s['customerId']);
		$this->assertSame('2099-12-31', $s['validUntil']);
	}

	public function testStateExpiredKeyValidFalse(): void
	{
		$s = $this->svc($this->wire(['issuedAt' => '2026-01-01', 'validUntil' => '2026-06-30']))->state();
		$this->assertFalse($s['valid']);
	}

	public function testIsMobilePlanActiveTrue(): void
	{
		$this->assertTrue($this->svc($this->wire())->isMobilePlanActive());
	}

	public function testIsMobilePlanActiveFalseExpired(): void
	{
		$this->assertFalse($this->svc($this->wire(['issuedAt' => '2020-01-01', 'validUntil' => '2020-12-31']))->isMobilePlanActive());
	}

	public function testAssertMobileAccessRequiresLicense(): void
	{
		try {
			$this->svc('')->assertMobileAccess('alice');
			$this->fail('expected CompanionException');
		} catch (CompanionException $e) {
			$this->assertSame('LICENSE_REQUIRED', $e->getErrorCode());
		}
	}

	public function testAssertMobileAccessRejectsExpired(): void
	{
		try {
			$this->svc($this->wire(['issuedAt' => '2020-01-01', 'validUntil' => '2020-12-31']))->assertMobileAccess('alice');
			$this->fail('expected CompanionException');
		} catch (CompanionException $e) {
			$this->assertSame('LICENSE_EXPIRED', $e->getErrorCode());
		}
	}

	public function testAssertMobileAccessRejectsNoSeat(): void
	{
		$this->seats->method('findByUid')->willReturn(null);
		try {
			$this->svc($this->wire())->assertMobileAccess('mallory');
			$this->fail('expected CompanionException');
		} catch (CompanionException $e) {
			$this->assertSame('NO_MOBILE_SEAT', $e->getErrorCode());
		}
	}

	public function testAssertMobileAccessPassesWithSeat(): void
	{
		$this->seats->method('findByUid')->willReturn(new MobileSeat());
		$this->svc($this->wire())->assertMobileAccess('alice');
		$this->addToAssertionCount(1);
	}

	public function testHasSeatDelegatesToMapper(): void
	{
		$this->seats->method('findByUid')->willReturnMap([['alice', new MobileSeat()], ['bob', null]]);
		$svc = $this->svc('');
		$this->assertTrue($svc->hasSeat('alice'));
		$this->assertFalse($svc->hasSeat('bob'));
	}

	public function testApplyRejectsMalformedKey(): void
	{
		$this->settings->expects($this->never())->method('set');
		try {
			$this->svc('')->apply('not-a-key', 'admin');
			$this->fail('expected LICENSE_INVALID');
		} catch (DomainException $e) {
			$this->assertSame('LICENSE_INVALID', $e->getErrorCode());
		}
	}

	public function testApplyRejectsForgedSignature(): void
	{
		$evil = sodium_crypto_sign_keypair();
		$payload = json_encode(['v' => 2, 'product' => 'expensecheck', 'customerId' => 'evil-inc',
			'issuedAt' => '2026-01-01', 'validUntil' => '2099-12-31', 'mobileSeats' => 999]);
		$sig = sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($evil));
		$wire = 'EXP2.' . VendorPublicKey::base64urlEncode($payload)
			. '.' . VendorPublicKey::base64urlEncode($sig);
		$this->settings->expects($this->never())->method('set');
		try {
			$this->svc('')->apply($wire, 'mallory');
			$this->fail('expected LICENSE_INVALID');
		} catch (DomainException $e) {
			$this->assertSame('LICENSE_INVALID', $e->getErrorCode());
		}
	}

	public function testApplyPersistsNormalizedKeyAndAudits(): void
	{
		$wire = $this->wire();
		$audit = $this->createMock(AuditLogService::class);
		$this->settings->method('get')->willReturn($wire);
		$this->settings->expects($this->once())->method('set')
			->with(SettingsService::KEY_LICENSE_KEY, $wire);
		$audit->expects($this->once())->method('record')
			->with('admin', 'license_applied', $this->callback(fn ($d) =>
				$d['customerId'] === 'acme-corp' && $d['mobileSeats'] === 5));
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-12-01'));
		$svc = new LicenseService(
			$this->createStub(IConfig::class), $this->seats, $this->settings, $time, $audit,
			$this->createStub(ILockingProvider::class),
			new PostCommitEffectQueue($this->createStub(IDBConnection::class), new NullLogger()),
			$this->createStub(NotificationService::class),
		);
		$state = $svc->apply(' ' . $wire . ' ', 'admin');
		$this->assertSame('acme-corp', $state['customerId']);
	}

	public function testRemoveClearsKeyAndAudits(): void
	{
		$audit = $this->createMock(AuditLogService::class);
		$this->settings->expects($this->once())->method('set')
			->with(SettingsService::KEY_LICENSE_KEY, '');
		$audit->expects($this->once())->method('record')->with('admin', 'license_removed');
		$svc = new LicenseService(
			$this->createStub(IConfig::class), $this->seats, $this->settings,
			$this->createStub(ITimeFactory::class), $audit,
			$this->createStub(ILockingProvider::class),
			new PostCommitEffectQueue($this->createStub(IDBConnection::class), new NullLogger()),
			$this->createStub(NotificationService::class),
		);
		$svc->remove('admin');
	}

	public function testRemoveSeatMissingIsNoop(): void
	{
		$this->seats->method('findByUid')->willReturn(null);
		$this->seats->expects($this->never())->method('delete');
		$this->svc('')->removeSeat('ghost', 'admin');
	}

	public function testRemoveSeatDeletesAndAudits(): void
	{
		$seat = new MobileSeat();
		$this->seats->method('findByUid')->willReturn($seat);
		$this->seats->expects($this->once())->method('delete')->with($seat);
		$audit = $this->createMock(AuditLogService::class);
		$audit->expects($this->once())->method('record')
			->with('admin', 'mobile_seat_removed', ['uid' => 'alice']);
		$this->settings->method('get')->willReturn('');
		$svc = new LicenseService(
			$this->createStub(IConfig::class), $this->seats, $this->settings,
			$this->createStub(ITimeFactory::class), $audit,
			$this->createStub(ILockingProvider::class),
			new PostCommitEffectQueue($this->createStub(IDBConnection::class), new NullLogger()),
			$this->createStub(NotificationService::class),
		);
		$svc->removeSeat('alice', 'admin');
	}

	public function testSeatInventorySortsByUid(): void
	{
		$a = new MobileSeat(); $a->setUserId('zoe');
		$b = new MobileSeat(); $b->setUserId('amy');
		$this->seats->method('findAll')->willReturn([$a, $b]);
		$inv = $this->svc($this->wire())->seatInventory();
		$this->assertSame(5, $inv['total']);
		$this->assertSame(2, $inv['used']);
		$this->assertSame('amy', $inv['seats'][0]['uid']);
		$this->assertSame('zoe', $inv['seats'][1]['uid']);
	}
}
