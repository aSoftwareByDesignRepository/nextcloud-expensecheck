<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Db\Attachment;
use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimAllocation;
use OCA\ExpenseCheck\Db\ClaimAllocationMapper;
use OCA\ExpenseCheck\Db\ClaimEvent;
use OCA\ExpenseCheck\Db\ClaimEventMapper;
use OCA\ExpenseCheck\Db\ClaimLine;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\CostCenter;
use OCA\ExpenseCheck\Db\CostCenterMapper;
use OCA\ExpenseCheck\Db\DelegateMapper;
use OCA\ExpenseCheck\Db\StepDecision;
use OCA\ExpenseCheck\Db\StepDecisionMapper;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\AzcBridge;
use OCA\ExpenseCheck\Service\ChainRoutingService;
use OCA\ExpenseCheck\Service\ClaimSerializer;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Spec §13.4 — the wire DTO contract shared by web and mobile. Every key
 * listed here is load-bearing for both clients.
 */
final class ClaimSerializerTest extends TestCase
{
	private function svc(
		array $lines = [], array $allocations = [], array $attachments = [],
		array $lineAttachments = [], array $decisions = [], array $events = [],
		?CostCenter $costCenter = null, bool $azcAvailable = false,
		bool $finance = false, bool $auditor = false, bool $canDecide = false,
	): ClaimSerializer {
		$lineMapper = $this->createStub(ClaimLineMapper::class);
		$lineMapper->method('findByClaim')->willReturn($lines);
		$alloc = $this->createStub(ClaimAllocationMapper::class);
		$alloc->method('findByLine')->willReturn($allocations);
		$att = $this->createStub(AttachmentMapper::class);
		$att->method('findByClaim')->willReturn($attachments);
		$att->method('findByLine')->willReturn($lineAttachments);
		$dec = $this->createStub(StepDecisionMapper::class);
		$dec->method('findByClaim')->willReturn($decisions);
		$ev = $this->createStub(ClaimEventMapper::class);
		$ev->method('findAllForClaim')->willReturn($events);
		$cc = $this->createStub(CostCenterMapper::class);
		$cc->method('findById')->willReturn($costCenter);
		$delegates = $this->createStub(DelegateMapper::class);
		$delegates->method('findActiveForDelegate')->willReturn([]);
		$routing = $this->createStub(ChainRoutingService::class);
		$routing->method('decisionRights')->willReturn(['allowed' => $canDecide, 'on_behalf_of' => null]);
		$access = $this->createStub(AccessControlService::class);
		$access->method('isFinance')->willReturn($finance);
		$access->method('isAuditor')->willReturn($auditor);
		$azc = $this->createStub(AzcBridge::class);
		$azc->method('facadeAvailable')->willReturn($azcAvailable);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-21 12:00:00'));
		return new ClaimSerializer($lineMapper, $alloc, $att, $dec, $ev,
			$cc, $delegates, $routing, $access, $azc, $time);
	}

	private function claim(): Claim
	{
		$c = new Claim();
		$c->setId(7);
		$c->setClaimNo('EXP-2026-00007');
		$c->setTitle('Trip');
		$c->setStatus('approved');
		$c->setCurrency('EUR');
		$c->setTotalMinor(1500);
		$c->setReimbursableTotalMinor(1200);
		$c->setNonreimbursableTotalMinor(300);
		$c->setUserId('alice');
		$c->setKind('regular');
		$c->setVersion(4);
		$c->setPurpose('conf');
		$c->setCurrentStep(2);
		$c->setDecisionNote('ok');
		$c->setSettlementRef('REF');
		$c->setBatchId(9);
		$c->setChainSnapshot('{"a":1}');
		$c->setAzcTripId(42);
		$c->setCorrectsClaimNo('EXP-2026-00001');
		$c->setSubmittedAt(new \DateTime('2026-11-01 09:00:00'));
		return $c;
	}

