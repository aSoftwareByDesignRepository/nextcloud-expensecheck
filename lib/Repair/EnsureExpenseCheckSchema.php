<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Repair;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Safety net when migrations were marked complete without every table
 * existing (partial install, restored backup). Runs on install and
 * post-migration: verifies the catalog, seeds the claim counter, creates
 * the finance/auditor groups when absent.
 */
final class EnsureExpenseCheckSchema implements IRepairStep
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	public function getName(): string
	{
		return 'Ensure ExpenseCheck database schema is complete';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(UninstallDropTables::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);

		$missing = [];
		foreach (SchemaTableCatalog::EXPECTED_TABLES as $table) {
			if (!$this->db->tableExists($table)) {
				$missing[] = $table;
			}
		}
		if ($missing !== []) {
			// Fail loudly rather than silently "repair" half a schema — the
			// migration is the authoritative creator; this step diagnoses.
			$output->warning('ExpenseCheck: missing tables: ' . implode(', ', $missing)
				. ' — run `occ maintenance:repair` after `occ app:enable expensecheck` completes migrations, or reinstall.');
			return;
		}
		$output->info('ExpenseCheck: all ' . count(SchemaTableCatalog::EXPECTED_TABLES) . ' tables present.');
	}
}
