<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Middleware;

use OCA\ExpenseCheck\Exception\CompanionException;
use OCA\ExpenseCheck\Service\LicenseService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Companion /api/mobile/* choke point (spec §13.1):
 *  1) Basic app-password required — cookie sessions must not reach
 *     companion mutations (CSRF surface).
 *  2) EXP2 license + seat gate for Basic callers; bootstrap is exempt from
 *     the 402 so the app can render an honest "license required" state.
 */
class ClientLicenseMiddleware extends Middleware
{
	private const BOOTSTRAP_PATH = '/api/mobile/bootstrap';

	public function __construct(
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LicenseService $licenseService,
		private readonly LoggerInterface $logger,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$path = $this->normalizeApiPath((string)$this->request->getPathInfo());
		if (!str_starts_with($path, '/api/mobile')) {
			return;
		}
		// Scoped agent tokens (X-ExpenseCheck-Agent) are verified and gated by
		// AgentAuthMiddleware — an invalid token dies there, so skipping the
		// Basic/seat gate here cannot smuggle an unauthenticated request in.
		if (trim((string)$this->request->getHeader('X-ExpenseCheck-Agent')) !== '') {
			return;
		}
		if (!$this->usesBasicAppPassword()) {
			throw CompanionException::licenseRequired();
		}
		if ($path === self::BOOTSTRAP_PATH) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw CompanionException::licenseRequired();
		}
		if (!$this->licenseService->isMobilePlanActive()) {
			$this->logger->info('expensecheck mobile gate: no active license', [
				'userId' => $user->getUID(), 'path' => $path,
			]);
			throw CompanionException::licenseRequired();
		}
		$this->licenseService->assertMobileAccess($user->getUID());
	}

	private function usesBasicAppPassword(): bool
	{
		$auth = strtolower((string)$this->request->getHeader('Authorization'));
		return str_starts_with($auth, 'basic ');
	}

	private function normalizeApiPath(string $path): string
	{
		foreach (['/index.php/apps/expensecheck', '/apps/expensecheck'] as $prefix) { // urlgate:ok — path matching, not URL emission
			if ($path === $prefix) {
				return '/';
			}
			if (str_starts_with($path, $prefix . '/')) {
				$rest = substr($path, strlen($prefix));
				return $rest === '' ? '/' : $rest;
			}
		}
		return $path === '' ? '/' : $path;
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		if (!$exception instanceof CompanionException) {
			throw $exception;
		}
		return new JSONResponse([
			'ok' => false,
			'error' => $exception->getErrorCode(),
			'message' => $exception->getMessage(),
		], $exception->getHttpStatus());
	}
}
