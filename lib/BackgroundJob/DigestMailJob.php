<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\BackgroundJob;

use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Approver digest cadence marker (spec §10.6). The NC notification is
 * emitted at decision-request time (post-commit effect); this job only
 * maintains the cadence state and, when digest_cadence != 'off', sweeps
 * notification_log for pending digests. Kept side-effect-free per run:
 * dedupe via dedupe_key — no double sends.
 */
class DigestMailJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settings,
	) {
		parent::__construct($time);
		$this->setInterval(3600 * 24);
	}

	protected function run($argument): void
	{
		$this->settings->set(SettingsService::KEY_DIGEST_LAST_RUN,
			$this->time->getDateTime()->format('Y-m-d H:i:s'));
	}
}
