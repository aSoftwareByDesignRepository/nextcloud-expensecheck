<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Exception\ValidationException;

/**
 * Money math (spec §6.1): every amount is an integer in minor units. No float
 * ever touches a total. Multiplication (distance/days/quantity × rate, FX
 * conversion) is done in scaled integer arithmetic with explicit half-up
 * rounding — deterministic on MariaDB and PostgreSQL.
 */
class MoneyMathService
{
	private const FX_SCALE = 1000000;      // DECIMAL(12,6) → integer micro-rate
	private const QTY_SCALE = 100;         // distance_km/days/quantity DECIMAL(…,2)

	/**
	 * Minor units from a decimal string with at most `decimals` fraction
	 * digits (default 2 = cents). Rejects anything else — no silent rounding
	 * on input.
	 */
	public static function toMinor(string $decimal, int $decimals = 2): int
	{
		$decimal = trim($decimal);
		if ($decimal === '') {
			throw new ValidationException(['amount' => 'required']);
		}
		$pattern = '/^-?\d{1,12}(\.\d{1,' . $decimals . '})?$/';
		if (!preg_match($pattern, $decimal)) {
			throw new ValidationException(['amount' => 'invalid_amount']);
		}
		$neg = str_starts_with($decimal, '-');
		$decimal = ltrim($decimal, '-');
		[$int, $frac] = array_pad(explode('.', $decimal, 2), 2, '');
		$frac = str_pad(substr($frac, 0, $decimals), $decimals, '0');
		$scale = 10 ** $decimals;
		$minor = (int)$int * $scale + ($frac === '' ? 0 : (int)$frac);
		return $neg ? -$minor : $minor;
	}

	/**
	 * quantity × rate → minor units, half-up. Both are exact: qty scaled by
	 * QTY_SCALE (2 decimals), rate already in minor units.
	 */
	public static function quantityTimesRate(int $qtyScaled, int $rateMinor): int
	{
		// (qtyScaled/100) * rateMinor, half-up
		$num = $qtyScaled * $rateMinor;
		$den = self::QTY_SCALE;
		return self::divRoundHalfUp($num, $den);
	}

	/**
	 * Convert foreign minor → claim minor via a stored fx rate string
	 * (DECIMAL(12,6)). amount = foreignMinor × fx, half-up.
	 */
	public static function convertWithRate(int $foreignMinor, string $fxRateDecimal): int
	{
		$fxMicro = self::decimalToScaledInt($fxRateDecimal, self::FX_SCALE);
		if ($fxMicro <= 0) {
			throw new ValidationException(['foreign_fx' => 'invalid_fx']);
		}
		return self::divRoundHalfUp($foreignMinor * $fxMicro, self::FX_SCALE);
	}

	/**
	 * "0.30" × 1e6 → 300000. DECIMAL(12,6)-style strings only.
	 */
	public static function decimalToScaledInt(string $decimal, int $scale): int
	{
		$decimal = trim($decimal);
		if (!preg_match('/^-?\d{1,12}(\.\d{1,9})?$/', $decimal)) {
			throw new ValidationException(['rate' => 'invalid_decimal']);
		}
		$neg = str_starts_with($decimal, '-');
		$decimal = ltrim($decimal, '-');
		[$int, $frac] = array_pad(explode('.', $decimal, 2), 2, '');
		$digits = strlen((string)intval($scale)) - 1;
		$frac = str_pad(substr($frac, 0, $digits), $digits, '0');
		$v = (int)$int * $scale + ($frac === '' ? 0 : (int)$frac);
		return $neg ? -$v : $v;
	}

	/**
	 * qty (DECIMAL(8,2)) → scaled int at QTY_SCALE. "12.5" → 1250.
	 */
	public static function quantityToScaled(string $qty): int
	{
		return self::decimalToScaledInt($qty, self::QTY_SCALE);
	}

