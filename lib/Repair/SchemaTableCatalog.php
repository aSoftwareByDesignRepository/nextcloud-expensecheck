<?php

declare(strict_types=1);

/**
 * Tables that must exist after expensecheck's migrations ran. Ensure*Schema
 * verifies this set; the uninstall drop list mirrors it (no orphan tables).
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
}
