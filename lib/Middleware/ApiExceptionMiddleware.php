<?php

declare(strict_types=1);

/**
 * Catch-all JSON envelope for ExpenseCheck API surfaces. Registered FIRST in
 * Application so the dispatcher's reverse-order afterException iteration
 * consults it LAST — typed middleware (SessionCsrf, AppAccess, ClientLicense)
 * gets first crack at its own exceptions; this is the fall-through.
 *
 * DomainException → {success:false,error:<code>,message,fields?} at its
 * declared HTTP status. Everything else → generic 500 (message never leaks
 * internals; full trace goes to the log).
 */

namespace OCA\ExpenseCheck\Middleware;

use OCA\ExpenseCheck\Exception\DomainException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class ApiExceptionMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	public function afterException($controller, $methodName, \Exception $exception): JSONResponse
	{
		if (!$this->isJsonSurface($this->normalizedPath())) {
			throw $exception;
		}

		if ($exception instanceof DomainException) {
			$body = [
				'ok' => false,
				'success' => false,
				'error' => $exception->getErrorCode(),
				'error_code' => $exception->getErrorCode(),
				'message' => $exception->getMessage(),
			];
			if ($exception->getFields() !== null) {
				$body['fields'] = $exception->getFields();
			}
			return new JSONResponse($body, $exception->getHttpStatus());
		}

		$this->logger->error('ExpenseCheck API request failed', [
			'app' => 'expensecheck',
			'exception' => $exception,
		]);
		$message = $this->l10nFactory->get('expensecheck')->t('an_error_occurred');
		return new JSONResponse([
			'ok' => false,
			'success' => false,
			'error' => 'internal_error',
			'error_code' => 'internal_error',
			'message' => $message,
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}

	/**
	 * JSON surfaces: /api/* paths and any request asking for a JSON body.
	 * Page navigations keep core's HTML error page — better UX there.
	 */
	private function isJsonSurface(string $path): bool
	{
		if (str_contains($path, '/api/')) {
			return true;
		}
		$accept = strtolower((string)$this->request->getHeader('Accept'));
		return str_contains($accept, 'application/json') || str_contains($accept, '+json');
	}

	private function normalizedPath(): string
	{
		$path = (string)$this->request->getPathInfo();
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
}
