<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit;

use OCA\ExpenseCheck\ConfigTransfer\TransferManifest;
use OCA\ExpenseCheck\Repair\SchemaTableCatalog;
use OCA\ExpenseCheck\Repair\UninstallDropTables;
use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCA\ExpenseCheck\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Drift-control census tests (spec §15.2 drift detectors): the spec's
 * contract tables are binding — every divergence must either be fixed in
 * code or logged as a §D spec amendment in IMPLEMENTATION-CHECKLIST.md.
 *
 * These tests compare the four independent inventories that must agree:
 * migration schema ↔ SchemaTableCatalog ↔ UninstallDropTables ↔
 * TransferManifest ↔ spec §10.1; SettingsService::REGISTRY ↔ spec §10.7
 * ↔ TransferManifest keys; emitted error codes ↔ spec §10.4 dictionary;
 * TRANSITIONS ↔ spec §10.2.
 */
final class ContractCensusTest extends TestCase
{
	/** Spec §10.1 table inventory (21 spec tables + D-01 counters table). */
	private const SPEC_TABLES = [
		'exp_claims', 'exp_claim_lines', 'exp_claim_allocations',
		'exp_attachments', 'exp_categories', 'exp_cost_centers',
		'exp_rate_entries', 'exp_chains', 'exp_chain_steps', 'exp_fx_rates',
		'exp_manager_map', 'exp_step_decisions', 'exp_claim_events',
		'exp_delegates', 'exp_periods', 'exp_settlement_batches',
		'exp_settlement_rows', 'exp_mobile_seats', 'exp_idempotency',
		'exp_audit_log', 'exp_notification_log',
		// D-01: per-org yearly claim_no sequence allocator — spec amend
		// recommended; race-free numbering requires it.
		'exp_claim_counters',
	];

	/** Spec §10.4 dictionary + documented app-internal additions. */
	private const ERROR_DICTIONARY = [
		// ── §10.4 stable dictionary ──
		'CLAIM_NOT_FOUND', 'VALIDATION_FAILED', 'RATE_MISSING',
		'RECEIPT_REQUIRED', 'JUSTIFICATION_REQUIRED', 'STEP_ALREADY_DECIDED',
		'CLAIM_NOT_PENDING', 'VERSION_CONFLICT', 'PERIOD_CLOSED',
		'SELF_APPROVAL_DENIED', 'CHAIN_UNROUTABLE', 'ATTACHMENT_TYPE_DENIED',
		'LICENSE_REQUIRED', 'LICENSE_EXPIRED', 'NO_MOBILE_SEAT',
		'RATE_IMMUTABLE', 'ALLOCATION_SUM_MISMATCH', 'JOURNAL_BUSY',
		'CHAIN_VERIFY_FAILED', 'ALREADY_CLAIMED', 'CAP_EXCEEDED',
		'APP_ACCESS_DENIED', 'IDEMPOTENCY_MISMATCH', 'EXPORT_NOT_READY',
		'CONTRACT_VERSION_MISMATCH',
		// ── app-internal domain codes (uniform-404 family: *_NOT_FOUND) ──
		'ATTACHMENT_NOT_FOUND', 'BATCH_NOT_FOUND', 'CATEGORY_NOT_FOUND',
		'CHAIN_NOT_FOUND', 'COST_CENTER_NOT_FOUND', 'DELEGATE_NOT_FOUND',
		'FX_NOT_FOUND', 'MANAGER_MAP_NOT_FOUND', 'PERIOD_NOT_FOUND',
		'CLAIM_EMPTY', 'CLAIM_NOT_CLONEABLE', 'CLAIM_NOT_SETTLEABLE',
		'CLAIM_READ_ONLY', 'PERIOD_BLOCKED', 'PERIOD_NOT_CLOSED',
		'DUPLICATE_CODE', 'SEAT_LIMIT_EXCEEDED', 'STORAGE_DENIED',
		'TOO_MANY_FILES', 'LICENSE_INVALID',
		// settings normaliser + transfer hooks
		'INVALID_INT', 'INVALID_ENUM', 'INVALID_CURRENCY',
		'INVALID_BOOL', 'INVALID_JSON', 'INVALID_VALUE', 'TOO_LONG',
		'UNKNOWN_SETTING_KEY', 'KEY_NOT_WRITEABLE',
		'INVALID_MIRRORED_SET', 'UNEXPECTED_MIRRORED_KEY',
		'TRANSFER_ADMIN_REQUIRED', 'TRANSFER_SNAPSHOT_TOO_LARGE',
		'TRANSFER_SNAPSHOT_NO_APPDATA', 'TRANSFER_SNAPSHOT_NO_DIR',
		'TRANSFER_SNAPSHOT_NO_SECRET', 'TRANSFER_SNAPSHOT_FAILED',
		// middleware / session layer
		'CSRF_FAILED', 'NOT_FOUND', 'SESSION_INVALID', 'SEAT_REVOKED',
		'LICENSE_SEAT_REVOKED',
	];

