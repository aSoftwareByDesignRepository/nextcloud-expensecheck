<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<FxRate>
 */
class FxRateMapper extends QBMapper
{
	public const TABLE = 'exp_fx_rates';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, FxRate::class);
	}

	public function findById(int $id): ?FxRate
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<FxRate> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('currency', 'ASC')->addOrderBy('effective_from', 'DESC');
		return $this->findEntities($qb);
	}

	public function findEffective(string $currency, \DateTimeInterface $onDate): ?FxRate
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('currency', $qb->createNamedParameter(strtoupper($currency))))
			->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($onDate->format('Y-m-d'))))
			->orderBy('effective_from', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

}
