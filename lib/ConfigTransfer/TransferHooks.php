<?php

declare(strict_types=1);

/**
 * ExpenseCheck wiring for the shared transfer engine (spec §12.6).
 *
 *  - requireTransferAdmin gates on the app's door-admin role
 *    (AccessControlService::isAppAdmin — system admin OR delegated admin).
 *  - validateValue/normalizeValue delegate to SettingsService::normalizeForWrite
 *    — the EXACT normaliser the settings API uses (validator parity, NN-08).
 *  - writeMirrored runs the access-door policy through
 *    AccessControlService::writeAccessPolicy in ONE atomic call — never raw
 *    setAppValue (NN-02). File values merge over current state so a partial
 *    mirrored set can never half-open or half-slam the door.
 *  - createSafetySnapshot delegates to TransferSnapshotService (FS-2) —
 *    transferable tables + wire-class keys dumped server-wrapped before
 *    replace mode wipes them.
 */

namespace OCA\ExpenseCheck\ConfigTransfer;

use OCA\ExpenseCheck\Exception\AppAccessDeniedException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\AuditLogService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCA\ExpenseCheck\Service\TransferSnapshotService;

final class TransferHooks implements ConfigTransferHooks
{
	/** Mirrored access-door keys — atomic write path only. */
	private const ACCESS_KEYS = [
		AccessControlService::KEY_ACCESS_RESTRICTION,
		AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
		AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
		AccessControlService::KEY_APP_ADMINS,
	];

	public function __construct(
		private readonly SettingsService $settings,
		private readonly AccessControlService $access,
		private readonly TransferSnapshotService $snapshots,
		private readonly AuditLogService $audit,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		if (!$this->access->isAppAdmin($uid)) {
			throw new AppAccessDeniedException('transfer_admin_required');
		}
	}

	public function validateValue(string $key, string $value): ?string
	{
		if ($key === AccessControlService::KEY_ACCESS_RESTRICTION) {
			return in_array($value, ['0', '1'], true) ? null : 'INVALID_BOOL';
		}
		if (in_array($key, self::ACCESS_KEYS, true)) {
			$decoded = json_decode($value, true);
			if (!is_array($decoded)) {
				return 'INVALID_JSON';
			}
			foreach ($decoded as $v) {
				if (!is_string($v)) {
					return 'INVALID_VALUE';
				}
			}
			return null;
		}
		if ($key === SettingsService::KEY_LICENSE_KEY) {
			// Sealed secret — imported only inside the encrypted container;
			// format validation lives in LicenseService::apply.
			return $value === '' || strlen($value) <= 16384 ? null : 'TOO_LONG';
		}
		try {
			$this->settings->normalizeForWrite($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		if (in_array($key, self::ACCESS_KEYS, true)) {
			if ($key === AccessControlService::KEY_ACCESS_RESTRICTION) {
				return $value === '1' ? '1' : '0';
			}
			$decoded = json_decode($value, true);
			return is_array($decoded)
				? json_encode(array_values(array_filter(array_map('strval', $decoded), fn (string $v): bool => $v !== '')))
				: '[]';
		}
		try {
			return $this->settings->normalizeForWrite($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	public function validateMirrored(array $keyValues): ?string
	{
		foreach ($keyValues as $key => $value) {
			if (!in_array($key, self::ACCESS_KEYS, true)) {
				return 'UNEXPECTED_MIRRORED_KEY';
			}
			if ($this->validateValue($key, $value) !== null) {
				return 'INVALID_MIRRORED_SET';
			}
		}
		return null;
	}

	public function writeMirrored(array $keyValues): void
	{
		// Merge file values over current state — a partial mirrored set
		// (e.g. a skipped unresolved key) must never half-write the door.
		$restriction = isset($keyValues[AccessControlService::KEY_ACCESS_RESTRICTION])
			? $keyValues[AccessControlService::KEY_ACCESS_RESTRICTION] === '1'
			: $this->access->isAccessRestrictionEnabled();
		$users = $this->idList($keyValues, AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS, $this->access->getAllowedUserIds());
		$groups = $this->idList($keyValues, AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS, $this->access->getAllowedGroupIds());
		$admins = $this->idList($keyValues, AccessControlService::KEY_APP_ADMINS, $this->access->getAppAdminIds());
		$this->access->writeAccessPolicy($restriction, $users, $groups, $admins);
	}

	public function securityRelevantKeys(): array
	{
		return [
			AccessControlService::KEY_ACCESS_RESTRICTION,
			AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
			AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
			AccessControlService::KEY_APP_ADMINS,
			SettingsService::KEY_ALLOW_SELF_APPROVE,
		];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		return $this->snapshots->createSnapshot($reason);
	}

	public function settingsSectionForKey(string $key): ?string
	{
		$spec = (new TransferManifest())->keySpec($key);
		return $spec['section'] ?? 'general';
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		try {
			$this->audit->record(
				$actor === '' ? 'cli' : $actor,
				'config_transfer_' . $action,
				$details,
			);
		} catch (\Throwable) {
			// audit must never fail the transfer itself
		}
	}

	/**
	 * @param array<string,string> $keyValues
	 * @param list<string> $fallback
	 * @return list<string>
	 */
	private function idList(array $keyValues, string $key, array $fallback): array
	{
		if (!isset($keyValues[$key])) {
			return $fallback;
		}
		$decoded = json_decode($keyValues[$key], true);
		if (!is_array($decoded)) {
			throw new \InvalidArgumentException('INVALID_MIRRORED_SET');
		}
		return array_values(array_filter(array_map('strval', $decoded), fn (string $v): bool => $v !== ''));
	}
}
