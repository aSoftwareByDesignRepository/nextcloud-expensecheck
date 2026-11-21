<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Support;

use OCA\ExpenseCheck\Support\AppFeedbackLinks;
use PHPUnit\Framework\TestCase;

final class AppFeedbackLinksTest extends TestCase
{
	public function testProblemMailtoUsesDevInboxAndEncodedSubject(): void
	{
		$links = new AppFeedbackLinks('expensecheck', 'ExpenseCheck', '1.2.3');
		$en = $links->problemMailto(['pageUrl' => '/apps/expensecheck/today'], 'en');
		$de = $links->problemMailto(['pageUrl' => '/apps/expensecheck/today'], 'de');
		self::assertStringStartsWith('mailto:dev@software-by-design.de?subject=', $en);
		self::assertStringContainsString(rawurlencode('ExpenseCheck: Problem report'), $en);
		self::assertStringContainsString(rawurlencode('ExpenseCheck: Fehlermeldung'), $de);
		self::assertStringContainsString('body=', $en);
		self::assertStringNotContainsString("\n", $en);
		self::assertStringNotContainsString('info@software-by-design.de', $en);
	}

	public function testIdeaMailtoUsesFeedbackSubject(): void
	{
		$links = new AppFeedbackLinks('expensecheck', 'ExpenseCheck');
		$href = $links->ideaMailto([], 'de');
		self::assertStringContainsString(rawurlencode('ExpenseCheck: Feedback'), $href);
	}

	public function testGithubIssuesUrlIsPublicVendorRepo(): void
	{
		$links = new AppFeedbackLinks('expensecheck', 'ExpenseCheck');
		self::assertSame(
			'https://github.com/aSoftwareByDesignRepository/nextcloud-expensecheck/issues',
			$links->githubIssuesUrl()
		);
	}

	public function testSanitizePageUrlStripsCredentialQueryKeys(): void
	{
		$links = new AppFeedbackLinks('expensecheck', 'ExpenseCheck');
		$clean = $links->sanitizePageUrl('https://cloud.example/apps/expensecheck/x?token=abc&keep=1&password=no');
		self::assertStringContainsString('keep=1', $clean);
		self::assertStringNotContainsString('token=', $clean);
		self::assertStringNotContainsString('password=', $clean);
		self::assertSame('', $links->sanitizePageUrl('javascript:alert(1)'));
		self::assertSame('', $links->sanitizePageUrl('data:text/html,<script>alert(1)</script>'));
		self::assertSame('', $links->sanitizePageUrl('vbscript:msgbox(1)'));
		self::assertSame('', $links->sanitizePageUrl("https://x.example/\r\nBcc:evil@example.com"));
		$keep = $links->sanitizePageUrl('https://cloud.example/apps/expensecheck/list?keep=1&token=abc');
		self::assertStringContainsString('keep=1', $keep);
		self::assertStringNotContainsString('token=', $keep);
	}

	public function testSanitizePageUrlStripsNextcloudUpgradeAckQueryKey(): void
	{
		$links = new AppFeedbackLinks('expensecheck', 'ExpenseCheck');
		$noisy = '/apps/expensecheck/?IKnowThatThisIsABigInstanceAndTheUpdateRequestCouldRunIntoATimeoutAndHowToRestoreABackup=IAmSuperSureToDoThis';
		self::assertSame('/apps/expensecheck/', $links->sanitizePageUrl($noisy));
	}

	public function testMailtoBodyOmitsIdentityAndRejectsUnsafeErrorCodes(): void
	{
		$links = new AppFeedbackLinks('expensecheck', 'ExpenseCheck', '9.9.9');
		$href = $links->problemMailto([
			'errorCode' => 'CONFLICT',
			'pageUrl' => '/apps/expensecheck/',
		], 'en');
		$body = urldecode((string)(parse_url($href, PHP_URL_QUERY) ? explode('body=', (string)parse_url($href, PHP_URL_QUERY), 2)[1] ?? '' : ''));
		self::assertStringContainsString('Error code: CONFLICT', $body);
		self::assertStringContainsString('App id: expensecheck', $body);
		self::assertStringNotContainsString('uid=', $body);
		self::assertStringNotContainsString('@', explode('--- Auto-filled', $body)[1] ?? $body);

		$bad = $links->problemMailto(['errorCode' => 'x <script>'], 'en');
		$badBody = urldecode((string)(parse_url($bad, PHP_URL_QUERY) ? explode('body=', (string)parse_url($bad, PHP_URL_QUERY), 2)[1] ?? '' : ''));
		self::assertStringNotContainsString('Error code:', $badBody);
	}

	public function testRejectsUnsafeDisplayName(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		new AppFeedbackLinks('expensecheck', "Evil\r\nBcc: attacker@example.com");
	}

	public function testForLocalePayload(): void
	{
		$links = new AppFeedbackLinks('expensecheck', 'ExpenseCheck', '1.0.0');
		$payload = $links->forLocale('de', ['pageUrl' => '/apps/expensecheck/']);
		self::assertSame('dev@software-by-design.de', $payload['feedbackEmail']);
		self::assertStringStartsWith('mailto:dev@software-by-design.de', $payload['problemMailto']);
		self::assertStringStartsWith('mailto:dev@software-by-design.de', $payload['ideaMailto']);
		self::assertStringStartsWith('https://github.com/aSoftwareByDesignRepository/', $payload['githubIssuesUrl']);
		self::assertSame('expensecheck', $payload['appId']);
	}
}