	public function testSummaryEmitsAllContractKeys(): void
	{
		$s = ClaimSerializer::summary($this->claim());
		foreach (['id','claim_no','title','status','currency','total_minor',
			'reimbursable_minor','nonreimbursable_minor','owner','kind',
			'corrects_claim_no','azc_trip_id','submitted_at','decided_at',
			'decided_by','settled_at','version','updated_at'] as $k) {
			$this->assertArrayHasKey($k, $s, "summary missing $k");
		}
		$this->assertSame(1500, $s['total_minor']);
		$this->assertSame(1200, $s['reimbursable_minor']);
		$this->assertSame(300, $s['nonreimbursable_minor']);
		$this->assertSame('2026-11-01 09:00:00', $s['submitted_at']);
		$this->assertSame(42, $s['azc_trip_id']);
	}

	public function testDetailAddsSectionsAndDecodesSnapshot(): void
	{
		$d = $this->svc()->detail($this->claim());
		$this->assertSame('conf', $d['purpose']);
		$this->assertSame(2, $d['current_step']);
		$this->assertSame('ok', $d['decision_note']);
		$this->assertSame('REF', $d['settlement_ref']);
		$this->assertSame(9, $d['batch_id']);
		$this->assertSame(['a' => 1], $d['chain_snapshot']);
		foreach (['lines', 'attachments', 'decisions', 'events'] as $k) {
			$this->assertArrayHasKey($k, $d);
		}
	}

	public function testDetailNullSnapshotStaysNull(): void
	{
		$c = $this->claim();
		$c->setChainSnapshot(null);
		$d = $this->svc()->detail($c);
		$this->assertNull($d['chain_snapshot']);
	}

	public function testLineDtoShapeAndDecodes(): void
	{
		$l = new ClaimLine();
		$l->setId(3);
		$l->setLineType('receipt');
		$l->setOccurredOn(new \DateTime('2026-10-05'));
		$l->setCategoryId(4);
		$l->setMerchant('Hotel');
		$l->setAmountMinor(1000);
		$l->setReimbursable(1);
		$l->setDistanceSource('manual');
		$l->setPolicyFlags('{"receipt_required":true}');
		$l->setSourceApp('mobilitycheck');
		$l->setSourceRef('seg-9');
		$a = new ClaimAllocation();
		$a->setCostCenterId(5);
		$a->setSharePercent(6000);
		$a->setAmountMinor(600);
		$a->setPcProjectId(77);
		$att = new Attachment();
		$svc = $this->svc(allocations: [$a], lineAttachments: [$att, $att]);
		$d = $svc->lineDto($l);
		$this->assertTrue($d['reimbursable']);
		$this->assertSame(['receipt_required' => true], $d['policy_flags']);
		$this->assertSame('mobilitycheck', $d['source_app']);
		$this->assertSame('seg-9', $d['source_ref']);
		$this->assertSame('2026-10-05', $d['occurred_on']);
		$this->assertSame(2, $d['attachment_count']);
		$this->assertSame(5, $d['allocations'][0]['cost_center_id']);
		$this->assertSame('6000', $d['allocations'][0]['share_percent']);
		$this->assertSame(77, $d['allocations'][0]['pc_project_id']);
	}

	public function testLineDtoNullPolicyFlagsEmptyArray(): void
	{
		$l = new ClaimLine();
		$l->setId(1);
		$l->setPolicyFlags(null);
		$l->setReimbursable(0);
		$d = $this->svc()->lineDto($l);
		$this->assertSame([], $d['policy_flags']);
		$this->assertFalse($d['reimbursable']);
	}

	public function testAttachmentDtoShape(): void
	{
		$a = new Attachment();
		$a->setId(1); $a->setClaimId(7); $a->setLineId(3);
		$a->setInbox(1); $a->setName('r.pdf'); $a->setMime('application/pdf');
		$a->setSize(123); $a->setSha256('abc');
		$d = ClaimSerializer::attachmentDto($a);
		$this->assertTrue($d['inbox']);
		$this->assertSame('r.pdf', $d['name']);
		$this->assertSame('abc', $d['sha256']);
	}

