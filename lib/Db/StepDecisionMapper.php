<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<StepDecision>
 */
class StepDecisionMapper extends QBMapper
{
	public const TABLE = 'exp_step_decisions';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, StepDecision::class);
	}

	public function findForStep(int $claimId, int $stepNo): ?StepDecision
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('claim_id', $qb->createNamedParameter($claimId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('step_no', $qb->createNamedParameter($stepNo, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<StepDecision> */
	public function findByClaim(int $claimId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('claim_id', $qb->createNamedParameter($claimId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('step_no', 'ASC');
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


	public function deleteByClaim(int $claimId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('claim_id', $qb->createNamedParameter($claimId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

}
