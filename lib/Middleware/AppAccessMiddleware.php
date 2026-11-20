<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Middleware;

use OCA\ExpenseCheck\Exception\AppAccessDeniedException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Blocks ExpenseCheck routes when the user fails the access door
 * (open/restricted mode — spec §3.1). App admins and NC admins always pass.
 */
class AppAccessMiddleware extends Middleware
{
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly AccessControlService $access,
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\ExpenseCheck\\Controller\\')) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return; // auth layer handles unauthenticated
		}
		if ($this->access->canUseApp($user->getUID())) {
			return;
		}
		$this->logger->warning('expensecheck app access denied', [
			'userId' => $user->getUID(),
			'path' => $this->request->getPathInfo(),
		]);
		throw new AppAccessDeniedException(AccessControlService::DENIAL_RESTRICTION);
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		if (!$exception instanceof AppAccessDeniedException) {
			throw $exception;
		}
		return new JSONResponse(
			['ok' => false, 'error' => ['code' => 'APP_ACCESS_DENIED', 'reason' => $exception->getDenialReason()]],
			Http::STATUS_FORBIDDEN,
		);
	}
}
