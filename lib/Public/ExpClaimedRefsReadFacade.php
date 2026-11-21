<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Public;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Suite flange — claimed-source-ref reverse link (spec §12.5 MC chip).
 *
 * MobilityCheck renders a "claimed via ExpenseCheck <claim_no>" chip on
 * private-vehicle segment rows by asking which of its own refs an EXP line
 * already consumed. The reverse link is deliberately narrow: source_ref →
 * claim_no for one source app, restricted to one claimant — a segment can
 * only ever belong to the uid that filed it, so per-uid scoping leaks
 * nothing across users.
 *
 * Consumers probe `class_exists` + `FACADE_VERSION >= 1`, resolve via
 * `OCP\Server::get`, and must fail closed on absence.
 */
class ExpClaimedRefsReadFacade {
	public const FACADE_VERSION = 1;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<string,string> source_ref => claim_no for the given
	 * sibling app and claimant. Empty on any failure — a missing chip is
	 * honest, a wrong chip is not.
	 */
	public function claimedRefsFor(string $sourceApp, string $uid): array
	{
		$sourceApp = mb_substr(trim($sourceApp), 0, 32);
		if ($sourceApp === '' || $uid === '') {
			return [];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('l.source_ref', 'c.claim_no')
				->from('exp_claim_lines', 'l')
				->innerJoin('l', 'exp_claims', 'c', $qb->expr()->eq('l.claim_id', 'c.id'))
				->where($qb->expr()->eq('l.source_app', $qb->createNamedParameter($sourceApp)))
				->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($uid)))
				->setMaxResults(5000);
			$out = [];
			foreach ($qb->executeQuery()->iterateAssociative() as $r) {
				$out[(string)$r['source_ref']] = (string)$r['claim_no'];
			}
			return $out;
		} catch (\Throwable $e) {
			$this->logger->info('expensecheck claimed-refs facade failed', ['exception' => $e->getMessage()]);
			return [];
		}
	}
}
