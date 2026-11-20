<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Gate 18 (spec §10.3/§17): the shipped route inventory must match
 * tests/public-routes.allowlist exactly — a silently added or removed
 * endpoint is a build failure, never a diff someone "notices later".
 */
final class RouteInventoryTest extends TestCase
{
	public function testRouteInventoryMatchesAllowlist(): void
	{
		$routes = (require \dirname(__DIR__, 2) . '/appinfo/routes.php')['routes'];
		$actual = [];
		foreach ($routes as $r) {
			$actual[] = $r['name'] . '|' . $r['verb'] . '|' . $r['url'] . '|' . ($r['postfix'] ?? '');
		}
		sort($actual);
		$allowlist = array_values(array_filter(array_map(
			'trim',
			explode("\n", (string)file_get_contents(\dirname(__DIR__, 2) . '/tests/public-routes.allowlist'))
		), fn ($l) => $l !== ''));
		sort($allowlist);
		$this->assertSame($allowlist, $actual,
			"Route inventory drift — update tests/public-routes.allowlist deliberately.\n"
			. 'Missing from allowlist: ' . json_encode(array_diff($actual, $allowlist)) . "\n"
			. 'Stale in allowlist: ' . json_encode(array_diff($allowlist, $actual)));
	}
}