	public function testEventDtoTombstonedHidesPayload(): void
	{
		$e = new ClaimEvent();
		$e->setSeq(2); $e->setEvent('erased'); $e->setActor('sys');
		$e->setTombstoned(1);
		$e->setPayload('{"secret":1}');
		$d = ClaimSerializer::eventDto($e);
		$this->assertTrue($d['tombstoned']);
		$this->assertNull($d['payload']); // GDPR tombstone must never leak payload
	}

	public function testEventDtoNormalDecodesPayload(): void
	{
		$e = new ClaimEvent();
		$e->setSeq(1); $e->setEvent('submitted'); $e->setActor('alice');
		$e->setTombstoned(0);
		$e->setFromStatus('draft'); $e->setToStatus('submitted');
		$e->setPayload('{"total":5}');
		$e->setCreatedAt(new \DateTime('2026-11-01 10:00:00'));
		$d = ClaimSerializer::eventDto($e);
		$this->assertFalse($d['tombstoned']);
		$this->assertSame(['total' => 5], $d['payload']);
		$this->assertSame('submitted', $d['event']);
		$this->assertSame('draft', $d['from_status']);
	}

	/**
	 * Mutation fence: every DTO slot carries a distinct sentinel and is
	 * asserted by exact value — kills ArrayItem/ArrayItemRemoval swaps that
	 * assertArrayHasKey alone cannot see.
	 */
	public function testSummaryEveryValueIsExact(): void
	{
		$c = $this->claim();
		$c->setDecidedAt(new \DateTime('2026-11-02 11:00:00'));
		$c->setDecidedBy('boss');
		$c->setSettledAt(new \DateTime('2026-11-03 12:00:00'));
		$c->setUpdatedAt(new \DateTime('2026-11-04 13:00:00'));
		$s = ClaimSerializer::summary($c);
		$this->assertSame(7, $s['id']);
		$this->assertSame('EXP-2026-00007', $s['claim_no']);
		$this->assertSame('Trip', $s['title']);
		$this->assertSame('approved', $s['status']);
		$this->assertSame('EUR', $s['currency']);
		$this->assertSame(1500, $s['total_minor']);
		$this->assertSame(1200, $s['reimbursable_minor']);
		$this->assertSame(300, $s['nonreimbursable_minor']);
		$this->assertSame('alice', $s['owner']);
		$this->assertSame('regular', $s['kind']);
		$this->assertSame('EXP-2026-00001', $s['corrects_claim_no']);
		$this->assertSame(42, $s['azc_trip_id']);
		$this->assertSame('2026-11-01 09:00:00', $s['submitted_at']);
		$this->assertSame('2026-11-02 11:00:00', $s['decided_at']);
		$this->assertSame('boss', $s['decided_by']);
		$this->assertSame('2026-11-03 12:00:00', $s['settled_at']);
		$this->assertSame(4, $s['version']);
		$this->assertSame('2026-11-04 13:00:00', $s['updated_at']);
	}

