<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Period>
 */
class PeriodMapper extends QBMapper
{
	public const TABLE = 'exp_periods';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Period::class);
	}

	/** @return list<Period> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('start_on', 'DESC');
		return $this->findEntities($qb);
	}

	public function findOpenContaining(\DateTimeInterface $date): ?Period
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->lte('start_on', $qb->createNamedParameter($date->format('Y-m-d'))))
			->andWhere($qb->expr()->gte('end_on', $qb->createNamedParameter($date->format('Y-m-d'))))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findClosedContaining(\DateTimeInterface $date): ?Period
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('closed')))
			->andWhere($qb->expr()->lte('start_on', $qb->createNamedParameter($date->format('Y-m-d'))))
			->andWhere($qb->expr()->gte('end_on', $qb->createNamedParameter($date->format('Y-m-d'))))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
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


	public function isDateClosed(\DateTimeInterface $date): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('closed')))
			->andWhere($qb->expr()->lte('start_on', $qb->createNamedParameter($date->format('Y-m-d'))))
			->andWhere($qb->expr()->gte('end_on', $qb->createNamedParameter($date->format('Y-m-d'))))
			->setMaxResults(1);
		return $qb->executeQuery()->fetch() !== false;
	}

}
