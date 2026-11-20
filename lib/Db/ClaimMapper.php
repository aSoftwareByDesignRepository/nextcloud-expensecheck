<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Claim>
 *
 * Visibility rule (spec §3.3 / NN-04): every fetch a non-privileged caller can
 * reach goes through a visibility-aware method that resolves "exists but not
 * visible" identically to "does not exist" — a uniform 404 with no existence
 * oracle. Callers pass a RoleScope describing what the uid may see.
 */
class ClaimMapper extends QBMapper
{
	public const TABLE = 'exp_claims';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, Claim::class);
	}

	public function findByIdRaw(int $id): ?Claim
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

	public function findByRequestKey(string $key): ?Claim
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('request_key', $qb->createNamedParameter($key)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Claim> */
	public function findByUser(string $uid, ?string $status = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->orderBy('id', 'DESC');
		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		return $this->findEntities($qb);
	}

	/** @return list<Claim> */
	public function findByStatus(string $status): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Finance/auditor listing with optional filters. Role-gated by the caller.
	 * @return list<Claim>
	 */
	public function findFiltered(?string $status, ?string $uid, ?int $periodId, int $limit = 500, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit)->setFirstResult($offset);
		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		if ($uid !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		}
		if ($periodId !== null) {
			$qb->andWhere($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * CAS update: UPDATE … WHERE id = :id AND version = :expected.
	 * Returns false when the row moved under us — caller maps to VERSION_CONFLICT.
	 */
	public function updateWithVersion(Claim $claim, int $expectedVersion): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('title', $qb->createNamedParameter($claim->getTitle()))
			->set('purpose', $qb->createNamedParameter($claim->getPurpose()))
			->set('status', $qb->createNamedParameter($claim->getStatus()))
			->set('currency', $qb->createNamedParameter($claim->getCurrency()))
			->set('total_minor', $qb->createNamedParameter($claim->getTotalMinor(), IQueryBuilder::PARAM_INT))
			->set('reimbursable_total_minor', $qb->createNamedParameter($claim->getReimbursableTotalMinor(), IQueryBuilder::PARAM_INT))
			->set('nonreimbursable_total_minor', $qb->createNamedParameter($claim->getNonreimbursableTotalMinor(), IQueryBuilder::PARAM_INT))
			->set('org_total_minor', $qb->createNamedParameter($claim->getOrgTotalMinor(), IQueryBuilder::PARAM_INT))
			->set('fx_rate', $qb->createNamedParameter($claim->getFxRate()))
			->set('chain_id', $qb->createNamedParameter($claim->getChainId(), IQueryBuilder::PARAM_INT))
			->set('chain_snapshot', $qb->createNamedParameter($claim->getChainSnapshot()))
			->set('current_step', $qb->createNamedParameter($claim->getCurrentStep(), IQueryBuilder::PARAM_INT))
			->set('cost_center_id', $qb->createNamedParameter($claim->getCostCenterId(), IQueryBuilder::PARAM_INT))
			->set('pc_customer_id', $qb->createNamedParameter($claim->getPcCustomerId(), IQueryBuilder::PARAM_INT))
			->set('pc_project_id', $qb->createNamedParameter($claim->getPcProjectId(), IQueryBuilder::PARAM_INT))
			->set('azc_trip_id', $qb->createNamedParameter($claim->getAzcTripId(), IQueryBuilder::PARAM_INT))
			->set('period_id', $qb->createNamedParameter($claim->getPeriodId(), IQueryBuilder::PARAM_INT))
			->set('submitted_at', $qb->createNamedParameter($claim->getSubmittedAt()?->format('Y-m-d H:i:s')))
			->set('decided_at', $qb->createNamedParameter($claim->getDecidedAt()?->format('Y-m-d H:i:s')))
			->set('decided_by', $qb->createNamedParameter($claim->getDecidedBy()))
			->set('decision_note', $qb->createNamedParameter($claim->getDecisionNote()))
			->set('settled_at', $qb->createNamedParameter($claim->getSettledAt()?->format('Y-m-d H:i:s')))
			->set('settled_by', $qb->createNamedParameter($claim->getSettledBy()))
			->set('settlement_ref', $qb->createNamedParameter($claim->getSettlementRef()))
			->set('batch_id', $qb->createNamedParameter($claim->getBatchId(), IQueryBuilder::PARAM_INT))
			->set('version', $qb->createNamedParameter($claim->getVersion(), IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($claim->getUpdatedAt()->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($claim->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($expectedVersion, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement() === 1;
	}
}
