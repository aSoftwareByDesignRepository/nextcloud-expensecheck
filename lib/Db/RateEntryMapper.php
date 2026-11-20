<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<RateEntry>
 */
class RateEntryMapper extends QBMapper
{
	public const TABLE = 'exp_rate_entries';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, RateEntry::class);
	}

	/** @return list<RateEntry> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('rate_type', 'ASC')->addOrderBy('code', 'ASC')->addOrderBy('effective_from', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Effective-dated resolution: newest active rate of (type, code) whose
	 * effective_from <= occurredOn. Returns null when none exists — the
	 * caller maps that to RATE_MISSING (fail-closed, never a guessed rate).
	 */
	public function findEffective(string $rateType, string $code, \DateTimeInterface $occurredOn): ?RateEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('rate_type', $qb->createNamedParameter($rateType)))
			->andWhere($qb->expr()->eq('code', $qb->createNamedParameter($code)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($occurredOn->format('Y-m-d'))))
			->orderBy('effective_from', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<RateEntry> */
	public function findVersions(string $rateType, string $code): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('rate_type', $qb->createNamedParameter($rateType)))
			->andWhere($qb->expr()->eq('code', $qb->createNamedParameter($code)))
			->orderBy('effective_from', 'DESC');
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
