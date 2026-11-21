<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\ConfigTransfer\TransferReceiptStore;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCA\ExpenseCheck\Support\AssetRegistrar;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
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
		'index' => ['template' => 'index', 'icon' => 'home', 'help' => 'What needs your attention — approvals, open claims, receipts.'],
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
		private readonly TransferReceiptStore $transferReceipts,
		private readonly \OCA\ExpenseCheck\Service\ApprovalService $approvals,
		private readonly \OCA\ExpenseCheck\Db\ClaimMapper $claims,
		private readonly \OCA\ExpenseCheck\Db\AttachmentMapper $attachments,
		private readonly \OCP\IConfig $config,
	) {
		parent::__construct($appName, $request);
	}

	private function render(string $pageId, array $params = []): TemplateResponse|NotFoundResponse
	{
		AssetRegistrar::registerStyles();
		Util::addScript(Application::APP_ID, 'app');
		Util::addScript(Application::APP_ID, 'common/toasts');
		Util::addScript(Application::APP_ID, 'common/mobile-nav');
		Util::addScript(Application::APP_ID, 'common/field-errors');
		Util::addScript(Application::APP_ID, 'common/error-copy');
		Util::addScript(Application::APP_ID, 'common/focus-preservation');
		Util::addScript(Application::APP_ID, 'common/view-components');
		Util::addScript(Application::APP_ID, 'pages/app-views');
		if (($params['settingsSection'] ?? '') === 'transfer') {
			Util::addStyle(Application::APP_ID, 'common/transfer');
			Util::addScript(Application::APP_ID, 'transfer-bridge');
			Util::addScript(Application::APP_ID, 'transfer');
		}
		$uid = $this->access->currentUserId();
		$role = 'member';
		if ($this->access->isAppAdmin($uid)) {
			$role = 'admin';
		} elseif ($this->access->isFinance($uid)) {
			$role = 'finance';
		} elseif ($this->access->isAuditor($uid)) {
			$role = 'auditor';
		}
		if ($pageId === 'settings') {
			$params['settingsSectionLabels'] = \OCA\ExpenseCheck\Service\SettingsSectionCatalog::labels($this->l10n);
			$sectionUrls = [];
			foreach (\OCA\ExpenseCheck\Service\SettingsSectionCatalog::SLUGS as $slug) {
				$sectionUrls[$slug] = $this->urlGenerator->linkToRoute(
					'expensecheck.page.settingsSection', ['section' => $slug]);
			}
			$params['settingsSectionUrls'] = $sectionUrls;
		}
		$params += [
			'pageId' => $pageId,
			'pageTitle' => $this->l10n->t(ucwords(str_replace('-', ' ', $pageId))),
			'pageHelp' => $this->l10n->t(self::PAGES[$pageId]['help'] ?? ''),
			'pageIcon' => self::PAGES[$pageId]['icon'] ?? 'file-text',
			'roleLabel' => $this->l10n->t(ucfirst($role)),
			'urls' => $this->pageUrls(),
			'navGroups' => $this->navGroups($uid, $pageId),
			'footerPartial' => __DIR__ . '/../../templates/parts/feedback-nav-footer.php',
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
		$groups = [
			['label' => $this->l10n->t('Work'), 'items' => [
				['id' => 'index', 'label' => $this->l10n->t('Overview'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.index'), 'icon' => 'home', 'active' => $active === 'index'],
				['id' => 'claims', 'label' => $this->l10n->t('My claims'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.claims'), 'icon' => 'clipboard-list', 'active' => $active === 'claims'],
				['id' => 'new-claim', 'label' => $this->l10n->t('New claim'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.newClaim'), 'icon' => 'plus', 'active' => $active === 'new-claim'],
				['id' => 'receipts', 'label' => $this->l10n->t('Receipts'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.receiptsInbox'), 'icon' => 'upload', 'active' => $active === 'receipts'],
			]],
		];
		// §9.4: the Approvals link renders only for users who can actually
		// decide — pending work or an oversight role — with a pending count.
		$pending = count($this->approvals->inboxFor($uid));
		if ($pending > 0 || $this->access->isFinance($uid) || $this->access->isAuditor($uid) || $this->access->isAppAdmin($uid)) {
			$item = ['id' => 'inbox', 'label' => $this->l10n->t('Approvals'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.inbox'), 'icon' => 'inbox', 'active' => $active === 'inbox'];
			if ($pending > 0) {
				$item['hint'] = $this->l10n->t('{n} pending', ['n' => $pending]);
			}
			$groups[] = ['label' => $this->l10n->t('Approve'), 'items' => [$item]];
		}
		if ($this->access->isFinance($uid) || $this->access->isAuditor($uid) || $this->access->isAppAdmin($uid)) {
			$groups[] = ['label' => $this->l10n->t('Finance'), 'items' => [
				['id' => 'all', 'label' => $this->l10n->t('All claims'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.allClaims'), 'icon' => 'list', 'active' => $active === 'all'],
				['id' => 'settlements', 'label' => $this->l10n->t('Settlements'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.settlements'), 'icon' => 'check-circle', 'active' => $active === 'settlements' || $active === 'settlement-detail'],
				['id' => 'reports', 'label' => $this->l10n->t('Reports'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.reports'), 'icon' => 'activity', 'active' => $active === 'reports'],
			]];
		}
		if ($this->access->isAppAdmin($uid)) {
			$groups[] = ['label' => $this->l10n->t('Admin'), 'items' => [
				['id' => 'categories', 'label' => $this->l10n->t('Categories'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.categories'), 'icon' => 'grid', 'active' => $active === 'categories'],
				['id' => 'rates', 'label' => $this->l10n->t('Rates'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.rates'), 'icon' => 'activity', 'active' => $active === 'rates'],
				['id' => 'cost-centers', 'label' => $this->l10n->t('Cost centers'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.costCenters'), 'icon' => 'layout-grid', 'active' => $active === 'cost-centers'],
				['id' => 'chains', 'label' => $this->l10n->t('Chains'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.chains'), 'icon' => 'repeat', 'active' => $active === 'chains'],
				['id' => 'settings', 'label' => $this->l10n->t('Settings'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.settings'), 'icon' => 'settings', 'active' => $active === 'settings'],
				['id' => 'license', 'label' => $this->l10n->t('License'), 'href' => $this->urlGenerator->linkToRoute('expensecheck.page.license'), 'icon' => 'shield', 'active' => $active === 'license'],
			]];
		}
		return $groups;
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function index(): TemplateResponse|NotFoundResponse
	{
		$uid = $this->access->currentUserId();
		return $this->render('index', [
			'dash' => $this->dashboard($uid),
			'onboarding' => $this->onboarding($uid),
		]);
	}

	/**
	 * §9.3 first-run checklist — first applicable persona wins, per-user
	 * dismissible. Returns null when dismissed.
	 */
	private function onboarding(string $uid): ?array
	{
		if ($this->config->getUserValue($uid, Application::APP_ID, 'onboarding_dismissed', '0') === '1') {
			return null;
		}
		// Admin first — bootstrap is the rarest, most valuable checklist.
		if ($this->access->isAppAdmin($uid)) {
			return ['role' => $this->l10n->t('Getting started — admin'), 'steps' => [
				$this->l10n->t('Create expense categories'),
				$this->l10n->t('Add mileage and per-diem rates'),
				$this->l10n->t('Set up an approval chain'),
				$this->l10n->t('Open the access door for your team'),
			]];
		}
		if ($this->access->isFinance($uid)) {
			return ['role' => $this->l10n->t('Getting started — finance'), 'steps' => [
				$this->l10n->t('Review the settlement queue'),
				$this->l10n->t('Settle your first batch'),
				$this->l10n->t('Export the payout file'),
			]];
		}
		if (count($this->approvals->inboxFor($uid)) > 0) {
			return ['role' => $this->l10n->t('Getting started — approver'), 'steps' => [
				$this->l10n->t('Open your Approvals inbox'),
				$this->l10n->t('Make your first decision'),
			]];
		}
		return ['role' => $this->l10n->t('Getting started'), 'steps' => [
			$this->l10n->t('Snap a receipt'),
			$this->l10n->t('Create a claim line'),
			$this->l10n->t('Submit your first claim'),
		]];
	}

	private function dashboard(string $uid): array
	{
		$pending = $this->approvals->inboxFor($uid);
		$mine = $this->claims->findByUser($uid);
		$open = [ClaimLifecycleService::STATUS_DRAFT, ClaimLifecycleService::STATUS_SUBMITTED, ClaimLifecycleService::STATUS_CHANGES_REQUESTED];
		$openCount = 0; $openMinor = 0; $currency = 'EUR';
		$drafts = 0; $changes = 0; $recent = [];
		foreach ($mine as $c) {
			if (in_array($c->getStatus(), $open, true)) {
				$openCount++; $openMinor += $c->getTotalMinor();
				$currency = $c->getCurrency() ?: $currency;
				if ($c->getStatus() === ClaimLifecycleService::STATUS_DRAFT) { $drafts++; }
				if ($c->getStatus() === ClaimLifecycleService::STATUS_CHANGES_REQUESTED) { $changes++; }
			}
		}
		// findByUser is newest-first — first 5 become the recent list.
		foreach (array_slice($mine, 0, 5) as $c) {
			$recent[] = [
				'no' => $c->getClaimNo(), 'title' => $c->getTitle(),
				'status' => $c->getStatus(),
				'href' => $this->urlGenerator->linkToRoute('expensecheck.page.claimDetail', ['id' => $c->getId()]),
			];
		}
		$unfiled = count($this->attachments->findInbox($uid));
		$queue = [];
		if (count($pending) > 0) {
			$queue[] = ['href' => $this->urlGenerator->linkToRoute('expensecheck.page.inbox'),
				'label' => $this->l10n->t('{n} claim(s) awaiting your approval', ['n' => count($pending)]),
				'icon' => 'inbox'];
		}
		if ($changes > 0) {
			$queue[] = ['href' => $this->urlGenerator->linkToRoute('expensecheck.page.claims'),
				'label' => $this->l10n->t('{n} claim(s) sent back for changes', ['n' => $changes]),
				'icon' => 'repeat'];
		}
		if ($drafts > 0) {
			$queue[] = ['href' => $this->urlGenerator->linkToRoute('expensecheck.page.claims'),
				'label' => $this->l10n->t('{n} draft(s) to finish', ['n' => $drafts]),
				'icon' => 'file-text'];
		}
		if ($unfiled > 0) {
			$queue[] = ['href' => $this->urlGenerator->linkToRoute('expensecheck.page.receiptsInbox'),
				'label' => $this->l10n->t('{n} receipt(s) to file', ['n' => $unfiled]),
				'icon' => 'upload'];
		}
		return [
			'pendingApprovals' => count($pending),
			'openClaims' => $openCount,
			'openTotal' => number_format($openMinor / 100, 2) . ' ' . $currency,
			'queue' => $queue,
			'recent' => $recent,
		];
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function claims(): TemplateResponse|NotFoundResponse
	{
		return $this->render('claims');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function newClaim(): TemplateResponse|NotFoundResponse
	{
		return $this->render('new-claim');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function claimDetail(int $id): TemplateResponse|NotFoundResponse
	{
		// Existence check — uniform 404 for invisible ids (page, not oracle).
		try {
			$this->lifecycle->loadVisible($id, $this->access->currentUserId());
		} catch (ClaimNotFoundException) {
			return new NotFoundResponse();
		}
		return $this->render('claim-detail', ['claimId' => $id]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function inbox(): TemplateResponse|NotFoundResponse
	{
		return $this->render('inbox');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function receiptsInbox(): TemplateResponse|NotFoundResponse
	{
		return $this->render('receipts');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function allClaims(): TemplateResponse|NotFoundResponse
	{
		if (!$this->access->isFinance($this->access->currentUserId())
			&& !$this->access->isAuditor($this->access->currentUserId())
			&& !$this->access->isAppAdmin($this->access->currentUserId())) {
			return new NotFoundResponse();
		}
		return $this->render('all');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function settlements(): TemplateResponse|NotFoundResponse
	{
		if (!$this->access->isFinance($this->access->currentUserId())) {
			return new NotFoundResponse();
		}
		return $this->render('settlements');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function settlementDetail(int $batchId): TemplateResponse|NotFoundResponse
	{
		if (!$this->access->isFinance($this->access->currentUserId())) {
			return new NotFoundResponse();
		}
		return $this->render('settlement-detail', ['batchId' => $batchId]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function reports(): TemplateResponse|NotFoundResponse
	{
		if (!$this->access->isFinance($this->access->currentUserId())
			&& !$this->access->isAuditor($this->access->currentUserId())
			&& !$this->access->isAppAdmin($this->access->currentUserId())) {
			return new NotFoundResponse();
		}
		return $this->render('reports');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function categories(): TemplateResponse|NotFoundResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			return new NotFoundResponse();
		}
		return $this->render('categories');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function rates(): TemplateResponse|NotFoundResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			return new NotFoundResponse();
		}
		return $this->render('rates');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function costCenters(): TemplateResponse|NotFoundResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			return new NotFoundResponse();
		}
		return $this->render('cost-centers');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function chains(): TemplateResponse|NotFoundResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			return new NotFoundResponse();
		}
		return $this->render('chains');
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function settings(): TemplateResponse|NotFoundResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			return new NotFoundResponse();
		}
		return $this->render('settings', [
			'settingsSection' => 'general',
			'isSystemAdmin' => false,
			'transferLastReceipt' => null,
		]);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function settingsSection(string $section): TemplateResponse|NotFoundResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			return new NotFoundResponse();
		}
		$params = ['settingsSection' => $section];
		if ($section === 'transfer') {
			$params['isSystemAdmin'] = $this->access->isSystemAdmin($this->access->currentUserId());
			$params['transferLastReceipt'] = $this->transferReceipts->lastReceipt();
			// Config-only transfer (spec §12.6) — the synced part defaults
			// to "business data" copy; this app must say what it ships.
			$params['transferExportBlurb'] = $this->l10n->t(
				'Download all settings, catalogues, approval chains and org config as one encrypted file — then import it on your production instance. Claims, receipts and journals never leave this instance.'
			);
		}
		return $this->render('settings', $params);
	}

	#[NoAdminRequired] #[NoCSRFRequired]
	public function license(): TemplateResponse|NotFoundResponse
	{
		if (!$this->access->isAppAdmin($this->access->currentUserId())) {
			return new NotFoundResponse();
		}
		return $this->render('license');
	}
}
