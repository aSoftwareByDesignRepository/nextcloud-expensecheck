<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Middleware;

use OCA\ExpenseCheck\Exception\AppAccessDeniedException;
use OCA\ExpenseCheck\Exception\CompanionException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\SessionCsrfException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Middleware\ApiExceptionMiddleware;
use OCA\ExpenseCheck\Middleware\AppAccessMiddleware;
use OCA\ExpenseCheck\Middleware\ClientLicenseMiddleware;
use OCA\ExpenseCheck\Middleware\SessionCsrfMiddleware;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\LicenseService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StubController.php';
use Psr\Log\LoggerInterface;

/**
 * The four request guards — each maps its failure to the documented
 * envelope. Wrong mapping = wrong client behavior (and, for the uniform-404
 * collapse, an existence oracle).
 */
final class MiddlewareTest extends TestCase
{
	private IRequest&MockObject $request;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void
	{
		$this->request = $this->createStub(IRequest::class);
		$this->logger = $this->createStub(LoggerInterface::class);
	}

	private function requestWith(string $path, array $headers = [], string $method = 'GET'): IRequest&MockObject
	{
		$r = $this->createMock(IRequest::class);
		$r->method('getPathInfo')->willReturn($path);
		$r->method('getHeader')->willReturnCallback(fn ($n) => $headers[strtolower($n)] ?? '');
		$r->method('getMethod')->willReturn($method);
		$r->method('getParam')->willReturnCallback(fn ($k, $d = null) => $d);
		return $r;
	}

	// ── ApiExceptionMiddleware ────────────────────────────────────────────

	public function testDomainExceptionMapsCodeAndStatus(): void
	{
		$mw = new ApiExceptionMiddleware($this->requestWith('/index.php/apps/expensecheck/api/claims'),
			$this->createStub(IFactory::class), $this->logger);
		$e = new DomainException('VERSION_CONFLICT', 'stale', 409);
		$res = $mw->afterException(new \stdClass(), 'x', $e);
		$body = $res->getData();
		$this->assertSame('VERSION_CONFLICT', $body['error']);
		$this->assertSame('VERSION_CONFLICT', $body['error_code']);
		$this->assertSame('stale', $body['message']);
		$this->assertFalse($body['ok']);
		$this->assertSame(409, $res->getStatus());
	}

	public function testValidationExceptionCarriesFields(): void
	{
		$mw = new ApiExceptionMiddleware($this->requestWith('/apps/expensecheck/api/claims'),
			$this->createStub(IFactory::class), $this->logger);
		$res = $mw->afterException(new \stdClass(), 'x', new ValidationException(['note' => 'required']));
		$this->assertSame('VALIDATION_FAILED', $res->getData()['error']);
		$this->assertSame(['note' => 'required'], $res->getData()['fields']);
		$this->assertSame(422, $res->getStatus());
	}

	public function testPageNavigationRethrows(): void
	{
		$mw = new ApiExceptionMiddleware($this->requestWith('/index.php/apps/expensecheck/claims'),
			$this->createStub(IFactory::class), $this->logger);
		$e = new \RuntimeException('boom');
		$this->expectExceptionObject($e);
		$mw->afterException(new \stdClass(), 'x', $e);
	}

	public function testUnknownExceptionBecomesGeneric500WithoutLeak(): void
	{
		$l10n = $this->createStub(\OCP\IL10N::class);
		$l10n->method('t')->willReturn('an_error_occurred');
		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$mw = new ApiExceptionMiddleware($this->requestWith('/apps/expensecheck/api/claims'), $factory, $this->logger);
		$res = $mw->afterException(new \stdClass(), 'x', new \RuntimeException('sensitive-sql-detail'));
		$this->assertSame(500, $res->getStatus());
		$this->assertSame('internal_error', $res->getData()['error']);
		$this->assertSame('an_error_occurred', $res->getData()['message']);
		$this->assertStringNotContainsString('sensitive-sql-detail', json_encode($res->getData()));
	}

	public function testJsonAcceptHeaderAlsoTreatedAsApi(): void
	{
		$mw = new ApiExceptionMiddleware(
			$this->requestWith('/index.php/apps/expensecheck/claims', ['accept' => 'application/json']),
			$this->createStub(IFactory::class), $this->logger);
		$res = $mw->afterException(new \stdClass(), 'x', new DomainException('X', 'm', 409));
		$this->assertSame('X', $res->getData()['error']);
	}

	// ── SessionCsrfMiddleware ─────────────────────────────────────────────

