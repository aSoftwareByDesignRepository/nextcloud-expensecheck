<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * EXP-YYYY-NNNNN claim numbers (spec §10.1): per-year counter row under a
 * named lock — gaps allowed (a rolled-back claim burns its number), a
 * collision never happens.
 */
class ClaimNumberService
{
	private const LOCK = 'expensecheck/claim_no';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILockingProvider $locking,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	public function next(): string
	{
		try {
			$this->locking->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			// Serialize rather than fail — the counter flip is microseconds.
			usleep(50000);
			$this->locking->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
		try {
			$year = (int)$this->timeFactory->getDateTime()->format('Y');
			$qb = $this->db->getQueryBuilder();
			$qb->select('next_seq')->from('exp_claim_counters')
				->where($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
			$row = $qb->executeQuery()->fetch();
			if ($row === false) {
				$seq = 1;
				$ins = $this->db->getQueryBuilder();
				$ins->insert('exp_claim_counters')->values([
					'year' => $ins->createNamedParameter($year, IQueryBuilder::PARAM_INT),
					'next_seq' => $ins->createNamedParameter(2, IQueryBuilder::PARAM_INT),
				]);
				try {
					$ins->executeStatement();
				} catch (\OCP\DB\Exception) {
					// Raced insert — re-read under the same lock.
					$row = $qb->executeQuery()->fetch();
					$seq = (int)$row['next_seq'];
					$this->bump($year, $seq + 1);
				}
			} else {
				$seq = (int)$row['next_seq'];
				$this->bump($year, $seq + 1);
			}
			return sprintf('EXP-%d-%05d', $year, $seq);
		} finally {
			$this->locking->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function bump(int $year, int $next): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update('exp_claim_counters')
			->set('next_seq', $qb->createNamedParameter($next, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
