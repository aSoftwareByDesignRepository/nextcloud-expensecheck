<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\NotificationLogEntry;
use OCA\ExpenseCheck\Db\NotificationLogMapper;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * NC-notification emitter (spec §12.7).
 *
 * Contract:
 *  - Content: claim_no + counts only. Amounts, merchants, attendees and
 *    purpose text never leave the authenticated app.
 *  - Dedupe: `exp_notification_log.dedupe_key` =
 *    `{event}:{claimId|0}:{recipient}:{Y-m-d}` — the same event for the
 *    same claim reaches the same uid at most once per day per channel.
 *  - Delivery is caller-deferred: emitters run inside
 *    PostCommitEffectQueue callbacks so a rolled-back transaction never
 *    announces undone state.
 *  - Best-effort: a dead notification pipeline logs and returns — it must
 *    never break a committed business operation.
 */
class NotificationService
{
	public const EVENT_SUBMITTED = 'claim_submitted';
	public const EVENT_APPROVED = 'claim_approved';
	public const EVENT_REJECTED = 'claim_rejected';
	public const EVENT_CHANGES_REQUESTED = 'claim_changes_requested';
	public const EVENT_SETTLED = 'claim_settled';
	public const EVENT_ADMIN_REVIEW = 'admin_review';
	public const EVENT_ALREADY_CLAIMED = 'already_claimed';
	public const EVENT_DELEGATION_GRANTED = 'delegation_granted';
	public const EVENT_SEAT_REVOKED = 'seat_revoked';
	public const EVENT_PERIOD_CLOSED = 'period_closed';
	public const EVENT_EXPORT_READY = 'export_ready';

	private const CHANNEL_NC = 'nc';

	public function __construct(
		private readonly IManager $notifications,
		private readonly NotificationLogMapper $log,
		private readonly IUserManager $userManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly AccessControlService $access,
		private readonly LoggerInterface $logger,
	) {
	}

	/** Claim reached a pending step — notify everyone holding decision rights. */
	public function claimSubmitted(Claim $claim, array $approverUids): void
	{
		$this->emit(self::EVENT_SUBMITTED, $claim->getId(), $claim->getClaimNo(), $approverUids);
	}

	/** A step/chain decision landed — notify the claimant (own data, instant channel). */
	public function decisionOnClaim(Claim $claim, string $decision): void
	{
		$event = match ($decision) {
			'approved' => self::EVENT_APPROVED,
			'rejected' => self::EVENT_REJECTED,
			default => self::EVENT_CHANGES_REQUESTED,
		};
		$this->emit($event, $claim->getId(), $claim->getClaimNo(), [$claim->getUserId()]);
	}

	public function claimSettled(Claim $claim): void
	{
		$this->emit(self::EVENT_SETTLED, $claim->getId(), $claim->getClaimNo(), [$claim->getUserId()]);
	}

	/** Unroutable claim fell back to admin-review (NN-09) — alert app admins. */
	public function routedToAdminReview(Claim $claim): void
	{
		$this->emit(self::EVENT_ADMIN_REVIEW, $claim->getId(), $claim->getClaimNo(), $this->access->getAppAdminIds());
	}

	/** F25/F24 dedupe collision — the linked record already funded a line. */
	public function alreadyClaimed(Claim $claim): void
	{
		$this->emit(self::EVENT_ALREADY_CLAIMED, $claim->getId(), $claim->getClaimNo(), [$claim->getUserId()]);
	}

	public function delegationGranted(string $delegateUid): void
	{
		$this->emit(self::EVENT_DELEGATION_GRANTED, null, '', [$delegateUid]);
	}

	public function seatRevoked(string $uid): void
	{
		$this->emit(self::EVENT_SEAT_REVOKED, null, '', [$uid]);
	}

	/** @param int $claimCount claims pinned to the closed period */
	public function periodClosed(int $periodId, int $claimCount): void
	{
		$recipients = $this->access->usersInGroup(AccessControlService::GROUP_FINANCE);
		$this->emit(self::EVENT_PERIOD_CLOSED, null, '', $recipients, ['count' => $claimCount]);
	}

	public function exportReady(string $requesterUid): void
	{
		$this->emit(self::EVENT_EXPORT_READY, null, '', [$requesterUid]);
	}

	/**
	 * @param list<string> $uids
	 * @param array<string,mixed> $extraParams
	 */
	private function emit(string $event, ?int $claimId, string $claimNo, array $uids, array $extraParams = []): void
	{
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
		foreach (array_unique($uids) as $uid) {
			if (!is_string($uid) || $uid === '' || !$this->access->userExists($uid)) {
				continue;
			}
			$dedupe = $event . ':' . ($claimId ?? 0) . ':' . $uid . ':' . $today;
			if ($this->log->hasDedupeKey($dedupe)) {
				continue;
			}
			try {
				$params = array_merge(['claim_no' => $claimNo], $extraParams);
				$n = $this->notifications->createNotification();
				$n->setApp(Application::APP_ID)
					->setUser($uid)
					->setObject('claim', (string)($claimId ?? 0))
					->setSubject($event, $params)
					->setDateTime(new \DateTime())
					->setLink($this->urlGenerator->linkToRouteAbsolute('expensecheck.page.index'));
				$this->notifications->notify($n);
				$this->record($dedupe, $event, $claimId, $uid, self::CHANNEL_NC);
			} catch (\Throwable $e) {
				$this->logger->warning('expensecheck notification failed', [
					'event' => $event, 'uid' => $uid, 'claim_id' => $claimId,
					'exception' => $e,
				]);
			}
		}
	}

	private function record(string $dedupe, string $event, ?int $claimId, string $uid, string $channel): void
	{
		try {
			$row = new NotificationLogEntry();
			$row->setDedupeKey($dedupe);
			$row->setEvent($event);
			$row->setClaimId($claimId);
			$row->setRecipient($uid);
			$row->setChannel($channel);
			$row->setCreatedAt(new \DateTime());
			$this->log->insert($row);
		} catch (\Throwable $e) {
			// Duplicate dedupe key (concurrent send) is benign — first sender won.
			$this->logger->info('expensecheck notification log insert failed', [
				'event' => $event, 'exception' => $e,
			]);
		}
	}
}