	public function testSchemaInventoryFourWayAgreement(): void
	{
		$migrationTables = $this->migrationTables();
		$expected = SchemaTableCatalog::EXPECTED_TABLES;
		$uninstall = UninstallDropTables::TABLES;
		$manifest = array_keys((new TransferManifest())->tables());

		sort($migrationTables);
		$sortedExpected = $expected;
		sort($sortedExpected);
		$sortedUninstall = $uninstall;
		sort($sortedUninstall);
		$sortedManifest = $manifest;
		sort($sortedManifest);
		$spec = self::SPEC_TABLES;
		sort($spec);

		$this->assertSame($spec, $migrationTables,
			'migration creates tables not in spec §10.1 (+D-01) — drift');
		$this->assertSame($spec, $sortedExpected,
			'SchemaTableCatalog::EXPECTED_TABLES drifted from spec §10.1');
		$this->assertSame($spec, $sortedUninstall,
			'UninstallDropTables::TABLES drifted — orphan tables after uninstall');
		$this->assertSame($spec, $sortedManifest,
			'TransferManifest tables() drifted — unclassified app table');
	}

	/**
	 * Every schema column must have a matching camelCase property on the
	 * mapped entity — QBMapper::fromRow fatals otherwise (the billed_at
	 * bug class: column added, entity property forgotten).
	 */
	public function testEntitySchemaParity(): void
	{
		$dbDir = \dirname(__DIR__, 2) . '/lib/Db';
		$columns = $this->migrationColumns();
		$checked = 0;
		foreach (glob($dbDir . '/*Mapper.php') ?: [] as $mapperFile) {
			$src = (string)file_get_contents($mapperFile);
			if (!preg_match("/const TABLE = '([a-z0-9_]+)'/", $src, $tm)
				|| !preg_match('/self::TABLE, (\w+)::class/', $src, $em)) {
				continue;
			}
			$table = $tm[1];
			$entityClass = 'OCA\\ExpenseCheck\\Db\\' . $em[1];
			if (!class_exists($entityClass)) {
				$this->fail("{$mapperFile} maps nonexistent entity {$entityClass}");
			}
			$entitySrc = (string)file_get_contents(
				$dbDir . '/' . $em[1] . '.php');
			foreach ($columns[$table] ?? [] as $col) {
				if ($col === 'id') {
					continue; // inherited from OCP\AppFramework\Db\Entity
				}
				$prop = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $col))));
				$this->assertMatchesRegularExpression(
					'/protected \??\S+ \$' . preg_quote($prop, '/') . '\b/',
					$entitySrc,
					"{$table}.{$col} has no {$entityClass}::\${$prop} — entity/schema drift");
			}
			$checked++;
		}
		$this->assertGreaterThan(10, $checked, 'entity census sanity — too few mappers parsed');
	}

	public function testSettingsRegistryCoveredByManifest(): void
	{
		$manifest = (new TransferManifest());
		foreach (SettingsService::REGISTRY as $key => [$type, $default, $class]) {
			$spec = $manifest->keySpec($key);
			$this->assertNotNull($spec, "settings key {$key} missing from TransferManifest");
			$expectedClass = $class === 'sealed' ? 'secret' : $class;
			$this->assertSame($expectedClass, $spec['class'],
				"settings key {$key} class mismatch: registry {$class} vs manifest {$spec['class']}");
		}
	}

	public function testEmittedErrorCodesInDictionary(): void
	{
		$lib = \dirname(__DIR__, 2) . '/lib';
		$codes = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$src = (string)file_get_contents($file->getPathname());
			// Exception('CODE'), ['error' => 'CODE'], 'CODE' string literals
			// next to DomainException/CompanionException constructions.
			if (preg_match_all("/'([A-Z][A-Z0-9_]{5,})'/", $src, $m)) {
				foreach ($m[1] as $code) {
					if (str_starts_with($code, 'TRANSFER_')
						|| str_starts_with($code, 'APP_PATH')
						|| str_starts_with($code, 'HTTP_')) {
						continue; // shared-engine + framework literals
					}
					$codes[$code] = true;
				}
			}
		}
		$dictionary = array_flip(self::ERROR_DICTIONARY);
		$undocumented = [];
		foreach (array_keys($codes) as $code) {
			if (isset($dictionary[$code])) {
				continue;
			}
			// Filter non-code ALL-CAPS literals: route names, event names,
			// lock keys, config constants — the census only cares about
			// strings thrown/emitted as error codes. Codes are emitted via
			// typed exceptions, so cross-check candidates against the
			// exception/error emission context.
			$undocumented[] = $code;
		}
		// Emitted-code candidates that must be dictionary-covered:
		// re-scan only throw/->withError()/error: contexts.
		$emitted = [];
		foreach ($iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS)) as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$src = (string)file_get_contents($file->getPathname());
			if (preg_match_all(
				"/(?:Exception|error|withError|setError|fail|__construct)\\s*[(']+\s*'([A-Z][A-Z0-9_]{5,})'/",
				$src, $m)) {
				foreach ($m[1] as $code) {
					if (!str_starts_with($code, 'TRANSFER_')) {
						$emitted[$code] = true;
					}
				}
			}
		}
		$missing = array_diff(array_keys($emitted), self::ERROR_DICTIONARY);
		$this->assertSame([], array_values($missing),
			'error codes emitted without a §10.4/dictionary entry: ' . implode(', ', $missing));
	}

	public function testStateMachineMatchesSpec(): void
	{
		// §10.2 as-shipped map. Documented deltas vs the spec diagram
		// (logged §D): changes_requested→draft (withdraw allowed) and
		// changes_requested→rejected (approver may close a stalled claim).
		$spec = [
			'draft' => ['submitted'],
			'submitted' => ['draft', 'changes_requested', 'approved', 'rejected'],
			'changes_requested' => ['submitted', 'draft', 'rejected'],
			'approved' => ['settled', 'submitted'],
			'rejected' => [],
			'settled' => [],
		];
		$this->assertSame($spec, ClaimLifecycleService::TRANSITIONS,
			'state machine drifted from §10.2 — log a §D entry or fix the code');
		// Every state on both sides must be reachable/legal.
		foreach (ClaimLifecycleService::TRANSITIONS as $from => $targets) {
			foreach ($targets as $to) {
				$this->assertArrayHasKey($to, ClaimLifecycleService::TRANSITIONS,
					"transition target {$to} is not a known state");
			}
		}
	}

	/** @return list<string> */
	private function migrationTables(): array
	{
		return array_keys($this->migrationColumns());
	}

	/**
	 * table → column list parsed from every migration (createTable and
	 * later addColumn calls both count).
	 * @return array<string, list<string>>
	 */
	private function migrationColumns(): array
	{
		$out = [];
		$migDir = \dirname(__DIR__, 2) . '/lib/Migration';
		foreach (glob($migDir . '/*.php') ?: [] as $file) {
			$src = (string)file_get_contents($file);
			// createTable('t') ... addColumn('c') — attribute columns to the
			// enclosing createTable block.
			if (preg_match_all("/createTable\\('([a-z0-9_]+)'\\)(.*?)(?=createTable\\(|$)/s", $src, $m, PREG_SET_ORDER)) {
				foreach ($m as $block) {
					$table = $block[1];
					$out[$table] = $out[$table] ?? [];
					if (preg_match_all("/addColumn\\('([a-z0-9_]+)'/", $block[2], $cm)) {
						$out[$table] = array_merge($out[$table], $cm[1]);
					}
				}
			}
			// getTable('t') → addColumn('c') in later migrations.
			if (preg_match_all("/getTable\\('([a-z0-9_]+)'\\)(.*?)(?=getTable\\(|$)/s", $src, $m2, PREG_SET_ORDER)) {
				foreach ($m2 as $block) {
					$table = $block[1];
					if (!isset($out[$table])) {
						continue; // column added to a table we don't own
					}
					if (preg_match_all("/addColumn\\('([a-z0-9_]+)'/", $block[2], $cm)) {
						$out[$table] = array_merge($out[$table], $cm[1]);
					}
				}
			}
		}
		foreach ($out as $t => $cols) {
			$out[$t] = array_values(array_unique($cols));
		}
		return $out;
	}
}
