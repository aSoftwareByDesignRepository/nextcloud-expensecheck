<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

/**
 * Formula-injection-safe CSV (spec §7.5): any cell starting with =, +, -, @,
 * TAB, or CR is prefixed with a single quote so Excel/LibreOffice never
 * interprets it as a formula. Deterministic output: caller supplies ordered
 * rows; we never sort.
 */
class CsvExporter
{
	private const DANGER_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

	/**
	 * @param list<string> $header
	 * @param list<list<string|int|null>> $rows
	 */
	public static function render(array $header, array $rows): string
	{
		$out = "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
		$out .= self::line($header);
		foreach ($rows as $row) {
			$out .= self::line(array_map(fn ($v) => $v === null ? '' : (string)$v, $row));
		}
		return $out;
	}

	/** @param list<string> $cells */
	private static function line(array $cells): string
	{
		return implode(';', array_map([self::class, 'cell'], $cells)) . "\r\n";
	}

	private static function cell(string $v): string
	{
		foreach (self::DANGER_PREFIXES as $p) {
			if (str_starts_with($v, $p)) {
				$v = "'" . $v;
				break;
			}
		}
		if (str_contains($v, ';') || str_contains($v, '"') || str_contains($v, "\n")) {
			$v = '"' . str_replace('"', '""', $v) . '"';
		}
		return $v;
	}
}
