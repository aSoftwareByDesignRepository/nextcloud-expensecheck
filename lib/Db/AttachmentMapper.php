<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Attachment>
 */
class AttachmentMapper extends QBMapper
{
	public const TABLE = 'exp_attachments';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Attachment::class);
	}

	/** @return list<Attachment> */
	public function findByClaim(int $claimId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('claim_id', $qb->createNamedParameter($claimId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<Attachment> */
	public function findByLine(int $lineId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('line_id', $qb->createNamedParameter($lineId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** Unfiled receipts-inbox rows owned by $uid. @return list<Attachment> */
	public function findInbox(string $uid): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('inbox', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uploaded_by', $qb->createNamedParameter($uid)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** Other claim attachments sharing a sha256 (cross-claim dup detection). @return list<Attachment> */
	public function findOtherClaimsByHash(string $sha256, int $excludeClaimId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('sha256', $qb->createNamedParameter($sha256)))
			->andWhere($qb->expr()->neq('claim_id', $qb->createNamedParameter($excludeClaimId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('claim_id'));
		return $this->findEntities($qb);
	}

	/** @return list<Attachment> */
	public function findInboxOlderThan(\DateTimeInterface $cutoff): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('inbox', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff->format('Y-m-d H:i:s'))));
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
