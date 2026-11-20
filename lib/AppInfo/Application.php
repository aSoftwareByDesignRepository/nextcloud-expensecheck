<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\AppInfo;

use OCA\ExpenseCheck\Middleware\ApiExceptionMiddleware;
use OCA\ExpenseCheck\Middleware\AppAccessMiddleware;
use OCA\ExpenseCheck\Middleware\ClientLicenseMiddleware;
use OCA\ExpenseCheck\Middleware\SessionCsrfMiddleware;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'expensecheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		// Order matters: afterException runs in reverse registration order.
		// ApiException FIRST so it is consulted LAST (fall-through) — typed
		// middleware handles its own exceptions before it.
		$context->registerMiddleware(ApiExceptionMiddleware::class);
		$context->registerMiddleware(SessionCsrfMiddleware::class);
		$context->registerMiddleware(AppAccessMiddleware::class);
		$context->registerMiddleware(ClientLicenseMiddleware::class);

		$context->registerCapability(\OCA\ExpenseCheck\Capabilities::class);
		$context->registerNotifierService(\OCA\ExpenseCheck\Notification\Notifier::class);
		$context->registerDashboardWidget(\OCA\ExpenseCheck\Dashboard\ExpenseWidget::class);
		$context->registerEventListener(
			\OCP\User\Events\UserDeletedEvent::class,
			\OCA\ExpenseCheck\Listener\UserDeletedListener::class
		);
	}

	public function boot(IBootContext $context): void
	{
	}
}
