<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Public;

use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Suite flange F22 — billable-claims read + billed-mark facade.
 *
 * the invoicing app learns *which* approved claims are billable to a customer —
 * never the receipt contents. The row shape is deliberately minimal:
 * claim id, customer/project link, money total, currency, generic_label
 * (the claim number). No user id, no merchant, no lines.
 *
 * Consumers probe `class_exists` + `FACADE_VERSION >= 1`, resolve via
 * `OCP\Server::get`, and must fail closed on absence.
 */
class ExpBillableReadFacade {
	public const FACADE_VERSION = 1;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Approved or settled claims flagged billable (pc_customer_id set) and
	 * not yet marked billed. Optionally restricted to claims submitted on
	 * or after $sinceIso (Y-m-d).
	 *
	 * @return list<array{claim_id:int,pc_customer_id:int,pc_project_id:?int,total_minor:int,currency:string,generic_label:string}>
	 */
	public function listBillable(?string $since = null): array
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'claim_no', 'pc_customer_id', 'pc_project_id', 'total_minor', 'currency')
				->from('exp_claims')
				->where($qb->expr()->isNotNull('pc_customer_id'))
				->andWhere($qb->expr()->isNull('billed_at'))
				->andWhere($qb->expr()->in('status', $qb->createNamedParameter([
					ClaimLifecycleService::STATUS_APPROVED,
					ClaimLifecycleService::STATUS_SETTLED,
				], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
			if ($since !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) === 1) {
				$qb->andWhere($qb->expr()->gte('submitted_at', $qb->createNamedParameter($since . ' 00:00:00')));
			}
			$qb->orderBy('id', 'ASC');

			$out = [];
			foreach ($qb->executeQuery()->iterateAssociative() as $r) {
				$out[] = [
					'claim_id' => (int)$r['id'],
					'pc_customer_id' => (int)$r['pc_customer_id'],
					'pc_project_id' => $r['pc_project_id'] !== null ? (int)$r['pc_project_id'] : null,
					'total_minor' => (int)$r['total_minor'],
					'currency' => (string)$r['currency'],
					'generic_label' => (string)$r['claim_no'],
				];
			}
			return $out;
		} catch (\Throwable $e) {
			$this->logger->info('expensecheck billable facade failed', ['exception' => $e->getMessage()]);
			return [];
		}
	}

	/**
	 * Mark a claim as invoiced by a sibling (F22 write side). Idempotent —
	 * re-marking the same ref is a no-op; a different ref on an already
	 * billed claim is refused so double-billing surfaces loudly.
	 */
	public function markBilled(int $claimId, string $invoiceRef): bool
	{
		$invoiceRef = mb_substr(trim($invoiceRef), 0, 191);
		if ($claimId <= 0 || $invoiceRef === '') {
			return false;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('exp_claims')
				->set('billed_at', $qb->createNamedParameter(new \DateTimeImmutable(), 'datetime'))
				->set('billed_ref', $qb->createNamedParameter($invoiceRef))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($claimId)))
				->andWhere($qb->expr()->isNotNull('pc_customer_id'))
				->andWhere($qb->expr()->in('status', $qb->createNamedParameter([
					ClaimLifecycleService::STATUS_APPROVED,
					ClaimLifecycleService::STATUS_SETTLED,
				], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->orX(
					$qb->expr()->isNull('billed_at'),
					$qb->expr()->eq('billed_ref', $qb->createNamedParameter($invoiceRef)),
				));
			return $qb->executeStatement() > 0;
		} catch (\Throwable $e) {
			$this->logger->info('expensecheck markBilled failed', ['exception' => $e->getMessage()]);
			return false;
		}
	}
}
