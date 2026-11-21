<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * GDPR erasure completeness guard: every uid-bearing column declared in the
 * schema must have an explicit erasure rule in ErasureService — pseudonymise,
 * scrub, tombstone, or delete. Adding a PII column without wiring it into
 * eraseUid() fails this test, which is exactly the audit finding this kills.
 */
final class ErasureServiceCoverageTest extends TestCase
{
	/** Columns that carry identity — matched per table. */
	private const UID_COLUMN_RE = '/^(user_id|.*_uid|.*_by|actor|recipient|manager_uids)$/';

	/**
	 * @return array<string,list<string>> table => uid columns
	 */
	private static function schemaUidColumns(): array
	{
		$migrationDir = dirname(__DIR__, 3) . '/lib/Migration';
		$tables = [];
		foreach (glob($migrationDir . '/Version*.php') as $file) {
			$src = file_get_contents($file);
			// Track createTable blocks and the addColumn calls inside them.
			if (!preg_match_all(
				"/createTable\\('(\\w+)'\\)(.*?)(?=createTable|\\z)/s",
				$src, $m, PREG_SET_ORDER
			)) {
				continue;
			}
			foreach ($m as $blk) {
				$table = $blk[1];
				if (!str_starts_with($table, 'exp_')) {
					continue;
				}
				preg_match_all("/addColumn\\('(\\w+)'/", $blk[2], $cols);
				foreach ($cols[1] as $col) {
					if (preg_match(self::UID_COLUMN_RE, $col)) {
						$tables[$table][$col] = true;
					}
				}
			}
		}
		return array_map('array_keys', $tables);
	}

	public function testEveryUidColumnHasAnErasureRule(): void
	{
		$service = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ErasureService.php');
		$this->assertNotFalse($service);
		$missing = [];
		foreach (self::schemaUidColumns() as $table => $cols) {
			foreach ($cols as $col) {
				// The erasure service references each covered column by name.
				if (!str_contains($service, "'" . $col . "'")) {
					$missing[] = "$table.$col";
				}
			}
		}
		$this->assertSame([], $missing,
			'uid columns without an erasure rule: ' . implode(', ', $missing));
	}

	public function testEveryPiiFreeTextColumnIsScrubbed(): void
	{
		$service = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ErasureService.php');
		foreach (['merchant', 'description', 'justification', 'attendees',
			'occasion', 'route_file_id', 'purpose', 'decision_note', 'note'] as $col) {
			$this->assertStringContainsString("'$col'", $service,
				"PII free-text column '$col' not scrubbed");
		}
	}
}
