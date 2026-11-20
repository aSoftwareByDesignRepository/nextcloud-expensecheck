<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ClaimAllocation>
 */
class ClaimAllocationMapper extends QBMapper
{
	public const TABLE = 'exp_claim_allocations';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, ClaimAllocation::class);
	}

	/** @return list<ClaimAllocation> */
	public function findByLine(int $lineId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('line_id', $qb->createNamedParameter($lineId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<ClaimAllocation> */
	public function findByClaim(int $claimId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.*')->from($this->getTableName(), 'a')
			->innerJoin('a', 'exp_claim_lines', 'l', $qb->expr()->eq('a.line_id', 'l.id'))
			->where($qb->expr()->eq('l.claim_id', $qb->createNamedParameter($claimId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function deleteByLine(int $lineId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('line_id', $qb->createNamedParameter($lineId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

}
