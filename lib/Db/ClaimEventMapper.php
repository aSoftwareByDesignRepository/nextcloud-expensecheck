<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ClaimEvent>
 *
 * Append-only journal rows (spec §11.5): this mapper intentionally exposes
 * insert + read + verify-support methods only. There is no update() or
 * delete() — the erasure path rewrites rows through tombstone(), the single
 * audited mutation the journal contract allows.
 */
class ClaimEventMapper extends QBMapper
{
	public const TABLE = 'exp_claim_events';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, ClaimEvent::class);
	}

	public function findHead(int $claimId): ?ClaimEvent
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('claim_id', $qb->createNamedParameter($claimId, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'DESC')
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/** @return list<ClaimEvent> */
	public function findAllForClaim(int $claimId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('claim_id', $qb->createNamedParameter($claimId, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Erasure rewrite (spec §11.5): payload → {"t":"erased","at":…},
	 * tombstoned=1, entry_hash = sha256("tombstoned" ‖ prev_hash).
	 * The chain stays verifiable — the row reports TOMBSTONED, not TAMPERED.
	 */
	public function tombstone(int $id, string $erasurePayloadJson, string $tombstoneHash): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('payload', $qb->createNamedParameter($erasurePayloadJson))
			->set('payload_hash', $qb->createNamedParameter(hash('sha256', $erasurePayloadJson)))
			->set('tombstoned', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->set('entry_hash', $qb->createNamedParameter($tombstoneHash))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
