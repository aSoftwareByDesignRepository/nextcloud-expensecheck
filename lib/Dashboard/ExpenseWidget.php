<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Dashboard;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\ApprovalService;
use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Dashboard widget (spec §10.6): claims awaiting the user's decision + the
 * user's own open claims. Rendered via API items — the widget never exposes
 * amounts or titles of other users' claims beyond what the approver role
 * would see in the inbox anyway.
 */
class ExpenseWidget implements IWidget
{
	public function __construct(
		private readonly ClaimMapper $claims,
		private readonly ApprovalService $approvals,
		private readonly AccessControlService $access,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
	) {
	}

	public function getId(): string
	{
		return 'expensecheck';
	}

	public function getTitle(): string
	{
		return $this->l10n->t('ExpenseCheck');
	}

	public function getOrder(): int
	{
		return 10;
	}

	public function getIconClass(): string
	{
		return 'icon-expensecheck';
	}

	public function getUrl(): ?string
	{
		return $this->urlGenerator->linkToRoute('expensecheck.page.index');
	}

	public function load(): void
	{
		\OCP\Util::addScript(Application::APP_ID, 'dashboard-widget');
	}
}
