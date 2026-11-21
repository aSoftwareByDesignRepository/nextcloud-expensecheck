<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * MH-15 / §11.6 — GDPR erasure (NC erase flow, never self-serve).
 *
 * uid columns are pseudonymised to `deleted-<hash8>` so financial rows stay
 * internally consistent without carrying the identity; claimant free-text
 * (merchant, description, justification, attendees, occasion, purpose,
 * decision_note, claim title) is scrubbed; attachment and route FILES are
 * deleted and their rows removed; journal rows are tombstoned (payload →
 * {"t":"erased","at":…}, entry_hash → sha256("tombstoned" ‖ prev_hash)) so
 * verify() reports TOMBSTONED, never TAMPERED. Amounts, status, period and
 * settlement refs are KEPT — they are the financial record.
 *
 * All in one transaction; the `erasure` chain event per claim makes the
 * hole itself auditable.
 */
class ErasureService {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ClaimMapper $claims,
		private readonly AttachmentMapper $attachments,
		private readonly AttachmentService $attachmentFiles,
		private readonly ExpChainService $chain,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	private static function pseudo(string $uid): string
	{
		return 'deleted-' . substr(hash('sha256', $uid), 0, 8);
	}

	/**
	 * Erase all personal data for $targetUid. Admin-invoked via the NC
	 * user-delete flow. Returns the number of scrubbed journal rows.
	 */
	public function eraseUid(string $targetUid, string $actorUid): int
	{
		$pseudo = self::pseudo($targetUid);
		$this->db->beginTransaction();
		try {
			// 1. The target's claim ids bound everything below.
			$sel = $this->db->getQueryBuilder();
			$sel->select('id')->from('exp_claims')
				->where($sel->expr()->eq('user_id', $sel->createNamedParameter($targetUid)));
			$claimIds = array_map('intval', $sel->executeQuery()->fetchFirstColumn());

			// 2. Attachment files + rows (claim-bound and inbox).
			$fileAtts = [];
			foreach ($this->attachments->findInbox($targetUid) as $a) {
				$fileAtts[] = $a;
			}
			foreach ($claimIds as $cid) {
				foreach ($this->attachments->findByClaim($cid) as $a) {
					$fileAtts[] = $a;
				}
			}
			foreach ($fileAtts as $a) {
				try {
					$this->attachmentFiles->delete($a, $targetUid);
				} catch (\Throwable) {
					// Deleted users have no home storage left — the file is
					// already gone; make sure the row is still removed.
					try {
						$this->attachments->delete($a);
					} catch (\Throwable) {
						$this->logger->info('expensecheck erasure: attachment row already removed', ['id' => $a->getId()]);
					}
				}
			}

			if ($claimIds !== []) {
				// 3. Line free-text + route files are claimant/third-party PII.
				$u = $this->db->getQueryBuilder();
				$u->update('exp_claim_lines')
					->set('merchant', $u->createNamedParameter(null))
					->set('description', $u->createNamedParameter(null))
					->set('justification', $u->createNamedParameter(null))
					->set('attendees', $u->createNamedParameter(null))
					->set('occasion', $u->createNamedParameter(null))
					->set('route_file_id', $u->createNamedParameter(null))
					->where($u->expr()->in('claim_id', $u->createNamedParameter($claimIds, IQueryBuilder::PARAM_INT_ARRAY)));
				$u->executeStatement();

				// 4. Claim identity + claimant-authored notes; money/status keep.
				//    title is NOT NULL — fall back to the claim_no so the row
				//    stays readable without claimant-authored text.
				$u = $this->db->getQueryBuilder();
				$u->update('exp_claims')
					->set('user_id', $u->createNamedParameter($pseudo))
					->set('title', 'claim_no')
					->set('purpose', $u->createNamedParameter(null))
					->set('decision_note', $u->createNamedParameter(null))
					->where($u->expr()->in('id', $u->createNamedParameter($claimIds, IQueryBuilder::PARAM_INT_ARRAY)));
				$u->executeStatement();

				// 5. Journal tombstone rewrite — one row at a time because
				//    entry_hash depends on each row's own prev_hash.
				$tombstoneCount = $this->tombstoneClaimEvents($claimIds);
				// The erasure itself is journaled last so it chains onto the
				// final tombstone hash and stays verifiable.
				foreach ($claimIds as $cid) {
					$this->chain->append($cid, 'erasure', $actorUid, null, null,
						['rows' => $tombstoneCount, 'uid_hash' => substr(hash('sha256', $targetUid), 0, 16)]);
				}
			} else {
				$tombstoneCount = 0;
			}

			// 6. Pseudonymise every other uid column the target appears in.
			foreach ([
				'exp_claims' => ['decided_by', 'settled_by'],
				'exp_step_decisions' => ['decided_by'],
				'exp_chain_steps' => ['user_id'],
				'exp_fx_rates' => ['created_by'],
				'exp_rate_entries' => ['created_by'],
				'exp_periods' => ['closed_by'],
				'exp_settlement_batches' => ['created_by'],
				'exp_manager_map' => ['user_id', 'created_by'],
				// The target acted on OTHER people's claims too — approver
				// events, delegate-uploaded attachments, seats they assigned,
				// delegations they created. Those rows are someone else's
				// financial record and must survive, but the uid must not.
				'exp_claim_events' => ['actor'],
				'exp_attachments' => ['uploaded_by'],
				'exp_mobile_seats' => ['assigned_by'],
				'exp_delegates' => ['created_by'],
			] as $table => $cols) {
				foreach ($cols as $col) {
					$u = $this->db->getQueryBuilder();
					$u->update($table)->set($col, $u->createNamedParameter($pseudo))
						->where($u->expr()->eq($col, $u->createNamedParameter($targetUid)));
					$u->executeStatement();
				}
			}
			// Approver notes on decisions the target wrote are their free text.
			$u = $this->db->getQueryBuilder();
			$u->update('exp_step_decisions')->set('note', $u->createNamedParameter(null))
				->where($u->expr()->eq('decided_by', $u->createNamedParameter($pseudo)));
			$u->executeStatement();
			$u = $this->db->getQueryBuilder();
			$u->update('exp_audit_log')
				->set('actor', $u->createNamedParameter($pseudo))
				->set('payload', $u->createNamedParameter(null))
				->where($u->expr()->eq('actor', $u->createNamedParameter($targetUid)));
			$u->executeStatement();

			// 7. manager_map manager_uids is a JSON list — scrub in PHP.
			$this->scrubManagerMap($targetUid);

			// 8. Operational rows that cannot exist without an owner — hard
			//    delete (delegation, seats, idempotency keys, notification log).
			foreach ([
				'exp_delegates' => ['manager_uid', 'delegate_uid'],
				'exp_mobile_seats' => ['user_id'],
				'exp_idempotency' => ['user_id'],
				'exp_notification_log' => ['recipient'],
			] as $table => $cols) {
				foreach ($cols as $col) {
					$d = $this->db->getQueryBuilder();
					$d->delete($table)
						->where($d->expr()->eq($col, $d->createNamedParameter($targetUid)));
					$d->executeStatement();
				}
			}

			$this->db->commit();
			return $tombstoneCount;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** @param list<int> $claimIds */
	private function tombstoneClaimEvents(array $claimIds): int
	{
		$at = $this->timeFactory->getDateTime()->format('Y-m-d\TH:i:s\Z');
		$sel = $this->db->getQueryBuilder();
		$sel->select('id', 'prev_hash')->from('exp_claim_events')
			->where($sel->expr()->in('claim_id', $sel->createNamedParameter($claimIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($sel->expr()->eq('tombstoned', $sel->createNamedParameter(0)));
		$rows = $sel->executeQuery()->fetchAll();
		foreach ($rows as $row) {
			$u = $this->db->getQueryBuilder();
			$u->update('exp_claim_events')
				->set('actor', $u->createNamedParameter(null))
				->set('payload', $u->createNamedParameter(json_encode(['t' => 'erased', 'at' => $at])))
				->set('tombstoned', $u->createNamedParameter(1))
				->set('entry_hash', $u->createNamedParameter(ExpChainService::tombstoneHashFor((string)$row['prev_hash'])))
				->where($u->expr()->eq('id', $u->createNamedParameter((int)$row['id'])));
			$u->executeStatement();
		}
		return count($rows);
	}

	private function scrubManagerMap(string $targetUid): void
	{
		$sel = $this->db->getQueryBuilder();
		$sel->select('id', 'manager_uids')->from('exp_manager_map')
			->where($sel->expr()->like('manager_uids', $sel->createNamedParameter('%' . $targetUid . '%')));
		foreach ($sel->executeQuery()->fetchAll() as $row) {
			$uids = array_values(array_filter(
				json_decode((string)$row['manager_uids'], true) ?: [],
				fn ($u) => $u !== $targetUid
			));
			$u = $this->db->getQueryBuilder();
			$u->update('exp_manager_map')
				->set('manager_uids', $u->createNamedParameter(json_encode($uids)))
				->where($u->expr()->eq('id', $u->createNamedParameter((int)$row['id'])));
			$u->executeStatement();
		}
	}
}
