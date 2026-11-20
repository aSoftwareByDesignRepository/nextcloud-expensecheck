<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck;

use OCA\ExpenseCheck\Service\LicenseService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;

/**
 * ExpenseCheck capabilities (OCS /ocs/v2.php/cloud/capabilities).
 *
 * Two consumers:
 *  - The EXP2 companion reads `expensecheck.mobile` + the companion floor
 *    and fails closed to app_outdated on `CONTRACT_VERSION_MISMATCH`
 *    (spec §13.1/13.6) when `expensecheck.companion.min` is absent or
 *    above the client's contract.
 *  - Sibling apps probe `check:expense-claims` to discover the F24
 *    draft-ingress endpoint (spec §12.5) — capability-detected, never
 *    probed by 404.
 */
class Capabilities implements ICapability
{
	/** Companion wire contract this server speaks. Bump on breaking change. */
	public const COMPANION_CONTRACT = 1;

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly SettingsService $settings,
		private readonly LicenseService $license,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getCapabilities(): array
	{
		$licensed = false;
		try {
			$licensed = $this->license->isMobilePlanActive();
		} catch (\Throwable) {
			$licensed = false;
		}

		return [
			'expensecheck' => [
				'version' => $this->appManager->getAppVersion('expensecheck'),
				'features' => [
					'expense-claims',
					'approval-chains',
					'settlement-export',
					'audit-journal',
					'receipts-inbox',
					'mileage-claims',
					'per-diem-claims',
				],
				// Suite discovery marker (F24): siblings create draft claim
				// lines via POST /api/ingress/drafts when this key is present.
				'check:expense-claims' => [
					'version' => 1,
					'ingressEndpoint' => '/api/ingress/drafts',
				],
				'mobile' => [
					'minAppVersion' => '1.0.0',
					'bootstrapEndpoint' => '/api/mobile/bootstrap',
					'licensed' => $licensed,
					'mileageEnabled' => $this->settings->getBool(SettingsService::KEY_ENABLE_MILEAGE),
					'routeEvidenceAllowed' => $this->settings->getBool(SettingsService::KEY_ALLOW_ROUTE_EVIDENCE),
				],
				// Companion API floor — clients fail closed when missing or
				// mismatched (never re-derive contract compatibility).
				'expensecheck.companion.min' => self::COMPANION_CONTRACT,
				'compliance' => [
					'gdpr' => true,
					'audit-journal' => true,
					'data-retention' => true,
					'append-only-events' => true,
				],
				'accessibility' => [
					'wcag-level' => 'AA',
					'screen-reader' => true,
					'keyboard-navigation' => true,
					'high-contrast' => true,
				],
			],
		];
	}
}
