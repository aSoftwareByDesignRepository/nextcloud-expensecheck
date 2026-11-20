<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Listener;

use OCA\ExpenseCheck\Service\ErasureService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * NC erase flow entry point (admin deletes a user) — MH-15 / §11.6.
 * Pseudonymises uid columns, scrubs claimant free-text, deletes receipt and
 * route files, tombstones journal rows. Failures are logged, never fatal to
 * the platform user-delete path.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private readonly ErasureService $erasure,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		try {
			$this->erasure->eraseUid($uid, 'system:user-deleted');
		} catch (\Throwable $e) {
			$this->logger->error('expensecheck erasure failed', ['exception' => $e]);
		}
	}
}
