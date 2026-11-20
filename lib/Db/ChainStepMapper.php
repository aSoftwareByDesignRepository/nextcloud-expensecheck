<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ChainStep>
 */
class ChainStepMapper extends QBMapper
{
	public const TABLE = 'exp_chain_steps';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, ChainStep::class);
	}

	/** @return list<ChainStep> */
	public function findByChain(int $chainId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('chain_id', $qb->createNamedParameter($chainId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('step_no', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteByChain(int $chainId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('chain_id', $qb->createNamedParameter($chainId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
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

}
