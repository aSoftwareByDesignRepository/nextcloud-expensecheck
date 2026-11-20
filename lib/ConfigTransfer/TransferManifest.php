<?php

declare(strict_types=1);

/**
 * ExpenseCheck transfer manifest — every appconfig key and every exp_*
 * table classified exactly once (gate:
 * scripts/check-config-transfer-manifest.py; spec §10.7 + §12.6).
 *
 * Transfer scope (spec §12.6): catalog tables merge by natural key —
 * categories, rate catalog, cost centers, chains + steps, manager map,
 * delegates, fx rates. Claims, lines, journals, settlements, seats,
 * idempotency and logs NEVER transfer (PII + instance-bound state).
 */

namespace OCA\ExpenseCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — mirrored class: written only
			//    through AccessControlService::writeAccessPolicy (atomic,
			//    existence-checked) via TransferHooks::writeMirrored (§3.4) ──
			'access_restriction_enabled' => ['class' => 'mirrored', 'security' => true, 'section' => 'access'],
			'access_allowed_user_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'access_allowed_group_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'app_admin_user_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			// ── org + policy settings (§10.7 'plain' class) ──
			'org_currency' => ['class' => 'plain', 'section' => 'general'],
			'enable_mileage_claims' => ['class' => 'plain', 'section' => 'general'],
			'allow_route_evidence' => ['class' => 'plain', 'security' => true, 'section' => 'general'],
			'allow_self_approve' => ['class' => 'plain', 'security' => true, 'section' => 'access'],
			'attachment_max_mb' => ['class' => 'plain', 'section' => 'general'],
			'attachment_max_per_claim_mb' => ['class' => 'plain', 'section' => 'general'],
			'attachment_max_files' => ['class' => 'plain', 'section' => 'general'],
			'inbox_purge_days' => ['class' => 'plain', 'section' => 'general'],
			'retention_years' => ['class' => 'plain', 'section' => 'general'],
			'digest_cadence' => ['class' => 'plain', 'section' => 'general'],
			'export_async_rows' => ['class' => 'plain', 'section' => 'general'],
			'mc_coexistence_mode' => ['class' => 'plain', 'section' => 'general'],
			'license_offline_grace_h' => ['class' => 'plain', 'section' => 'license'],
			'journal_version' => ['class' => 'plain', 'section' => 'general'],
			// ── secrets — redacted inside the container, never plaintext ──
			'license_key' => ['class' => 'secret', 'section' => 'license'],
			// ── internal bookkeeping — never transferred ──
			'enabled' => 'internal',
			'installed_version' => 'internal',
			'uninstall_repair_pass' => 'internal',
			'digest_last_run' => 'internal',
			'export_job_state' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ catalog — admin-managed configuration (spec §12.6) ══
			'exp_categories' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			'exp_rate_entries' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['rate_type', 'code', 'effective_from']],
			'exp_fx_rates' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['currency', 'effective_from']],
			'exp_cost_centers' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code'],
				// self-FK resolves post-load (topo order cannot be
				// guaranteed inside one upsert pass).
				'fk_late' => ['parent_id' => 'exp_cost_centers'],
				// AZC team link is sibling-app state — instance-bound,
				// dropped to NULL on the target (NN-10 class).
				'env_columns' => ['azc_team_id']],
			'exp_chains' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name'],
				// category_id NULL = all categories; an unresolvable category
				// skips the chain + reports — never silently widens scope.
				'fk' => ['category_id' => 'exp_categories'],
				'depends_on' => ['exp_categories']],
			'exp_chain_steps' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['chain_id', 'step_no'],
				'fk' => ['chain_id' => 'exp_chains'],
				'depends_on' => ['exp_chains'],
				// step approver refs: NULL for line_manager; a ghost
				// uid/gid skips the step row + reports (spec §12.6).
				'uid_ref' => ['user_id'],
				'uid_ref_nullable' => ['user_id'],
				'gid_ref' => ['group_id']],
			'exp_manager_map' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id'],
				'uid_ref' => ['user_id'],
				// ghost managers filtered from the JSON list + reported;
				// the row survives (filtered ≠ skipped).
				'json_uid_list' => ['manager_uids']],
			'exp_delegates' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['manager_uid', 'delegate_uid', 'valid_from'],
				'uid_ref' => ['manager_uid', 'delegate_uid']],

			// ══ claim domain — PII + instance state, NEVER transferred ══
			// (spec §12.6 "business/user-data — never transfer": classed
			// internal so no strategy can ever put them on the wire.)
			'exp_claims' => ['class' => 'internal'],
			'exp_claim_lines' => ['class' => 'internal'],
			'exp_claim_allocations' => ['class' => 'internal'],
			'exp_attachments' => ['class' => 'internal'],
			'exp_step_decisions' => ['class' => 'internal'],
			'exp_claim_events' => ['class' => 'internal'],
			'exp_periods' => ['class' => 'internal'],
			'exp_settlement_batches' => ['class' => 'internal'],
			'exp_settlement_rows' => ['class' => 'internal'],
			'exp_claim_counters' => ['class' => 'internal'],
			'exp_mobile_seats' => ['class' => 'internal'],
			'exp_idempotency' => ['class' => 'internal'],

			// ══ logs — never transferred ══
			'exp_audit_log' => ['class' => 'log'],
			'exp_notification_log' => ['class' => 'log'],
		]);
	}
}
