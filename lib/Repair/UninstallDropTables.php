<?php

declare(strict_types=1);

/**
 * Drops every table the expensecheck app has ever created, its migration
 * rows, and app config.
 *
 * NC runs this step on disable AND on remove. Disable (incl. auto-disable
 * during a server upgrade) must preserve data; only an explicit second
 * invocation (the remove pass) drops. The two-pass guard uses
 * REPAIR_PASS_KEY: first run sets it and preserves everything; a second
 * run with the flag set performs the drop. EnsureExpenseCheckSchema clears
 * the flag on any successful (re)enable so a later disable starts fresh.
 *
 * Uses physical-name DROP TABLE IF EXISTS so IDBConnection injection works
 * on all supported platforms; MySQL disables FK checks for the sweep.
 */

namespace OCA\ExpenseCheck\Repair;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class UninstallDropTables implements IRepairStep
{
	public const APP_ID = 'expensecheck';
	public const REPAIR_PASS_KEY = 'uninstall_repair_pass';

	public const TABLES = SchemaTableCatalog::EXPECTED_TABLES;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Remove ExpenseCheck tables and app data';
	}

	public function run(IOutput $output): void
	{
		if ($this->config->getAppValue(self::APP_ID, self::REPAIR_PASS_KEY, '') !== 'remove') {
			// First pass = disable. Preserve; arm the second pass.
			$this->config->setAppValue(self::APP_ID, self::REPAIR_PASS_KEY, 'remove');
			$output->info('expensecheck: preserving data on disable. Tables, migration history, and settings are kept until the app is fully removed.');
			return;
		}
		$this->dropAllTablesAndMetadata($output);
	}

	private function dropAllTablesAndMetadata(IOutput $output): void
	{
		$provider = $this->db->getDatabaseProvider();
		if ($provider === IDBConnection::PLATFORM_MYSQL) {
			$this->db->executeStatement('SET FOREIGN_KEY_CHECKS=0');
		}
		$dropped = 0;
		foreach (self::TABLES as $table) {
			if ($this->dropLogicalTableIfExists($table)) {
				$dropped++;
			}
		}
		if ($provider === IDBConnection::PLATFORM_MYSQL) {
			$this->db->executeStatement('SET FOREIGN_KEY_CHECKS=1');
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter(self::APP_ID)));
		$migrationsRemoved = $qb->executeStatement();

		$this->config->deleteAppValues(self::APP_ID);

		$output->info(sprintf(
			'expensecheck: dropped %d of %d table(s); removed %d migration row(s) and app config.',
			$dropped, count(self::TABLES), $migrationsRemoved
		));
	}

	private function dropLogicalTableIfExists(string $logicalTable): bool
	{
		if (!$this->db->tableExists($logicalTable)) {
			return false;
		}
		$prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
		$physical = $prefix . $logicalTable;
		$provider = $this->db->getDatabaseProvider();
		match (true) {
			$provider === IDBConnection::PLATFORM_MYSQL =>
				$this->db->executeStatement(sprintf('DROP TABLE IF EXISTS `%s`', $physical)),
			$provider === IDBConnection::PLATFORM_POSTGRES =>
				$this->db->executeStatement(sprintf('DROP TABLE IF EXISTS "%s" CASCADE', $physical)),
			$provider === IDBConnection::PLATFORM_ORACLE =>
				$this->db->executeStatement(sprintf('DROP TABLE %s CASCADE CONSTRAINTS', $physical)),
			default =>
				$this->db->executeStatement(sprintf('DROP TABLE IF EXISTS "%s"', $physical)),
		};
		return true;
	}
}
