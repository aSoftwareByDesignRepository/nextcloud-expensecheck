<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ClaimLine>
 */
class ClaimLineMapper extends QBMapper
{
	public const TABLE = 'exp_claim_lines';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, ClaimLine::class);
	}

	/** @return list<ClaimLine> */
	public function findByClaim(int $claimId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('claim_id', $qb->createNamedParameter($claimId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('sort', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteByClaim(int $claimId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('claim_id', $qb->createNamedParameter($claimId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** Duplicate-range probe for per-diem claims of one claimant (edge US-004 AC-2). @return list<ClaimLine> */
	public function findPerDiemOverlaps(string $uid, \DateTimeInterface $start, \DateTimeInterface $end, ?int $excludeClaimId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.*')->from($this->getTableName(), 'l')
			->innerJoin('l', 'exp_claims', 'c', $qb->expr()->eq('l.claim_id', 'c.id'))
			->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('l.line_type', $qb->createNamedParameter('per_diem')))
			->andWhere($qb->expr()->in('c.status', $qb->createNamedParameter(['draft','submitted','approved'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('l.occurred_on', $qb->createNamedParameter($end->format('Y-m-d'))))
			->andWhere($qb->expr()->gte('l.occurred_on', $qb->createNamedParameter($start->format('Y-m-d'))));
		return $this->findEntities($qb);
	}


	public function findById(int $id)
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}


	/** Open (submitted/approved/changes_requested) claims touching a date range. */
	public function countOpenClaimsInRange(\DateTimeInterface $from, \DateTimeInterface $to): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.claim_id')->from($this->getTableName(), 'l')
			->innerJoin('l', 'exp_claims', 'c', $qb->expr()->eq('l.claim_id', 'c.id'))
			->where($qb->expr()->in('c.status', $qb->createNamedParameter(['submitted','approved','changes_requested'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->gte('l.occurred_on', $qb->createNamedParameter($from->format('Y-m-d'))))
			->andWhere($qb->expr()->lte('l.occurred_on', $qb->createNamedParameter($to->format('Y-m-d'))))
			->groupBy('l.claim_id');
		$count = 0;
		$res = $qb->executeQuery();
		while ($res->fetch()) {
			$count++;
		}
		return $count;
	}

	/** Draft claim ids touching a date range (close report). @return list<int> */
	public function draftClaimIdsInRange(\DateTimeInterface $from, \DateTimeInterface $to): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.claim_id')->from($this->getTableName(), 'l')
			->innerJoin('l', 'exp_claims', 'c', $qb->expr()->eq('l.claim_id', 'c.id'))
			->where($qb->expr()->eq('c.status', $qb->createNamedParameter('draft')))
			->andWhere($qb->expr()->gte('l.occurred_on', $qb->createNamedParameter($from->format('Y-m-d'))))
			->andWhere($qb->expr()->lte('l.occurred_on', $qb->createNamedParameter($to->format('Y-m-d'))))
			->groupBy('l.claim_id');
		$res = $qb->executeQuery();
		$out = [];
		while ($r = $res->fetch()) {
			$out[] = (int)$r['claim_id'];
		}
		return $out;
	}

}
