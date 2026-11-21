<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * §9.2 contract guard: every error code the server emits must have a
 * localized client sentence + recovery in js/common/error-copy.js.
 * Scanning lib/ for the emission patterns — a new code that ships without
 * copy fails this test instead of leaking raw server text to users.
 */
class ErrorCopyCoverageTest extends TestCase {
	public function testEveryEmittedCodeHasCopy(): void {
		$app = \dirname(__DIR__, 2);
		$emitted = [];
		foreach ($this->phpFiles($app . '/lib') as $file) {
			$src = file_get_contents($file);
			foreach (['DomainException', 'ApiException', 'ValidationException'] as $ex) {
				if (preg_match_all('/new \\?*' . $ex . "\\('\\s*([A-Z_]{4,})'/", $src, $m)) {
					$emitted += array_flip($m[1]);
				}
			}
			if (preg_match_all("/new self\\('([A-Z_]{4,})'/", $src, $m)) {
				$emitted += array_flip($m[1]);
			}
			if (preg_match_all("/['\"](?:error|error_code)['\"]\\s*=>\\s*'([A-Z_]{4,})'/", $src, $m)) {
				$emitted += array_flip($m[1]);
			}
		}
		$this->assertNotEmpty($emitted, 'scan must find emitted codes — patterns drifted');

		$js = file_get_contents($app . '/js/common/error-copy.js');
		$this->assertNotFalse($js);
		preg_match_all('/^\\s*([A-Z_]{4,})\\s*:/m', $js, $m);
		$mapKeys = array_flip($m[1]);
		preg_match_all("/'([A-Z_]{4,})'/", $js, $m2); // NF-family list entries
		$mapKeys += array_flip($m2[1]);

		$missing = array_diff_key($emitted, $mapKeys);
		$this->assertSame([], array_keys($missing),
			'server error codes without §9.2 client copy: ' . implode(', ', array_keys($missing)));
	}

	/** @return list<string> */
	private function phpFiles(string $dir): array {
		$out = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
			if ($f->isFile() && $f->getExtension() === 'php') {
				$out[] = $f->getPathname();
			}
		}
		return $out;
	}
}
