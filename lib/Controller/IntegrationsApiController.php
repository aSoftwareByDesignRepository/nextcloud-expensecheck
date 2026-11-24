<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Db\CostCenterMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\AzcBridge;
use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCA\ExpenseCheck\Service\ClaimLineService;
use OCA\ExpenseCheck\Service\ClaimSerializer;
use OCA\ExpenseCheck\Service\McBridge;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;

/**
 * Suite integration surface (spec §12.2/§12.4c):
 *
 * - summary            — sibling presence + coexistence mode for UI hints
 * - mcSegments         — F25 claimable private-vehicle trips (MC facade)
 * - claimMcSegment     — draft claim + mileage line bound to the segment
 * - mcChargebacks      — F26 counter-signed "you owe" notices
 * - claimMcChargeback  — claimant-confirmed deduction draft (never auto-posted)
 * - azcDoorConfig      — MH-22 setup assist (app admin only)
 *
 * Money/dates are re-derived from the sibling facade — the client picks the
 * target ref, never the values. A stale/absent sibling is a 404-equivalent
 * domain error, not an existence oracle.
 */
class IntegrationsApiController extends AApiController
{
	/** Surface gate: the mobility app is not yet published — all MC-driven UI
	 *  (new-claim import pickers, settings coexistence) keys on this flag and
	 *  stays hidden until the sibling ships. The /api/integrations/mc/*
	 *  endpoints stay functional for direct consumers. Flip when public. */
	private const MC_SURFACE_ENABLED = false;

	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly IAppManager $appManager,
		private readonly McBridge $mc,
		private readonly AzcBridge $azc,
		private readonly ClaimLifecycleService $lifecycle,
		private readonly ClaimLineService $lineService,
		private readonly ClaimSerializer $serializer,
		private readonly CostCenterMapper $costCenters,
		private readonly SettingsService $settings,
		private readonly IDBConnection $db,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function summary(): JSONResponse
	{
		$expenseSubmit = self::MC_SURFACE_ENABLED ? $this->mc->expenseSubmitEnabled() : null;
		return new JSONResponse([
			'ok' => true,
			'siblings' => [
				'arbeitszeitcheck' => [
					'installed' => $this->azc->available(),
					'facade' => $this->azc->facadeAvailable(),
					'trips_list_url' => $this->azc->available() ? $this->azc->tripsListUrl() : null,
				],
				'mobilitycheck' => [
					'installed' => self::MC_SURFACE_ENABLED && $this->mc->available(),
					'expense_submit_enabled' => $expenseSubmit,
				],
				'budgetcheck' => ['installed' => $this->isInstalled('budgetcheck')],
				'customercheck' => ['installed' => $this->isInstalled('customercheck')],
			],
			'coexistence' => [
				'mode' => $this->settings->get(SettingsService::KEY_MC_COEXISTENCE_MODE),
				'mileage_enabled' => $this->settings->getBool(SettingsService::KEY_ENABLE_MILEAGE),
			],
		]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function mcSegments(): JSONResponse
	{
		return new JSONResponse([
			'ok' => true,
			'segments' => $this->mc->segmentsFor($this->uid()),
			'claimed' => $this->claimedRefs('mobilitycheck'),
		]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function claimMcSegment(): JSONResponse
	{
		$b = $this->body();
		$segmentId = (int)($b['segment_id'] ?? 0);
		$segment = null;
		foreach ($this->mc->segmentsFor($this->uid()) as $s) {
			if ($s['segment_id'] === $segmentId) {
				$segment = $s;
				break;
			}
		}
		if ($segment === null || $segment['claimable'] !== true) {
			throw new DomainException('SEGMENT_NOT_FOUND', 'Segment not available for claiming.', 404);
		}
		if (!$this->settings->getBool(SettingsService::KEY_ENABLE_MILEAGE)) {
			throw new DomainException('MILEAGE_DISABLED', 'Mileage claims are disabled for this organisation.', 409);
		}
		$line = [
			'line_type' => 'mileage',
			'occurred_on' => $segment['date'],
			'category_id' => (int)($b['category_id'] ?? 0),
			'qty' => (string)$segment['distance_km'],
			'rate_code' => $this->str($b, 'rate_code', 64),
			'merchant' => 'the mobility app',
			'description' => trim($segment['from'] . ' → ' . $segment['to'], ' →')
				. ($segment['vehicle_label'] !== '' ? ' (' . $segment['vehicle_label'] . ')' : ''),
			'distance_source' => 'mc_segment',
			'source_app' => 'mobilitycheck',
			'source_ref' => (string)$segmentId,
			'reimbursable' => true,
		];
		$allocations = $this->resolveAllocations($b);
		return $this->claimLineIntoDraft('standard', $b, $line, $allocations,
			'Mileage — ' . $segment['date']);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function mcChargebacks(): JSONResponse
	{
		return new JSONResponse([
			'ok' => true,
			'chargebacks' => $this->mc->openChargebacksFor($this->uid()),
			'claimed' => $this->claimedRefs('mobilitycheck'),
		]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function claimMcChargeback(): JSONResponse
	{
		$b = $this->body();
		$cbId = (int)($b['chargeback_id'] ?? 0);
		$chargeback = null;
		foreach ($this->mc->openChargebacksFor($this->uid()) as $cb) {
			if ($cb['chargeback_id'] === $cbId) {
				$chargeback = $cb;
				break;
			}
		}
		if ($chargeback === null) {
			throw new DomainException('CHARGEBACK_NOT_FOUND', 'Charge-back not available.', 404);
		}
		$ref = 'chargeback:' . $cbId;
		// Dedupe is the uniq(source_app, source_ref) backstop — claim once.
		$line = [
			'line_type' => 'receipt',
			'occurred_on' => $chargeback['entry_date'] !== '' ? $chargeback['entry_date'] : date('Y-m-d'),
			'category_id' => (int)($b['category_id'] ?? 0),
			'amount' => number_format(-$chargeback['amount_minor'] / 100, 2, '.', ''),
			'merchant' => 'the mobility app',
			'description' => 'Charge-back #' . $cbId
				. ($chargeback['vehicle_label'] !== '' ? ' — ' . $chargeback['vehicle_label'] : '')
				. ($chargeback['notes'] !== '' ? ': ' . $chargeback['notes'] : ''),
			'source_app' => 'mobilitycheck',
			'source_ref' => $ref,
			'reimbursable' => true,
		];
		$allocations = $this->resolveAllocations($b);
		return $this->claimLineIntoDraft('deduction', $b, $line, $allocations,
			'the mobility app charge-back #' . $cbId);
	}

	/**
	 * MH-22 setup assist — the AZC access-door config, app-admin only.
	 * Read-only mirror; the EXP door itself stays per-app.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function azcDoorConfig(): JSONResponse
	{
		if (!$this->access->isAppAdmin($this->uid())) {
			throw new DomainException('NOT_FOUND', 'Not found.', 404);
		}
		return new JSONResponse([
			'ok' => true,
			'installed' => $this->azc->available(),
			'door' => $this->azc->doorConfig(),
		]);
	}

	/**
	 * Shared "create-or-append" path for sibling-sourced lines: when a
	 * claim_id is supplied the line is appended to that own draft; else a
	 * fresh draft claim of the required kind is created around it. One
	 * transaction — no orphan drafts, no half-claims.
	 *
	 * @param array<string,mixed> $line
	 * @param list<array<string,mixed>> $allocations
	 */
	private function claimLineIntoDraft(string $kind, array $b, array $line, array $allocations, string $defaultTitle): JSONResponse
	{
		$this->db->beginTransaction();
		try {
			$claimId = (int)($b['claim_id'] ?? 0);
			if ($claimId > 0) {
				$claim = $this->lifecycle->loadVisible($claimId, $this->uid());
				if ($claim->getUserId() !== $this->uid() || $claim->getKind() !== $kind) {
					throw new DomainException('CLAIM_NOT_FOUND', 'Claim not found.', 404);
				}
			} else {
				$claim = $this->lifecycle->createDraft(
					$this->uid(),
					$this->str($b, 'title', 191) !== '' ? $this->str($b, 'title', 191) : $defaultTitle,
					strtoupper($this->str($b, 'currency', 3)) ?: 'EUR',
					null,
					['kind' => $kind],
				);
			}
			$this->lineService->addLine($claim, $line, $allocations, $this->uid());
			$this->db->commit();
			$claim = $this->lifecycle->loadVisible($claim->getId(), $this->uid());
			return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim, $this->uid())], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Allocations for sibling-sourced lines: explicit client choice wins;
	 * otherwise MH-23 resolves the default cost center from the claimant's
	 * AZC team link (`exp_cost_centers.azc_team_id`).
	 *
	 * @return list<array<string,mixed>>
	 */
	private function resolveAllocations(array $b): array
	{
		if (isset($b['allocations']) && is_array($b['allocations'])) {
			return array_values($b['allocations']);
		}
		$ccId = (int)($b['cost_center_id'] ?? 0);
		if ($ccId <= 0) {
			$ccId = $this->defaultCostCenterFor($this->uid());
		}
		if ($ccId <= 0) {
			throw new ValidationException(['cost_center_id' => 'required']);
		}
		return [['cost_center_id' => $ccId, 'share_percent' => '100']];
	}

	/** MH-23 — first active cost center linked to one of the claimant's AZC teams. */
	private function defaultCostCenterFor(string $uid): int
	{
		foreach ($this->azc->teamIdsFor($uid) as $teamId) {
			foreach ($this->costCenters->findAll() as $cc) {
				if ($cc->getAzcTeamId() === $teamId && $cc->getActive() === 1 && $cc->getDeleted() === 0) {
					return $cc->getId();
				}
			}
		}
		return 0;
	}

	/** @return array<string,string> source_ref => claim_no for this user */
	private function claimedRefs(string $sourceApp): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('l.source_ref', 'c.claim_no')
			->from('exp_claim_lines', 'l')
			->innerJoin('l', 'exp_claims', 'c', $qb->expr()->eq('l.claim_id', 'c.id'))
			->where($qb->expr()->eq('l.source_app', $qb->createNamedParameter($sourceApp)))
			->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($this->uid())))
			->setMaxResults(5000);
		$out = [];
		foreach ($qb->executeQuery()->iterateAssociative() as $r) {
			$out[(string)$r['source_ref']] = (string)$r['claim_no'];
		}
		return $out;
	}

	private function isInstalled(string $appId): bool
	{
		try {
			return $this->appManager->isEnabledForUser($appId);
		} catch (\Throwable) {
			return false;
		}
	}
}
