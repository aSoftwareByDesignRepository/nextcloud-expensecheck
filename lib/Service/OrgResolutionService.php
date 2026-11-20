<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\DelegateMapper;
use OCA\ExpenseCheck\Db\ManagerMapMapper;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\Server;

/**
 * Line-manager resolution (spec §2.1/MH-05a/F20):
 *
 *   1. AZC facade `OCA\ArbeitszeitCheck\Public\AzcOrgReadFacade` via
 *      OCP\Server::get — capability-detected; absent class or AZC → fallback.
 *   2. `exp_manager_map` — admin-maintained uid → ordered manager list.
 *   3. Null → caller fails closed to the admin-review queue (never auto-approve).
 *
 * Cycle safety: resolution walks at most MAX_DEPTH hops; a cycle is detected
 * and returns the empty set (edge 24).
 */
class OrgResolutionService
{
	private const MAX_DEPTH = 8;
	public const FACADE_CLASS = 'OCA\\ArbeitszeitCheck\\Public\\AzcOrgReadFacade';

	public function __construct(
		private readonly ManagerMapMapper $managerMap,
		private readonly IAppManager $appManager,
		private readonly IUserManager $userManager,
	) {
	}

	public function azcFacadeAvailable(): bool
	{
		if (!$this->appManager->isEnabledForUser('arbeitszeitcheck')) {
			return false;
		}
		try {
			return class_exists(self::FACADE_CLASS);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Resolve the claimant's line manager uid list, escalation order.
	 * @return list<string> empty = unroutable → admin-review
	 */
	public function managersFor(string $uid): array
	{
		if ($uid === '') {
			return [];
		}
		if ($this->azcFacadeAvailable()) {
			try {
				$facade = Server::get(self::FACADE_CLASS);
				$managers = $facade->managersFor($uid);
				$out = array_values(array_filter(array_map('strval', (array)$managers), fn (string $m): bool => $m !== ''));
				if ($out !== []) {
					return $out;
				}
				// AZC present but resolved nobody — local map may still cover.
			} catch (\Throwable) {
				// Facade contract violation → degrade to the local map.
			}
		}
		return $this->localManagersFor($uid);
	}

	/**
	 * exp_manager_map lookup with cycle detection (edge 24): A→B→A stops and
	 * escalates to admin-review instead of looping forever.
	 * @return list<string>
	 */
	public function localManagersFor(string $uid): array
	{
		$seen = [$uid => true];
		$current = $uid;
		for ($i = 0; $i < self::MAX_DEPTH; $i++) {
			$map = $this->managerMap->findByUser($current);
			if ($map === null) {
				return [];
			}
			$managers = json_decode($map->getManagerUids(), true);
			if (!is_array($managers) || $managers === []) {
				return [];
			}
			$managers = array_values(array_filter(array_map('strval', $managers), fn (string $m): bool => $m !== ''));
			foreach ($managers as $m) {
				if (isset($seen[$m])) {
					// Cycle — fail closed.
					return [];
				}
				$seen[$m] = true;
			}
			return $managers; // First hop is the resolved set; order = escalation order.
		}
		return [];
	}

	/**
	 * Substitutes (delegates) for a resolved manager: local exp_delegates
	 * first; F20 `substitutesFor` when AZC exposes it (MH-20).
	 * @return list<string>
	 */
	public function substitutesFor(string $managerUid, \DateTimeInterface $today, DelegateMapper $delegates): array
	{
		$out = [];
		foreach ($delegates->findAll() as $d) {
			if ($d->getManagerUid() !== $managerUid || $d->getRevokedAt() !== null) {
				continue;
			}
			if ($d->getValidFrom() <= $today && $d->getValidTo() >= $today) {
				$out[] = $d->getDelegateUid();
			}
		}
		if ($this->azcFacadeAvailable()) {
			try {
				$facade = Server::get(self::FACADE_CLASS);
				if (method_exists($facade, 'substitutesFor')) {
					foreach ((array)$facade->substitutesFor($managerUid) as $uid) {
						$out[] = (string)$uid;
					}
				}
			} catch (\Throwable) {
			}
		}
		return array_values(array_unique(array_filter($out, fn (string $u): bool => $u !== '')));
	}
}
