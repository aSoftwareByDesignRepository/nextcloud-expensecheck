<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<MobileSeat>
 */
class MobileSeatMapper extends QBMapper
{
	public const TABLE = 'exp_mobile_seats';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, MobileSeat::class);
	}

	public function findByUid(string $uid): ?MobileSeat
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<MobileSeat> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('assigned_at', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function countAll(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from($this->getTableName());
		return (int)$qb->executeQuery()->fetchOne();
	}

}
