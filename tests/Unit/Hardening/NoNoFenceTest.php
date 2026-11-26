<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Hardening;

use OCA\ExpenseCheck\Service\ChainRoutingService;
use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use PHPUnit\Framework\TestCase;

/**
 * Gate 20 — NN-fence proofs (spec §7 + §17 gate 20): every Absolute No-No
 * gets a detection test that scans the real shipped artifacts, so "absence"
 * is proven rather than asserted. A future dep, seed, route, or log line
 * that violates a fence fails here.
 */
final class NoNoFenceTest extends TestCase
{
	private const ROOT = __DIR__ . '/../../..';

	/** @return list<string> */
	private static function files(string $sub, string $pattern): array
	{
		$out = [];
		$dir = self::ROOT . '/' . $sub;
		if (!is_dir($dir)) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
		foreach ($it as $f) {
			if ($f->isFile() && fnmatch($pattern, $f->getFilename())) {
				$out[] = $f->getPathname();
			}
		}
		return $out;
	}

	private static function allPhpSource(): string
	{
		$buf = '';
		foreach (self::files('lib', '*.php') as $f) {
			$buf .= file_get_contents($f);
		}
		return $buf;
	}

	public function testNn01NoPaymentExecution(): void
	{
		$composer = (string)file_get_contents(self::ROOT . '/composer.json');
		$package = (string)file_get_contents(self::ROOT . '/package.json');
		foreach (['stripe', 'paypal', 'braintree', 'mollie', 'klarna', 'sepa', 'adyen', 'payone', 'iban4 php'] as $dep) {
			$this->assertStringNotContainsStringIgnoringCase($dep, $composer, "composer.json must not require $dep");
			$this->assertStringNotContainsStringIgnoringCase($dep, $package, "package.json must not require $dep");
		}
		$src = self::allPhpSource();
		foreach (['SepaDirectDebit', 'StripeClient', 'PaypalOrder', 'BankTransferJob', 'SEPA-CREDIT-TRANSFER'] as $needle) {
			$this->assertStringNotContainsString($needle, $src, "no payment-execution code in lib/: $needle");
		}
	}

	public function testNn02NoOcrOrAiAutofill(): void
	{
		$composer = (string)file_get_contents(self::ROOT . '/composer.json');
		$package = (string)file_get_contents(self::ROOT . '/package.json');
		foreach (['tesseract', 'openai', 'anthropic', 'textract', 'vision', 'receipt-ocr', 'mindee', 'veryfi', 'llama', 'gpt'] as $dep) {
			$this->assertStringNotContainsStringIgnoringCase($dep, $composer);
			$this->assertStringNotContainsStringIgnoringCase($dep, $package);
		}
		foreach (self::files('lib', '*.php') as $f) {
			$this->assertDoesNotMatchRegularExpression('/Ocr|AiSuggest|AutoFill|Textract/i', $f, basename($f));
		}
	}

	public function testNn03NoShippedStatutoryRates(): void
	{
		// Rate catalog must ship empty — migrations create schema, never seed.
		foreach (self::files('lib/Migration', '*.php') as $f) {
			$src = (string)file_get_contents($f);
			$this->assertDoesNotMatchRegularExpression('/->insert\(/', $src, basename($f) . ' must not seed rows');
		}
		// No rate data files may ship anywhere.
		foreach (['data', 'resources', 'fixtures', 'assets'] as $dir) {
			foreach (self::files($dir, '*') as $f) {
				$this->assertDoesNotMatchRegularExpression('/rate|vat|per_diem|kilometer|mehrwertsteuer/i', basename($f));
			}
		}
		// No jurisdiction-shaped literals in *code* (EUR/km constants, VAT
		// %). Comments are stripped first — a doc-block example like
		// `"0.30" × 1e6` in the decimal parser is documentation, not a rate.
		$code = '';
		foreach (self::files('lib', '*.php') as $f) {
			foreach (preg_split('/\n/', (string)file_get_contents($f)) as $line) {
				if (!preg_match('/^\s*(\*|\/\/|#)/', $line)) {
					$code .= $line . "\n";
				}
			}
		}
		$this->assertDoesNotMatchRegularExpression('/0\.30|0\.38|VAT_RATE|PER_DIEM_DE|perDiemRate|kmRateMinor/i', $code);
	}

	public function testNn05NoFloatMoney(): void
	{
		foreach (self::files('lib', '*.php') as $f) {
			$src = (string)file_get_contents($f);
			// Float casts may never touch minor/amount/rate expressions.
			$this->assertDoesNotMatchRegularExpression(
				'/\(float\)\s*\$?[a-zA-Z_]*((minor|amount|rate|money|total))/i', $src, basename($f));
			$this->assertDoesNotMatchRegularExpression(
				'/floatval\(\s*\$?[a-zA-Z_]*(minor|amount|rate|total)/i', $src, basename($f));
		}
		// Money columns are integer-typed in the entity layer.
		$src = self::allPhpSource();
		$this->assertDoesNotMatchRegularExpression('/function set[A-Za-z]*Minor\(float/', $src);
	}

