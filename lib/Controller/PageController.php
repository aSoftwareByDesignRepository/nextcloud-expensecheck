<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCA\ExpenseCheck\Support\AssetRegistrar;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

/**
 * Server-rendered pages — check-shell chrome + a body partial per surface.
 * All data loads happen via the JSON API (same endpoints the companion
 * uses); the page templates are static shells + a JS page module.
 */
class PageController extends Controller
{
	private const PAGES = [
		'claims' => ['template' => 'claims', 'icon' => 'clipboard-list', 'help' => 'Your expense claims — drafts, submitted, and history.'],
		'new-claim' => ['template' => 'claim-form', 'icon' => 'plus', 'help' => 'Create a new expense claim.'],
		'claim-detail' => ['template' => 'claim-detail', 'icon' => 'file-text', 'help' => 'Claim detail, lines, receipts, and decisions.'],
		'inbox' => ['template' => 'inbox', 'icon' => 'inbox', 'help' => 'Claims waiting for your approval.'],
		'receipts' => ['template' => 'receipts', 'icon' => 'upload', 'help' => 'Captured receipts not yet filed to a claim line.'],
		'all' => ['template' => 'all-claims', 'icon' => 'list', 'help' => 'All claims visible to your role.'],
		'settlements' => ['template' => 'settlements', 'icon' => 'check-circle', 'help' => 'Finance settlement queue and payout batches.'],
		'settlement-detail' => ['template' => 'settlement-detail', 'icon' => 'file-check', 'help' => 'Settlement batch detail and export.'],
		'reports' => ['template' => 'reports', 'icon' => 'activity', 'help' => 'Claim totals by period, category, and cost center.'],
		'categories' => ['template' => 'admin-categories', 'icon' => 'grid', 'help' => 'Expense categories and their rules.'],
		'rates' => ['template' => 'admin-rates', 'icon' => 'activity', 'help' => 'Versioned rate catalog for mileage, per-diem, and flat rates.'],
		'cost-centers' => ['template' => 'admin-cost-centers', 'icon' => 'layout-grid', 'help' => 'Cost-center tree for allocations.'],
		'chains' => ['template' => 'admin-chains', 'icon' => 'repeat', 'help' => 'Approval chains and routing.'],
		'settings' => ['template' => 'settings', 'icon' => 'settings', 'help' => 'ExpenseCheck configuration.'],
		'license' => ['template' => 'license', 'icon' => 'shield', 'help' => 'Mobile companion license (EXP2) and seats.'],
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly ClaimLifecycleService $lifecycle,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	private function render(string $pageId, array $params = []): TemplateResponse
	{
		AssetRegistrar::registerStyles();
		Util::addScript(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'common/toasts');
		Util::addScript(Application::APP_ID, 'common/mobile-nav');
		Util::addScript(Application::APP_ID, 'common/field-errors');
		Util::addScript(Application::APP_ID, 'common/focus-preservation');
		Util::addScript(Application::APP_ID, 'common/view-components');
		Util::addScript(Application::APP_ID, 'pages/app-views');
		$uid = $this->access->currentUserId();
		$role = 'member';
		if ($this->access->isAppAdmin($uid)) {
			$role = 'admin';
		} elseif ($this->access->isFinance($uid)) {
			$role = 'finance';
		} elseif ($this->access->isAuditor($uid)) {
			$role = 'auditor';
		}
		$params += [
			'pageId' => $pageId,
			'pageTitle' => $this->l10n->t(ucwords(str_replace('-', ' ', $pageId))),
			'pageHelp' => self::PAGES[$pageId]['help'] ?? '',
			'pageIcon' => self::PAGES[$pageId]['icon'] ?? 'file-text',
			'roleLabel' => $this->l10n->t(ucfirst($role)),
			'urls' => $this->pageUrls(),
			'navGroups' => $this->navGroups($uid, $pageId),
			'clientHints' => ['locale' => 'en-US', 'htmlLang' => 'en-US', 'timezone' => 'UTC'],
			'brandTitle' => 'ExpenseCheck',
			'brandIcon' => 'file-text',
			'isAdmin' => $this->access->isAppAdmin($uid),
			'isFinance' => $this->access->isFinance($uid),
		];
		return new TemplateResponse(Application::APP_ID, 'main', $params);
	}

	private function pageUrls(): array
	{
		$u = $this->urlGenerator;
		return [
			'home' => $u->linkToRoute('expensecheck.page.index'),
			'claims' => $u->linkToRoute('expensecheck.page.claims'),
			'newClaim' => $u->linkToRoute('expensecheck.page.newClaim'),
			'inbox' => $u->linkToRoute('expensecheck.page.inbox'),
			'receipts' => $u->linkToRoute('expensecheck.page.receiptsInbox'),
			'settlements' => $u->linkToRoute('expensecheck.page.settlements'),
			'reports' => $u->linkToRoute('expensecheck.page.reports'),
			'settings' => $u->linkToRoute('expensecheck.page.settings'),
			'license' => $u->linkToRoute('expensecheck.page.license'),
			'apiClaims' => $u->linkToRoute('expensecheck.claimApi.list'),
			'apiInbox' => $u->linkToRoute('expensecheck.inboxApi.list'),
			'apiReceipts' => $u->linkToRoute('expensecheck.receiptsApi.list'),
			'apiLookups' => $u->linkToRoute('expensecheck.claimApi.lookups'),
			'apiSettings' => $u->linkToRoute('expensecheck.settingsApi.get'),
		];
	}

	private function navGroups(string $uid, string $active): array
	{
		$items = [
			['id' => 'claims', 'label' => $this->l10n->t('My claims'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.claims'), 'icon' => 'clipboard-list', 'active' => $active === 'claims'],
			['id' => 'new-claim', 'label' => $this->l10n->t('New claim'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.newClaim'), 'icon' => 'plus', 'active' => $active === 'new-claim'],
			['id' => 'receipts', 'label' => $this->l10n->t('Receipts'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.receiptsInbox'), 'icon' => 'upload', 'active' => $active === 'receipts'],
			['id' => 'inbox', 'label' => $this->l10n->t('Approvals'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.inbox'), 'icon' => 'inbox', 'active' => $active === 'inbox'],
		];
		if ($this->access->isFinance($uid) || $this->access->isAuditor($uid) || $this->access->isAppAdmin($uid)) {
			$items[] = ['id' => 'all', 'label' => $this->l10n->t('All claims'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.allClaims'), 'icon' => 'list', 'active' => $active === 'all'];
			$items[] = ['id' => 'settlements', 'label' => $this->l10n->t('Settlements'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.settlements'), 'icon' => 'check-circle', 'active' => $active === 'settlements' || $active === 'settlement-detail'];
			$items[] = ['id' => 'reports', 'label' => $this->l10n->t('Reports'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.reports'), 'icon' => 'activity', 'active' => $active === 'reports'];
		}
		if ($this->access->isAppAdmin($uid)) {
			$items[] = ['id' => 'settings', 'label' => $this->l10n->t('Settings'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.settings'), 'icon' => 'settings', 'active' => $active === 'settings'];
			$items[] = ['id' => 'license', 'label' => $this->l10n->t('License'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.license'), 'icon' => 'shield', 'active' => $active === 'license'];
		}
		return [['label' => '', 'items' => $items]];
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return $this->render('claims');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function claims(): TemplateResponse
	{
		return $this->render('claims');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function newClaim(): TemplateResponse
	{
		return $this->render('new-claim');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function claimDetail(int $id): TemplateResponse
	{
		// Existence check — uniform 404 for invisible ids (page, not oracle).
		$this->lifecycle->loadVisible($id, $this->access->currentUserId());
		return $this->render('claim-detail', ['claimId' => $id]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function inbox(): TemplateResponse
	{
		return $this->render('inbox');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function receiptsInbox(): TemplateResponse
	{
		return $this->render('receipts');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function allClaims(): TemplateResponse
	{
		if (!$this->access->isFinance($this->access->currentUserId())
			&& !$this->access->isAuditor($this->access->currentUserId())
			&& !$this->access->isAppAdmin($this->access->currentUserId())) {
			throw new ClaimNotFoundException();
		}
		return $this->render('all');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function settlements(): TemplateResponse
	{
		if (!$this->access->isFinance($this->access->currentUserId())) {
			throw new ClaimNotFoundException();
		}
		return $this->render('settlements');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function settlementDetail(int $batchId): TemplateResponse
	{
		if (!$this->access->isFinance($this->access->currentUserId())) {
			throw new ClaimNotFoundException();
		}
		return $this->render('settlement-detail', ['batchId' => $batchId]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function reports(): TemplateResponse
	{
		if (!$this->access->isFinance($this->access->currentUserId())
			&& !$this->access->isAuditor($this->access->currentUserId())
			&& !$this->access->isAppAdmin($this->access->currentUserId())) {
			throw new ClaimNotFoundException();
		}
		return $this->render('reports');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function categories(): TemplateResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			throw new ClaimNotFoundException();
		}
		return $this->render('categories');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function rates(): TemplateResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			throw new ClaimNotFoundException();
		}
		return $this->render('rates');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function costCenters(): TemplateResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			throw new ClaimNotFoundException();
		}
		return $this->render('cost-centers');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function chains(): TemplateResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			throw new ClaimNotFoundException();
		}
		return $this->render('chains');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function settings(): TemplateResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			throw new ClaimNotFoundException();
		}
		return $this->render('settings', ['settingsSection' => 'general']);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function settingsSection(string $section): TemplateResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			throw new ClaimNotFoundException();
		}
		return $this->render('settings', ['settingsSection' => $section]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function license(): TemplateResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			throw new ClaimNotFoundException();
		}
		return $this->render('license');
	}
}
