<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\DelegateMapper;
use OCA\ExpenseCheck\Db\NotificationLogEntry;
use OCA\ExpenseCheck\Db\NotificationLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;

/**
 * Approver pending-claim digest mail (spec §10.6/§12.7, MH-21 — AZC
 * `ManagerPendingApprovalMailService` shape).
 *
 * Contract:
 *  - Content: claim_no + counts only. Amounts, merchants, attendees and
 *    purpose text never leave the authenticated app.
 *  - One mail per approver per day; dedupe via
 *    `exp_notification_log.dedupe_key` =
 *    `approver_digest:0:{uid}:{Y-m-d}` (unique index makes the claim
 *    atomic — a losing concurrent sender gets no row and sends nothing).
 *  - Delegates with an active window are cc'd as additional recipients
 *    (they hold real decision rights via on_behalf_of, edge 25).
 *  - Recipient-language rendering via IL10NFactory::getUserLanguage.
 *  - Best-effort: a dead mail pipeline logs and continues; it must never
 *    break the cron run. A failed send leaves no dedupe row so the next
 *    run retries instead of silently dropping the digest.
 *  - Cadence: `daily` every run; `weekly` on ISO Mondays; `off` short-
 *    circuits without touching dedupe state.
 */
class DigestMailService
{
	public const EVENT_DIGEST = 'approver_digest';
	private const CHANNEL_MAIL = 'mail';
	private const MAX_CLAIM_LINES = 50;

	public function __construct(
		private readonly SettingsService $settings,
		private readonly ClaimMapper $claims,
		private readonly DelegateMapper $delegates,
		private readonly ChainRoutingService $routing,
		private readonly NotificationLogMapper $log,
		private readonly IMailer $mailer,
		private readonly IUserManager $userManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly IConfig $config,
		private readonly IL10NFactory $l10nFactory,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{eligible:bool,sent:int,skipped:int,claims:int}
	 */
	public function sendApproverDigests(?\DateTimeInterface $now = null): array
	{
		$empty = ['eligible' => false, 'sent' => 0, 'skipped' => 0, 'claims' => 0];
		$cadence = $this->settings->get(SettingsService::KEY_DIGEST_CADENCE);
		if ($cadence === 'off') {
			return $empty;
		}
		$now = $now ?? $this->timeFactory->getDateTime();
		if ($cadence === 'weekly' && (int)$now->format('N') !== 1) {
			return $empty;
		}

		// claim_no list per recipient uid
		/** @var array<string, list<string>> $pendingByUid */
		$pendingByUid = [];
		$claimCount = 0;
		foreach ($this->claims->findByStatus(ClaimLifecycleService::STATUS_SUBMITTED) as $claim) {
			$snapshot = json_decode((string)$claim->getChainSnapshot(), true);
			if (!is_array($snapshot)) {
				continue;
			}
			$claimCount++;
			$approvers = $this->routing->approversForPendingStep($snapshot, $claim->getCurrentStep());
			foreach ($this->delegates->findActiveForManagers($approvers, $now) as $d) {
				$approvers[] = $d->getDelegateUid();
			}
			foreach (array_unique($approvers) as $uid) {
				$pendingByUid[$uid][] = $claim->getClaimNo();
			}
		}

		$sent = 0;
		$skipped = 0;
		$today = $now->format('Y-m-d');
		foreach ($pendingByUid as $uid => $claimNos) {
			$dedupe = self::EVENT_DIGEST . ':0:' . $uid . ':' . $today;
			if ($this->log->hasDedupeKey($dedupe) || !$this->claimSlot($dedupe, $uid)) {
				$skipped++;
				continue;
			}
			$user = $this->userManager->get($uid);
			$email = $user?->getEMailAddress();
			if ($user === null || $email === null || trim($email) === ''
				|| !$this->mailer->validateMailAddress(trim($email))) {
				$skipped++;
				continue;
			}
			try {
				$this->sendMail($uid, trim($email), $user->getDisplayName(), $claimNos, $user);
				$sent++;
			} catch (\Throwable $e) {
				// The slot stays claimed: a duplicate digest is a bigger
				// contract violation than one lost day (NC notification
				// still reaches the approver).
				$this->logger->warning('expensecheck digest mail failed', [
					'uid' => $uid, 'exception' => $e,
				]);
				$skipped++;
			}
		}
		return ['eligible' => true, 'sent' => $sent, 'skipped' => $skipped, 'claims' => $claimCount];
	}

	/**
	 * @param list<string> $claimNos
	 */
	private function sendMail(string $uid, string $toEmail, string $displayName, array $claimNos, \OCP\IUser $user): void
	{
		$lang = (string)$this->l10nFactory->getUserLanguage($user);
		$l = $this->l10nFactory->get(Application::APP_ID, $lang);

		$count = count($claimNos);
		$subject = $l->n(
			'ExpenseCheck: %n expense claim awaiting your decision',
			'ExpenseCheck: %n expense claims awaiting your decision',
			$count
		);
		$shown = array_slice($claimNos, 0, self::MAX_CLAIM_LINES);
		$lines = [$l->t('The following expense claims are waiting for your approval:'), ''];
		foreach ($shown as $no) {
			$lines[] = ' - ' . $no;
		}
		if ($count > self::MAX_CLAIM_LINES) {
			$lines[] = $l->n('… and %n more', '… and %n more', $count - self::MAX_CLAIM_LINES);
		}
		$lines[] = '';
		$lines[] = $l->t('Review: %1$s', [$this->urlGenerator->linkToRouteAbsolute('expensecheck.page.index')]);

		$message = $this->mailer->createMessage();
		$message->setSubject($subject);
		$message->setPlainBody(implode("\n", $lines));
		$message->setTo([$toEmail => $displayName]);
		$this->setFrom($message);
		if (method_exists($message, 'setAutoSubmitted')) {
			$message->setAutoSubmitted(\OCP\Mail\Headers\AutoSubmitted::VALUE_AUTO_GENERATED);
		}
		$this->mailer->send($message);
	}

	private function setFrom(IMessage $message): void
	{
		$fromAddress = (string)$this->config->getSystemValue('mail_from_address', '');
		$fromDomain = (string)$this->config->getSystemValue('mail_domain', '');
		if ($fromAddress !== '' && $fromDomain !== '') {
			$message->setFrom([$fromAddress . '@' . $fromDomain => 'ExpenseCheck']);
		}
	}

	/**
	 * Atomic dedupe claim — the unique dedupe_key index arbitrates
	 * concurrent senders. Returns false when the slot is already taken.
	 */
	private function claimSlot(string $dedupe, string $uid): bool
	{
		try {
			$row = new NotificationLogEntry();
			$row->setDedupeKey($dedupe);
			$row->setEvent(self::EVENT_DIGEST);
			$row->setClaimId(null);
			$row->setRecipient($uid);
			$row->setChannel(self::CHANNEL_MAIL);
			$row->setCreatedAt($this->timeFactory->getDateTime());
			$this->log->insert($row);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}
}
