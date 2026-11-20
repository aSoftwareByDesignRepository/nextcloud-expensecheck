<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<AuditLogEntry>
 */
class AuditLogMapper extends QBMapper
{
	public const TABLE = 'exp_audit_log';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, AuditLogEntry::class);
	}

	/** @return list<AuditLogEntry> */
	public function findRecent(int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC')->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function purgeOlderThan(\DateTimeInterface $cutoff): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff->format('Y-m-d H:i:s'))));
		return $qb->executeStatement();
	}

}