	public function testNn06NoDirectSiblingTableAccess(): void
	{
		// Foreign-key *columns* (azc_trip_id, pc_customer_id) are legal;
		// joining/selecting another app's tables is not. Scan for
		// table-names-as-queries only: from()/update()/insert() calls or
		// raw SQL naming a sibling-owned table.
		foreach (self::files('lib', '*.php') as $f) {
			$src = (string)file_get_contents($f);
			$this->assertDoesNotMatchRegularExpression(
				"/(from|update|insert|table)\(\s*['\"](?:\*PREFIX\*)?(azc|mc|bc|cuc|pc|ic|tck|dc)_/",
				$src, basename($f) . ' queries a sibling table');
			$this->assertDoesNotMatchRegularExpression(
				'/\b(JOIN|FROM|INTO|UPDATE)\s+`?\*?P?R?E?F?I?X?\*?(azc|mc|bc|cuc)_/i',
				$src, basename($f) . ' raw SQL touches a sibling table');
		}
	}

	public function testNn07NoPublicAttachmentSurface(): void
	{
		// #[PublicPage] is legitimate exactly on manifest-declared agent
		// methods: scoped agent tokens never establish a session (NN-10),
		// so the framework login gate would kill the request before
		// AgentAuthMiddleware runs. Everywhere else it is forbidden.
		$allowed = [];
		$manifest = json_decode(
			(string)file_get_contents(self::ROOT . '/tests/contracts/agent-tools.json'),
			true
		);
		foreach (($manifest['tools'] ?? []) as $tool) {
			$allowed[(string)explode('#', (string)$tool['route'])[1]] = true;
		}
		foreach (self::files('lib/Controller', '*.php') as $f) {
			if (str_starts_with(basename($f), 'Agent')) {
				continue;
			}
			$src = (string)file_get_contents($f);
			foreach (explode("\n", $src) as $i => $line) {
				if (!str_contains($line, 'PublicPage') || str_starts_with(ltrim($line), 'use ')) {
					continue;
				}
				// the attribute must sit directly above an agent-declared method
				$next = $src;
				$rest = implode("\n", array_slice(explode("\n", $src), $i, 8));
				$hit = preg_match('/public function (\w+)\(/', $rest, $m) === 1
					&& isset($allowed[$m[1]]);
				$this->assertTrue($hit, basename($f) . ": PublicPage on a non-agent method (line " . ($i + 1) . ")");
			}
			$this->assertStringNotContainsString('ShareAPI', $src, basename($f));
		}
		$routes = (string)file_get_contents(self::ROOT . '/appinfo/routes.php');
		$this->assertStringNotContainsString('share', strtolower($routes));
	}

	public function testNn08SettledIsTerminal(): void
	{
		$this->assertSame([], ClaimLifecycleService::TRANSITIONS[ClaimLifecycleService::STATUS_SETTLED]);
		$this->assertSame([], ClaimLifecycleService::TRANSITIONS[ClaimLifecycleService::STATUS_REJECTED]);
	}

	public function testNn09UnroutableFailsToAdminReview(): void
	{
		// The admin-review pool exists as code, not convention.
		$this->assertSame('__admin_review__', ChainRoutingService::ADMIN_REVIEW_UID);
		$src = (string)file_get_contents(self::ROOT . '/lib/Service/ChainRoutingService.php');
		$this->assertStringContainsString('unroutable', $src);
	}

	public function testNn10NoPiiInLogs(): void
	{
		foreach (self::files('lib', '*.php') as $f) {
			foreach (preg_split('/\n/', (string)file_get_contents($f)) as $i => $line) {
				if (preg_match('/->(debug|info|warning|error|critical|log)\(/', $line)) {
					// Flag interpolated PII *values* ($title, {merchant},
					// $claim->getAmount…), not literal words in messages
					// like "receipt write failed".
					$this->assertDoesNotMatchRegularExpression(
						'/[\$\{][a-zA-Z_]*(merchant|title|amountMinor|amount_minor|attendee|purpose|receipt|filename|uid\b)/i',
						$line, basename($f) . ':' . ($i + 1) . ' logs a PII-bearing value');
				}
			}
		}
	}

	public function testNn04Uniform404Taxonomy(): void
	{
		// Entity-misses collapse to a single 404 exception type; controllers
		// must never hand-build 403s (existence/authorization oracle split).
		$src = self::allPhpSource();
		$this->assertStringContainsString('CLAIM_NOT_FOUND', $src);
		foreach (self::files('lib/Controller', '*.php') as $f) {
			$this->assertStringNotContainsString('STATUS_FORBIDDEN', (string)file_get_contents($f), basename($f));
		}
	}
}
