<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\BackgroundJob;

use OCA\ExpenseCheck\Db\IdempotencyStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Purge idempotency keys older than 7 days — they only dedupe retries. */
class IdempotencyPurgeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly IdempotencyStore $store,
	) {
		parent::__construct($time);
		$this->setInterval(3600 * 24);
	}

	protected function run($argument): void
	{
		$this->store->purgeOlderThan($this->time->getDateTime()->modify('-7 days'));
	}
}
