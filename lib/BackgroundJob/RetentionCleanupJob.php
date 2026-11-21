<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\BackgroundJob;

use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Db\AuditLogMapper;
use OCA\ExpenseCheck\Db\DelegateMapper;
use OCA\ExpenseCheck\Db\NotificationLogMapper;
use OCA\ExpenseCheck\Service\AttachmentService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Automatic retention purges (spec §11.6 schedule — automatic rows only):
 * unfiled inbox receipts > inbox_purge_days, notification log > 90d,
 * delegations past valid_to + 1y, audit log > retention_years.
 * Claim-purge is deliberately absent — it is admin-initiated with a
 * report first, never a silent sweep.
 */
class RetentionCleanupJob extends TimedJob
{
	private const NOTIFICATION_LOG_DAYS = 90;
	private const DELEGATE_TAIL_DAYS = 365;

	public function __construct(
		ITimeFactory $time,
		private readonly AttachmentMapper $attachments,
		private readonly AttachmentService $attachmentService,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
		private readonly NotificationLogMapper $notificationLog,
		private readonly AuditLogMapper $auditLog,
		private readonly DelegateMapper $delegates,
	) {
		parent::__construct($time);
		$this->setInterval(3600 * 24);
	}

	protected function run($argument): void
	{
		$now = $this->time->getDateTime();

		$days = max(1, $this->settings->getInt(SettingsService::KEY_INBOX_PURGE_DAYS));
		$cutoff = (clone $now)->modify('-' . $days . ' days');
		foreach ($this->attachments->findInboxOlderThan($cutoff) as $a) {
			try {
				$this->attachmentService->delete($a, $a->getUploadedBy());
			} catch (\Throwable $e) {
				$this->logger->warning('expensecheck inbox purge failed', ['id' => $a->getId()]);
			}
		}

		try {
			$this->notificationLog->purgeOlderThan((clone $now)->modify('-' . self::NOTIFICATION_LOG_DAYS . ' days'));
		} catch (\Throwable $e) {
			$this->logger->warning('expensecheck notification-log purge failed');
		}

		try {
			$years = max(1, $this->settings->getInt(SettingsService::KEY_RETENTION_YEARS));
			$this->auditLog->purgeOlderThan((clone $now)->modify('-' . $years . ' years'));
		} catch (\Throwable $e) {
			$this->logger->warning('expensecheck audit-log purge failed');
		}

		try {
			$this->delegates->purgeExpiredBefore((clone $now)->modify('-' . self::DELEGATE_TAIL_DAYS . ' days'));
		} catch (\Throwable $e) {
			$this->logger->warning('expensecheck delegates purge failed');
		}
	}
}