	public function testLineDtoEveryValueIsExact(): void
	{
		$l = new ClaimLine();
		$l->setId(11);
		$l->setLineType('mileage');
		$l->setOccurredOn(new \DateTime('2026-10-06'));
		$l->setCategoryId(12);
		$l->setMerchant('Shell');
		$l->setDescription('fuel');
		$l->setAmountMinor(4321);
		$l->setTaxMinor(700);
		$l->setTaxRate('19.00');
		$l->setForeignAmountMinor(5000);
		$l->setForeignCurrency('USD');
		$l->setForeignFx('1.080000');
		$l->setDistanceKm('12.50');
		$l->setDays('1.00');
		$l->setQuantity('2.00');
		$l->setRateCode('KM-PRIV');
		$l->setRateMinor(30);
		$l->setRateName('Private car');
		$l->setJustification('client visit');
		$l->setReimbursable(1);
		$l->setDistanceSource('mc_segment');
		$l->setAttendees('bob,carol');
		$l->setOccasion('kickoff');
		$l->setTipMinor(150);
		$l->setPolicyFlags('{"over_threshold":true}');
		$l->setSourceApp('mobilitycheck');
		$l->setSourceRef('seg-77');
		$a = new ClaimAllocation();
		$a->setCostCenterId(31);
		$a->setSharePercent(10000);
		$a->setAmountMinor(4321);
		$a->setPcProjectId(88);
		$att = new Attachment();
		$d = $this->svc(allocations: [$a], lineAttachments: [$att])->lineDto($l);
		$this->assertSame([
			'id' => 11, 'line_type' => 'mileage', 'occurred_on' => '2026-10-06',
			'category_id' => 12, 'merchant' => 'Shell', 'description' => 'fuel',
			'amount_minor' => 4321, 'tax_minor' => 700, 'tax_rate' => '19.00',
			'foreign_amount_minor' => 5000, 'foreign_currency' => 'USD',
			'foreign_fx' => '1.080000', 'distance_km' => '12.50',
			'days' => '1.00', 'quantity' => '2.00', 'rate_code' => 'KM-PRIV',
			'rate_minor' => 30, 'rate_name' => 'Private car',
			'justification' => 'client visit', 'reimbursable' => true,
			'distance_source' => 'mc_segment', 'attendees' => 'bob,carol',
			'occasion' => 'kickoff', 'tip_minor' => 150,
			'policy_flags' => ['over_threshold' => true],
			'source_app' => 'mobilitycheck', 'source_ref' => 'seg-77',
			'allocations' => [[
				'cost_center_id' => 31, 'share_percent' => '10000',
				'amount_minor' => 4321, 'pc_project_id' => 88,
			]],
			'attachment_count' => 1,
		], $d);
	}

	public function testAttachmentAndEventDtoEveryValueIsExact(): void
	{
		$a = new Attachment();
		$a->setId(21); $a->setClaimId(7); $a->setLineId(3);
		$a->setInbox(1); $a->setName('receipt.jpg'); $a->setMime('image/jpeg');
		$a->setSize(2048); $a->setSha256('deadbeef');
		$this->assertSame([
			'id' => 21, 'claim_id' => 7, 'line_id' => 3, 'inbox' => true,
			'name' => 'receipt.jpg', 'mime' => 'image/jpeg',
			'size' => 2048, 'sha256' => 'deadbeef',
		], ClaimSerializer::attachmentDto($a));

		$e = new ClaimEvent();
		$e->setSeq(9); $e->setEvent('submitted'); $e->setActor('alice');
		$e->setFromStatus('draft'); $e->setToStatus('submitted');
		$e->setTombstoned(0); $e->setPayload('{"step":1}');
		$e->setCreatedAt(new \DateTime('2026-11-01 09:00:00'));
		$this->assertSame([
			'seq' => 9, 'event' => 'submitted', 'actor' => 'alice',
			'from_status' => 'draft', 'to_status' => 'submitted',
			'tombstoned' => false, 'payload' => ['step' => 1],
			'created_at' => '2026-11-01 09:00:00',
		], ClaimSerializer::eventDto($e));
	}

	public function testDetailDecisionRowEveryValueIsExact(): void
	{
		$d = new StepDecision();
		$d->setStepNo(2);
		$d->setDecidedBy('boss');
		$d->setDecision('approved');
		$d->setNote('ok go');
		$d->setCreatedAt(new \DateTime('2026-11-05 08:30:00'));
		$out = $this->svc(decisions: [$d])->detail($this->claim());
		$this->assertSame([[
			'step_no' => 2, 'decided_by' => 'boss', 'decision' => 'approved',
			'note' => 'ok go', 'created_at' => '2026-11-05 08:30:00',
		]], $out['decisions']);
	}

	/** §10.6 — caller-scoped can.* block is the only affordance authority. */
	public function testCanForOwnerDraftEditable(): void
	{
		$c = $this->claim();
		$c->setStatus('draft');
		$c->setCurrentStep(0);
		$can = $this->svc(lines: [new ClaimLine()])->canFor($c, 'alice', 1);
		$this->assertTrue($can['canEdit']);
		$this->assertTrue($can['canSubmit']);
		$this->assertTrue($can['canAttach']);
		$this->assertFalse($can['canWithdraw']);
		$this->assertFalse($can['canDecide']);
		$this->assertFalse($can['canSettle']);
	}

