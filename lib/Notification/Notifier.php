<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Notification;

use OCA\ExpenseCheck\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders ExpenseCheck NC notifications in the recipient's language.
 *
 * Content contract (spec §12.7): subjects carry claim numbers and counts
 * only — never amounts, merchant names, attendee names, or purpose text.
 * Those stay behind the authenticated app link.
 */
class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10nFactory->get(Application::APP_ID)->t('ExpenseCheck');
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$claimNo = (string)($params['claim_no'] ?? '');
		$count = (int)($params['count'] ?? 0);

		switch ($notification->getSubject()) {
			case 'claim_submitted':
				$notification->setParsedSubject($l->t('Approval needed: expense claim %s', [$claimNo]));
				$notification->setParsedMessage($l->t('A claim is waiting for your decision. Open ExpenseCheck to review it.'));
				break;
			case 'claim_approved':
				$notification->setParsedSubject($l->t('Approved: expense claim %s', [$claimNo]));
				$notification->setParsedMessage($l->t('Your claim passed all approval steps and now waits for settlement.'));
				break;
			case 'claim_rejected':
				$notification->setParsedSubject($l->t('Rejected: expense claim %s', [$claimNo]));
				$notification->setParsedMessage($l->t('Your claim was rejected. Open ExpenseCheck to read the note or clone it into a new draft.'));
				break;
			case 'claim_changes_requested':
				$notification->setParsedSubject($l->t('Changes requested: expense claim %s', [$claimNo]));
				$notification->setParsedMessage($l->t('An approver asked for changes. Open ExpenseCheck to review the note and resubmit.'));
				break;
			case 'claim_settled':
				$notification->setParsedSubject($l->t('Settled: expense claim %s', [$claimNo]));
				$notification->setParsedMessage($l->t('Your claim was settled in a payout batch. Open ExpenseCheck for the payment reference.'));
				break;
			case 'admin_review':
				$notification->setParsedSubject($l->t('Review needed: expense claim %s', [$claimNo]));
				$notification->setParsedMessage($l->t('A claim could not be routed to an approver and needs admin review.'));
				break;
			case 'already_claimed':
				$notification->setParsedSubject($l->t('Already claimed: %s', [$claimNo]));
				$notification->setParsedMessage($l->t('A linked record was already turned into a claim line, so no duplicate was created.'));
				break;
			case 'delegation_granted':
				$notification->setParsedSubject($l->t('Approval delegation granted'));
				$notification->setParsedMessage($l->t('You may now decide expense claims on behalf of a manager for a limited period.'));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('expensecheck.page.inbox'));
				return $notification;
			case 'seat_revoked':
				$notification->setParsedSubject($l->t('Mobile seat revoked'));
				$notification->setParsedMessage($l->t('Your ExpenseCheck companion seat was unassigned. Queued items stay on your device until a seat is reassigned.'));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('expensecheck.page.index'));
				return $notification;
			case 'period_closed':
				$notification->setParsedSubject($count > 0
					? $l->t('Settlement period closed (%s claims)', [$count])
					: $l->t('Settlement period closed'));
				$notification->setParsedMessage($l->t('No further changes are possible for claims in this period.'));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('expensecheck.page.settlements'));
				return $notification;
			case 'export_ready':
				$notification->setParsedSubject($l->t('Export ready'));
				$notification->setParsedMessage($l->t('Your settlement export finished building. Open ExpenseCheck to download it.'));
				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('expensecheck.page.settlements'));
				return $notification;
			default:
				throw new UnknownNotificationException();
		}
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute('expensecheck.page.claimDetail', [
			'id' => $notification->getObjectId(),
		]));
		return $notification;
	}
}
