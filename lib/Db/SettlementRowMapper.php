<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SettlementRow>
 */
class SettlementRowMapper extends QBMapper
{
	public const TABLE = 'exp_settlement_rows';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, SettlementRow::class);
	}

	/** @return list<SettlementRow> */
	public function findByBatch(int $batchId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('batch_id', $qb->createNamedParameter($batchId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

}
