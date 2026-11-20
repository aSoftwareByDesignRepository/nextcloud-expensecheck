<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<NotificationLogEntry>
 */
class NotificationLogMapper extends QBMapper
{
	public const TABLE = 'exp_notification_log';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, NotificationLogEntry::class);
	}

	public function hasDedupeKey(string $key): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from($this->getTableName())
			->where($qb->expr()->eq('dedupe_key', $qb->createNamedParameter($key)));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	public function purgeOlderThan(\DateTimeInterface $cutoff): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff->format('Y-m-d H:i:s'))));
		return $qb->executeStatement();
	}

}
