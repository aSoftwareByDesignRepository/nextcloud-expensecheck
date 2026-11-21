<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Service\AzcBridge;
use OCA\ExpenseCheck\Service\McBridge;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Standalone-mode proof (§12 exit gate): with no sibling facades loadable —
 * the EXP test env only autoloads OCA\ExpenseCheck, so class_exists()
 * inside the bridges fails exactly like an uninstalled sibling — every
 * read must degrade to empty/null, never throw, never fabricate.
 */
class SiblingBridgesTest extends TestCase {
	private function appManager(bool $enabled): IAppManager {
		$m = $this->createMock(IAppManager::class);
		$m->method('isEnabledForUser')->willReturn($enabled);
		return $m;
	}

	public function testMcSegmentsEmptyWhenAppDisabled(): void {
		$bridge = new McBridge($this->appManager(false));
		$this->assertFalse($bridge->available());
		$this->assertSame([], $bridge->segmentsFor('u1'));
		$this->assertSame([], $bridge->openChargebacksFor('u1'));
		$this->assertNull($bridge->expenseSubmitEnabled());
	}

	public function testMcFacadesAbsentStillFailClosed(): void {
		// App "enabled": the real facade may resolve via Server::get (the
		// suite autoloads all apps under apps/). Either way the bridge must
		// return safe data — never crash into the claims surface.
		$bridge = new McBridge($this->appManager(true));
		$this->assertIsArray($bridge->segmentsFor('u1'));
		$this->assertIsArray($bridge->openChargebacksFor('u1'));
		$enabled = $bridge->expenseSubmitEnabled();
		$this->assertTrue($enabled === null || is_bool($enabled));
	}

	public function testMcEmptyUidIsRejectedBeforeFacade(): void {
		$bridge = new McBridge($this->appManager(true));
		$this->assertSame([], $bridge->segmentsFor(''));
	}

	public function testAzcAbsentDegradesEverywhere(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/apps/arbeitszeitcheck/trips');
		$bridge = new AzcBridge(
			$this->appManager(false), $urls,
			$this->createMock(LoggerInterface::class));
		$this->assertFalse($bridge->available());
		$this->assertFalse($bridge->facadeAvailable());
		$this->assertSame([], $bridge->teamIdsFor('u1'));
		$this->assertNull($bridge->doorConfig());
	}

	public function testAzcEnabledButFacadeMissing(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://nc.local');
		$bridge = new AzcBridge(
			$this->appManager(true), $urls,
			$this->createMock(LoggerInterface::class));
		$this->assertTrue($bridge->available()); // app on
		// The facade may or may not resolve in this env — either outcome is
		// contract-legal, but every read must stay safe-shaped.
		$facadeUp = $bridge->facadeAvailable();
		$this->assertIsBool($facadeUp);
		$this->assertSame([], $bridge->teamIdsFor('u1'));
		if ($facadeUp) {
			$this->assertIsArray($bridge->doorConfig());
		} else {
			$this->assertNull($bridge->doorConfig());
		}
		// Deep-links always work — they are plain URLs, not facade calls.
		$this->assertSame('https://nc.local/apps/arbeitszeitcheck/api/business-trips', $bridge->tripsListUrl());
		$this->assertSame('https://nc.local/apps/arbeitszeitcheck/business-trips/5', $bridge->tripPageUrl(5));
	}
	// ── deterministic facade stubs: normalization + fail-closed guards ──

	private function mcWithFacades(?object $segments, ?object $chargebacks): McBridge {
		return new class($this->appManager(true), $segments, $chargebacks) extends McBridge {
			public function __construct(
				\OCP\App\IAppManager $m, private ?object $seg, private ?object $cb) {
				parent::__construct($m);
			}
			protected function facade(string $class): ?object {
				return $class === self::SEGMENT_FACADE ? $this->seg : $this->cb;
			}
		};
	}