	private function csrfMiddleware(IRequest&MockObject $req, ?IUser $user, bool $validToken): SessionCsrfMiddleware
	{
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$validator = $this->createStub(\OCA\ExpenseCheck\Service\CsrfTokenValidator::class);
		$validator->method('isValid')->willReturn($validToken);
		return new SessionCsrfMiddleware($req, $session, $validator);
	}

	private function user(string $uid): IUser&MockObject
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		return $u;
	}

	public function testCsrfSkipsGet(): void
	{
		$mw = $this->csrfMiddleware($this->requestWith('/apps/expensecheck/api/claims', [], 'GET'), $this->user('u'), false);
		$mw->beforeController(new \OCA\ExpenseCheck\Controller\StubController(), 'm'); // no exception
		$this->assertTrue(true);
	}

	public function testCsrfSkipsBasicAuthMutation(): void
	{
		$mw = $this->csrfMiddleware(
			$this->requestWith('/apps/expensecheck/api/claims', ['authorization' => 'Basic dTpw'], 'POST'),
			$this->user('u'), false);
		$mw->beforeController(new \OCA\ExpenseCheck\Controller\StubController(), 'm');
		$this->assertTrue(true);
	}

	public function testCsrfSkipsAgentMutation(): void
	{
		// Agent tokens are non-ambient credentials — the request must not be
		// forced to carry a session requesttoken.
		$mw = $this->csrfMiddleware(
			$this->requestWith('/apps/expensecheck/api/claims', ['x-expensecheck-agent' => 'ecst_token'], 'POST'),
			$this->user('u'), false);
		$mw->beforeController(new \OCA\ExpenseCheck\Controller\StubController(), 'm');
		$this->assertTrue(true);
	}

	public function testCsrfRejectsSessionMutationWithoutToken(): void
	{
		$mw = $this->csrfMiddleware(
			$this->requestWith('/apps/expensecheck/api/claims', [], 'POST'), $this->user('u'), false);
		$this->expectException(SessionCsrfException::class);
		$mw->beforeController(new \OCA\ExpenseCheck\Controller\StubController(), 'm');
	}

	public function testCsrfAcceptsValidToken(): void
	{
		$req = $this->createMock(IRequest::class);
		$req->method('getMethod')->willReturn('POST');
		$req->method('getHeader')->willReturnCallback(fn ($n) => strtolower($n) === 'requesttoken' ? 'tok' : '');
		$req->method('getParam')->willReturnCallback(fn ($k, $d = null) => $k === 'requesttoken' ? 'tok' : $d);
		$mw = $this->csrfMiddleware($req, $this->user('u'), true);
		$mw->beforeController(new \OCA\ExpenseCheck\Controller\StubController(), 'm');
		$this->assertTrue(true);
	}

	public function testCsrfSkipsAnonymous(): void
	{
		$mw = $this->csrfMiddleware($this->requestWith('/apps/expensecheck/api/x', [], 'POST'), null, false);
		$mw->beforeController(new \OCA\ExpenseCheck\Controller\StubController(), 'm');
		$this->assertTrue(true);
	}

	public function testCsrfExceptionMapsTo412(): void
	{
		$session = $this->createStub(IUserSession::class);
		$mw = new SessionCsrfMiddleware($this->requestWith('/x', [], 'POST'), $session,
			$this->createStub(\OCA\ExpenseCheck\Service\CsrfTokenValidator::class));
		$res = $mw->afterException(new \stdClass(), 'm', new SessionCsrfException());
		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $res->getStatus());
		$this->assertSame('CSRF_FAILED', $res->getData()['error']['code']);
	}

	// ── AppAccessMiddleware ───────────────────────────────────────────────

	public function testAccessDoorPassesAnonymous(): void
	{
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$mw = new AppAccessMiddleware($session, $this->createStub(AccessControlService::class),
			$this->requestWith('/x'), $this->logger, $this->createStub(IFactory::class),
			$this->createStub(IURLGenerator::class));
		$mw->beforeController(new \OCA\ExpenseCheck\Controller\StubController(), 'm');
		$this->assertTrue(true);
	}

	public function testAccessDoorBlocksDeniedUser(): void
	{
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($this->user('restricted'));
		$access = $this->createStub(AccessControlService::class);
		$access->method('canUseApp')->willReturn(false);
		$mw = new AppAccessMiddleware($session, $access, $this->requestWith('/x'), $this->logger,
			$this->createStub(IFactory::class), $this->createStub(IURLGenerator::class));
		$this->expectException(AppAccessDeniedException::class);
		$mw->beforeController(new \OCA\ExpenseCheck\Controller\StubController(), 'm');
	}

	public function testAccessDeniedMapsTo403(): void
	{
		$mw = new AppAccessMiddleware($this->createStub(IUserSession::class),
			$this->createStub(AccessControlService::class), $this->requestWith('/apps/expensecheck/api/claims'),
			$this->logger, $this->createStub(IFactory::class), $this->createStub(IURLGenerator::class));
		$res = $mw->afterException(new \stdClass(), 'm', new AppAccessDeniedException('restriction'));
		$this->assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
		$this->assertSame('APP_ACCESS_DENIED', $res->getData()['error']['code']);
		$this->assertSame('restriction', $res->getData()['error']['reason']);
	}

	public function testAccessDeniedRendersTemplateForPageRequest(): void
	{
		$l10n = $this->createStub(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createStub(IURLGenerator::class);
		$urls->method('linkToDefaultPageUrl')->willReturn('/');
		$mw = new AppAccessMiddleware($this->createStub(IUserSession::class),
			$this->createStub(AccessControlService::class), $this->requestWith('/apps/expensecheck/claims'),
			$this->logger, $factory, $urls);
		$res = $mw->afterException(new \stdClass(), 'm', new AppAccessDeniedException('restriction'));
		$this->assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
		$this->assertInstanceOf(\OCP\AppFramework\Http\TemplateResponse::class, $res);
		$this->assertSame('access-denied', $res->getTemplateName());
		$this->assertSame('restriction', AccessControlService::DENIAL_RESTRICTION);
	}

	// ── ClientLicenseMiddleware ───────────────────────────────────────────

	private function licenseMiddleware(string $path, array $headers, ?IUser $user, LicenseService $lic): ClientLicenseMiddleware
	{
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new ClientLicenseMiddleware($this->requestWith($path, $headers), $session, $lic, $this->logger);
	}

	public function testMobileGateIgnoresNonMobilePath(): void
	{
		$lic = $this->createStub(LicenseService::class);
		$mw = $this->licenseMiddleware('/index.php/apps/expensecheck/api/claims', [], null, $lic);
		$mw->beforeController(new \stdClass(), 'm');
		$this->assertTrue(true);
	}

	public function testMobileGateRequiresBasicAuth(): void
	{
		$lic = $this->createStub(LicenseService::class);
		$mw = $this->licenseMiddleware('/apps/expensecheck/api/mobile/claims', [], $this->user('u'), $lic);
		$this->expectException(CompanionException::class);
		$mw->beforeController(new \stdClass(), 'm');
	}

	public function testMobileGateBootstrapIsExempt(): void
	{
		$lic = $this->createStub(LicenseService::class);
		$mw = $this->licenseMiddleware('/apps/expensecheck/api/mobile/bootstrap',
			['authorization' => 'Basic dTpw'], $this->user('u'), $lic);
		$mw->beforeController(new \stdClass(), 'm');
		$this->assertTrue(true);
	}

	public function testMobileGateNoLicenseFails(): void
	{
		$lic = $this->createStub(LicenseService::class);
		$lic->method('isMobilePlanActive')->willReturn(false);
		$mw = $this->licenseMiddleware('/apps/expensecheck/api/mobile/claims',
			['authorization' => 'Basic dTpw'], $this->user('u'), $lic);
		$this->expectException(CompanionException::class);
		$mw->beforeController(new \stdClass(), 'm');
	}

	public function testMobileGateLicensedCallsSeatCheck(): void
	{
		$lic = $this->createMock(LicenseService::class);
		$lic->method('isMobilePlanActive')->willReturn(true);
		$lic->expects($this->once())->method('assertMobileAccess')->with('u');
		$mw = $this->licenseMiddleware('/apps/expensecheck/api/mobile/claims',
			['authorization' => 'Basic dTpw'], $this->user('u'), $lic);
		$mw->beforeController(new \stdClass(), 'm');
	}

	public function testMobileGateSkipsAgentRequests(): void
	{
		// X-ExpenseCheck-Agent requests are authenticated + seat-gated by
		// AgentAuthMiddleware; the Basic/license gate must not claim them.
		$lic = $this->createMock(LicenseService::class);
		$lic->expects($this->never())->method('isMobilePlanActive');
		$lic->expects($this->never())->method('assertMobileAccess');
		$mw = $this->licenseMiddleware('/apps/expensecheck/api/mobile/claims',
			['x-expensecheck-agent' => 'ecst_token'], null, $lic);
		$mw->beforeController(new \stdClass(), 'm');
		$this->assertTrue(true);
	}
}
