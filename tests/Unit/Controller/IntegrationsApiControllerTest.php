<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Controller;

use OCA\ExpenseCheck\Controller\IntegrationsApiController;
use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimLine;
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
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * F21/F25/F26 + MH-22 surface: the sibling pick must re-derive money/dates
 * from the facade at claim time — the client can never name its own km or
 * amount. Fail-closed on absent sibling data (uniform-404 shape).
 */
class IntegrationsApiControllerTest extends TestCase {
	private IRequest $request;
	private AccessControlService $access;
	private McBridge $mc;
	private AzcBridge $azc;
	private ClaimLifecycleService $lifecycle;
	private ClaimLineService $lineService;
	private ClaimSerializer $serializer;
	private CostCenterMapper $costCenters;
	private SettingsService $settings;
	private IDBConnection $db;
	private IAppManager $appManager;
	private IntegrationsApiController $ctrl;
	private array $body = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn () => $this->body);
		$this->access = $this->createMock(AccessControlService::class);
		$this->access->method('currentUserId')->willReturn('u1');
		$this->appManager = $this->createMock(IAppManager::class);
		$this->mc = $this->createMock(McBridge::class);
		$this->azc = $this->createMock(AzcBridge::class);
		$this->lifecycle = $this->createMock(ClaimLifecycleService::class);
		$this->lineService = $this->createMock(ClaimLineService::class);
		$this->serializer = $this->createMock(ClaimSerializer::class);
		$this->costCenters = $this->createMock(CostCenterMapper::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->ctrl = new IntegrationsApiController(
			$this->request, $this->access, $this->appManager, $this->mc, $this->azc,
			$this->lifecycle, $this->lineService, $this->serializer,
			$this->costCenters, $this->settings, $this->db,
		);
	}

	private function segment(array $over = []): array {
		return array_merge([
			'segment_id' => 77, 'uid' => 'u1', 'date' => '2026-12-01',
			'from' => 'Office', 'to' => 'Client', 'distance_km' => 42.5,
			'vehicle_label' => 'VW Golf', 'claimable' => true,
		], $over);
	}

	private function draft(): Claim {
		$c = new Claim();
		$c->setId(7);
		$c->setUserId('u1');
		$c->setKind('standard');
		return $c;
	}

	// ── F25 segment claim ────────────────────────────────────────────────

	public function testClaimUnknownSegmentIs404(): void {
		$this->body = ['segment_id' => 999];
		$this->mc->method('segmentsFor')->willReturn([$this->segment()]);
		try {
			$this->ctrl->claimMcSegment();
			$this->fail('expected SEGMENT_NOT_FOUND');
		} catch (DomainException $e) {
			$this->assertSame('SEGMENT_NOT_FOUND', $e->getErrorCode());
			$this->assertSame(404, $e->getHttpStatus());
		}
	}

	public function testNonClaimableSegmentIs404NotPrivilegeHint(): void {
		// Mode A: MC still settles — the row exists but is not claimable.
		// Uniform-404 keeps "which segments exist" out of oracle reach.
		$this->body = ['segment_id' => 77];
		$this->mc->method('segmentsFor')->willReturn([$this->segment(['claimable' => false])]);
		$this->expectException(DomainException::class);
		$this->ctrl->claimMcSegment();
	}

	public function testClaimSegmentBlockedWhenMileageDisabled(): void {
		$this->body = ['segment_id' => 77];
		$this->mc->method('segmentsFor')->willReturn([$this->segment()]);
		$this->settings->method('getBool')
			->with(SettingsService::KEY_ENABLE_MILEAGE)->willReturn(false);
		try {
			$this->ctrl->claimMcSegment();
			$this->fail('expected MILEAGE_DISABLED');
		} catch (DomainException $e) {
			$this->assertSame('MILEAGE_DISABLED', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testClaimSegmentCreatesDraftWithServerDerivedValues(): void {
		$this->body = ['segment_id' => 77, 'cost_center_id' => 5, 'title' => 'My trip'];
		$this->mc->method('segmentsFor')->willReturn([$this->segment()]);
		$this->settings->method('getBool')->willReturn(true);
		$claim = $this->draft();
		$this->lifecycle->method('createDraft')->willReturn($claim);
		$this->lifecycle->method('loadVisible')->willReturn($claim);
		$this->serializer->method('detail')->willReturn(['id' => 7]);

		$captured = null;
		$this->lineService->method('addLine')->willReturnCallback(
			function ($c, array $line) use (&$captured) {
				$captured = $line;
				return new ClaimLine();
			});

		$res = $this->ctrl->claimMcSegment();
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		// The line is built from facade data — client can only pick the ref.
		$this->assertSame('mileage', $captured['line_type']);
		$this->assertSame('2026-12-01', $captured['occurred_on']);
		$this->assertSame('42.5', $captured['qty']);
		$this->assertSame('mobilitycheck', $captured['source_app']);
		$this->assertSame('77', $captured['source_ref']);
		$this->assertSame('mc_segment', $captured['distance_source']);
	}

	public function testClaimSegmentWithoutCostCenterIsFieldError(): void {
		$this->body = ['segment_id' => 77];
		$this->mc->method('segmentsFor')->willReturn([$this->segment()]);
		$this->settings->method('getBool')->willReturn(true);
		$this->azc->method('teamIdsFor')->willReturn([]); // no MH-23 link
		try {
			$this->ctrl->claimMcSegment();
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['cost_center_id'] ?? '');
		}
	}

	public function testClaimSegmentAppendsToOwnDraftOnly(): void {
		// claim_id supplied → append; another user's or non-matching-kind
		// claim is a 404, never an append into foreign data.
		$this->body = ['segment_id' => 77, 'cost_center_id' => 5, 'claim_id' => 7];
		$this->mc->method('segmentsFor')->willReturn([$this->segment()]);
		$this->settings->method('getBool')->willReturn(true);
		$foreign = $this->draft();
		$foreign->setUserId('u2');
		$this->lifecycle->method('loadVisible')->willReturn($foreign);
		$this->expectException(DomainException::class);
		$this->ctrl->claimMcSegment();
	}

	// ── F26 charge-back deduction drafts ─────────────────────────────────

	private function chargeback(array $over = []): array {
		return array_merge([
			'chargeback_id' => 9, 'uid' => 'u1', 'amount_minor' => 4550,
			'entry_date' => '2026-11-15', 'notes' => 'Fuel spill',
			'vehicle_label' => 'VW Golf AB-123',
		], $over);
	}

	public function testUnknownChargebackIs404(): void {
		$this->body = ['chargeback_id' => 3];
		$this->mc->method('openChargebacksFor')->willReturn([$this->chargeback()]);
		try {
			$this->ctrl->claimMcChargeback();
			$this->fail('expected CHARGEBACK_NOT_FOUND');
		} catch (DomainException $e) {
			$this->assertSame('CHARGEBACK_NOT_FOUND', $e->getErrorCode());
			$this->assertSame(404, $e->getHttpStatus());
		}
	}

	public function testClaimChargebackCreatesNegativeDeductionLine(): void {
		$this->body = ['chargeback_id' => 9, 'cost_center_id' => 5];
		$this->mc->method('openChargebacksFor')->willReturn([$this->chargeback()]);
		$claim = $this->draft();
		$this->lifecycle->method('createDraft')->willReturn($claim);
		$this->lifecycle->method('loadVisible')->willReturn($claim);
		$this->serializer->method('detail')->willReturn(['id' => 7]);

		$kindUsed = null;
		$this->lifecycle->expects($this->once())->method('createDraft')
			->willReturnCallback(function ($uid, $title, $cur, $key, array $opts) use (&$kindUsed, $claim) {
				$kindUsed = $opts['kind'] ?? null;
				return $claim;
			});
		$captured = null;
		$this->lineService->method('addLine')->willReturnCallback(
			function ($c, array $line) use (&$captured) { $captured = $line; return new ClaimLine(); });

		$this->ctrl->claimMcChargeback();
		$this->assertSame('deduction', $kindUsed);
		$this->assertSame('-45.50', $captured['amount']); // server-derived, negated
		$this->assertSame('chargeback:9', $captured['source_ref']);
		$this->assertSame('receipt', $captured['line_type']);
	}

	// ── MH-22 door-config assist ─────────────────────────────────────────

	public function testDoorConfigDeniedForNonAdmin(): void {
		$this->access->method('isAppAdmin')->willReturn(false);
		$this->expectException(DomainException::class);
		$this->ctrl->azcDoorConfig();
	}

	public function testDoorConfigMirrorsAzcForAdmin(): void {
		$this->access->method('isAppAdmin')->willReturn(true);
		$this->azc->method('available')->willReturn(true);
		$this->azc->method('doorConfig')->willReturn(
			['mode' => 'restricted', 'allowed_users' => ['a'], 'allowed_groups' => ['g']]);
		$res = $this->ctrl->azcDoorConfig();
		$data = $res->getData();
		$this->assertTrue($data['ok']);
		$this->assertSame('restricted', $data['door']['mode']);
	}

	// ── summary shape ────────────────────────────────────────────────────

	public function testSummaryReportsStandaloneWhenNoSiblings(): void {
		$this->azc->method('available')->willReturn(false);
		$this->mc->method('available')->willReturn(false);
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->settings->method('get')->willReturn('unset');
		$res = $this->ctrl->summary();
		$data = $res->getData();
		$this->assertFalse($data['siblings']['arbeitszeitcheck']['installed']);
		$this->assertFalse($data['siblings']['mobilitycheck']['installed']);
		$this->assertNull($data['siblings']['mobilitycheck']['expense_submit_enabled']);
	}
}
