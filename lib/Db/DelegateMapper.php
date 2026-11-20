<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Delegate>
 */
class DelegateMapper extends QBMapper
{
	public const TABLE = 'exp_delegates';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Delegate::class);
	}

	/** @return list<Delegate> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('manager_uid', 'ASC')->addOrderBy('valid_from', 'ASC');
		return $this->findEntities($qb);
	}

	/** Active delegations where $uid may act on behalf of someone. @return list<Delegate> */
	public function findActiveForDelegate(string $uid, \DateTimeInterface $today): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('delegate_uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($today->format('Y-m-d'))))
			->andWhere($qb->expr()->gte('valid_to', $qb->createNamedParameter($today->format('Y-m-d'))));
		return $this->findEntities($qb);
	}

	/**
	 * Active delegations granted BY any of the given managers — batch
	 * inverse of findActiveForDelegate (digest fan-out, no N+1).
	 * @param list<string> $managerUids @return list<Delegate>
	 */
	public function findActiveForManagers(array $managerUids, \DateTimeInterface $today): array
	{
		if ($managerUids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('manager_uid', $qb->createNamedParameter($managerUids, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNull('revoked_at'))
			->andWhere($qb->expr()->lte('valid_from', $qb->createNamedParameter($today->format('Y-m-d'))))
			->andWhere($qb->expr()->gte('valid_to', $qb->createNamedParameter($today->format('Y-m-d'))));
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
