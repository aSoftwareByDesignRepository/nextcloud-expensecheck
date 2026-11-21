<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Public;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Suite flange — CUC company-360 count chip (spec §12.3).
 *
 * CustomerCheck renders "N expense claims" on its company 360 surface by
 * counting EXP claims linked via `pc_customer_id` — counts only, never
 * amounts, merchants, or claimant names (the chip tells you claims exist
 * for this customer; opening them still requires EXP-side visibility).
 *
 * Consumers probe `class_exists` + `FACADE_VERSION >= 1`, resolve via
 * `OCP\Server::get`, and must fail closed on absence.
 */
class ExpCustomerClaimsReadFacade {
	public const FACADE_VERSION = 1;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Counts of customer-linked claims, split by pipeline stage.
	 * @return array{claims:int,open:int,approved_or_settled:int}
	 */
	public function countsFor(int $pcCustomerId): array
	{
		if ($pcCustomerId <= 0) {
			return ['claims' => 0, 'open' => 0, 'approved_or_settled' => 0];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('status')
				->from('exp_claims')
				->where($qb->expr()->eq('pc_customer_id', $qb->createNamedParameter($pcCustomerId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->setMaxResults(10000);
			$total = 0;
			$approved = 0;
			foreach ($qb->executeQuery()->iterateAssociative() as $r) {
				$total++;
				if (in_array((string)$r['status'], ['approved', 'settled'], true)) {
					$approved++;
				}
			}
			return [
				'claims' => $total,
				'open' => max(0, $total - $approved),
				'approved_or_settled' => $approved,
			];
		} catch (\Throwable $e) {
			$this->logger->info('expensecheck customer-claims facade failed', ['exception' => $e->getMessage()]);
			return ['claims' => 0, 'open' => 0, 'approved_or_settled' => 0];
		}
	}
}
