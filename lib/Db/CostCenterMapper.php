<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<CostCenter>
 */
class CostCenterMapper extends QBMapper
{
	public const TABLE = 'exp_cost_centers';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, CostCenter::class);
	}

	/** @return list<CostCenter> */
	public function findAll(bool $includeDeleted = false): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('code', 'ASC');
		if (!$includeDeleted) {
			$qb->where($qb->expr()->eq('deleted', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		}
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

}
