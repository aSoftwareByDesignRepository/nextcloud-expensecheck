<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\BackgroundJob;

use OCA\ExpenseCheck\Service\DigestMailService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Approver pending-claim digest (spec §10.6/§12.7). Sends one mail per
 * approver per run when digest_cadence allows (daily / weekly-on-Monday),
 * dedupe-claimed atomically via the unique notification_log.dedupe_key.
 */
class DigestMailJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settings,
		private readonly DigestMailService $digest,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600 * 24);
	}

	protected function run($argument): void
	{
		try {
			$result = $this->digest->sendApproverDigests();
			if ($result['eligible']) {
				$this->settings->set(SettingsService::KEY_DIGEST_LAST_RUN,
					$this->time->getDateTime()->format('Y-m-d H:i:s'));
			}
		} catch (\Throwable $e) {
			// A dead mail pipeline must not wedge cron — diagnostics shows
			// last-run age instead.
			$this->logger->warning('expensecheck digest job failed', ['exception' => $e]);
		}
	}
}
