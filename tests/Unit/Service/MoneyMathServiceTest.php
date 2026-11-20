<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\MoneyMathService;
use PHPUnit\Framework\TestCase;

/**
 * Spec §6.1 — every amount is integer minor units; no float ever.
 * These tests pin the deterministic rounding contracts the whole
 * lifecycle (lines, allocations, FX, settlement) depends on.
 */
final class MoneyMathServiceTest extends TestCase
{
	/* ---------- toMinor ---------- */

	public function testToMinorWholeAmount(): void
	{
		$this->assertSame(1200, MoneyMathService::toMinor('12'));
		$this->assertSame(100, MoneyMathService::toMinor('1'));
		$this->assertSame(0, MoneyMathService::toMinor('0'));
	}

	public function testToMinorTwoDecimals(): void
	{
		$this->assertSame(1234, MoneyMathService::toMinor('12.34'));
		$this->assertSame(5, MoneyMathService::toMinor('0.05'));
		$this->assertSame(990, MoneyMathService::toMinor('9.9'));
	}

	public function testToMinorNegative(): void
	{
		// correction claims may be negative — the parser permits, callers gate.
		$this->assertSame(-550, MoneyMathService::toMinor('-5.50'));
	}

	public function testToMinorRejectsTooManyDecimals(): void
	{
		$this->expectException(ValidationException::class);
		MoneyMathService::toMinor('1.234');
	}

	public function testToMinorRejectsGarbage(): void
	{
		$this->expectException(ValidationException::class);
		MoneyMathService::toMinor('abc');
	}

	public function testToMinorRejectsEmpty(): void
	{
		$this->expectException(ValidationException::class);
		MoneyMathService::toMinor('');
	}

	public function testToMinorRejectsComma(): void
	{
		// German decimal comma must NOT silently parse — ambiguous input is a bug.
		$this->expectException(ValidationException::class);
		MoneyMathService::toMinor('12,50');
	}

	public function testToMinorTrimsWhitespace(): void
	{
		$this->assertSame(1234, MoneyMathService::toMinor(" 12.34 \t"));
	}