	public function testMcSegmentsNormalizesValidRowsAndDropsBroken(): void {
		$facade = new class {
			public function segmentsFor(string $uid): array {
				return [
					['segment_id' => 5, 'date' => '2026-11-02', 'from' => 'A', 'to' => 'B',
					 'distance_km' => 12.4, 'vehicle_label' => 'Golf', 'claimable' => true],
					['segment_id' => 0, 'date' => '2026-11-03', 'distance_km' => 9],   // no id → drop
					['segment_id' => 6, 'date' => '', 'distance_km' => 9],            // no date → drop
					['segment_id' => 7, 'date' => '2026-11-04', 'distance_km' => 0],  // no km → drop
				];
			}
		};
		$rows = $this->mcWithFacades($facade, null)->segmentsFor('u1');
		$this->assertCount(1, $rows);
		$r = $rows[0];
		$this->assertSame(5, $r['segment_id']);
		$this->assertSame('2026-11-02', $r['date']);
		$this->assertSame('A', $r['from']);
		$this->assertSame('B', $r['to']);
		$this->assertSame(12.4, $r['distance_km']);
		$this->assertSame('Golf', $r['vehicle_label']);
		$this->assertTrue($r['claimable']);
	}

	public function testMcSegmentsNonArrayFacadeResultIsEmpty(): void {
		$facade = new class { public function segmentsFor(string $u): mixed { return 'garbage'; } };
		$this->assertSame([], $this->mcWithFacades($facade, null)->segmentsFor('u1'));
	}

	public function testMcSegmentsNullFacadeIsEmpty(): void {
		$this->assertSame([], $this->mcWithFacades(null, null)->segmentsFor('u1'));
	}

	public function testMcChargebacksNormalizesAndDropsBroken(): void {
		$facade = new class {
			public function openFor(string $uid): array {
				return [
					['chargeback_id' => 3, 'amount_minor' => 2500, 'entry_date' => '2026-10-30',
					 'notes' => 'Fuel below minimum', 'vehicle_label' => 'Van'],
					['chargeback_id' => 0, 'amount_minor' => 500],   // no id → drop
					['chargeback_id' => 4, 'amount_minor' => 0],     // zero → drop
				];
			}
		};
		$rows = $this->mcWithFacades(null, $facade)->openChargebacksFor('u1');
		$this->assertCount(1, $rows);
		$this->assertSame(3, $rows[0]['chargeback_id']);
		$this->assertSame(2500, $rows[0]['amount_minor']);
		$this->assertSame('2026-10-30', $rows[0]['entry_date']);
	}

	public function testMcExpenseSubmitEnabledReadsFacadeFlag(): void {
		$facade = new class { public function expenseSubmitEnabled(): bool { return false; } };
		$this->assertFalse($this->mcWithFacades($facade, null)->expenseSubmitEnabled());
	}

	public function testMcExpenseSubmitEnabledNullWithoutFacade(): void {
		$this->assertNull($this->mcWithFacades(null, null)->expenseSubmitEnabled());
	}

	public function testMcEmptyUidNeverReachesFacade(): void {
		$facade = new class { public function segmentsFor(string $u): array {
			throw new \LogicException('must not be called'); } };
		$this->assertSame([], $this->mcWithFacades($facade, null)->segmentsFor(''));
		$cb = new class { public function openFor(string $u): array {
			throw new \LogicException('must not be called'); } };
		$this->assertSame([], $this->mcWithFacades(null, $cb)->openChargebacksFor(''));
	}

	private function azcWithFacade(?object $facade): AzcBridge {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getBaseUrl')->willReturn('https://nc.local');
		return new class($this->appManager(true), $urls,
			$this->createMock(LoggerInterface::class), $facade) extends AzcBridge {
			public function __construct(\OCP\App\IAppManager $m, \OCP\IURLGenerator $u,
				LoggerInterface $l, private ?object $f) {
				parent::__construct($m, $u, $l);
			}
			protected function orgFacade(): ?object { return $this->f; }
			public function facadeAvailable(): bool { return $this->f !== null; }
		};
	}

	public function testAzcTeamIdsDedupesAndDropsNonPositive(): void {
		$facade = new class {
			public function teamIdsFor(string $uid): array { return [4, '7', 4, -1, 0, 'x']; }
		};
		$this->assertSame([4, 7], $this->azcWithFacade($facade)->teamIdsFor('u1'));
	}

	public function testAzcTeamIdsEmptyUidNeverReachesFacade(): void {
		$facade = new class { public function teamIdsFor(string $u): array {
			throw new \LogicException('must not be called'); } };
		$this->assertSame([], $this->azcWithFacade($facade)->teamIdsFor(''));
	}

