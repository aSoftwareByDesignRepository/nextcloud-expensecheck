<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\BackgroundJob;

use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Service\AttachmentService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Inbox purge (spec §12): unfiled receipt captures older than
 * inbox_purge_days are removed — the inbox is a staging area, not archive.
 */
class RetentionCleanupJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly AttachmentMapper $attachments,
		private readonly AttachmentService $attachmentService,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600 * 24);
	}

	protected function run($argument): void
	{
		$days = max(1, $this->settings->getInt(SettingsService::KEY_INBOX_PURGE_DAYS));
		$cutoff = $this->time->getDateTime()->modify('-' . $days . ' days');
		foreach ($this->attachments->findInboxOlderThan($cutoff) as $a) {
			try {
				$this->attachmentService->delete($a, $a->getUploadedBy());
			} catch (\Throwable $e) {
				$this->logger->warning('expensecheck inbox purge failed', ['id' => $a->getId()]);
			}
		}
	}
}
