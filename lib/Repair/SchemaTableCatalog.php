<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after expensecheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=expensecheck
 */
namespace OCA\ExpenseCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
		'exp_attachments',
		'exp_audit_log',
		'exp_categories',
		'exp_chain_steps',
		'exp_chains',
		'exp_claim_allocations',
		'exp_claim_counters',
		'exp_claim_events',
		'exp_claim_lines',
		'exp_claims',
		'exp_cost_centers',
		'exp_delegates',
		'exp_fx_rates',
		'exp_idempotency',
		'exp_manager_map',
		'exp_mobile_seats',
		'exp_notification_log',
		'exp_periods',
		'exp_rate_entries',
		'exp_settlement_batches',
		'exp_settlement_rows',
		'exp_step_decisions',
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [

	];
}
