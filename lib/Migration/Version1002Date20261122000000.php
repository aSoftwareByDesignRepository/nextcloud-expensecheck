<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Make `exp_notification_log.dedupe_key` UNIQUE — dedupe claims must be
 * atomic, otherwise two concurrent senders (digest job vs notification
 * emitter, or two cron runners) can both pass the hasDedupeKey check and
 * double-send. Existing duplicate keys are collapsed to the earliest row
 * before the index is added.
 */
class Version1002Date20261122000000 extends SimpleMigrationStep {
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('exp_notification_log')) {
			$t = $schema->getTable('exp_notification_log');
			if (!$t->hasIndex('exp_nlog_dedup_uq')) {
				if ($t->hasIndex('exp_nlog_dedup_idx')) {
					$t->dropIndex('exp_nlog_dedup_idx');
				}
				$t->addUniqueIndex(['dedupe_key'], 'exp_nlog_dedup_uq');
			}
		}

		return $schema;
	}

	/**
	 * Collapse duplicate dedupe_key rows to the earliest id BEFORE the
	 * unique index is applied — changeSchema's ALTER would otherwise fail
	 * on installs that already logged a duplicate.
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('exp_notification_log')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('dedupe_key')
			->selectAlias($qb->func()->min('id'), 'keep_id')
			->from('exp_notification_log')
			->groupBy('dedupe_key')
			->having($qb->expr()->gt($qb->func()->count('id'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$keep = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$keep[(string)$row['dedupe_key']] = (int)$row['keep_id'];
		}
		$result->closeCursor();

		foreach ($keep as $key => $keepId) {
			$del = $this->db->getQueryBuilder();
			$del->delete('exp_notification_log')
				->where($del->expr()->eq('dedupe_key', $del->createNamedParameter($key)))
				->andWhere($del->expr()->neq('id', $del->createNamedParameter($keepId, IQueryBuilder::PARAM_INT)));
			$del->executeStatement();
		}
	}
}
