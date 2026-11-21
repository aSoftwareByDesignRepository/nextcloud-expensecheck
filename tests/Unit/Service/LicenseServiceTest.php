<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Db\MobileSeat;
use OCA\ExpenseCheck\Db\MobileSeatMapper;
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
use OCA\ExpenseCheck\Config\VendorPublicKey;
use OCA\ExpenseCheck\Exception\CompanionException;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class LicenseServiceTest extends TestCase
{
	private MobileSeatMapper&MockObject $seats;
	private SettingsService&MockObject $settings;
	private ILockingProvider&MockObject $locking;
	private LicenseService $svc;

	protected function setUp(): void
	{
		$this->seats = $this->createMock(MobileSeatMapper::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->svc = new LicenseService(
			$this->createMock(IConfig::class),
			$this->seats,
			$this->settings,
			$this->createMock(ITimeFactory::class),
			$this->createMock(AuditLogService::class),
			$this->locking,
			new PostCommitEffectQueue($this->createStub(IDBConnection::class), $this->createStub(LoggerInterface::class)),
			$this->createStub(NotificationService::class),
		);
	}

	public function testSeatInventoryWithoutLicenseIsZeroedNotNull(): void
	{
		$this->settings->method('get')->willReturn(''); // no key stored → state() null
		$this->seats->method('findAll')->willReturn([]);
		$inv = $this->svc->seatInventory();
		$this->assertSame(0, $inv['total']);
		$this->assertSame(0, $inv['used']);
		$this->assertSame([], $inv['seats']);
	}

	public function testAssignSeatWithoutLicenseFailsClosed(): void
	{
		$this->settings->method('get')->willReturn('');
		$this->seats->method('findAll')->willReturn([]);
		$this->seats->method('findByUid')->willReturn(null);
		try {
			$this->svc->assignSeat('alice', 'admin');
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame('SEAT_LIMIT_EXCEEDED', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testAssignSeatIdempotentWhenAlreadyAssigned(): void
	{
		$seat = new MobileSeat();
		$this->seats->method('findByUid')->willReturn($seat);
		$this->seats->method('findAll')->willReturn([$seat]);
		$this->settings->method('get')->willReturn('');
		$this->seats->expects($this->never())->method('insert');
		$this->svc->assignSeat('alice', 'admin'); // returns quietly
		$this->addToAssertionCount(1);
	}

	public function testAssignSeatReleasesLockOnFailure(): void
	{
		$this->settings->method('get')->willReturn('');
		$this->seats->method('findAll')->willReturn([]);
		$this->seats->method('findByUid')->willReturn(null);
		$this->locking->expects($this->once())
			->method('releaseLock')
			->with('exp_seat_assign', ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$this->svc->assignSeat('alice', 'admin');
		} catch (DomainException) {
			// release must still have happened
		}
	}

	public function testAssignSeatAcquiresExclusiveLock(): void
	{
		$this->settings->method('get')->willReturn('');
		$this->seats->method('findAll')->willReturn([]);
		$this->seats->method('findByUid')->willReturn(null);
		$this->locking->expects($this->once())
			->method('acquireLock')
			->with('exp_seat_assign', ILockingProvider::LOCK_EXCLUSIVE, $this->anything());
		try {
			$this->svc->assignSeat('alice', 'admin');
		} catch (DomainException) {
		}
	}
}
