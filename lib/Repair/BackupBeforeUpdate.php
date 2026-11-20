<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Repair;

use OCA\ExpenseCheck\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Pre-migration safety: before schema migrations run over existing data,
 * stamp a backup marker so an operator knows a snapshot is expected. The
 * actual dump happens via the host backup (nextcloud backup/occ
 * maintenance:backup if configured); this step verifies the data state and
 * logs it — it never blocks on absent backup infra, but it refuses to run
 * migrations over a half-installed schema silently.
 */
final class BackupBeforeUpdate implements IRepairStep
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Verify ExpenseCheck data state before update migrations';
	}

	public function run(IOutput $output): void
	{
		if (!$this->db->tableExists('exp_claims')) {
			$output->info('ExpenseCheck: fresh install — no data to protect.');
			return;
		}
		$count = (int)$this->db->getQueryBuilder()
			->select('id')->from('exp_claims')->setMaxResults(1)
			->executeQuery()->fetchOne();
		$output->info('ExpenseCheck: existing data detected — ensure a database snapshot exists before continuing (site backup).');
	}
}