	/**
	 * Percent-share allocations → minor amounts that sum EXACTLY to the line
	 * total (largest-remainder method, deterministic order). Returns per-row
	 * minor amounts in input order.
	 *
	 * @param list<float|int|string> $percents e.g. [50, 25.5, 24.5]
	 * @return list<int>
	 */
	public static function percentToAmounts(array $percents, int $lineMinor): array
	{
		$bps = [];
		$totalBp = 0;
		foreach ($percents as $p) {
			$bp = self::percentToBp($p);
			$bps[] = $bp;
			$totalBp += $bp;
		}
		if ($totalBp !== 10000) {
			throw new ValidationException(['allocations' => 'ALLOCATION_SUM_MISMATCH']);
		}
		// Floor each share, then hand the remainder to the largest fractional parts.
		$shares = [];
		$remainders = [];
		$assigned = 0;
		foreach ($bps as $i => $bp) {
			$exact = $lineMinor * $bp;           // in 1/10000 units
			$floor = intdiv($exact, 10000);
			$shares[$i] = $floor;
			$remainders[$i] = $exact - $floor * 10000;
			$assigned += $floor;
		}
		$left = $lineMinor - $assigned;
		// Largest remainder first; stable on ties (earlier row wins) — deterministic.
		$order = array_keys($remainders);
		usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);
		foreach (array_slice($order, 0, $left) as $i) {
			$shares[$i]++;
		}
		return $shares;
	}

	/** "12.34"|12.34|12 → basis points (×1e4). */
	public static function percentToBp(float|int|string $percent): int
	{
		$s = is_string($percent) ? trim($percent) : (string)$percent;
		if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $s)) {
			throw new ValidationException(['allocations' => 'invalid_percent']);
		}
		$v = self::decimalToScaledInt($s, 100);
		if ($v < 0 || $v > 10000) {
			throw new ValidationException(['allocations' => 'invalid_percent']);
		}
		return $v;
	}

	/**
	 * Validate a full allocation set for one line:
	 * either all rows percent-based (sum exactly 100.00) or all amount-based
	 * (sum exactly lineMinor). Mixed modes are rejected — ambiguity is a bug
	 * source, not a feature. Returns normalized rows with resolved amounts.
	 *
	 * @param list<array{cost_center_id:int,share_percent:?string,amount_minor:?int,pc_project_id:?int}> $allocs
	 * @return list<array{cost_center_id:int,amount_minor:int,pc_project_id:?int}>
	 */
	public function resolveAllocations(array $allocs, int $lineMinor): array
	{
		if ($allocs === []) {
			throw new ValidationException(['allocations' => 'required']);
		}
		$percentMode = null;
		foreach ($allocs as $a) {
			$hasPct = ($a['share_percent'] ?? null) !== null && $a['share_percent'] !== '';
			$hasAmt = ($a['amount_minor'] ?? null) !== null && $a['amount_minor'] !== '';
			if ($hasPct === $hasAmt) {
				// exactly one of percent|amount per row (XOR)
				throw new ValidationException(['allocations' => 'ALLOCATION_SUM_MISMATCH']);
			}
			$mode = $hasPct ? 'pct' : 'amt';
			if ($percentMode === null) {
				$percentMode = $mode;
			} elseif ($percentMode !== $mode) {
				throw new ValidationException(['allocations' => 'ALLOCATION_SUM_MISMATCH']);
			}
		}
		if ($percentMode === 'pct') {
			$amounts = self::percentToAmounts(array_map(fn ($a) => $a['share_percent'], $allocs), $lineMinor);
		} else {
			$sum = 0;
			foreach ($allocs as $a) {
				$sum += (int)$a['amount_minor'];
			}
			if ($sum !== $lineMinor) {
				throw new ValidationException(['allocations' => 'ALLOCATION_SUM_MISMATCH']);
			}
			$amounts = array_map(fn ($a) => (int)$a['amount_minor'], $allocs);
		}
		$out = [];
		foreach ($allocs as $i => $a) {
			$out[] = [
				'cost_center_id' => (int)$a['cost_center_id'],
				'amount_minor' => $amounts[$i],
				'pc_project_id' => isset($a['pc_project_id']) ? (int)$a['pc_project_id'] : null,
			];
		}
		return $out;
	}

	/**
	 * Integer division with half-up rounding for possibly-negative numerators.
	 * Deterministic across platforms — the money primitive everything uses.
	 */
	public static function divRoundHalfUp(int $num, int $den): int
	{
		if ($den <= 0) {
			throw new \InvalidArgumentException('denominator must be positive');
		}
		if ($num >= 0) {
			return intdiv($num + intdiv($den, 2), $den);
		}
		return -self::divRoundHalfUp(-$num, $den);
	}

	/**
	 * Claim totals from line rows. Claim-currency amounts only — the wire
	 * never sums mixed currencies.
	 *
	 * @param list<array{amount_minor:int,reimbursable:int}> $lines
	 * @return array{total:int,reimbursable:int,nonreimbursable:int}
	 */
	public static function claimTotals(array $lines): array
	{
		$total = 0;
		$reimb = 0;
		foreach ($lines as $l) {
			$amt = (int)$l['amount_minor'];
			$total += $amt;
			if ((int)$l['reimbursable'] === 1) {
				$reimb += $amt;
			}
		}
		return ['total' => $total, 'reimbursable' => $reimb, 'nonreimbursable' => $total - $reimb];
	}
}
