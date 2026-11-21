<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\Server;

/**
 * ArbeitszeitCheck bridge (F20/F21, spec §12.2/§12.5) — capability-detected
 * access to the AZC org facade and trip surfaces.
 *
 * F21 is deliberately thin: the approved-trip *data* is fetched client-side
 * against AZC's own per-user API (the claimant's session authorizes it —
 * EXP never proxies other users' trip data through its own controllers).
 * What this bridge provides server-side: presence detection, the link
 * targets for chips, and the door-config read for the MH-22 setup assist.
 */
class AzcBridge
{
	public const APP_ID = 'arbeitszeitcheck';
	public const FACADE_CLASS = 'OCA\\ArbeitszeitCheck\\Public\\AzcOrgReadFacade';

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function available(): bool
	{
		try {
			return $this->appManager->isEnabledForUser(self::APP_ID);
		} catch (\Throwable) {
			return false;
		}
	}

	public function facadeAvailable(): bool
	{
		if (!$this->available()) {
			return false;
		}
		try {
			return class_exists(self::FACADE_CLASS);
		} catch (\Throwable) {
			return false;
		}
	}

	/** AZC per-user business-trips list endpoint — queried client-side (F21). */
	public function tripsListUrl(): string
	{
		return $this->urlGenerator->getBaseUrl() . '/apps/' . self::APP_ID . '/api/business-trips';
	}

	/** AZC trip detail page — the "open in ArbeitszeitCheck" chip target. */
	public function tripPageUrl(int $tripId): string
	{
		return $this->urlGenerator->getBaseUrl() . '/apps/' . self::APP_ID . '/business-trips/' . $tripId;
	}

	/**
	 * MH-22 setup assist — AZC access-door config for one-click prefill of
	 * the EXP door group/user lists. Read-only; the door stays per-app.
	 * Returns null when the facade or the method is absent (older AZC).
	 *
	 * @return array{mode:string,allowed_users:list<string>,allowed_groups:list<string>}|null
	 */
	public function doorConfig(): ?array
	{
		if (!$this->facadeAvailable()) {
			return null;
		}
		try {
			$facade = $this->orgFacade();
			if ($facade === null || !method_exists($facade, 'doorConfig')) {
				return null;
			}
			$cfg = (array)$facade->doorConfig();
			return [
				'mode' => (string)($cfg['mode'] ?? ''),
				'allowed_users' => array_values(array_map('strval', (array)($cfg['allowed_users'] ?? []))),
				'allowed_groups' => array_values(array_map('strval', (array)($cfg['allowed_groups'] ?? []))),
			];
		} catch (\Throwable) {
			return null;
		}
	}

	/** Resolved org facade, or null when registration is absent. */
	protected function orgFacade(): ?object
	{
		try {
			return Server::get(self::FACADE_CLASS);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * MH-23 — AZC team ids the user belongs to (for the azc_team_id
	 * cost-center soft link → default allocation). Empty when unavailable.
	 * @return list<int>
	 */
	public function teamIdsFor(string $uid): array
	{
		if (!$this->facadeAvailable() || $uid === '') {
			return [];
		}
		try {
			$facade = $this->orgFacade();
			if ($facade === null || !method_exists($facade, 'teamIdsFor')) {
				return [];
			}
			$out = [];
			foreach ((array)$facade->teamIdsFor($uid) as $id) {
				$id = (int)$id;
				if ($id > 0) {
					$out[] = $id;
				}
			}
			return array_values(array_unique($out));
		} catch (\Throwable) {
			return [];
		}
	}
}
