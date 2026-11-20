<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\AppInfo\Application;
use OCP\IConfig;

/**
 * §10.7 settings-key registry — every appconfig key enumerated with type,
 * default and transfer class. The census test asserts code ↔ registry parity.
 */
class SettingsService
{
	public const KEY_ORG_CURRENCY = 'org_currency';
	public const KEY_ENABLE_MILEAGE = 'enable_mileage_claims';
	public const KEY_ALLOW_ROUTE_EVIDENCE = 'allow_route_evidence';
	public const KEY_ALLOW_SELF_APPROVE = 'allow_self_approve';
	public const KEY_ATTACHMENT_MAX_MB = 'attachment_max_mb';
	public const KEY_ATTACHMENT_MAX_CLAIM_MB = 'attachment_max_per_claim_mb';
	public const KEY_ATTACHMENT_MAX_FILES = 'attachment_max_files';
	public const KEY_INBOX_PURGE_DAYS = 'inbox_purge_days';
	public const KEY_RETENTION_YEARS = 'retention_years';
	public const KEY_DIGEST_CADENCE = 'digest_cadence';
	public const KEY_EXPORT_ASYNC_ROWS = 'export_async_rows';
	public const KEY_MC_COEXISTENCE_MODE = 'mc_coexistence_mode';
	public const KEY_LICENSE_KEY = 'license_key';
	public const KEY_LICENSE_OFFLINE_GRACE_H = 'license_offline_grace_h';
	public const KEY_JOURNAL_VERSION = 'journal_version';
	public const KEY_DIGEST_LAST_RUN = 'digest_last_run';
	public const KEY_EXPORT_JOB_STATE = 'export_job_state';

	/**
	 * Registry rows: key → [type, default, transfer-class].
	 * transfer classes: plain | mirrored | secret | internal
	 */
	public const REGISTRY = [
		self::KEY_ORG_CURRENCY => ['currency', 'EUR', 'plain'],
		self::KEY_ENABLE_MILEAGE => ['bool', '1', 'plain'],
		self::KEY_ALLOW_ROUTE_EVIDENCE => ['bool', '0', 'plain'],
		self::KEY_ALLOW_SELF_APPROVE => ['bool', '0', 'plain'],
		self::KEY_ATTACHMENT_MAX_MB => ['int', '5', 'plain'],
		self::KEY_ATTACHMENT_MAX_CLAIM_MB => ['int', '25', 'plain'],
		self::KEY_ATTACHMENT_MAX_FILES => ['int', '10', 'plain'],
		self::KEY_INBOX_PURGE_DAYS => ['int', '90', 'plain'],
		self::KEY_RETENTION_YEARS => ['int', '10', 'plain'],
		self::KEY_DIGEST_CADENCE => ['enum:daily,weekly,off', 'daily', 'plain'],
		self::KEY_EXPORT_ASYNC_ROWS => ['int', '5000', 'plain'],
		self::KEY_MC_COEXISTENCE_MODE => ['enum:unset,a,b', 'unset', 'plain'],
		self::KEY_LICENSE_KEY => ['sealed', '', 'secret'],
		self::KEY_LICENSE_OFFLINE_GRACE_H => ['int', '72', 'plain'],
		self::KEY_JOURNAL_VERSION => ['int', '1', 'plain'],
		self::KEY_DIGEST_LAST_RUN => ['ts', '', 'internal'],
		self::KEY_EXPORT_JOB_STATE => ['json', '', 'internal'],
	];

	public function __construct(private readonly IConfig $config)
	{
	}

	public function get(string $key): string
	{
		$def = self::REGISTRY[$key] ?? null;
		$default = $def !== null ? (string)$def[1] : '';
		return $this->config->getAppValue(Application::APP_ID, $key, $default);
	}

	public function set(string $key, string $value): void
	{
		$this->config->setAppValue(Application::APP_ID, $key, $value);
	}

	public function getBool(string $key): bool
	{
		return $this->get($key) === '1';
	}

	public function setBool(string $key, bool $value): void
	{
		$this->set($key, $value ? '1' : '0');
	}

	public function getInt(string $key): int
	{
		return (int)$this->get($key);
	}

	public function orgCurrency(): string
	{
		return strtoupper($this->get(self::KEY_ORG_CURRENCY));
	}

	/**
	 * The single settings writer normaliser (validator parity, NN-08):
	 * used by the settings API AND the config-transfer import path so a
	 * transferred value lands byte-identical to a UI-saved one.
	 *
	 * @throws \InvalidArgumentException stable code in the message
	 */
	public function normalizeForWrite(string $key, string $value): string
	{
		$def = self::REGISTRY[$key] ?? null;
		if ($def === null) {
			throw new \InvalidArgumentException('UNKNOWN_SETTING_KEY');
		}
		[$type, , $class] = $def;
		if ($class !== 'plain') {
			throw new \InvalidArgumentException('KEY_NOT_WRITEABLE');
		}
		if ($type === 'bool') {
			return in_array(strtolower($value), ['1', 'true', 'on'], true) ? '1' : '0';
		}
		if ($type === 'int') {
			if (!preg_match('/^\d+$/', $value)) {
				throw new \InvalidArgumentException('INVALID_INT');
			}
			return $value;
		}
		if (str_starts_with($type, 'enum:')) {
			if (!in_array($value, explode(',', substr($type, 5)), true)) {
				throw new \InvalidArgumentException('INVALID_ENUM');
			}
			return $value;
		}
		if ($type === 'currency') {
			$v = strtoupper($value);
			if (!preg_match('/^[A-Z]{3}$/', $v)) {
				throw new \InvalidArgumentException('INVALID_CURRENCY');
			}
			return $v;
		}
		// 'ts'/'json'/'sealed' types never reach this point (non-plain class).
		throw new \InvalidArgumentException('KEY_NOT_WRITEABLE');
	}

	/**
	 * Server-side clamp for attachment limits: admin-tunable DOWN only —
	 * family ceiling is hard (5 MB/file, 25 MB/claim, 10 files).
	 */
	public function attachmentMaxMb(): int
	{
		return min(5, max(1, $this->getInt(self::KEY_ATTACHMENT_MAX_MB)));
	}

	public function attachmentMaxClaimMb(): int
	{
		return min(25, max(1, $this->getInt(self::KEY_ATTACHMENT_MAX_CLAIM_MB)));
	}

	public function attachmentMaxFiles(): int
	{
		return min(10, max(1, $this->getInt(self::KEY_ATTACHMENT_MAX_FILES)));
	}

	/**
	 * @return array<string, string> GET parity for the settings API — every
	 * non-secret registry key readable so clients render gated UX correctly
	 * (ui-api-client contract rule 4). Secrets never leave the server.
	 */
	public function exportPublic(): array
	{
		$out = [];
		foreach (self::REGISTRY as $key => [$type, $default, $class]) {
			if ($class === 'secret' || $class === 'internal') {
				continue;
			}
			$out[$key] = $this->get($key);
		}
		return $out;
	}
}
