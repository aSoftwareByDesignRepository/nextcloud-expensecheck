<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * ExpenseCheck schema v1 — spec §10.1.
 *
 * Conventions: no DB-level foreign keys (logical refs only), all money BIGINT
 * minor units, booleans stored as '1'/'0' strings via smallint, every
 * identifier ≤ 27 chars (Oracle headroom), idempotent hasTable guards.
 */
class Version1000Date20261120000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('exp_claims')) {
			$t = $schema->createTable('exp_claims');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('claim_no', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('title', Types::STRING, ['length' => 191, 'notnull' => true]);
			$t->addColumn('purpose', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['length' => 24, 'notnull' => true, 'default' => 'draft']);
			$t->addColumn('currency', Types::STRING, ['length' => 3, 'notnull' => true, 'default' => 'EUR']);
			$t->addColumn('total_minor', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('reimbursable_total_minor', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('nonreimbursable_total_minor', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('org_total_minor', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('fx_rate', Types::DECIMAL, ['notnull' => true, 'precision' => 12, 'scale' => 6, 'default' => 1]);
			$t->addColumn('chain_id', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('chain_snapshot', Types::TEXT, ['notnull' => false]);
			$t->addColumn('current_step', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('cost_center_id', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('pc_customer_id', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('pc_project_id', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('azc_trip_id', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('period_id', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('submitted_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('decided_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('decided_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('decision_note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('settled_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('settled_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('settlement_ref', Types::STRING, ['length' => 191, 'notnull' => false]);
			$t->addColumn('batch_id', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('version', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('request_key', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->setPrimaryKey(['id'], 'exp_claims_pk');
			$t->addUniqueIndex(['claim_no'], 'exp_claims_no_uidx');
			$t->addUniqueIndex(['request_key'], 'exp_claims_rkey_uidx');
			$t->addIndex(['user_id', 'status'], 'exp_claims_ustat_idx');
			$t->addIndex(['status'], 'exp_claims_stat_idx');
			$t->addIndex(['batch_id'], 'exp_claims_batch_idx');
			$t->addIndex(['period_id'], 'exp_claims_period_idx');
		}

		if (!$schema->hasTable('exp_claim_lines')) {
			$t = $schema->createTable('exp_claim_lines');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('claim_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('line_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('occurred_on', Types::DATE, ['notnull' => true]);
			$t->addColumn('category_id', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('merchant', Types::STRING, ['length' => 191, 'notnull' => false]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('amount_minor', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('tax_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('tax_rate', Types::DECIMAL, ['notnull' => false, 'precision' => 5, 'scale' => 2]);
			$t->addColumn('foreign_amount_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('foreign_currency', Types::STRING, ['length' => 3, 'notnull' => false]);
			$t->addColumn('foreign_fx', Types::DECIMAL, ['notnull' => false, 'precision' => 12, 'scale' => 6]);
			$t->addColumn('distance_km', Types::DECIMAL, ['notnull' => false, 'precision' => 8, 'scale' => 2]);
			$t->addColumn('days', Types::DECIMAL, ['notnull' => false, 'precision' => 5, 'scale' => 2]);
			$t->addColumn('quantity', Types::DECIMAL, ['notnull' => false, 'precision' => 8, 'scale' => 2]);
			$t->addColumn('rate_id', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('rate_code', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('rate_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('rate_name', Types::STRING, ['length' => 191, 'notnull' => false]);
			$t->addColumn('justification', Types::TEXT, ['notnull' => false]);
			$t->addColumn('reimbursable', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('distance_source', Types::STRING, ['length' => 16, 'notnull' => false]);
			$t->addColumn('route_file_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('source_app', Types::STRING, ['length' => 32, 'notnull' => false]);
			$t->addColumn('source_ref', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('attendees', Types::TEXT, ['notnull' => false]);
			$t->addColumn('occasion', Types::STRING, ['length' => 191, 'notnull' => false]);
			$t->addColumn('tip_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('policy_flags', Types::TEXT, ['notnull' => false]);
			$t->addColumn('sort', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'exp_clines_pk');
			$t->addIndex(['claim_id'], 'exp_clines_claim_idx');
			$t->addIndex(['category_id'], 'exp_clines_cat_idx');
			$t->addUniqueIndex(['source_app', 'source_ref'], 'exp_clines_src_uidx');
		}

		if (!$schema->hasTable('exp_claim_allocations')) {
			$t = $schema->createTable('exp_claim_allocations');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('line_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('cost_center_id', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('share_percent', Types::DECIMAL, ['notnull' => false, 'precision' => 5, 'scale' => 2]);
			$t->addColumn('amount_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('pc_project_id', Types::INTEGER, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'exp_calloc_pk');
			$t->addIndex(['line_id'], 'exp_calloc_line_idx');
			$t->addIndex(['cost_center_id'], 'exp_calloc_cc_idx');
		}

		if (!$schema->hasTable('exp_attachments')) {
			$t = $schema->createTable('exp_attachments');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('claim_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('line_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('inbox', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 191, 'notnull' => true]);
			$t->addColumn('mime', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('sha256', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('uploaded_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'exp_att_pk');
			$t->addIndex(['claim_id'], 'exp_att_claim_idx');
			$t->addIndex(['line_id'], 'exp_att_line_idx');
			$t->addIndex(['inbox', 'uploaded_by'], 'exp_att_inbox_idx');
			$t->addIndex(['sha256'], 'exp_att_sha_idx');
		}

		if (!$schema->hasTable('exp_categories')) {
			$t = $schema->createTable('exp_categories');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 191, 'notnull' => true]);
			$t->addColumn('receipt_required', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('justification_threshold_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('justification_min_length', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('default_amount_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('requires_attendees', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('max_amount_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('max_is_hard', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('allow_lines', Types::STRING, ['length' => 64, 'notnull' => true, 'default' => 'receipt,mileage,per_diem,flat']);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('sort', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('deleted', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'exp_cat_pk');
			$t->addUniqueIndex(['code'], 'exp_cat_code_uidx');
		}

		if (!$schema->hasTable('exp_cost_centers')) {
			$t = $schema->createTable('exp_cost_centers');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 32, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 191, 'notnull' => true]);
			$t->addColumn('parent_id', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('azc_team_id', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('budget_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('currency', Types::STRING, ['length' => 3, 'notnull' => false]);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('deleted', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'exp_cc_pk');
			$t->addUniqueIndex(['code'], 'exp_cc_code_uidx');
			$t->addIndex(['parent_id'], 'exp_cc_parent_idx');
		}

		if (!$schema->hasTable('exp_rate_entries')) {
			$t = $schema->createTable('exp_rate_entries');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('rate_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$t->addColumn('code', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 191, 'notnull' => true]);
			$t->addColumn('currency', Types::STRING, ['length' => 3, 'notnull' => true]);
			$t->addColumn('rate_minor', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('effective_from', Types::DATE, ['notnull' => true]);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'exp_rate_pk');
			$t->addUniqueIndex(['rate_type', 'code', 'effective_from'], 'exp_rate_ver_uidx');
			$t->addIndex(['rate_type', 'code', 'active'], 'exp_rate_lkup_idx');
		}

		if (!$schema->hasTable('exp_chains')) {
			$t = $schema->createTable('exp_chains');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 191, 'notnull' => true]);
			$t->addColumn('active', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('min_total_minor', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('category_id', Types::INTEGER, ['notnull' => false]);
			$t->addColumn('sort', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'exp_chain_pk');
			$t->addIndex(['active', 'sort'], 'exp_chain_act_idx');
		}

		if (!$schema->hasTable('exp_chain_steps')) {
			$t = $schema->createTable('exp_chain_steps');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('chain_id', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('step_no', Types::SMALLINT, ['notnull' => true]);
			$t->addColumn('step_type', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'group']);
			$t->addColumn('group_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->setPrimaryKey(['id'], 'exp_cstep_pk');
			$t->addIndex(['chain_id', 'step_no'], 'exp_cstep_chain_idx');
		}

		if (!$schema->hasTable('exp_fx_rates')) {
			$t = $schema->createTable('exp_fx_rates');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('currency', Types::STRING, ['length' => 3, 'notnull' => true]);
			$t->addColumn('rate', Types::DECIMAL, ['notnull' => true, 'precision' => 12, 'scale' => 6]);
			$t->addColumn('effective_from', Types::DATE, ['notnull' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'exp_fx_pk');
			$t->addUniqueIndex(['currency', 'effective_from'], 'exp_fx_ver_uidx');
		}

		if (!$schema->hasTable('exp_manager_map')) {
			$t = $schema->createTable('exp_manager_map');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('manager_uids', Types::TEXT, ['notnull' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'exp_mmap_pk');
			$t->addUniqueIndex(['user_id'], 'exp_mmap_uidx');
		}

		if (!$schema->hasTable('exp_step_decisions')) {
			$t = $schema->createTable('exp_step_decisions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('claim_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('step_no', Types::SMALLINT, ['notnull' => true]);
			$t->addColumn('decided_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('decision', Types::STRING, ['length' => 24, 'notnull' => true]);
			$t->addColumn('note', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'exp_sdec_pk');
			$t->addUniqueIndex(['claim_id', 'step_no'], 'exp_sdec_uidx');
		}

		if (!$schema->hasTable('exp_claim_events')) {
			$t = $schema->createTable('exp_claim_events');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('claim_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('seq', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('actor', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('event', Types::STRING, ['length' => 48, 'notnull' => true]);
			$t->addColumn('from_status', Types::STRING, ['length' => 24, 'notnull' => false]);
			$t->addColumn('to_status', Types::STRING, ['length' => 24, 'notnull' => false]);
			$t->addColumn('payload', Types::TEXT, ['notnull' => false]);
			$t->addColumn('payload_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('prev_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('entry_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('tombstoned', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'exp_event_pk');
			$t->addUniqueIndex(['claim_id', 'seq'], 'exp_event_seq_uidx');
			$t->addIndex(['claim_id'], 'exp_event_claim_idx');
		}

		if (!$schema->hasTable('exp_delegates')) {
			$t = $schema->createTable('exp_delegates');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('manager_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('delegate_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('valid_from', Types::DATE, ['notnull' => true]);
			$t->addColumn('valid_to', Types::DATE, ['notnull' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('revoked_at', Types::DATETIME, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'exp_del_pk');
			$t->addIndex(['manager_uid'], 'exp_del_mgr_idx');
			$t->addIndex(['delegate_uid'], 'exp_del_del_idx');
		}

		if (!$schema->hasTable('exp_periods')) {
			$t = $schema->createTable('exp_periods');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', Types::STRING, ['length' => 191, 'notnull' => true]);
			$t->addColumn('start_on', Types::DATE, ['notnull' => true]);
			$t->addColumn('end_on', Types::DATE, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'open']);
			$t->addColumn('closed_by', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('closed_at', Types::DATETIME, ['notnull' => false]);
			$t->addColumn('reopen_reason', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'exp_period_pk');
			$t->addIndex(['status'], 'exp_period_stat_idx');
		}

		if (!$schema->hasTable('exp_settlement_batches')) {
			$t = $schema->createTable('exp_settlement_batches');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->addColumn('format', Types::STRING, ['length' => 8, 'notnull' => true, 'default' => 'csv']);
			$t->addColumn('row_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('file_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('period_id', Types::INTEGER, ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'exp_sbatch_pk');
		}

		if (!$schema->hasTable('exp_settlement_rows')) {
			$t = $schema->createTable('exp_settlement_rows');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('batch_id', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('claim_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('paid_ref', Types::STRING, ['length' => 191, 'notnull' => true]);
			$t->addColumn('paid_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'exp_srow_pk');
			$t->addIndex(['batch_id'], 'exp_srow_batch_idx');
			$t->addIndex(['claim_id'], 'exp_srow_claim_idx');
		}

		if (!$schema->hasTable('exp_mobile_seats')) {
			$t = $schema->createTable('exp_mobile_seats');
			$t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('assigned_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('assigned_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'exp_seat_pk');
			$t->addUniqueIndex(['user_id'], 'exp_seat_uidx');
		}

		if (!$schema->hasTable('exp_idempotency')) {
			$t = $schema->createTable('exp_idempotency');
			$t->addColumn('key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('claim_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['key'], 'exp_idem_pk');
			$t->addIndex(['created_at'], 'exp_idem_age_idx');
		}

		if (!$schema->hasTable('exp_audit_log')) {
			$t = $schema->createTable('exp_audit_log');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('actor', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('action', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('payload', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'exp_audit_pk');
			$t->addIndex(['created_at'], 'exp_audit_age_idx');
		}

		if (!$schema->hasTable('exp_notification_log')) {
			$t = $schema->createTable('exp_notification_log');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('dedupe_key', Types::STRING, ['length' => 191, 'notnull' => true]);
			$t->addColumn('event', Types::STRING, ['length' => 48, 'notnull' => true]);
			$t->addColumn('claim_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('recipient', Types::STRING, ['length' => 64, 'notnull' => true]);
			$t->addColumn('channel', Types::STRING, ['length' => 8, 'notnull' => true]);
			$t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'exp_nlog_pk');
			$t->addIndex(['dedupe_key'], 'exp_nlog_dedup_idx');
			$t->addIndex(['created_at'], 'exp_nlog_age_idx');
		}

		// Per-year claim-number sequence (EXP-YYYY-NNNNN); gaps allowed, collisions never.
		if (!$schema->hasTable('exp_claim_counters')) {
			$t = $schema->createTable('exp_claim_counters');
			$t->addColumn('year', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('next_seq', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$t->setPrimaryKey(['year'], 'exp_ctr_pk');
		}

		return $schema;
	}
}