	public function testCanForEmptyDraftCannotSubmit(): void
	{
		$c = $this->claim();
		$c->setStatus('draft');
		$can = $this->svc()->canFor($c, 'alice', 0);
		$this->assertFalse($can['canSubmit'], 'submit affordance must hide on a line-less draft');
		$this->assertTrue($can['canEdit']);
	}

	public function testCanForSubmittedOwnerWithdrawsNotDecides(): void
	{
		$c = $this->claim();
		$c->setStatus('submitted');
		$can = $this->svc(canDecide: false)->canFor($c, 'alice', 2);
		$this->assertTrue($can['canWithdraw']);
		$this->assertFalse($can['canEdit']);
		$this->assertFalse($can['canDecide']);
	}

	public function testCanDecideFollowsRoutingRights(): void
	{
		$c = $this->claim();
		$c->setStatus('submitted');
		$can = $this->svc(canDecide: true)->canFor($c, 'boss', 2);
		$this->assertTrue($can['canDecide']);
		$this->assertFalse($can['canWithdraw'], 'non-owner never withdraws');
	}

	public function testCanSettleFinanceOnly(): void
	{
		$c = $this->claim();
		$c->setStatus('approved');
		$this->assertTrue($this->svc(finance: true)->canFor($c, 'fin', 1)['canSettle']);
		$this->assertFalse($this->svc(finance: false, auditor: true)->canFor($c, 'aud', 1)['canSettle']);
		$this->assertTrue($this->svc(auditor: true)->canFor($c, 'aud', 1)['canExport']);
	}

	public function testSummaryForAddsCanBlock(): void
	{
		$out = $this->svc()->summaryFor($this->claim(), 'alice');
		$this->assertArrayHasKey('can', $out);
		foreach (['canEdit','canSubmit','canWithdraw','canDecide','canSettle','canAttach','canExport'] as $k) {
			$this->assertArrayHasKey($k, $out['can'], "can.$k missing");
			$this->assertIsBool($out['can'][$k]);
		}
	}

	public function testDetailWithUidAddsCanAndResolvedLabel(): void
	{
		$cc = new CostCenter();
		$cc->setCode('OPS-100');
		$c = $this->claim();
		$c->setCostCenterId(5);
		$d = $this->svc(costCenter: $cc, azcAvailable: true)->detail($c, 'alice');
		$this->assertArrayHasKey('can', $d);
		$this->assertSame('OPS-100', $d['resolved_label']['cost_center']);
		$this->assertSame('AZC #42', $d['resolved_label']['azc_trip']);
	}

	public function testResolvedLabelBrokenLinksNull(): void
	{
		$c = $this->claim();
		$c->setCostCenterId(99);
		$d = $this->svc(costCenter: null, azcAvailable: false)->detail($c, 'alice');
		$this->assertNull($d['resolved_label']['cost_center'], 'deleted cost center renders muted, not raw id');
		$this->assertNull($d['resolved_label']['azc_trip'], 'AZC absent → muted soft link');
	}

	public function testDetailWithoutUidOmitsCan(): void
	{
		$d = $this->svc()->detail($this->claim());
		$this->assertArrayNotHasKey('can', $d);
		$this->assertArrayNotHasKey('resolved_label', $d);
	}

	public function testDetailComposesSubsections(): void
	{
		$l = new ClaimLine();
		$l->setId(1);
		$a = new Attachment();
		$a->setInbox(0);
		$sd = new StepDecision();
		$sd->setStepNo(1); $sd->setDecidedBy('boss'); $sd->setDecision('approved');
		$e = new ClaimEvent();
		$e->setTombstoned(0);
		$d = $this->svc(lines: [$l], attachments: [$a], decisions: [$sd], events: [$e])
			->detail($this->claim());
		$this->assertCount(1, $d['lines']);
		$this->assertCount(1, $d['attachments']);
		$this->assertSame('boss', $d['decisions'][0]['decided_by']);
		$this->assertSame('approved', $d['decisions'][0]['decision']);
		$this->assertCount(1, $d['events']);
	}
}
