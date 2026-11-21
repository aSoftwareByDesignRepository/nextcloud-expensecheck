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

	private RateCatalogService $rates;
	private bool $casResult = true;

	protected function setUp(): void {
		$this->lines = $this->createMock(ClaimLineMapper::class);
		$this->claims = $this->createMock(ClaimMapper::class);
		$this->categories = $this->createMock(CategoryMapper::class);
		$this->attachments = $this->createMock(AttachmentMapper::class);
		$this->money = $this->createMock(MoneyMathService::class);
		$this->money->method('resolveAllocations')->willReturn([]);
		$this->casResult = true;
		$this->claims->method('updateWithVersion')->willReturnCallback(fn () => $this->casResult);
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
		// array_merge, not `+` — `+` silently ignores overrides of default
		// keys (amount/line_type), which made negative-amount tests pass 10.00.
		return array_merge(['line_type' => 'receipt', 'occurred_on' => '2026-12-01',
			'category_id' => 3, 'amount' => '10.00'], $over);
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

	public function testJustificationRequiredAtExactThreshold(): void {
		// >= boundary: amount == threshold needs justification too.
		$this->categories->method('findById')->willReturn($this->category([
			'just_threshold' => 1000, 'just_min_len' => 10,
		]));
		try {
			$this->lines->method('findByClaim')->willReturn([]);
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'amount' => '10.00', 'justification' => 'short']), [], 'u1');
			$this->fail('expected JUSTIFICATION_REQUIRED at exact threshold');
		} catch (DomainException $e) {
			$this->assertSame('JUSTIFICATION_REQUIRED', $e->getErrorCode());
		}
	}

	public function testJustificationExactlyMinLengthPasses(): void {
		$this->categories->method('findById')->willReturn($this->category([
			'just_threshold' => 1, 'just_min_len' => 10,
		]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams([
			'amount' => '1.00', 'justification' => '1234567890']), [], 'u1');
		$this->assertSame('1234567890', $line->getJustification());
	}

	public function testHardCapExactMaxPasses(): void {
		// > boundary: amount == max is allowed.
		$this->categories->method('findById')->willReturn($this->category(['max' => 1000, 'max_hard' => 1]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams(['amount' => '10.00']), [], 'u1');
		$this->assertSame(1000, $line->getAmountMinor());
	}

	public function testFreeTextFieldsAreTrimmedAndTruncated(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams([
			'merchant' => '  Hotel Foo  ',
			'description' => str_repeat('d', 2500),
			'justification' => '   ',
		]), [], 'u1');
		$this->assertSame('Hotel Foo', $line->getMerchant());
		$this->assertSame(2000, mb_strlen((string)$line->getDescription()));
		$this->assertNull($line->getJustification()); // whitespace-only → null
	}

	public function testMerchantTruncatedTo191(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams([
			'merchant' => str_repeat('m', 300),
		]), [], 'u1');
		$this->assertSame(191, mb_strlen((string)$line->getMerchant()));
	}

	public function testOccurredOnRejectsInvalidFormats(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		foreach (['2026-13-01', '01.12.2026', 'not-a-date', '2026-12-1', ''] as $bad) {
			try {
				$this->lines->method('findByClaim')->willReturn([]);
				$this->svc->addLine($this->draftClaim(), $this->receiptParams(['occurred_on' => $bad]), [], 'u1');
				$this->fail("date {$bad} accepted");
			} catch (ValidationException $e) {
				$this->assertSame('invalid_date', $e->getFields()['occurred_on']);
			}
		}
	}

	public function testForeignAmountMustBePositive(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		foreach (['0', '-5.00'] as $bad) {
			try {
				$this->lines->method('findByClaim')->willReturn([]);
				$this->svc->addLine($this->draftClaim(), $this->receiptParams([
					'foreign_amount' => $bad, 'foreign_currency' => 'USD', 'foreign_fx' => '1.10']), [], 'u1');
				$this->fail("foreign_amount {$bad} accepted");
			} catch (ValidationException $e) {
				$this->assertSame('invalid_amount', $e->getFields()['foreign_amount']);
			}
		}
	}

	public function testForeignFxZeroRejected(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		try {
			$this->lines->method('findByClaim')->willReturn([]);
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'foreign_amount' => '5.00', 'foreign_currency' => 'USD', 'foreign_fx' => '0']), [], 'u1');
			$this->fail('zero fx accepted');
		} catch (ValidationException $e) {
			$this->assertSame('invalid_fx', $e->getFields()['foreign_fx']);
		}
	}

	public function testMileageQtyMustBePositive(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		foreach (['0', '-1.5'] as $bad) {
			try {
				$this->lines->method('findByClaim')->willReturn([]);
				$this->svc->addLine($this->draftClaim(), $this->receiptParams([
					'line_type' => 'mileage', 'qty' => $bad, 'rate_code' => 'KM']), [], 'u1');
				$this->fail("qty {$bad} accepted");
			} catch (ValidationException $e) {
				$this->assertSame('invalid_amount', $e->getFields()['qty']);
			}
		}
	}

	public function testMileageRateCodeRequired(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		try {
			$this->lines->method('findByClaim')->willReturn([]);
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'line_type' => 'mileage', 'qty' => '10']), [], 'u1');
			$this->fail('missing rate_code accepted');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['rate_code']);
		}
	}

	public function testTipParsedToMinor(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams(['tip' => '1.50']), [], 'u1');
		$this->assertSame(150, $line->getTipMinor());
		$line2 = $this->svc->addLine($this->draftClaim(), $this->receiptParams(['tip' => '']), [], 'u1');
		$this->assertNull($line2->getTipMinor());
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

	// ── edge 37: sibling source-ref dedupe ──────────────────────────────

	public function testSourceRefDuplicateIsDomainErrorNotDbCrash(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$this->lines->method('findClaimBySource')->willReturn(
			['claim_id' => 42, 'line_id' => 99, 'claim_no' => 'EXP-2026-0042']);
		try {
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'source_app' => 'mobilitycheck', 'source_ref' => '77']), [], 'u1');
			$this->fail('expected ALREADY_CLAIMED');
		} catch (DomainException $e) {
			$this->assertSame('ALREADY_CLAIMED', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
			$this->assertStringContainsString('EXP-2026-0042', $e->getMessage());
		}
	}

	public function testSourceRefUniqueViolationMapsToAlreadyClaimed(): void {
		// TOCTOU: index fires between pre-check and insert — the catch must
		// surface the same 409, never a raw DB 500.
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$this->lines->method('findClaimBySource')
			->willReturnOnConsecutiveCalls(null,
				['claim_id' => 42, 'line_id' => 99, 'claim_no' => 'EXP-2026-0042']);
		$this->lines->method('insert')->willThrowException(
			new \RuntimeException('Integrity constraint violation: 1062 Duplicate entry'));
		try {
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'source_app' => 'mobilitycheck', 'source_ref' => '77']), [], 'u1');
			$this->fail('expected ALREADY_CLAIMED');
		} catch (DomainException $e) {
			$this->assertSame('ALREADY_CLAIMED', $e->getErrorCode());
		}
	}

	public function testSameClaimSecondLineCannotReuseSourceRef(): void {
		// Dedupe is per LINE, not per claim — a second line on the same
		// claim must still not re-consume the segment's km.
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$this->lines->method('findClaimBySource')->willReturn(
			['claim_id' => 7, 'line_id' => 55, 'claim_no' => 'EXP-2026-0007']);
		$this->expectException(DomainException::class);
		$this->svc->addLine($this->draftClaim(), $this->receiptParams([
			'source_app' => 'mobilitycheck', 'source_ref' => '77']), [], 'u1');
	}

	public function testUpdateLineKeepsOwnSourceRef(): void {
		// The line's own row is not a collision — updating other fields on
		// an already-linked line must pass.
		$this->categories->method('findById')->willReturn($this->category([]));
		$line = new ClaimLine();
		$line->setId(55);
		$line->setClaimId(7);
		$this->lines->method('findByClaim')->willReturn([]);
		$this->lines->method('findClaimBySource')->willReturn(
			['claim_id' => 7, 'line_id' => 55, 'claim_no' => 'EXP-2026-0007']);
		$out = $this->svc->updateLine($this->draftClaim(), $line, $this->receiptParams([
			'source_app' => 'mobilitycheck', 'source_ref' => '77']), [], 'u1');
		$this->assertSame('mobilitycheck', $out->getSourceApp());
	}

	// ── edge 20: negative money only on correction/deduction kinds ───────

	public function testNegativeReceiptBlockedOnStandardClaim(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		try {
			$this->svc->addLine($this->draftClaim(), $this->receiptParams(['amount' => '-12.00']), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('invalid_amount', $e->getFields()['amount'] ?? '');
		}
	}

	public function testNegativeReceiptAllowedOnDeductionClaim(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$claim = $this->draftClaim();
		$claim->setKind('deduction');
		$line = $this->svc->addLine($claim, $this->receiptParams(['amount' => '-45.50']), [], 'u1');
		$this->assertSame(-4550, $line->getAmountMinor());
	}

	public function testNegativeReceiptAllowedOnCorrectionClaim(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$claim = $this->draftClaim();
		$claim->setKind('correction');
		$line = $this->svc->addLine($claim, $this->receiptParams(['amount' => '-10.00']), [], 'u1');
		$this->assertSame(-1000, $line->getAmountMinor());
	}

	public function testZeroAmountStillBlockedOnDeductionClaim(): void {
		// Negative allowed, but a zero line is still meaningless data.
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$claim = $this->draftClaim();
		$claim->setKind('deduction');
		$this->expectException(ValidationException::class);
		$this->svc->addLine($claim, $this->receiptParams(['amount' => '0.00']), [], 'u1');
	}

	// ── distance_source provenance + distance_estimated flag ────────────

	private function mileageParams(array $over = []): array {
		return array_merge(['line_type' => 'mileage', 'occurred_on' => '2026-12-01',
			'category_id' => 3, 'qty' => '25', 'rate_code' => 'KM'], $over);
	}

	private function svcWithRate(): ClaimLineService {
		$rates = $this->createMock(RateCatalogService::class);
		$rate = new \OCA\ExpenseCheck\Db\RateEntry();
		$rate->setId(9);
		$rate->setCode('KM');
		$rate->setName('Kilometre');
		$rate->setRateMinor(30);
		$rate->setCurrency('EUR');
		$rates->method('resolve')->willReturn($rate);
		$db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-12-01'));
		return new ClaimLineService(
			$this->lines, $this->claims,
			$this->createMock(ClaimAllocationMapper::class),
			$this->categories, $this->attachments,
			$rates, $this->createMock(PeriodService::class),
			$this->money, $this->createMock(ExpChainService::class), $db, $time,
		);
	}

	public function testDistanceSourceRejectsUnknownValue(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		try {
			$this->svcWithRate()->addLine($this->draftClaim(), $this->mileageParams(['distance_source' => 'magic']), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('invalid', $e->getFields()['distance_source'] ?? '');
		}
	}

	public function testMcSegmentSourceRequiresSiblingLink(): void {
		// distance_source=mc_segment without the evidence link is a lie —
		// the provenance label must be earned by source_app+source_ref.
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		try {
			$this->svcWithRate()->addLine($this->draftClaim(), $this->mileageParams(['distance_source' => 'mc_segment']), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['source_ref'] ?? '');
		}
	}

	public function testMcSegmentSourceAcceptedWithLink(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$this->lines->method('findClaimBySource')->willReturn(null);
		$line = $this->svcWithRate()->addLine($this->draftClaim(), $this->mileageParams([
			'distance_source' => 'mc_segment',
			'source_app' => 'mobilitycheck', 'source_ref' => '77']), [], 'u1');
		$this->assertSame('mc_segment', $line->getDistanceSource());
		$this->assertSame('77', $line->getSourceRef());
		$this->assertSame(750, $line->getAmountMinor()); // 25 km × 0.30
	}

	public function testDistanceEstimatedStampsPolicyFlag(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svcWithRate()->addLine($this->draftClaim(), $this->mileageParams([
			'distance_estimated' => true]), [], 'u1');
		$this->assertContains('distance_estimated', json_decode((string)$line->getPolicyFlags(), true));
	}

	public function testDistanceEstimatedStringFalseIsNotTruthy(): void {
		// Form-encoded "false" is a string — must NOT stamp the flag.
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svcWithRate()->addLine($this->draftClaim(), $this->mileageParams([
			'distance_estimated' => 'false']), [], 'u1');
		$this->assertNull($line->getPolicyFlags());
	}
	// ── golden-state assertions: every setter on the builder is a contract ──

	public function testReceiptLinePersistsEveryField(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$claim = $this->draftClaim();
		$line = $this->svc->addLine($claim, $this->receiptParams([
			'amount' => '12.34', 'merchant' => 'Shell', 'description' => 'Fuel',
			'justification' => 'Client visit', 'reimbursable' => false,
			'attendees' => 'A, B', 'occasion' => 'Dinner',
			'source_app' => 'mobilitycheck', 'source_ref' => 'cb:9',
			'tip' => '1.50',
		]), [], 'u1');
		$this->assertSame(7, $line->getClaimId());
		$this->assertSame('receipt', $line->getLineType());
		$this->assertSame(3, $line->getCategoryId());
		$this->assertSame('2026-12-01', $line->getOccurredOn()->format('Y-m-d'));
		$this->assertSame('Shell', $line->getMerchant());
		$this->assertSame('Fuel', $line->getDescription());
		$this->assertSame('Client visit', $line->getJustification());
		$this->assertSame(0, $line->getReimbursable());
		$this->assertSame('A, B', $line->getAttendees());
		$this->assertSame('Dinner', $line->getOccasion());
		$this->assertSame('mobilitycheck', $line->getSourceApp());
		$this->assertSame('cb:9', $line->getSourceRef());
		$this->assertSame(1234, $line->getAmountMinor());
		$this->assertNull($line->getDistanceKm());
		$this->assertNull($line->getDays());
		$this->assertNull($line->getQuantity());
		$this->assertNull($line->getRateId());
		$this->assertNull($line->getRateCode());
		$this->assertNull($line->getRateMinor());
		$this->assertNull($line->getRateName());
		$this->assertSame(150, $line->getTipMinor());
	}

	public function testMileageLinePersistsEveryField(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svcWithRate()->addLine($this->draftClaim(), $this->mileageParams([
			'qty' => '42.5',
			'source_app' => 'mobilitycheck', 'source_ref' => '77',
			'distance_source' => 'mc_segment',
		]), [], 'u1');
		$this->assertSame('mileage', $line->getLineType());
		$this->assertSame('42.50', $line->getDistanceKm());
		$this->assertNull($line->getDays());
		$this->assertNull($line->getQuantity());
		$this->assertNull($line->getTipMinor());
		$this->assertSame(9, $line->getRateId());
		$this->assertSame('KM', $line->getRateCode());
		$this->assertSame(30, $line->getRateMinor());
		$this->assertSame('Kilometre', $line->getRateName());
		$this->assertSame(1275, $line->getAmountMinor()); // 42.50 km x 0.30
		$this->assertSame('mc_segment', $line->getDistanceSource());
		$this->assertSame('mobilitycheck', $line->getSourceApp());
		$this->assertSame('77', $line->getSourceRef());
	}

	public function testMileageDefaultDistanceSourceIsManual(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svcWithRate()->addLine($this->draftClaim(), $this->mileageParams(), [], 'u1');
		$this->assertSame('manual', $line->getDistanceSource());
	}

	public function testMcSegmentSourceWithoutRefRejected(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		try {
			$this->svcWithRate()->addLine($this->draftClaim(), $this->mileageParams([
				'distance_source' => 'mc_segment',
				'source_app' => 'mobilitycheck', 'source_ref' => '',
			]), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['source_ref']);
		}
	}

	public function testMcSegmentSourceRequiresMcSourceApp(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		try {
			$this->svcWithRate()->addLine($this->draftClaim(), $this->mileageParams([
				'distance_source' => 'mc_segment',
				'source_app' => 'otherapp', 'source_ref' => '5',
			]), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['source_ref']);
		}
	}

	public function testInvalidDistanceSourceRejected(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		try {
			$this->svcWithRate()->addLine($this->draftClaim(), $this->mileageParams([
				'distance_source' => 'telepathy']), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('invalid', $e->getFields()['distance_source']);
		}
	}

	// ── transaction integrity: every write call is a contract ───────────

	/** @return array{0:ClaimLineService,1:array<string,mixed>} */
	private function svcWithSpyMocks(): array {
		$lines = $this->createMock(ClaimLineMapper::class);
		$claims = $this->createMock(ClaimMapper::class);
		$claims->method('updateWithVersion')->willReturn(true);
		$cats = $this->createMock(CategoryMapper::class);
		$atts = $this->createMock(AttachmentMapper::class);
		$allocs = $this->createMock(ClaimAllocationMapper::class);
		$rates = $this->createMock(RateCatalogService::class);
		$periods = $this->createMock(PeriodService::class);
		$money = $this->createMock(MoneyMathService::class);
		$money->method('resolveAllocations')->willReturn([]);
		$chain = $this->createMock(ExpChainService::class);
		$db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-12-01'));
		$svc = new ClaimLineService($lines, $claims, $allocs, $cats, $atts,
			$rates, $periods, $money, $chain, $db, $time);
		return [$svc, ['lines' => $lines, 'cats' => $cats, 'allocs' => $allocs,
			'chain' => $chain, 'db' => $db]];
	}

	public function testUpdateLineCommitsAllWritesInsideTransaction(): void {
		[$svc, $m] = $this->svcWithSpyMocks();
		$m['cats']->method('findById')->willReturn($this->category([]));
		$line = new ClaimLine();
		$line->setId(55);
		$line->setClaimId(7);
		$m['lines']->method('findClaimBySource')->willReturn(null);
		$m['lines']->method('findByClaim')->willReturn([]);
		$m['lines']->method('update')->willReturnCallback(fn ($e) => $e);
		$m['db']->expects($this->once())->method('beginTransaction');
		$m['db']->expects($this->once())->method('commit');
		$m['db']->expects($this->never())->method('rollBack');
		$m['allocs']->expects($this->once())->method('deleteByLine')->with(55);
		$m['chain']->expects($this->once())->method('append')
			->with(7, 'line_updated', 'u1', null, null, ['line_id' => 55]);
		$out = $svc->updateLine($this->draftClaim(), $line, $this->receiptParams(), [], 'u1');
		$this->assertSame('2026-12-01', $out->getUpdatedAt()->format('Y-m-d'));
	}

	public function testUpdateLineRollsBackOnWriteFailure(): void {
		[$svc, $m] = $this->svcWithSpyMocks();
		$m['cats']->method('findById')->willReturn($this->category([]));
		$line = new ClaimLine();
		$line->setId(55);
		$line->setClaimId(7);
		$m['lines']->method('findClaimBySource')->willReturn(null);
		$m['lines']->method('findByClaim')->willReturn([]);
		$m['lines']->method('update')->willThrowException(new \RuntimeException('db gone'));
		$m['db']->expects($this->once())->method('rollBack');
		$m['db']->expects($this->never())->method('commit');
		$this->expectException(\RuntimeException::class);
		$svc->updateLine($this->draftClaim(), $line, $this->receiptParams(), [], 'u1');
	}

	public function testDeleteLineRemovesAllocsAndAppendsChain(): void {
		[$svc, $m] = $this->svcWithSpyMocks();
		$line = new ClaimLine();
		$line->setId(55);
		$line->setClaimId(7);
		$m['db']->expects($this->once())->method('beginTransaction');
		$m['db']->expects($this->once())->method('commit');
		$m['allocs']->expects($this->once())->method('deleteByLine')->with(55);
		$m['lines']->expects($this->once())->method('delete')->with($line);
		$m['chain']->expects($this->once())->method('append')
			->with(7, 'line_removed', 'u1', null, null, ['line_id' => 55]);
		$svc->deleteLine($this->draftClaim(), $line, 'u1');
	}

	public function testAddLineCommitsAllWritesInsideTransaction(): void {
		[$svc, $m] = $this->svcWithSpyMocks();
		$m['cats']->method('findById')->willReturn($this->category([]));
		$m['lines']->method('findClaimBySource')->willReturn(null);
		$m['lines']->method('findByClaim')->willReturn([]);
		$m['lines']->method('insert')->willReturnCallback(function ($e) { $e->setId(11); return $e; });
		$m['db']->expects($this->once())->method('beginTransaction');
		$m['db']->expects($this->once())->method('commit');
		$m['db']->expects($this->never())->method('rollBack');
		$m['chain']->expects($this->once())->method('append')
			->with(7, 'line_added', 'u1', null, null, ['line_id' => 11, 'line_type' => 'receipt']);
		$out = $svc->addLine($this->draftClaim(), $this->receiptParams(), [], 'u1');
		$this->assertSame('2026-12-01', $out->getCreatedAt()->format('Y-m-d'));
		$this->assertSame('2026-12-01', $out->getUpdatedAt()->format('Y-m-d'));
	}

	public function testForeignCurrencyEvidencePersists(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams([
			'amount' => '10.00', 'foreign_amount' => '11.00',
			'foreign_currency' => 'USD', 'foreign_fx' => '0.9091',
		]), [], 'u1');
		$this->assertSame(1100, $line->getForeignAmountMinor());
		$this->assertSame('USD', $line->getForeignCurrency());
		$this->assertNotNull($line->getForeignFx());
	}

	public function testForeignCurrencyRequiresValidIso(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		try {
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'foreign_amount' => '11.00', 'foreign_currency' => 'usd!']), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('invalid', $e->getFields()['foreign_currency']);
		}
	}

	public function testForeignAmountWithoutFxRejected(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		try {
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'foreign_amount' => '11.00', 'foreign_currency' => 'USD']), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('invalid_fx', $e->getFields()['foreign_fx']);
		}
	}

	public function testNoForeignAmountLeavesFieldsNull(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams(), [], 'u1');
		$this->assertNull($line->getForeignAmountMinor());
		$this->assertNull($line->getForeignCurrency());
		$this->assertNull($line->getForeignFx());
	}

	/**
	 * Mutation killers: every setter in buildLine must actually persist —
	 * MethodCallRemoval escapes if a test only checks "no exception".
	 */
	public function testReceiptLinePersistsAllOptionalFields(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams([
			'merchant' => '  Café Blum  ',
			'description' => 'Team lunch',
			'justification' => '  over budget reason  ',
			'occasion' => 'sprint review',
			'attendees' => 'Ana, Bo',
			'tip' => '2.50',
			'foreign_amount' => '11.00', 'foreign_currency' => 'usd', 'foreign_fx' => '0.92',
			'reimbursable' => '0',
		]), [], 'u1');
		$this->assertSame('Café Blum', $line->getMerchant());
		$this->assertSame('Team lunch', $line->getDescription());
		$this->assertSame('over budget reason', $line->getJustification());
		$this->assertSame('sprint review', $line->getOccasion());
		$this->assertSame('Ana, Bo', $line->getAttendees());
		$this->assertSame(250, $line->getTipMinor());
		$this->assertSame(1100, $line->getForeignAmountMinor());
		$this->assertSame('USD', $line->getForeignCurrency()); // upper-cased
		$this->assertSame('0.92', $line->getForeignFx());
		$this->assertSame(0, $line->getReimbursable());
		$this->assertNull($line->getDistanceKm());
		$this->assertNull($line->getRateCode());
	}

	public function testAttendeesTruncatedAt2000Chars(): void {
		$this->categories->method('findById')->willReturn($this->category(['requires_attendees' => 1]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams([
			'attendees' => str_repeat('x', 2100), 'occasion' => 'offsite',
		]), [], 'u1');
		$this->assertSame(2000, mb_strlen((string)$line->getAttendees()));
	}

	public function testOccasionTruncatedAt191(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams([
			'occasion' => str_repeat('y', 200),
		]), [], 'u1');
		$this->assertSame(191, mb_strlen((string)$line->getOccasion()));
	}


	public function testMcSegmentNeedsProvenance(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$rate = new \OCA\ExpenseCheck\Db\RateEntry();
		$rate->setId(9); $rate->setCode('KM'); $rate->setName('Mileage'); $rate->setRateMinor(30);
		$this->rates = $this->createMock(\OCA\ExpenseCheck\Service\RateCatalogService::class);
		$this->rates->method('resolve')->willReturn($rate);
		$this->rebuild(['rates' => $this->rates]);
		try {
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'line_type' => 'mileage', 'qty' => '10', 'rate_code' => 'KM',
				'distance_source' => 'mc_segment', // no source_app/source_ref
			]), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['source_ref']);
		}
	}

	public function testMileageRoutesQtyToDistanceKmAndSnapshotsRate(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$rate = new \OCA\ExpenseCheck\Db\RateEntry();
		$rate->setId(9); $rate->setCode('KM'); $rate->setName('Mileage rate'); $rate->setRateMinor(30);
		$this->rates = $this->createMock(\OCA\ExpenseCheck\Service\RateCatalogService::class);
		$this->rates->method('resolve')->willReturn($rate);
		$this->rebuild(['rates' => $this->rates]);
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams([
			'line_type' => 'mileage', 'qty' => '12.5', 'rate_code' => 'KM',
		]), [], 'u1');
		$this->assertSame('12.50', $line->getDistanceKm());
		$this->assertNull($line->getDays());
		$this->assertNull($line->getQuantity());
		$this->assertSame(9, $line->getRateId());
		$this->assertSame('KM', $line->getRateCode());
		$this->assertSame(30, $line->getRateMinor());
		$this->assertSame('Mileage rate', $line->getRateName());
		// 12.5 km × 0.30 € = 3.75 €
		$this->assertSame(375, $line->getAmountMinor());
	}

	public function testPerDiemRoutesQtyToDays(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$rate = new \OCA\ExpenseCheck\Db\RateEntry();
		$rate->setId(5); $rate->setCode('PD'); $rate->setName('Per diem'); $rate->setRateMinor(2800);
		$this->rates = $this->createMock(\OCA\ExpenseCheck\Service\RateCatalogService::class);
		$this->rates->method('resolve')->willReturn($rate);
		$this->rebuild(['rates' => $this->rates]);
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams([
			'line_type' => 'per_diem', 'qty' => '2', 'rate_code' => 'PD',
		]), [], 'u1');
		$this->assertSame('2.00', $line->getDays());
		$this->assertNull($line->getDistanceKm());
		$this->assertSame(5600, $line->getAmountMinor());
	}

	public function testZeroQtyRatedRejected(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		try {
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'line_type' => 'mileage', 'qty' => '0', 'rate_code' => 'KM',
			]), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('invalid_amount', $e->getFields()['qty']);
		}
	}

	public function testRatedLineWithoutRateCodeRejected(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		try {
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'line_type' => 'mileage', 'qty' => '5', 'rate_code' => '',
			]), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['rate_code']);
		}
	}

	public function testJustificationBoundaryAtExactThreshold(): void {
		$this->categories->method('findById')->willReturn($this->category([
			'just_threshold' => 1000, 'just_min_len' => 5,
		]));
		try {
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'amount' => '10.00', // == threshold → required
			]), [], 'u1');
			$this->fail('expected JUSTIFICATION_REQUIRED');
		} catch (DomainException $e) {
			$this->assertSame('JUSTIFICATION_REQUIRED', $e->getErrorCode());
		}
	}

	public function testHardCapBoundaryAtExactMaxAllowed(): void {
		$this->categories->method('findById')->willReturn($this->category([
			'max' => 1000, 'max_hard' => 1,
		]));
		$this->lines->method('findByClaim')->willReturn([]);
		// == cap is allowed (>), one cent over is not.
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams(['amount' => '10.00']), [], 'u1');
		$this->assertSame(1000, $line->getAmountMinor());
	}

	public function testInvalidForeignCurrencyRejected(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		try {
			$this->svc->addLine($this->draftClaim(), $this->receiptParams([
				'foreign_amount' => '11.00', 'foreign_currency' => 'US', 'foreign_fx' => '0.92',
			]), [], 'u1');
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertSame('invalid', $e->getFields()['foreign_currency']);
		}
	}

	public function testUpdateLineWrongClaimRejected(): void {
		$line = new ClaimLine();
		$line->setId(4); $line->setClaimId(999); // other claim
		try {
			$this->svc->updateLine($this->draftClaim(), $line, $this->receiptParams(), [], 'u1');
			$this->fail('expected CLAIM_NOT_FOUND');
		} catch (DomainException $e) {
			$this->assertSame('CLAIM_NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testDeleteLineWrongClaimRejected(): void {
		$line = new ClaimLine();
		$line->setId(4); $line->setClaimId(999);
		try {
			$this->svc->deleteLine($this->draftClaim(), $line, 'u1');
			$this->fail('expected CLAIM_NOT_FOUND');
		} catch (DomainException $e) {
			$this->assertSame('CLAIM_NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testAddLineOnSubmittedClaimRejected(): void {
		$c = $this->draftClaim();
		$c->setStatus('submitted');
		try {
			$this->svc->addLine($c, $this->receiptParams(), [], 'u1');
			$this->fail('expected CLAIM_READ_ONLY');
		} catch (DomainException $e) {
			$this->assertSame('CLAIM_READ_ONLY', $e->getErrorCode());
		}
	}


	public function testLineSortIncrementsBeyondExistingMax(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$l1 = new ClaimLine(); $l1->setSort(0);
		$l2 = new ClaimLine(); $l2->setSort(3);
		$this->lines->method('findByClaim')->willReturn([$l1, $l2]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams(), [], 'u1');
		$this->assertSame(4, $line->getSort());
	}

	public function testFirstLineSortIsOne(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->lines->method('findByClaim')->willReturn([]);
		$line = $this->svc->addLine($this->draftClaim(), $this->receiptParams(), [], 'u1');
		$this->assertSame(1, $line->getSort());
	}

	private function rebuild(array $over): void {
		$db = $this->createMock(IDBConnection::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-12-01'));
		$this->svc = new ClaimLineService(
			$this->lines,
			$this->claims,
			$this->createMock(ClaimAllocationMapper::class),
			$this->categories,
			$this->attachments,
			$over['rates'] ?? $this->createMock(RateCatalogService::class),
			$this->createMock(PeriodService::class),
			$this->money,
			$this->createMock(ExpChainService::class),
			$db,
			$time,
		);
	}

	public function testReceiptEvidenceThrowsWithoutAttachments(): void {
		$claim = $this->draftClaim();
		$line = new \OCA\ExpenseCheck\Db\ClaimLine();
		$line->setId(1); $line->setCategoryId(3);
		$this->lines->method('findByClaim')->willReturn([$line]);
		$this->attachments->method('findByClaim')->willReturn([]);
		$this->categories->method('findById')->willReturn($this->category(['receipt_required' => 1]));
		try {
			$this->svc->assertReceiptEvidence($claim);
			$this->fail('expected RECEIPT_REQUIRED');
		} catch (\OCA\ExpenseCheck\Exception\DomainException $e) {
			$this->assertSame('RECEIPT_REQUIRED', $e->getErrorCode());
			$this->assertSame(422, $e->getHttpStatus());
		}
	}

	public function testReceiptEvidencePassesWithClaimAttachment(): void {
		$claim = $this->draftClaim();
		$line = new \OCA\ExpenseCheck\Db\ClaimLine();
		$line->setId(1); $line->setCategoryId(3);
		$this->lines->method('findByClaim')->willReturn([$line]);
		$this->attachments->method('findByClaim')->willReturn([new \OCA\ExpenseCheck\Db\Attachment()]);
		$this->categories->method('findById')->willReturn($this->category(['receipt_required' => 1]));
		$this->svc->assertReceiptEvidence($claim);
		$this->addToAssertionCount(1);
	}

	public function testReceiptEvidenceSkipsNonRequiredCategories(): void {
		$claim = $this->draftClaim();
		$line = new \OCA\ExpenseCheck\Db\ClaimLine();
		$line->setId(1); $line->setCategoryId(3);
		$this->lines->method('findByClaim')->willReturn([$line]);
		$this->attachments->method('findByClaim')->willReturn([]);
		$this->categories->method('findById')->willReturn($this->category(['receipt_required' => 0]));
		$this->svc->assertReceiptEvidence($claim);
		$this->addToAssertionCount(1);
	}

	public function testReceiptEvidenceSkipsDeletedCategory(): void {
		$claim = $this->draftClaim();
		$line = new \OCA\ExpenseCheck\Db\ClaimLine();
		$line->setId(1); $line->setCategoryId(99);
		$this->lines->method('findByClaim')->willReturn([$line]);
		$this->attachments->method('findByClaim')->willReturn([]);
		$this->categories->method('findById')->willReturn(null);
		$this->svc->assertReceiptEvidence($claim);
		$this->addToAssertionCount(1);
	}

	public function testTotalsCasConflictThrowsVersionConflict(): void {
		$this->casResult = false;
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->money->method('claimTotals')->willReturn(['total' => 0, 'reimbursable' => 0, 'nonreimbursable' => 0]);
		try {
			$this->svc->addLine($this->draftClaim(), $this->receiptParams(), [], 'u1');
			$this->fail('expected VERSION_CONFLICT');
		} catch (\OCA\ExpenseCheck\Exception\DomainException $e) {
			$this->assertSame('VERSION_CONFLICT', $e->getErrorCode());
		}
	}

	public function testAddLineBumpsClaimVersionForCas(): void {
		$this->categories->method('findById')->willReturn($this->category([]));
		$this->money->method('claimTotals')->willReturn(['total' => 0, 'reimbursable' => 0, 'nonreimbursable' => 0]);
		$this->claims->expects($this->once())->method('updateWithVersion')
			->with($this->callback(fn (Claim $c) => $c->getVersion() === 2), 1)
			->willReturn(true);
		$this->svc->addLine($this->draftClaim(), $this->receiptParams(), [], 'u1');
	}
}
