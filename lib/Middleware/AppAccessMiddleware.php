<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Middleware;

use OCA\ExpenseCheck\Exception\AppAccessDeniedException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory as IL10NFactory;
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
		private readonly IL10NFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
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
		$path = (string)($this->request->getPathInfo() ?? '');
		$isApi = str_contains($path, '/api/') || $this->request->getMethod() !== 'GET';
		if ($isApi) {
			return new JSONResponse(
				['ok' => false, 'error' => ['code' => 'APP_ACCESS_DENIED', 'reason' => $exception->getDenialReason()]],
				Http::STATUS_FORBIDDEN,
			);
		}
		$l = $this->l10nFactory->get('expensecheck');
		[$message, $hint] = match ($exception->getDenialReason()) {
			AccessControlService::DENIAL_RESTRICTION => [
				$l->t('Your organisation restricts ExpenseCheck access. You are not on the allowed-users list and you are not a member of any allowed group.'),
				$l->t('Ask a Nextcloud or ExpenseCheck administrator to add you to the allowed users or groups in ExpenseCheck → Settings → Access.'),
			],
			default => [
				$l->t('You are not allowed to use ExpenseCheck right now.'),
				$l->t('If you believe this is a mistake, contact your ExpenseCheck administrator.'),
			],
		};
		$response = new TemplateResponse('expensecheck', 'access-denied', [
			'message' => $message,
			'hint' => $hint,
			'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
		]);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		$response->renderAs(TemplateResponse::RENDER_AS_USER);
		return $response;
	}
}
