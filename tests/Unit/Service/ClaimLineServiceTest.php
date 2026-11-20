<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Db\Attachment;
use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Db\Category;
use OCA\ExpenseCheck\Db\CategoryMapper;
use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimAllocationMapper;
use OCA\ExpenseCheck\Db\ClaimLine;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\ClaimLineService;
use OCA\ExpenseCheck\Service\ExpChainService;
use OCA\ExpenseCheck\Service\MoneyMathService;
use OCA\ExpenseCheck\Service\PeriodService;
use OCA\ExpenseCheck\Service\RateCatalogService;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ClaimLineServiceTest extends TestCase {
	private ClaimLineMapper $lines;
	private ClaimMapper $claims;
	private CategoryMapper $categories;
	private AttachmentMapper $attachments;
	private MoneyMathService $money;
	private ClaimLineService $svc;

	protected function setUp(): void {
		$this->lines = $this->createMock(ClaimLineMapper::class);
		$this->claims = $this->createMock(ClaimMapper::class);
		$this->categories = $this->createMock(CategoryMapper::class);
		$this->attachments = $this->createMock(AttachmentMapper::class);
		$this->money = $this->createMock(MoneyMathService::class);
		$this->money->method('resolveAllocations')->willReturn([]);
		$this->claims->method('updateWithVersion')->willReturn(true);
		$this->lines->method('insert')->willReturnCallback(function (Entity $e) {
			$e->setId(11);
			return $e;
		});
		$this->lines->method('update')->willReturnCallback(fn (Entity $e) => $e);
		$db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-12-01'));
		$this->svc = new ClaimLineService(
			$this->lines,
			$this->claims,
			$this->createMock(ClaimAllocationMapper::class),
			$this->categories,
			$this->attachments,
			$this->createMock(RateCatalogService::class),
			$this->createMock(PeriodService::class),
			$this->money,
			$this->createMock(ExpChainService::class),
			$db,
			$time,
		);
	}

	private function draftClaim(): Claim {
		$c = new Claim();
		$c->setId(7);
		$c->setStatus('draft');
		$c->setCurrency('EUR');
		$c->setVersion(1);
		return $c;
	}

	private function category(array $props): Category {
		$c = new Category();
		$c->setId(3);
		$c->setCode('MEAL');
		$c->setName('Meals');
		$c->setReceiptRequired($props['receipt_required'] ?? 0);
		$c->setJustificationThresholdMinor($props['just_threshold'] ?? null);
		$c->setJustificationMinLength($props['just_min_len'] ?? 10);
		$c->setDefaultAmountMinor($props['default'] ?? null);
		$c->setRequiresAttendees($props['requires_attendees'] ?? 0);
		$c->setMaxAmountMinor($props['max'] ?? null);
		$c->setMaxIsHard($props['max_hard'] ?? 0);
		$c->setAllowLines($props['allow_lines'] ?? 'receipt,mileage,per_diem,flat');
		$c->setActive(1);
		return $c;
	}

	private function receiptParams(array $over = []): array {
		return ['line_type' => 'receipt', 'occurred_on' => '2026-12-01', 'category_id' => 3, 'amount' => '10.00'] + $over;
	}

	public function testRequiresAttendeesBlocksMissingFields(): void {
		$this->categories->method('findById')->willReturn($this->category(['requires_attendees' => 1]));
		try {
			$this->lines->method("findByClaim")->willReturn([]);
		$this->svc->addLine($this->draftClaim(), $this->receiptParams(), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$fields = $e->getFields();
			$this->assertSame('required', $fields['attendees']);
			$this->assertSame('required', $fields['occasion']);
		}
	}

	public function testRequiresAttendeesAcceptsCompleteFields(): void {
		$this->categories->method('findById')->willReturn($this->category(['requires_attendees' => 1]));
		$this->lines->method("findByClaim")->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams([
			'attendees' => 'Max, Anne', 'occasion' => 'Kundentermin',
		]), [], 'u1');
		$this->assertSame('Max, Anne', $line->getAttendees());
		$this->assertSame('Kundentermin', $line->getOccasion());
	}

	public function testAllowLinesFenceRejectsType(): void {
		$this->categories->method('findById')->willReturn($this->category(['allow_lines' => 'mileage']));
		$this->expectException(ValidationException::class);
		$this->lines->method("findByClaim")->willReturn([]);
		$this->svc->addLine($this->draftClaim(), $this->receiptParams(), [], 'u1');
	}

	public function testHardCapThrowsCapExceeded(): void {
		$this->categories->method('findById')->willReturn($this->category(['max' => 500, 'max_hard' => 1]));
		try {
			$this->lines->method("findByClaim")->willReturn([]);
		$this->svc->addLine($this->draftClaim(), $this->receiptParams(['amount' => '10.00']), [], 'u1');
			$this->fail('expected CAP_EXCEEDED');
		} catch (DomainException $e) {
			$this->assertSame('CAP_EXCEEDED', $e->getErrorCode());
			$this->assertSame(422, $e->getHttpStatus());
		}
	}

	public function testSoftCapDoesNotBlock(): void {
		$this->categories->method('findById')->willReturn($this->category(['max' => 500, 'max_hard' => 0]));
		$this->lines->method("findByClaim")->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams(['amount' => '10.00']), [], 'u1');
		$this->assertSame(1000, $line->getAmountMinor());
	}

	public function testJustificationThresholdEnforced(): void {
		$this->categories->method('findById')->willReturn($this->category([
			'just_threshold' => 1000, 'just_min_len' => 10,
		]));
		try {
			$this->lines->method("findByClaim")->willReturn([]);
		$this->svc->addLine($this->draftClaim(), $this->receiptParams(['amount' => '10.00', 'justification' => 'short']), [], 'u1');
			$this->fail('expected JUSTIFICATION_REQUIRED');
		} catch (DomainException $e) {
			$this->assertSame('JUSTIFICATION_REQUIRED', $e->getErrorCode());
		}
	}

	public function testJustificationBelowThresholdNotRequired(): void {
		$this->categories->method('findById')->willReturn($this->category([
			'just_threshold' => 5000, 'just_min_len' => 10,
		]));
		$this->lines->method("findByClaim")->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams(['amount' => '10.00']), [], 'u1');
		$this->assertNull($line->getJustification());
	}

	public function testPolicyFlagsComputed(): void {
		$cat = $this->category(['max' => 500, 'default' => 800]);
		$this->categories->method('findById')->willReturn($cat);
		$line = new ClaimLine();
		$line->setId(11);
		$line->setClaimId(7);
		$line->setCategoryId(3);
		$line->setAmountMinor(1000);
		$line->setOccurredOn(new \DateTime('2026-12-06')); // Sunday
		$this->lines->method('findByClaim')->willReturn([$line]);
		$att = new Attachment();
		$att->setSha256('abc');
		$this->attachments->method('findByClaim')->willReturn([$att]);
		$this->attachments->method('findOtherClaimsByHash')->willReturn([new Attachment()]);

		$this->svc->computePolicyFlags($this->draftClaim());
		$this->assertSame(
			['over_threshold', 'deviates_default', 'weekend_date', 'dup_receipt_hash'],
			json_decode((string)$line->getPolicyFlags(), true)
		);
	}

	public function testPolicyFlagsEmptyWhenClean(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$line = new ClaimLine();
		$line->setId(11);
		$line->setClaimId(7);
		$line->setCategoryId(3);
		$line->setAmountMinor(1000);
		$line->setOccurredOn(new \DateTime('2026-12-01')); // Tuesday
		$this->lines->method('findByClaim')->willReturn([$line]);
		$this->attachments->method('findByClaim')->willReturn([]);
		$this->svc->computePolicyFlags($this->draftClaim());
		$this->assertNull($line->getPolicyFlags());
	}

	public function testReceiptRequiredBlocksSubmit(): void {
		$this->categories->method('findById')->willReturn($this->category(['receipt_required' => 1]));
		$line = new ClaimLine();
		$line->setCategoryId(3);
		$this->lines->method('findByClaim')->willReturn([$line]);
		$this->attachments->method('findByClaim')->willReturn([]);
		try {
			$this->svc->assertReceiptEvidence($this->draftClaim());
			$this->fail('expected RECEIPT_REQUIRED');
		} catch (DomainException $e) {
			$this->assertSame('RECEIPT_REQUIRED', $e->getErrorCode());
		}
	}

	public function testReceiptRequiredSatisfiedByAttachment(): void {
		$this->categories->method('findById')->willReturn($this->category(['receipt_required' => 1]));
		$line = new ClaimLine();
		$line->setCategoryId(3);
		$this->lines->method('findByClaim')->willReturn([$line]);
		$this->attachments->method('findByClaim')->willReturn([new Attachment()]);
		$this->svc->assertReceiptEvidence($this->draftClaim());
		$this->addToAssertionCount(1);
	}

	private function closedPeriodSvc(): ClaimLineService {
		$periods = $this->createMock(PeriodService::class);
		$periods->method('assertLineDateAllowed')->willThrowException(
			new DomainException('PERIOD_CLOSED', 'closed', 422));
		$db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-12-01'));
		$this->categories->method('findById')->willReturn($this->category([]));
		return new ClaimLineService(
			$this->lines, $this->claims,
			$this->createMock(ClaimAllocationMapper::class),
			$this->categories, $this->attachments,
			$this->createMock(RateCatalogService::class),
			$periods, $this->money,
			$this->createMock(ExpChainService::class), $db, $time,
		);
	}

	public function testLateSyncInteractivePathStillBlocksClosedPeriod(): void {
		$svc = $this->closedPeriodSvc();
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('closed');
		$svc->addLine($this->draftClaim(), $this->receiptParams(), [], 'u1');
	}

	public function testLateSyncAcceptsClosedPeriodAndStampsFlag(): void {
		$svc = $this->closedPeriodSvc();
		$line = $svc->addLine($this->draftClaim(), $this->receiptParams(), [], 'u1', true);
		$flags = json_decode((string)$line->getPolicyFlags(), true);
		$this->assertContains('late_sync', $flags);
		$this->assertSame('receipt', $line->getLineType());
	}

	public function testComputePolicyFlagsPreservesLateSync(): void {
		$line = new ClaimLine();
		$line->setClaimId(7);
		$line->setLineType('receipt');
		$line->setCategoryId(3);
		$line->setAmountMinor(1000);
		$line->setReimbursable(1);
		$line->setOccurredOn(new \DateTime('2026-12-01'));
		$line->setPolicyFlags(json_encode(['late_sync']));
		$this->lines->method('findByClaim')->willReturn([$line]);
		$this->attachments->method('findByClaim')->willReturn([]);
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->svc->computePolicyFlags($this->draftClaim());
		$this->assertContains('late_sync', json_decode((string)$line->getPolicyFlags(), true));
	}
}