	public function testToMinorRejectsEmbeddedText(): void
	{
		// anchor mutants: pattern must reject prefixes and suffixes
		foreach (['x12.34', '12.34x', 'a1.23', '1.2.3', '--1.23'] as $bad) {
			try {
				MoneyMathService::toMinor($bad);
				$this->fail("toMinor accepted {$bad}");
			} catch (ValidationException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testToMinorErrorFieldIsAmount(): void
	{
		try {
			MoneyMathService::toMinor('bogus');
			$this->fail('no exception');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('amount', $e->getFields());
		}
	}

	/* ---------- divRoundHalfUp ---------- */

	public function testDivRoundHalfUpZeroNumerator(): void
	{
		$this->assertSame(0, MoneyMathService::divRoundHalfUp(0, 3));
	}

	public function testDivRoundHalfUpZeroDenominator(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		MoneyMathService::divRoundHalfUp(5, 0);
	}

	public function testDivRoundHalfUpNegativeDenominator(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		MoneyMathService::divRoundHalfUp(5, -2);
	}

	public function testDivRoundHalfUpNegativeNumerator(): void
	{
		// symmetric half-away-from-zero: -2.5 → -3 (deterministic)
		$this->assertSame(-3, MoneyMathService::divRoundHalfUp(-5, 2));
		$this->assertSame(-2, MoneyMathService::divRoundHalfUp(-4, 2));
	}

	public function testPercentToBpRejectsEmbeddedText(): void
	{
		foreach (['x50', '50x', '1.2.3', '-5'] as $bad) {
			try {
				MoneyMathService::percentToBp($bad);
				$this->fail("percentToBp accepted {$bad}");
			} catch (ValidationException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testResolveAllocationsFieldIsAllocations(): void
	{
		$svc = new MoneyMathService();
		try {
			$svc->resolveAllocations([], 1000);
			$this->fail('no exception');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('allocations', $e->getFields());
		}
	}

	/* ---------- quantityTimesRate ---------- */

	public function testQuantityTimesRateExact(): void
	{
		// 100 km × 30 cents = 30.00
		$this->assertSame(3000, MoneyMathService::quantityTimesRate(10000, 30));
	}

	public function testQuantityTimesRateHalfUp(): void
	{
		// 0.5 km × 30 cents = 15 cents
		$this->assertSame(15, MoneyMathService::quantityTimesRate(50, 30));
		// 1.5 days × 1411 cents = 2116.5 → 2117
		$this->assertSame(2117, MoneyMathService::quantityTimesRate(150, 1411));
	}

	public function testQuantityTimesRateHalfUpNotBankers(): void
	{
		// exactly .5 must round UP deterministically, not to-even
		$this->assertSame(1, MoneyMathService::quantityTimesRate(100, 1));
		$this->assertSame(2, MoneyMathService::quantityTimesRate(150, 1)); // 1.5 → 2
		$this->assertSame(3, MoneyMathService::quantityTimesRate(250, 1)); // 2.5 → 3 (half-up)
	}

	/* ---------- convertWithRate ---------- */

	public function testConvertWithRate(): void
	{
		// 100.00 GBP at 1.15 EUR → 115.00
		$this->assertSame(11500, MoneyMathService::convertWithRate(10000, '1.15'));
	}

	public function testConvertWithRateRoundsHalfUp(): void
	{
		// 1 minor × 0.333333 → 0.333 → 0
		$this->assertSame(0, MoneyMathService::convertWithRate(1, '0.333333'));
		// 3 minor × 0.5 → 1.5 → 2
		$this->assertSame(2, MoneyMathService::convertWithRate(3, '0.5'));
	}

	public function testConvertWithRateRejectsZeroAndNegative(): void
	{
		$this->expectException(ValidationException::class);
		MoneyMathService::convertWithRate(100, '0');
	}

	public function testConvertWithRateRejectsNegative(): void
	{
		$this->expectException(ValidationException::class);
		MoneyMathService::convertWithRate(100, '-1.5');
	}

	/* ---------- percentToBp ---------- */

	public function testPercentToBp(): void
	{
		$this->assertSame(10000, MoneyMathService::percentToBp('100'));
		$this->assertSame(5050, MoneyMathService::percentToBp('50.5'));
		$this->assertSame(1, MoneyMathService::percentToBp('0.01'));
		$this->assertSame(0, MoneyMathService::percentToBp('0'));
	}

	public function testPercentToBpRejectsOver100(): void
	{
		$this->expectException(ValidationException::class);
		MoneyMathService::percentToBp('100.01');
	}

	public function testPercentToBpRejectsNegative(): void
	{
		$this->expectException(ValidationException::class);
		MoneyMathService::percentToBp('-1');
	}

	/* ---------- percentToAmounts (largest remainder) ---------- */

	public function testPercentSplitEven(): void
	{
		$this->assertSame([5000, 5000], MoneyMathService::percentToAmounts(['50', '50'], 10000));
	}

	public function testPercentSplitUnevenSumsExactly(): void
	{
		// 10003 minor over thirds — largest-remainder must sum exactly.
		$out = MoneyMathService::percentToAmounts(['33.34', '33.33', '33.33'], 10003);
		$this->assertSame(10003, array_sum($out));
	}

	public function testPercentSplitDeterministicTie(): void
	{
		// tie on remainder → earlier row wins (deterministic, no drift).
		$out = MoneyMathService::percentToAmounts(['50', '50'], 3);
		$this->assertSame(3, array_sum($out));
		$this->assertSame(2, $out[0]); // first gets remainder
		$this->assertSame(1, $out[1]);
	}

	public function testPercentSplitRejectsNonHundred(): void
	{
		$this->expectException(ValidationException::class);
		MoneyMathService::percentToAmounts(['50', '40'], 1000);
	}

	public function testPercentSplitSingleHundred(): void
	{
		$this->assertSame([999], MoneyMathService::percentToAmounts(['100'], 999));
	}

	public function testPercentSplitPennyRoundRobins(): void
	{
		// 1 minor split three ways at 33.33/33.33/33.34 — must still sum to 1.
		$out = MoneyMathService::percentToAmounts(['33.33', '33.33', '33.34'], 1);
		$this->assertSame(1, array_sum($out));
	}

	/* ---------- resolveAllocations ---------- */

	public function testResolveAllocationsPercentMode(): void
	{
		$svc = new MoneyMathService();
		$out = $svc->resolveAllocations([
			['cost_center_id' => 1, 'share_percent' => '50', 'amount_minor' => null, 'pc_project_id' => null],
			['cost_center_id' => 2, 'share_percent' => '50', 'amount_minor' => null, 'pc_project_id' => null],
		], 1000);
		$this->assertSame(500, $out[0]['amount_minor']);
		$this->assertSame(500, $out[1]['amount_minor']);
	}

	public function testResolveAllocationsAmountMode(): void
	{
		$svc = new MoneyMathService();
		$out = $svc->resolveAllocations([
			['cost_center_id' => 1, 'share_percent' => null, 'amount_minor' => 700, 'pc_project_id' => null],
			['cost_center_id' => 2, 'share_percent' => null, 'amount_minor' => 300, 'pc_project_id' => null],
		], 1000);
		$this->assertSame(1000, $out[0]['amount_minor'] + $out[1]['amount_minor']);
	}

	public function testResolveAllocationsRejectsMixed(): void
	{
		$svc = new MoneyMathService();
		$this->expectException(ValidationException::class);
		$svc->resolveAllocations([
			['cost_center_id' => 1, 'share_percent' => '50', 'amount_minor' => null, 'pc_project_id' => null],
			['cost_center_id' => 2, 'share_percent' => null, 'amount_minor' => 500, 'pc_project_id' => null],
		], 1000);
	}

	public function testResolveAllocationsRejectsBothAndNeither(): void
	{
		$svc = new MoneyMathService();
		$this->expectException(ValidationException::class);
		$svc->resolveAllocations([
			['cost_center_id' => 1, 'share_percent' => '50', 'amount_minor' => 500, 'pc_project_id' => null],
		], 1000);
	}

	public function testResolveAllocationsRejectsAmountSumMismatch(): void
	{
		$svc = new MoneyMathService();
		$this->expectException(ValidationException::class);
		$svc->resolveAllocations([
			['cost_center_id' => 1, 'share_percent' => null, 'amount_minor' => 400, 'pc_project_id' => null],
			['cost_center_id' => 2, 'share_percent' => null, 'amount_minor' => 400, 'pc_project_id' => null],
		], 1000);
	}

	public function testResolveAllocationsRejectsEmpty(): void
	{
		$svc = new MoneyMathService();
		$this->expectException(ValidationException::class);
		$svc->resolveAllocations([], 1000);
	}

	/* ---------- quantityToScaled ---------- */

	public function testQuantityToScaled(): void
	{
		$this->assertSame(10000, MoneyMathService::quantityToScaled('100'));
		$this->assertSame(1250, MoneyMathService::quantityToScaled('12.5'));
		$this->assertSame(1, MoneyMathService::quantityToScaled('0.01'));
	}

	/** Kills: regex anchor removals, field-map mutants, percent bounds. */
	public function testPercentToBpRejectsMalformedAndOutOfRange(): void
	{
		foreach (['x50', '50x', 'a', '50.123', '101', '1000', '-1', ''] as $bad) {
			try {
				MoneyMathService::percentToBp($bad);
				$this->fail("percentToBp accepted $bad");
			} catch (ValidationException $e) {
				$this->assertSame('invalid_percent', $e->getFields()['allocations'] ?? null);
			}
		}
		$this->assertSame(5000, MoneyMathService::percentToBp('50'));
		$this->assertSame(10000, MoneyMathService::percentToBp('100'));
		$this->assertSame(0, MoneyMathService::percentToBp('0'));
		$this->assertSame(2550, MoneyMathService::percentToBp('25.50'));
	}

	/** Kills: allocation error field-map mutants. */
	public function testResolveAllocationsFieldMaps(): void
	{
		$svc = new MoneyMathService();
		try {
			$svc->resolveAllocations([], 1000);
			$this->fail('empty allocs accepted');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['allocations']);
		}
		// XOR violation: both percent and amount set
		try {
			$svc->resolveAllocations([
				['cost_center_id' => 1, 'share_percent' => '100.00', 'amount_minor' => 1000],
			], 1000);
			$this->fail('dual-mode row accepted');
		} catch (ValidationException $e) {
			$this->assertSame('ALLOCATION_SUM_MISMATCH', $e->getFields()['allocations']);
		}
		// Mixed modes across rows
		try {
			$svc->resolveAllocations([
				['cost_center_id' => 1, 'share_percent' => '50.00'],
				['cost_center_id' => 2, 'amount_minor' => 500],
			], 1000);
			$this->fail('mixed modes accepted');
		} catch (ValidationException $e) {
			$this->assertSame('ALLOCATION_SUM_MISMATCH', $e->getFields()['allocations']);
		}
		// Amount sum mismatch
		try {
			$svc->resolveAllocations([
				['cost_center_id' => 1, 'amount_minor' => 400],
				['cost_center_id' => 2, 'amount_minor' => 500],
			], 1000);
			$this->fail('wrong sum accepted');
		} catch (ValidationException $e) {
			$this->assertSame('ALLOCATION_SUM_MISMATCH', $e->getFields()['allocations']);
		}
		// Percent sum mismatch
		try {
			$svc->resolveAllocations([
				['cost_center_id' => 1, 'share_percent' => '50.00'],
				['cost_center_id' => 2, 'share_percent' => '40.00'],
			], 1000);
			$this->fail('90% split accepted');
		} catch (ValidationException $e) {
			$this->assertSame('ALLOCATION_SUM_MISMATCH', $e->getFields()['allocations']);
		}
	}

	/** Kills: largest-remainder sort mutants, remainder distribution. */
	public function testLargestRemainderDistribution(): void
	{
		// 100/3: exact 33.33… → one unit must go to some row deterministically.
		$this->assertSame([34, 33, 33], MoneyMathService::percentToAmounts(
			['33.34', '33.33', '33.33'], 100));
		// Tie on remainders → earlier row wins the extra unit.
		$this->assertSame([1, 1, 1], MoneyMathService::percentToAmounts(
			['33.34', '33.33', '33.33'], 3));
		$this->assertSame([50, 50], MoneyMathService::percentToAmounts(['50', '50'], 100));
		// Resolved rows carry cost_center_id + pc_project_id passthrough.
		$svc = new MoneyMathService();
		$rows = $svc->resolveAllocations([
			['cost_center_id' => 7, 'share_percent' => '60.00', 'pc_project_id' => '42'],
			['cost_center_id' => 8, 'share_percent' => '40.00'],
		], 1000);
		$this->assertSame(600, $rows[0]['amount_minor']);
		$this->assertSame(400, $rows[1]['amount_minor']);
		$this->assertSame(7, $rows[0]['cost_center_id']);
		$this->assertSame(42, $rows[0]['pc_project_id']);
		$this->assertNull($rows[1]['pc_project_id']);
	}

	/** Kills: fx sign/zero guards, rounding-direction mutants. */
	public function testConvertWithRateEdges(): void
	{
		foreach (['0', '-1.5'] as $bad) {
			try {
				MoneyMathService::convertWithRate(1000, $bad);
				$this->fail("fx accepted $bad");
			} catch (ValidationException $e) {
				$this->assertSame('invalid_fx', $e->getFields()['foreign_fx'] ?? null);
			}
		}
		// Malformed rate strings fail at the parser with a 'rate' field key.
		foreach (['abc', ''] as $bad) {
			try {
				MoneyMathService::convertWithRate(1000, $bad);
				$this->fail("fx accepted $bad");
			} catch (ValidationException $e) {
				$this->assertSame('invalid_decimal', $e->getFields()['rate'] ?? null);
			}
		}
		// half-up at .5: 100×0.005 = 0.5 → 1
		$this->assertSame(1, MoneyMathService::convertWithRate(100, '0.005'));
		$this->assertSame(0, MoneyMathService::convertWithRate(100, '0.004'));
		$this->assertSame(1000, MoneyMathService::convertWithRate(1000, '1.000000'));
	}

	/** Kills: divRoundHalfUp negative-numerator and denominator mutants. */
	public function testDivRoundHalfUpEdges(): void
	{
		$this->assertSame(3, MoneyMathService::divRoundHalfUp(5, 2));       // 2.5 → half-up away from zero
		$this->assertSame(-3, MoneyMathService::divRoundHalfUp(-5, 2));     // -2.5 → -3 (away from zero)
		$this->assertSame(2, MoneyMathService::divRoundHalfUp(4, 2));
		try {
			MoneyMathService::divRoundHalfUp(1, 0);
			$this->fail('zero denominator accepted');
		} catch (\InvalidArgumentException $e) {
			$this->assertTrue(true);
		}
		try {
			MoneyMathService::divRoundHalfUp(1, -1);
			$this->fail('negative denominator accepted');
		} catch (\InvalidArgumentException $e) {
			$this->assertTrue(true);
		}
	}

	/** Kills percentToBp trim/cast/ternary mutants. */
	public function testPercentToBpTypeHandling(): void
	{
		$this->assertSame(5000, MoneyMathService::percentToBp(' 50 '));
		$this->assertSame(5000, MoneyMathService::percentToBp(50));
		$this->assertSame(1250, MoneyMathService::percentToBp(12.5));
		$this->assertSame(2550, MoneyMathService::percentToBp(25.5));
	}

	/** Kills decimalToScaledInt anchor/trim mutants. */
	public function testDecimalToScaledIntRejectsMalformed(): void
	{
		foreach (['x1.5', '1.5x', 'a', '1.2.3'] as $bad) {
			try {
				MoneyMathService::decimalToScaledInt($bad, 100);
				$this->fail("decimalToScaledInt accepted $bad");
			} catch (ValidationException $e) {
				$this->assertSame('invalid_decimal', $e->getFields()['rate']);
			}
		}
		$this->assertSame(150, MoneyMathService::decimalToScaledInt(' 1.5 ', 100));
		$this->assertSame(-150, MoneyMathService::decimalToScaledInt('-1.5', 100));
		$this->assertSame(1500000, MoneyMathService::decimalToScaledInt('1.5', 1000000));
		$this->assertSame(1234567, MoneyMathService::decimalToScaledInt('1.234567', 1000000));
		$this->assertSame(1234567, MoneyMathService::decimalToScaledInt('1.2345678', 1000000)); // truncated at scale
	}

	/** Kills toMinor empty-input field-map + throw mutants. */
	public function testToMinorEmptyInputFieldMap(): void
	{
		try {
			MoneyMathService::toMinor('  ');
			$this->fail('empty accepted');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['amount']);
		}
		try {
			MoneyMathService::toMinor('abc');
			$this->fail('invalid accepted');
		} catch (ValidationException $e) {
			$this->assertSame('invalid_amount', $e->getFields()['amount']);
		}
	}

	/** Kills resolveAllocations cast-removal mutants (string inputs → ints). */
	public function testResolveAllocationsCastsToInt(): void
	{
		$svc = new MoneyMathService();
		$rows = $svc->resolveAllocations([
			['cost_center_id' => '7', 'amount_minor' => '600', 'pc_project_id' => '9'],
			['cost_center_id' => '8', 'amount_minor' => '400'],
		], 1000);
		$this->assertSame(7, $rows[0]['cost_center_id']);
		$this->assertSame(600, $rows[0]['amount_minor']);
		$this->assertSame(9, $rows[0]['pc_project_id']);
		$this->assertSame(8, $rows[1]['cost_center_id']);
	}

	/** Kills largest-remainder intdiv/remainder-constant mutants. */
	public function testLargestRemainderNonDivisible(): void
	{
		// 101 @ 50/50: floors 50/50, left 1 → first row wins tie.
		$this->assertSame([51, 50], MoneyMathService::percentToAmounts(['50', '50'], 101));
		// 7 @ 33.33/33.33/33.34: floors 2/2/2, left 1 → largest remainder (idx 2, .34).
		$this->assertSame([2, 2, 3], MoneyMathService::percentToAmounts(['33.33', '33.33', '33.34'], 7));
	}
}
