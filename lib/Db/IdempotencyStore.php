<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * exp_idempotency — submit dedupe (spec §10.5). String PK, so raw QB instead
 * of QBMapper. `key` is a client-generated token per form-open; the same key
 * with the same claim returns the original response, with a different payload
 * → IDEMPOTENCY_MISMATCH.
 */
class IdempotencyStore
{
	public const TABLE = 'exp_idempotency';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	/** @return array{key:string,user_id:string,claim_id:?int,created_at:string}|null */
	public function find(string $key): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('key', $qb->createNamedParameter($key)));
		$row = $qb->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	/**
	 * Insert-then-read: on duplicate-key the existing row is returned so a
	 * retried submit resolves to the original claim rather than 500ing.
	 * @return array{key:string,user_id:string,claim_id:?int,created_at:string}
	 */
	public function insertOrGet(string $key, string $uid, ?int $claimId): array
	{
		$existing = $this->find($key);
		if ($existing !== null) {
			return $existing;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'key' => $qb->createNamedParameter($key),
				'user_id' => $qb->createNamedParameter($uid),
				'claim_id' => $qb->createNamedParameter($claimId, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter((new \DateTimeImmutable())->format('Y-m-d H:i:s')),
			]);
		try {
			$qb->executeStatement();
		} catch (\OCP\DB\Exception) {
			// Lost the insert race — the winner's row is the answer.
		}
		return $this->find($key) ?? ['key' => $key, 'user_id' => $uid, 'claim_id' => $claimId, 'created_at' => ''];
	}

	public function linkClaim(string $key, int $claimId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('claim_id', $qb->createNamedParameter($claimId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('key', $qb->createNamedParameter($key)));
		$qb->executeStatement();
	}

	public function purgeOlderThan(\DateTimeInterface $cutoff): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff->format('Y-m-d H:i:s'))));
		return $qb->executeStatement();
	}
}
