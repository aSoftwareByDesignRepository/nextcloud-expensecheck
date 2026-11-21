<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Controller;

use OCA\ExpenseCheck\Controller\ReportsApiController;
use OCA\ExpenseCheck\Db\CategoryMapper;
use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimLine;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\CostCenter;
use OCA\ExpenseCheck\Db\CostCenterMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * NH-28 spend-insights: role gate (uniform 404), month validation, aggregate
 * correctness and anomaly detection. Aggregates use org_total_minor; category
 * rows stay per-currency since lines carry no org-normalized total.
 */
class ReportsApiControllerTest extends TestCase {
	private IRequest $request;
	private AccessControlService $access;
	private ClaimMapper $claims;
	private ClaimLineMapper $lines;
	private CategoryMapper $categories;
	private CostCenterMapper $costCenters;
	private SettingsService $settings;
	private ReportsApiController $ctrl;
	private ?string $month = null;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(fn ($k) => $k === 'month' ? $this->month : null);
		$this->access = $this->createMock(AccessControlService::class);
		$this->access->method('currentUserId')->willReturn('fin1');
		$this->claims = $this->createMock(ClaimMapper::class);
		$this->lines = $this->createMock(ClaimLineMapper::class);
		$this->categories = $this->createMock(CategoryMapper::class);
		$this->costCenters = $this->createMock(CostCenterMapper::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('orgCurrency')->willReturn('EUR');
		$this->ctrl = new ReportsApiController(
			$this->request, $this->access, $this->claims, $this->lines,
			$this->categories, $this->costCenters, $this->settings);
	}

	private function finance(): void {
		$this->access->method('isFinance')->willReturn(true);
	}

	private function claim(int $id, string $uid, int $orgMinor, ?int $ccId): Claim {
		$c = new Claim();
		$c->setId($id);
		$c->setUserId($uid);
		$c->setClaimNo('EXP-2026-000' . $id);
		$c->setCurrency('EUR');
		$c->setOrgTotalMinor($orgMinor);
		$c->setCostCenterId($ccId);
		return $c;
	}

	public function testNonFinanceGetsUniform404(): void {
		$this->expectException(ClaimNotFoundException::class);
		$this->ctrl->summary();
	}

	public function testBadMonthRejected(): void {
		$this->finance();
		$this->month = '2026-13';
		$this->expectException(ValidationException::class);
		$this->ctrl->summary();
	}

	public function testAggregatesAndAnomalies(): void {
		$this->finance();
		$this->month = '2026-11';
		$claims = [
			$this->claim(1, 'u-a', 10000, 7),
			$this->claim(2, 'u-a', 10000, 7),
			$this->claim(3, 'u-b', 10000, null),
			$this->claim(4, 'u-b', 90000, 7), // outlier: > 3× median(10000)
		];
		$this->claims->method('findSubmittedBetween')->willReturn($claims);
		$cc = new CostCenter();
		$cc->setId(7); $cc->setCode('OPS'); $cc->setName('Operations');
		$this->costCenters->method('findAll')->willReturn([$cc]);
		$this->categories->method('findAll')->willReturn([]);
		$line = new ClaimLine();
		$line->setClaimId(4); $line->setCategoryId(9); $line->setAmountMinor(90000);
		$this->lines->method('findByClaims')->willReturn([$line]);

		$res = $this->ctrl->summary()->getData();

		$this->assertSame('2026-11', $res['month']);
		$this->assertSame('2026-11-30', $res['to']);
		$this->assertSame(['claims' => 4, 'org_total_minor' => 120000], $res['totals']);
		$this->assertSame('u-b', $res['by_claimant'][0]['uid']);
		$this->assertSame(100000, $res['by_claimant'][0]['org_total_minor']);
		$this->assertSame('OPS — Operations', $res['by_cost_center'][0]['name']);
		$this->assertSame(110000, $res['by_cost_center'][0]['org_total_minor']);
		$this->assertSame(9, $res['by_category'][0]['id']);
		$this->assertSame('EUR', $res['by_category'][0]['currency']);
		$this->assertSame(90000, $res['by_category'][0]['amount_minor']);
		$this->assertCount(1, $res['anomalies']);
		$this->assertSame(4, $res['anomalies'][0]['id']);
		$this->assertSame('EUR', $res['org_currency']);
	}

	public function testEmptyWindowReportsZeroesNotAnomalies(): void {
		$this->finance();
		$this->month = '2026-02';
		$this->claims->method('findSubmittedBetween')->willReturn([]);
		$this->costCenters->method('findAll')->willReturn([]);
		$this->categories->method('findAll')->willReturn([]);
		$this->lines->method('findByClaims')->willReturn([]);

		$res = $this->ctrl->summary()->getData();
		$this->assertSame(['claims' => 0, 'org_total_minor' => 0], $res['totals']);
		$this->assertSame([], $res['anomalies']);
		$this->assertSame([], $res['by_claimant']);
	}

	public function testDefaultMonthIsCurrent(): void {
		$this->finance();
		$this->claims->method('findSubmittedBetween')->willReturn([]);
		$this->costCenters->method('findAll')->willReturn([]);
		$this->categories->method('findAll')->willReturn([]);
		$this->lines->method('findByClaims')->willReturn([]);
		$res = $this->ctrl->summary()->getData();
		$this->assertSame((new \DateTimeImmutable('first day of this month'))->format('Y-m'), $res['month']);
	}
}
