<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\BackgroundJob;

use OCA\ExpenseCheck\Service\ExportService;
use OCA\ExpenseCheck\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Async settlement export worker (spec §11.10/edge 44): finishes pending
 * export_job_state entries (render → file_hash on the batch → ready +
 * 15-min token window) and sweeps expired entries.
 */
class ExportGenerateJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly ExportService $exports,
		private readonly NotificationService $notify,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	protected function run($argument): void
	{
		try {
			$result = $this->exports->runPending();
		} catch (\Throwable $e) {
			$this->logger->warning('expensecheck export job failed', ['exception' => $e]);
			return;
		}
		// §12.7: "export ready" → requester, NC notification channel only.
		foreach ($result['ready_uids'] ?? [] as $uid) {
			try {
				$this->notify->exportReady($uid);
			} catch (\Throwable $e) {
				$this->logger->warning('expensecheck export notify failed', ['exception' => $e]);
			}
		}
	}
}
