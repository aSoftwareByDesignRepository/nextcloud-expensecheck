<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCP\App\IAppManager;
use OCP\Server;

/**
 * MobilityCheck bridge (F25/F26, spec §12.4c) — capability-detected access
 * to the MC public facades. Every call degrades to "sibling absent": a
 * missing app, an older MC without the facade class, or a contract
 * violation all surface as empty/unavailable, never as an exception into
 * the claim path (S4 degrade).
 */
class McBridge
{
	public const APP_ID = 'mobilitycheck';
	public const SEGMENT_FACADE = 'OCA\\MobilityCheck\\Public\\McExpenseSegmentReadFacade';
	public const CHARGEBACK_FACADE = 'OCA\\MobilityCheck\\Public\\McChargebackReadFacade';

	public function __construct(
		private readonly IAppManager $appManager,
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

	/** Whether MC still runs its own private-car submit path (Mode A). */
	public function expenseSubmitEnabled(): ?bool
	{
		if (!$this->available()) {
			return null;
		}
		try {
			$facade = $this->facade(self::SEGMENT_FACADE);
			if ($facade !== null && method_exists($facade, 'expenseSubmitEnabled')) {
				return (bool)$facade->expenseSubmitEnabled();
			}
		} catch (\Throwable) {
		}
		return null;
	}

	/**
	 * F25 — confirmed private-vehicle segments the claimant may settle
	 * through EXP. Each row: {segment_id, uid, date, from, to, distance_km,
	 * vehicle_label, claimable}. `claimable=false` rows render as context
	 * ("settled in MobilityCheck"), never as an action.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function segmentsFor(string $uid): array
	{
		if (!$this->available() || $uid === '') {
			return [];
		}
		try {
			$facade = $this->facade(self::SEGMENT_FACADE);
			if ($facade === null) {
				return [];
			}
			$rows = $facade->segmentsFor($uid);
			if (!is_array($rows)) {
				return [];
			}
			$out = [];
			foreach ($rows as $r) {
				$r = (array)$r;
				$id = (int)($r['segment_id'] ?? 0);
				$km = (float)($r['distance_km'] ?? 0);
				if ($id <= 0 || $km <= 0 || (string)($r['date'] ?? '') === '') {
					continue; // contract violation — drop, don't propagate
				}
				$out[] = [
					'segment_id' => $id,
					'date' => (string)$r['date'],
					'from' => (string)($r['from'] ?? ''),
					'to' => (string)($r['to'] ?? ''),
					'distance_km' => $km,
					'vehicle_label' => (string)($r['vehicle_label'] ?? ''),
					'claimable' => (bool)($r['claimable'] ?? false),
				];
			}
			return $out;
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * F26 — counter-signed MC charge-backs the employee still owes.
	 * Each row: {chargeback_id, amount_minor, entry_date, notes, vehicle_label}.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function openChargebacksFor(string $uid): array
	{
		if (!$this->available() || $uid === '') {
			return [];
		}
		try {
			$facade = $this->facade(self::CHARGEBACK_FACADE);
			if ($facade === null) {
				return [];
			}
			$rows = $facade->openFor($uid);
			if (!is_array($rows)) {
				return [];
			}
			$out = [];
			foreach ($rows as $r) {
				$r = (array)$r;
				$id = (int)($r['chargeback_id'] ?? 0);
				$amount = (int)($r['amount_minor'] ?? 0);
				if ($id <= 0 || $amount <= 0) {
					continue;
				}
				$out[] = [
					'chargeback_id' => $id,
					'amount_minor' => $amount,
					'entry_date' => (string)($r['entry_date'] ?? ''),
					'notes' => (string)($r['notes'] ?? ''),
					'vehicle_label' => (string)($r['vehicle_label'] ?? ''),
				];
			}
			return $out;
		} catch (\Throwable) {
			return [];
		}
	}

	/** Resolved facade instance, or null when the class/registration is absent. */
	protected function facade(string $class): ?object
	{
		try {
			if (!class_exists($class)) {
				return null;
			}
			return Server::get($class);
		} catch (\Throwable) {
			return null;
		}
	}
}
