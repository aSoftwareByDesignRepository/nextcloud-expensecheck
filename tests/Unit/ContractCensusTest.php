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
		// F25/F26 integration domain codes:
		'SEGMENT_NOT_FOUND', 'CHARGEBACK_NOT_FOUND',
		// F25 Mode-A guard — EXP mileage disabled while MC settles km:
		'MILEAGE_DISABLED',
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

	/**
	 * Spec §10.1 column inventory (verbatim names; spec shorthand expanded
	 * to the shipped column names — deviations are commented inline).
	 * A column added in a migration but absent here fails the census:
	 * either revert the column or log a §D spec amendment.
	 */
	private const SPEC_COLUMNS = [
		'exp_claims' => ['id', 'claim_no', 'user_id', 'title', 'purpose',
			'status', 'currency', 'total_minor', 'reimbursable_total_minor',
			'nonreimbursable_total_minor', 'org_total_minor', 'fx_rate',
			'chain_id', 'chain_snapshot', 'current_step', 'cost_center_id',
			'pc_customer_id', 'pc_project_id', 'azc_trip_id', 'period_id',
			'submitted_at', 'decided_at', 'decided_by', 'decision_note',
			'settled_at', 'settled_by', 'settlement_ref', 'batch_id',
			'version', 'created_at', 'updated_at', 'request_key',
			// F22 billed marker (Version1001) — §D amendment pending:
			// ExpBillableReadFacade needs a durable invoiced stamp.
			'billed_at', 'billed_ref',
			// §D amendment (Version1003): edge-20 correction claims + F26
			// deduction drafts need a durable kind + corrected-claim link.
			'kind', 'corrects_claim_no'],
		'exp_claim_lines' => ['id', 'claim_id', 'line_type', 'occurred_on',
			'category_id', 'merchant', 'description', 'amount_minor',
			'tax_minor', 'tax_rate', 'foreign_amount_minor',
			'foreign_currency', 'foreign_fx', 'distance_km', 'days',
			'quantity', 'rate_id', 'rate_code', 'rate_minor', 'rate_name',
			'justification', 'reimbursable', 'distance_source',
			'route_file_id', 'source_app', 'source_ref', 'attendees',
			'occasion', 'tip_minor', 'policy_flags', 'sort', 'created_at',
			'updated_at'],
		'exp_claim_allocations' => ['id', 'line_id', 'cost_center_id',
			'share_percent', 'amount_minor', 'pc_project_id'],
		'exp_attachments' => ['id', 'claim_id', 'line_id', 'inbox', 'file_id',
			'name', 'mime', 'size', 'sha256', 'uploaded_by', 'created_at'],
		'exp_categories' => ['id', 'code', 'name', 'receipt_required',
			// spec names just_threshold_minor/just_min_len; shipped names are
			// unambiguous — §D amendment recommended over renaming columns.
			'justification_threshold_minor', 'justification_min_length',
			'default_amount_minor', 'requires_attendees', 'max_amount_minor',
			'max_is_hard', 'allow_lines', 'active', 'sort', 'deleted'],
		'exp_cost_centers' => ['id', 'code', 'name', 'parent_id',
			'azc_team_id', 'budget_minor', 'currency', 'active', 'deleted'],
		'exp_rate_entries' => ['id', 'rate_type', 'code', 'name', 'currency',
			'rate_minor', 'effective_from', 'active', 'created_by',
			'created_at'],
		'exp_chains' => ['id', 'name', 'active', 'min_total_minor',
			'category_id', 'sort'],
		'exp_chain_steps' => ['id', 'chain_id', 'step_no', 'step_type',
			'group_id', 'user_id'],
		'exp_fx_rates' => ['id', 'currency', 'rate', 'effective_from',
			'created_by', 'created_at'],
		'exp_manager_map' => ['id', 'user_id', 'manager_uids', 'created_by',
			'created_at', 'updated_at'],
		'exp_step_decisions' => ['id', 'claim_id', 'step_no', 'decided_by',
			'decision', 'note', 'created_at'],
		'exp_claim_events' => ['id', 'claim_id', 'seq', 'actor', 'event',
			'from_status', 'to_status', 'payload', 'payload_hash',
			'prev_hash', 'entry_hash', 'tombstoned', 'created_at'],
		'exp_delegates' => ['id', 'manager_uid', 'delegate_uid', 'valid_from',
			'valid_to', 'created_by', 'created_at', 'revoked_at'],
		'exp_periods' => ['id', 'name', 'start_on', 'end_on', 'status',
			'closed_by', 'closed_at', 'reopen_reason'],
		'exp_settlement_batches' => ['id', 'created_by', 'created_at',
			'format', 'row_count', 'file_hash', 'period_id'],
		'exp_settlement_rows' => ['id', 'batch_id', 'claim_id', 'paid_ref',
			'paid_at'],
		'exp_mobile_seats' => ['id', 'user_id', 'assigned_by', 'assigned_at'],
		'exp_idempotency' => ['key', 'user_id', 'claim_id', 'created_at'],
		'exp_audit_log' => ['id', 'actor', 'action', 'payload', 'created_at'],
		'exp_notification_log' => ['id', 'dedupe_key', 'event', 'claim_id',
			'recipient', 'channel', 'created_at'],
		// D-01: per-org yearly claim_no sequence allocator.
		'exp_claim_counters' => ['year', 'next_seq'],
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
	 * Column-level census: migration schema vs spec §10.1 inventory —
	 * a column added or silently dropped without a spec amendment is drift.
	 */
	public function testSchemaColumnInventoryMatchesSpec(): void
	{
		$actual = $this->migrationColumns();
		$specKeys = array_keys(self::SPEC_COLUMNS);
		sort($specKeys);
		$actualKeys = array_keys($actual);
		sort($actualKeys);
		$this->assertSame($specKeys, $actualKeys,
			'table set drifted from §10.1');
		foreach (self::SPEC_COLUMNS as $table => $cols) {
			$expected = $cols;
			sort($expected);
			$got = $actual[$table];
			sort($got);
			$this->assertSame($expected, $got,
				"{$table} columns drifted from §10.1 — log a §D amendment or revert the migration delta");
		}
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

	/**
	 * §10.7 census: every spec settings key is either a REGISTRY row or a
	 * documented alternate home. No appconfig key may exist outside this
	 * census (invisible config = config drift).
	 */
	public function testSettingsKeysMatchSpecInventory(): void
	{
		// Spec §10.7 keys that live in SettingsService::REGISTRY verbatim.
		$specRegistry = [
			'org_currency', 'enable_mileage_claims', 'allow_route_evidence',
			'allow_self_approve', 'attachment_max_mb',
			'attachment_max_per_claim_mb', 'attachment_max_files',
			'inbox_purge_days', 'retention_years', 'digest_cadence',
			'export_async_rows', 'mc_coexistence_mode', 'license_key',
			'license_offline_grace_h', 'digest_last_run', 'export_job_state',
		];
		foreach ($specRegistry as $key) {
			$this->assertArrayHasKey($key, SettingsService::REGISTRY,
				"§10.7 key {$key} missing from REGISTRY");
		}
		// Mirrored access-door keys live in AccessControlService (§3.4).
		foreach (['access_restriction_enabled', 'access_allowed_user_ids',
			'access_allowed_group_ids', 'app_admin_user_ids'] as $key) {
			$this->assertContains($key, [
				\OCA\ExpenseCheck\Service\AccessControlService::KEY_ACCESS_RESTRICTION,
				\OCA\ExpenseCheck\Service\AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS,
				\OCA\ExpenseCheck\Service\AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS,
				\OCA\ExpenseCheck\Service\AccessControlService::KEY_APP_ADMINS,
			], "access-door key {$key} homeless");
		}
		// D-05: seat_assignments ships as the exp_mobile_seats table, not
		// an appconfig blob — it must NOT reappear as a settings key.
		$this->assertArrayNotHasKey('seat_assignments', SettingsService::REGISTRY);
		// Reverse direction: no undocumented registry keys either.
		$documented = array_merge($specRegistry, ['journal_version']); // chain format version, internal
		$this->assertSame([], array_values(array_diff(
			array_keys(SettingsService::REGISTRY), $documented)),
			'REGISTRY keys without a §10.7 row — spec amend or remove');
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
