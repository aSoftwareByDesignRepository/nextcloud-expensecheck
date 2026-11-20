<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Db\MobileSeat;
use OCA\ExpenseCheck\Db\MobileSeatMapper;
use OCA\ExpenseCheck\Exception\CompanionException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\License\Exp2Codec;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * EXP2 license + seat gate (spec §13). The wire key is verified offline
 * (Ed25519, embedded vendor pubkey); validity is evaluated at gate time —
 * `validUntil` inclusive, with `license_offline_grace_h` covering an
 * unreachable verification? No — verification is local, so the grace window
 * applies to the license's own validity horizon only. Expired → gate closed.
 *
 * Seats: `exp_mobile_seats` assignments; `mobileSeats` in the payload is the
 * purchased cap. Seat assignment is admin-managed.
 */
class LicenseService
{
	public function __construct(
		private readonly IConfig $config,
		private readonly MobileSeatMapper $seats,
		private readonly SettingsService $settings,
		private readonly ITimeFactory $timeFactory,
		private readonly AuditLogService $audit,
		private readonly ILockingProvider $locking,
		private readonly PostCommitEffectQueue $postCommit,
		private readonly NotificationService $notify,
	) {
	}

	private function storedKey(): string
	{
		return $this->settings->get(SettingsService::KEY_LICENSE_KEY);
	}

	/**
	 * Verified license state, or null when no/invalid key is stored.
	 * @return array{payload:array<string,mixed>,valid:bool,validUntil:string,mobileSeats:int}|null
	 */
	public function state(): ?array
	{
		$key = $this->storedKey();
		if ($key === '') {
			return null;
		}
		$verified = Exp2Codec::parseAndVerify($key);
		if ($verified === null) {
			return null;
		}
		$p = $verified['payload'];
		$today = $this->timeFactory->getDateTime()->format('Y-m-d');
		return [
			'payload' => $p,
			'valid' => Exp2Codec::isValidOn((string)$p['validUntil'], $today),
			'validUntil' => (string)$p['validUntil'],
			'mobileSeats' => (int)$p['mobileSeats'],
			'customerId' => (string)$p['customerId'],
		];
	}

	public function isMobilePlanActive(): bool
	{
		$state = $this->state();
		return $state !== null && $state['valid'];
	}

	/**
	 * Apply a new wire key. Verifies BEFORE persisting — a bad key never
	 * reaches storage.
	 */
	public function apply(string $wireKey, string $actor): array
	{
		$error = Exp2Codec::classifyError($wireKey);
		if ($error !== '') {
			throw new DomainException('LICENSE_INVALID', match ($error) {
				Exp2Codec::ERROR_INVALID_FORMAT => 'The key does not have the expected EXP2.<payload>.<signature> shape.',
				Exp2Codec::ERROR_INVALID_SIGNATURE => 'The signature does not match — the key was altered or not issued for ExpenseCheck.',
				default => 'The license payload is invalid.',
			}, 422);
		}
		$this->settings->set(SettingsService::KEY_LICENSE_KEY, Exp2Codec::normalizeWireKey($wireKey));
		$state = $this->state();
		$this->audit->record($actor, 'license_applied', [
			'customerId' => $state['customerId'] ?? null,
			'validUntil' => $state['validUntil'] ?? null,
			'mobileSeats' => $state['mobileSeats'] ?? null,
		]);
		return $state;
	}

	public function remove(string $actor): void
	{
		$this->settings->set(SettingsService::KEY_LICENSE_KEY, '');
		$this->audit->record($actor, 'license_removed');
	}

	/**
	 * The mobile gate: active license + assigned seat for the uid.
	 * @throws CompanionException
	 */
	public function assertMobileAccess(string $uid): void
	{
		$state = $this->state();
		if ($state === null) {
			throw CompanionException::licenseRequired();
		}
		if (!$state['valid']) {
			throw CompanionException::licenseExpired();
		}
		if ($this->seats->findByUid($uid) === null) {
			throw CompanionException::noSeat();
		}
	}

	public function hasSeat(string $uid): bool
	{
		return $this->seats->findByUid($uid) !== null;
	}

	/**
	 * Seat inventory: total purchased, assigned count, per-user assignments.
	 * @return array{total:int,used:int,seats:list<array{uid:string,assigned_at:string}>}
	 */
	public function seatInventory(): array
	{
		$total = ($this->state() ?? [])['mobileSeats'] ?? 0;
		$list = array_map(
			fn (MobileSeat $s) => ['uid' => $s->getUserId(), 'assigned_at' => $s->getAssignedAt()?->format('Y-m-d H:i:s') ?? ''],
			$this->seats->findAll()
		);
		usort($list, fn ($a, $b) => strcmp($a['uid'], $b['uid']));
		return ['total' => $total, 'used' => count($list), 'seats' => $list];
	}

	public function assignSeat(string $uid, string $actor): void
	{
		// Check-then-act MUST be serialized: two admins assigning when
		// used == total-1 would both pass the limit check and over-allocate.
		// The unique-key catch below only backstops same-uid duplicates.
		try {
			$this->locking->acquireLock('exp_seat_assign', ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			usleep(50000);
			$this->locking->acquireLock('exp_seat_assign', ILockingProvider::LOCK_EXCLUSIVE);
		}
		try {
			$inv = $this->seatInventory();
			if ($this->hasSeat($uid)) {
				return; // idempotent
			}
			if ($inv['used'] >= $inv['total']) {
				throw new DomainException('SEAT_LIMIT_EXCEEDED', 'All licensed mobile seats are assigned.', 409);
			}
			$seat = new MobileSeat();
			$seat->setUserId($uid);
			$seat->setAssignedBy($actor);
			$seat->setAssignedAt($this->timeFactory->getDateTime());
			try {
				$this->seats->insert($seat);
			} catch (\OCP\DB\Exception) {
				throw new DomainException('SEAT_LIMIT_EXCEEDED', 'Seat assignment raced — retry.', 409);
			}
			$this->audit->record($actor, 'mobile_seat_assigned', ['uid' => $uid]);
		} finally {
			$this->locking->releaseLock('exp_seat_assign', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function removeSeat(string $uid, string $actor): void
	{
		$seat = $this->seats->findByUid($uid);
		if ($seat === null) {
			return;
		}
		$this->seats->delete($seat);
		$this->audit->record($actor, 'mobile_seat_removed', ['uid' => $uid]);
		$this->postCommit->runAfterCurrentCommit(function () use ($uid): void {
			$this->notify->seatRevoked($uid);
		});
	}
}
