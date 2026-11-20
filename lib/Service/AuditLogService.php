<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Admin/config audit trail (exp_audit_log) — separate from the per-claim
 * hash chain. Records WHO changed WHAT configuration and when; payload is a
 * digest, never secrets or full PII dumps.
 */
class AuditLogService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/** @param array<string,mixed> $payload */
	public function record(string $actor, string $action, array $payload = []): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('exp_audit_log')->values([
			'actor' => $qb->createNamedParameter(mb_substr($actor, 0, 64)),
			'action' => $qb->createNamedParameter(mb_substr($action, 0, 64)),
			'payload' => $qb->createNamedParameter($payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE)),
			'created_at' => $qb->createNamedParameter($this->timeFactory->getDateTime()->format('Y-m-d H:i:s')),
		]);
		$qb->executeStatement();
	}

	/** @return list<array<string,mixed>> newest first */
	public function list(int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('exp_audit_log')->orderBy('id', 'DESC')->setMaxResults($limit);
		$res = $qb->executeQuery();
		$out = [];
		while ($r = $res->fetch()) {
			$out[] = $r;
		}
		return $out;
	}
}