	public function testAzcTeamIdsEmptyWithoutFacade(): void {
		$this->assertSame([], $this->azcWithFacade(null)->teamIdsFor('u1'));
	}

	public function testAzcDoorConfigNormalizesShape(): void {
		$facade = new class {
			public function doorConfig(): array {
				return ['mode' => 'restricted', 'allowed_users' => ['u1', 9], 'allowed_groups' => ['g1']];
			}
		};
		$cfg = $this->azcWithFacade($facade)->doorConfig();
		$this->assertSame('restricted', $cfg['mode']);
		$this->assertSame(['u1', '9'], $cfg['allowed_users']);
		$this->assertSame(['g1'], $cfg['allowed_groups']);
	}

	public function testAzcDoorConfigNullWithoutFacade(): void {
		$this->assertNull($this->azcWithFacade(null)->doorConfig());
	}

	public function testAzcDoorConfigNullWhenMethodMissing(): void {
		$facade = new class {}; // no doorConfig — older AZC facade
		$this->assertNull($this->azcWithFacade($facade)->doorConfig());
	}

	public function testMcDisabledAppIgnoresFacadeRows(): void {
		// || → && on the guard would let facade rows leak on a disabled app.
		$seg = new class { public function segmentsFor(string $u): array {
			return [['segment_id' => 5, 'date' => '2026-11-02', 'distance_km' => 9]];
		} };
		$cb = new class { public function openFor(string $u): array {
			return [['chargeback_id' => 3, 'amount_minor' => 100]];
		} };
		$bridge = new class($this->appManager(false), $seg, $cb) extends McBridge {
			public function __construct(
				\OCP\App\IAppManager $m, private ?object $seg, private ?object $cb) {
				parent::__construct($m);
			}
			protected function facade(string $class): ?object {
				return $class === self::SEGMENT_FACADE ? $this->seg : $this->cb;
			}
		};
		$this->assertSame([], $bridge->segmentsFor('u1'));
		$this->assertSame([], $bridge->openChargebacksFor('u1'));
	}

	public function testMcSegmentsAcceptsObjectRowsAndMissingKeysAreDropped(): void {
		$row = new \stdClass();
		$row->segment_id = 8; $row->date = '2026-11-05'; $row->distance_km = 3.5;
		$facade = new class($row) {
			public function __construct(private \stdClass $row) {}
			public function segmentsFor(string $u): array {
				return [$this->row, ['date' => '2026-11-06', 'distance_km' => 4]]; // missing segment_id → drop
			}
		};
		$rows = $this->mcWithFacades($facade, null)->segmentsFor('u1');
		$this->assertCount(1, $rows);
		$this->assertSame(8, $rows[0]['segment_id']);
		$this->assertFalse($rows[0]['claimable']); // missing → false, not error
	}

	public function testMcChargebackMissingKeysAreDropped(): void {
		$facade = new class { public function openFor(string $u): array {
			return [['amount_minor' => 500]]; // no chargeback_id → drop
		} };
		$this->assertSame([], $this->mcWithFacades(null, $facade)->openChargebacksFor('u1'));
	}

	public function testAzcFacadeThrowingDegradesEmpty(): void {
		$facade = new class { public function teamIdsFor(string $u): array {
			throw new \RuntimeException('db gone'); } };
		$this->assertSame([], $this->azcWithFacade($facade)->teamIdsFor('u1'));
		$this->assertNull($this->azcWithFacade($facade)->doorConfig());
	}

	public function testAzcDisabledIgnoresFacade(): void {
		$facade = new class { public function teamIdsFor(string $u): array { return [5]; } };
		$urls = $this->createMock(IURLGenerator::class);
		$bridge = new class($this->appManager(false), $urls,
			$this->createMock(LoggerInterface::class), $facade) extends AzcBridge {
			public function __construct(\OCP\App\IAppManager $m, \OCP\IURLGenerator $u,
				LoggerInterface $l, private ?object $f) {
				parent::__construct($m, $u, $l);
			}
			protected function orgFacade(): ?object { return $this->f; }
		};
		$this->assertSame([], $bridge->teamIdsFor('u1'));
		$this->assertNull($bridge->doorConfig());
	}

}
