<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Db\CategoryMapper;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\CostCenterMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Spend insights (NH-28): month-scoped aggregates by claimant, cost center and
 * category plus anomaly outliers. Finance/auditor/admin only — uniform 404.
 * All money figures use claim `org_total_minor` (org-currency normalized);
 * category rows stay per-currency because lines carry no org-normalized total.
 */
class ReportsApiController extends AApiController
{
	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly ClaimMapper $claims,
		private readonly ClaimLineMapper $lines,
		private readonly CategoryMapper $categories,
		private readonly CostCenterMapper $costCenters,
		private readonly SettingsService $settings,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	private function assertVisible(): void
	{
		$uid = $this->uid();
		if (!$this->access->isFinance($uid)
			&& !$this->access->isAuditor($uid)
			&& !$this->access->isAppAdmin($uid)) {
			throw new ClaimNotFoundException(); // uniform 404 — no role oracle
		}
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function summary(): JSONResponse
	{
		$this->assertVisible();
		$month = (string)($this->request->getParam('month') ?? '');
		if ($month === '') {
			$month = (new \DateTimeImmutable('first day of this month'))->format('Y-m');
		}
		if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
			throw new ValidationException(['month' => 'invalid']);
		}
		$from = new \DateTimeImmutable($month . '-01');
		$to = $from->modify('first day of next month');

		$window = $this->claims->findSubmittedBetween($from, $to);
		$totals = ['claims' => 0, 'org_total_minor' => 0];
		$byClaimant = [];
		$byCostCenter = [];
		foreach ($window as $c) {
			$totals['claims']++;
			$totals['org_total_minor'] += $c->getOrgTotalMinor();
			$uid = $c->getUserId();
			$byClaimant[$uid]['uid'] = $uid;
			$byClaimant[$uid]['claims'] = ($byClaimant[$uid]['claims'] ?? 0) + 1;
			$byClaimant[$uid]['org_total_minor'] = ($byClaimant[$uid]['org_total_minor'] ?? 0) + $c->getOrgTotalMinor();
			$ccId = $c->getCostCenterId() ?? 0;
			$byCostCenter[$ccId]['id'] = $ccId ?: null;
			$byCostCenter[$ccId]['claims'] = ($byCostCenter[$ccId]['claims'] ?? 0) + 1;
			$byCostCenter[$ccId]['org_total_minor'] = ($byCostCenter[$ccId]['org_total_minor'] ?? 0) + $c->getOrgTotalMinor();
		}

		// Resolve cost-center display names once, not per row.
		$ccNames = [];
		foreach ($this->costCenters->findAll() as $cc) {
			$ccNames[$cc->getId()] = $cc->getCode() . ' — ' . $cc->getName();
		}
		foreach ($byCostCenter as &$row) {
			$row['name'] = $row['id'] === null ? null : ($ccNames[$row['id']] ?? ('#' . $row['id']));
		}
		unset($row);

		// Category rollups over the in-window claims' lines (per currency).
		$catNames = [];
		foreach ($this->categories->findAll() as $cat) {
			$catNames[$cat->getId()] = $cat->getCode() . ' — ' . $cat->getName();
		}
		$claimIds = [];
		$claimCurrency = [];
		foreach ($window as $c) {
			$claimIds[] = $c->getId();
			$claimCurrency[$c->getId()] = $c->getCurrency();
		}
		$byCategory = [];
		foreach ($this->lines->findByClaims($claimIds) as $l) {
			$cur = $claimCurrency[$l->getClaimId()] ?? '';
			$key = $l->getCategoryId() . '|' . $cur;
			$row = &$byCategory[$key];
			$row['id'] = $l->getCategoryId();
			$row['name'] = $catNames[$l->getCategoryId()] ?? ('#' . $l->getCategoryId());
			$row['currency'] = $cur;
			$row['lines'] = ($row['lines'] ?? 0) + 1;
			$row['amount_minor'] = ($row['amount_minor'] ?? 0) + $l->getAmountMinor();
		}

		// Anomalies: org totals above 3× the window median (NH-28 outliers).
		$amounts = array_map(fn ($c) => $c->getOrgTotalMinor(), $window);
		sort($amounts);
		$median = $amounts === [] ? 0 : $amounts[intdiv(count($amounts), 2)];
		$threshold = $median > 0 ? $median * 3 : PHP_INT_MAX;
		$anomalies = [];
		foreach ($window as $c) {
			if ($c->getOrgTotalMinor() > $threshold) {
				$anomalies[] = [
					'id' => $c->getId(),
					'claim_no' => $c->getClaimNo(),
					'uid' => $c->getUserId(),
					'org_total_minor' => $c->getOrgTotalMinor(),
					'median_minor' => $median,
				];
			}
		}

		$sortDesc = fn (array $rows, string $k) => array_values((function () use ($rows, $k) {
			usort($rows, fn ($a, $b) => $b[$k] <=> $a[$k]);
			return $rows;
		})());

		return new JSONResponse([
			'ok' => true,
			'month' => $month,
			'from' => $from->format('Y-m-d'),
			'to' => $to->modify('-1 day')->format('Y-m-d'),
			'totals' => $totals,
			'by_claimant' => $sortDesc(array_values($byClaimant), 'org_total_minor'),
			'by_cost_center' => $sortDesc(array_values($byCostCenter), 'org_total_minor'),
			'by_category' => $sortDesc(array_values($byCategory), 'amount_minor'),
			'anomalies' => $anomalies,
			'org_currency' => $this->settings->orgCurrency(),
		]);
	}
}
