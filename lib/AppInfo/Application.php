<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\AppInfo;

use OCP\Lock\ILockingProvider;
use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCA\ExpenseCheck\Service\UpgradeBackupService;
use OCA\ExpenseCheck\Repair\BackupBeforeUpdate;
use OCA\ExpenseCheck\Repair\EnsureAgentTables;
use OCA\ExpenseCheck\Repair\EnsureExpenseCheckSchema;
use OCA\ExpenseCheck\Repair\UninstallAgentTables;
use OCA\ExpenseCheck\Repair\UninstallDropTables;
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

		// _shared/agent-auth pack — scoped AI-agent tokens (suite MCP contract)
		$context->registerService(\OCA\ExpenseCheck\Agent\AgentManifest::class, function ($c) {
			return new \OCA\ExpenseCheck\Agent\AgentManifest(
				'expensecheck',
				\dirname(__DIR__, 2) . '/appinfo/agent-manifest.json'
			);
		});
		$context->registerService(\OCA\ExpenseCheck\Agent\AgentSeatGate::class, function ($c) {
			return new \OCA\ExpenseCheck\Agent\ExpenseCheckAgentSeatGate(
				$c->get(\OCA\ExpenseCheck\Service\LicenseService::class)
			);
		});
		$context->registerService(\OCA\ExpenseCheck\Agent\AgentAccessGate::class, function ($c) {
			return new \OCA\ExpenseCheck\Agent\ExpenseCheckAgentAccessGate(
				$c->get(\OCA\ExpenseCheck\Service\AccessControlService::class),
				$c->get(\OCP\IGroupManager::class)
			);
		});
		$context->registerService(\OCA\ExpenseCheck\Middleware\AgentAuthMiddleware::class, function ($c) {
			return new \OCA\ExpenseCheck\Middleware\AgentAuthMiddleware(
				$c->get(\OCP\IRequest::class),
				$c->get(\OCA\ExpenseCheck\Agent\AgentContext::class),
				$c->get(\OCA\ExpenseCheck\Agent\AgentTokenService::class),
				$c->get(\OCA\ExpenseCheck\Agent\AgentManifest::class),
				$c->get(\OCA\ExpenseCheck\Agent\AgentSeatGate::class),
				$c->get(\OCA\ExpenseCheck\Agent\AgentAuditMapper::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				'expensecheck',
				$c->query(\OCA\ExpenseCheck\Agent\AgentWebhookDispatcher::class)
			);
		});
		$context->registerMiddleware(\OCA\ExpenseCheck\Middleware\AgentAuthMiddleware::class);

		$context->registerCapability(\OCA\ExpenseCheck\Capabilities::class);
		$context->registerNotifierService(\OCA\ExpenseCheck\Notification\Notifier::class);
		$context->registerDashboardWidget(\OCA\ExpenseCheck\Dashboard\ExpenseWidget::class);
		$context->registerEventListener(
			\OCP\User\Events\UserDeletedEvent::class,
			\OCA\ExpenseCheck\Listener\UserDeletedListener::class
		);
		$context->registerEventListener(
			\OCP\User\Events\UserDeletedEvent::class,
			\OCA\ExpenseCheck\Listener\AgentUserDeletedListener::class
		);

		// ── Config & data transfer (test → production, spec §12.6) ──────
		$context->registerService(\OCA\ExpenseCheck\ConfigTransfer\TransferManifest::class, fn () => new \OCA\ExpenseCheck\ConfigTransfer\TransferManifest());
		$context->registerService(\OCA\ExpenseCheck\Service\TransferSnapshotService::class, function ($c): \OCA\ExpenseCheck\Service\TransferSnapshotService {
			return new \OCA\ExpenseCheck\Service\TransferSnapshotService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCA\ExpenseCheck\ConfigTransfer\TransferManifest::class),
			);
		});
		$context->registerService(\OCA\ExpenseCheck\ConfigTransfer\TransferHooks::class, function ($c): \OCA\ExpenseCheck\ConfigTransfer\TransferHooks {
			return new \OCA\ExpenseCheck\ConfigTransfer\TransferHooks(
				$c->get(\OCA\ExpenseCheck\Service\SettingsService::class),
				$c->get(\OCA\ExpenseCheck\Service\AccessControlService::class),
				$c->get(\OCA\ExpenseCheck\Service\TransferSnapshotService::class),
				$c->get(\OCA\ExpenseCheck\Service\AuditLogService::class),
			);
		});
		$context->registerService(\OCA\ExpenseCheck\ConfigTransfer\TransferPendingStore::class, function ($c): \OCA\ExpenseCheck\ConfigTransfer\TransferPendingStore {
			return new \OCA\ExpenseCheck\ConfigTransfer\TransferPendingStore(
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(\OCP\IConfig::class),
				'expensecheck',
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\ExpenseCheck\ConfigTransfer\TransferReceiptStore::class, function ($c): \OCA\ExpenseCheck\ConfigTransfer\TransferReceiptStore {
			return new \OCA\ExpenseCheck\ConfigTransfer\TransferReceiptStore(
				$c->get(\OCP\IConfig::class),
				'expensecheck',
			);
		});
		$context->registerService(\OCA\ExpenseCheck\ConfigTransfer\TransferService::class, function ($c): \OCA\ExpenseCheck\ConfigTransfer\TransferService {
			return new \OCA\ExpenseCheck\ConfigTransfer\TransferService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\IUserManager::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\OCP\Files\IRootFolder::class),
				$c->get(\OCP\App\IAppManager::class),
				$c->get(\OCP\Lock\ILockingProvider::class),
				$c->get(\OCP\IURLGenerator::class),
				$c->get(\Psr\Log\LoggerInterface::class),
				$c->get(\OCA\ExpenseCheck\ConfigTransfer\TransferManifest::class),
				$c->get(\OCA\ExpenseCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\ExpenseCheck\ConfigTransfer\TransferPendingStore::class),
				$c->get(\OCA\ExpenseCheck\ConfigTransfer\TransferReceiptStore::class),
				'expensecheck',
				$c->get(\OCP\IAppConfig::class),
			);
		});
		$context->registerService(\OCA\ExpenseCheck\BackgroundJob\TransferApplyJob::class, function ($c): \OCA\ExpenseCheck\BackgroundJob\TransferApplyJob {
			return new \OCA\ExpenseCheck\BackgroundJob\TransferApplyJob(
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->get(\OCA\ExpenseCheck\ConfigTransfer\TransferService::class),
				$c->get(\OCP\Lock\ILockingProvider::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerService(\OCA\ExpenseCheck\Controller\TransferApiController::class, function ($c): \OCA\ExpenseCheck\Controller\TransferApiController {
			return new \OCA\ExpenseCheck\Controller\TransferApiController(
				'expensecheck',
				$c->get(\OCP\IRequest::class),
				$c->get(\OCA\ExpenseCheck\ConfigTransfer\TransferHooks::class),
				$c->get(\OCA\ExpenseCheck\ConfigTransfer\TransferService::class),
				$c->get(\OCP\BackgroundJob\IJobList::class),
				$c->get(\OCP\Security\Bruteforce\IThrottler::class),
				$c->get(\OCP\IGroupManager::class),
				$c->get(\OCP\IUserSession::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});

		$context->registerService(EnsureExpenseCheckSchema::class, function ($c): EnsureExpenseCheckSchema {
			return new EnsureExpenseCheckSchema(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
			);
		});

		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
			);
		});

		$context->registerService(EnsureAgentTables::class, function ($c): EnsureAgentTables {
			return new EnsureAgentTables(
				$c->get(\OCP\IDBConnection::class),
			);
		});

		$context->registerService(UninstallAgentTables::class, function ($c): UninstallAgentTables {
			return new UninstallAgentTables(
				$c->get(\OCP\IDBConnection::class),
			);
		});

	}

	public function boot(IBootContext $context): void
	{
		$app = $context->getAppContainer();
		\OCA\ExpenseCheck\Support\NavGate::register(
			$app,
			'expensecheck.page.index',
			'app.svg',
			'ExpenseCheck',
			10,
			static fn(string $uid): bool => $app->get(\OCA\ExpenseCheck\Service\AccessControlService::class)->canUseApp($uid),
		);
	}
}
