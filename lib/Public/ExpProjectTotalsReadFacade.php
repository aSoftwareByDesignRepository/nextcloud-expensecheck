<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Public;

use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Suite flange F23 — project expense totals for ProjectCheck cost panels.
 *
 * Aggregate-only surface: a project id in, a minor-unit total per currency
 * out. Claim-level detail, claimant identity and receipt data never leave
 * this app. Consumers probe `class_exists` + `FACADE_VERSION >= 1`,
 * resolve via `OCP\Server::get`, and must fail closed on absence.
 */
class ExpProjectTotalsReadFacade {
	public const FACADE_VERSION = 1;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Approved + settled totals for one project in an optional ISO window
	 * ([from, to] inclusive, Y-m-d, matched on submitted_at).
	 *
	 * @return list<array{pc_project_id:int,period:string,approved_total_minor:int,currency:string}>
	 */
	public function totalsForProject(int $pcProjectId, ?string $from = null, ?string $to = null): array
	{
		if ($pcProjectId <= 0) {
			return [];
		}
		if (($from !== null && !self::isIsoDate($from)) || ($to !== null && !self::isIsoDate($to))) {
			return [];
		}
		if ($from !== null && $to !== null && $from > $to) {
			return [];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('currency')
				->selectAlias($qb->func()->sum('total_minor'), 'total')
				->from('exp_claims')
				->where($qb->expr()->eq('pc_project_id', $qb->createNamedParameter($pcProjectId)))
				->andWhere($qb->expr()->in('status', $qb->createNamedParameter([
					ClaimLifecycleService::STATUS_APPROVED,
					ClaimLifecycleService::STATUS_SETTLED,
				], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
			if ($from !== null) {
				$qb->andWhere($qb->expr()->gte('submitted_at', $qb->createNamedParameter($from . ' 00:00:00')));
			}
			if ($to !== null) {
				$qb->andWhere($qb->expr()->lte('submitted_at', $qb->createNamedParameter($to . ' 23:59:59')));
			}
			$qb->groupBy('currency');

			$period = ($from ?? '…') . '/' . ($to ?? '…');
			$out = [];
			foreach ($qb->executeQuery()->iterateAssociative() as $r) {
				$out[] = [
					'pc_project_id' => $pcProjectId,
					'period' => $period,
					'approved_total_minor' => (int)$r['total'],
					'currency' => (string)$r['currency'],
				];
			}
			return $out;
		} catch (\Throwable $e) {
			$this->logger->info('expensecheck project-totals facade failed', ['exception' => $e->getMessage()]);
			return [];
		}
	}

	private static function isIsoDate(string $d): bool
	{
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
	}
}
