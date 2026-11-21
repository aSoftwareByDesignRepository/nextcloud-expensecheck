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
use OCA\ExpenseCheck\Db\StepDecision;
use OCA\ExpenseCheck\Db\StepDecisionMapper;
use OCA\ExpenseCheck\Service\ClaimSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Spec §13.4 — the wire DTO contract shared by web and mobile. Every key
 * listed here is load-bearing for both clients.
 */
final class ClaimSerializerTest extends TestCase
{
	private function svc(array $lines = [], array $allocations = [], array $attachments = [], array $lineAttachments = [], array $decisions = [], array $events = []): ClaimSerializer
	{
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
		return new ClaimSerializer($lineMapper, $alloc, $att, $dec, $ev);
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
