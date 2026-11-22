<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Db\CategoryMapper;
use OCA\ExpenseCheck\Db\Chain;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\ChainMapper;
use OCA\ExpenseCheck\Db\ChainStep;
use OCA\ExpenseCheck\Db\ChainStepMapper;
use OCA\ExpenseCheck\Db\CostCenterMapper;
use OCA\ExpenseCheck\Db\Delegate;
use OCA\ExpenseCheck\Db\DelegateMapper;
use OCA\ExpenseCheck\Db\FxRate;
use OCA\ExpenseCheck\Db\FxRateMapper;
use OCA\ExpenseCheck\Db\ManagerMap;
use OCA\ExpenseCheck\Db\ManagerMapMapper;
use OCA\ExpenseCheck\Db\RateEntryMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\AuditLogService;
use OCA\ExpenseCheck\Service\CategoryService;
use OCA\ExpenseCheck\Service\ChainRoutingService;
use OCA\ExpenseCheck\Service\ExpChainService;
use OCA\ExpenseCheck\Service\LicenseService;
use OCA\ExpenseCheck\Service\MoneyMathService;
use OCA\ExpenseCheck\Service\RateCatalogService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * Admin config API — every endpoint is app-admin gated (uniform 404 for
 * non-admins, same as finance gate: no role oracle).
 */
class AdminApiController extends AApiController
{
	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly CategoryMapper $categories,
		private readonly CostCenterMapper $costCenters,
		private readonly RateEntryMapper $rates,
		private readonly FxRateMapper $fxRates,
		private readonly ChainMapper $chains,
		private readonly ChainStepMapper $chainSteps,
		private readonly DelegateMapper $delegates,
		private readonly ManagerMapMapper $managerMap,
		private readonly ClaimMapper $claimMapper,
		private readonly CategoryService $categoryService,
		private readonly RateCatalogService $rateCatalog,
		private readonly ExpChainService $chain,
		private readonly LicenseService $license,
		private readonly AuditLogService $audit,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly \OCA\ExpenseCheck\Service\PostCommitEffectQueue $postCommit,
		private readonly \OCA\ExpenseCheck\Service\NotificationService $notify,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	private function assertAdmin(): void
	{
		if (!$this->access->isAppAdmin($this->uid())) {
			throw new ClaimNotFoundException();
		}
	}

	// ── Categories ────────────────────────────────────────────────

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function listCategories(): JSONResponse
	{
		$this->assertAdmin();
		return new JSONResponse(['ok' => true, 'categories' => array_map(fn ($c) => [
			'id' => $c->getId(), 'code' => $c->getCode(), 'name' => $c->getName(),
			'receipt_required' => $c->getReceiptRequired() === 1,
			'justification_threshold_minor' => $c->getJustificationThresholdMinor(),
			'justification_min_length' => $c->getJustificationMinLength(),
			'default_amount_minor' => $c->getDefaultAmountMinor(),
			'requires_attendees' => $c->getRequiresAttendees() === 1,
			'max_amount_minor' => $c->getMaxAmountMinor(), 'max_is_hard' => $c->getMaxIsHard() === 1,
			'allow_lines' => $c->getAllowLines(), 'active' => $c->getActive() === 1,
			'sort' => $c->getSort(), 'deleted' => $c->getDeleted() === 1,
		], $this->categories->findAll(true))]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function saveCategory(): JSONResponse
	{
		$this->assertAdmin();
		$b = $this->body();
		$id = isset($b['id']) && $b['id'] !== '' ? (int)$b['id'] : null;
		$cat = $this->categoryService->saveCategory($id, $b, $this->uid());
		return new JSONResponse(['ok' => true, 'id' => $cat->getId()]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function deleteCategory(): JSONResponse
	{
		$this->assertAdmin();
		$this->categoryService->deleteCategory($this->routeId(), $this->uid());
		return new JSONResponse(['ok' => true]);
	}

	// ── Rates ─────────────────────────────────────────────────────

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function listRates(): JSONResponse
	{
		$this->assertAdmin();
		return new JSONResponse(['ok' => true, 'rates' => array_map(fn ($r) => [
			'id' => $r->getId(), 'rate_type' => $r->getRateType(), 'code' => $r->getCode(),
			'name' => $r->getName(), 'currency' => $r->getCurrency(),
			'rate_minor' => $r->getRateMinor(),
			'effective_from' => $r->getEffectiveFrom()?->format('Y-m-d'),
			'active' => $r->getActive() === 1,
		], $this->rates->findAll())]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function saveRate(): JSONResponse
	{
		$this->assertAdmin();
		$b = $this->body();
		$id = isset($b['id']) && $b['id'] !== '' ? (int)$b['id'] : null;
		try {
			$eff = new \DateTimeImmutable($this->str($b, 'effective_from', 10));
		} catch (\Throwable) {
			throw new ValidationException(['effective_from' => 'invalid_date']);
		}
		$rate = $this->rateCatalog->save(
			$id,
			$this->str($b, 'rate_type', 16),
			$this->str($b, 'code', 64),
			$this->str($b, 'name', 191),
			strtoupper($this->str($b, 'currency', 3)),
			MoneyMathService::toMinor($this->str($b, 'rate', 20), 2),
			$eff,
			$this->uid(),
			in_array((string)($b['active'] ?? '1'), ['1', 'true'], true),
		);
		$this->audit->record($this->uid(), $id === null ? 'rate_created' : 'rate_updated', ['code' => $rate->getCode()]);
		return new JSONResponse(['ok' => true, 'id' => $rate->getId()]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function deleteRate(): JSONResponse
	{
		$this->assertAdmin();
		$this->rateCatalog->delete($this->routeId());
		return new JSONResponse(['ok' => true]);
	}

	// ── Cost centers ──────────────────────────────────────────────

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function listCostCenters(): JSONResponse
	{
		$this->assertAdmin();
		return new JSONResponse(['ok' => true, 'cost_centers' => array_map(fn ($c) => [
			'id' => $c->getId(), 'code' => $c->getCode(), 'name' => $c->getName(),
			'parent_id' => $c->getParentId(), 'azc_team_id' => $c->getAzcTeamId(),
			'budget_minor' => $c->getBudgetMinor(), 'currency' => $c->getCurrency(),
			'active' => $c->getActive() === 1, 'deleted' => $c->getDeleted() === 1,
		], $this->costCenters->findAll(true))]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function saveCostCenter(): JSONResponse
	{
		$this->assertAdmin();
		$b = $this->body();
		$id = isset($b['id']) && $b['id'] !== '' ? (int)$b['id'] : null;
		$cc = $this->categoryService->saveCostCenter($id, $b, $this->uid());
		return new JSONResponse(['ok' => true, 'id' => $cc->getId()]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function deleteCostCenter(): JSONResponse
	{
		$this->assertAdmin();
		$cc = $this->costCenters->findById($this->routeId());
		if ($cc === null) {
			throw new DomainException('COST_CENTER_NOT_FOUND', 'Cost center not found.', 404);
		}
		$cc->setDeleted(1);
		$cc->setActive(0);
		$this->costCenters->update($cc);
		$this->audit->record($this->uid(), 'cost_center_deleted', ['code' => $cc->getCode()]);
		return new JSONResponse(['ok' => true]);
	}

	// ── Chains ────────────────────────────────────────────────────

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function listChains(): JSONResponse
	{
		$this->assertAdmin();
		$out = [];
		foreach ($this->chains->findAll() as $c) {
			$out[] = [
				'id' => $c->getId(), 'name' => $c->getName(), 'active' => $c->getActive() === 1,
				'min_total_minor' => $c->getMinTotalMinor(), 'category_id' => $c->getCategoryId(),
				'sort' => $c->getSort(),
				'steps' => array_map(fn ($s) => [
					'step_no' => $s->getStepNo(), 'step_type' => $s->getStepType(),
					'group_id' => $s->getGroupId(), 'user_id' => $s->getUserId(),
				], $this->chainSteps->findByChain($c->getId())),
			];
		}
		return new JSONResponse(['ok' => true, 'chains' => $out]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function saveChain(): JSONResponse
	{
		$this->assertAdmin();
		$b = $this->body();
		$id = isset($b['id']) && $b['id'] !== '' ? (int)$b['id'] : null;
		$name = $this->str($b, 'name', 191);
		if ($name === '') {
			throw new ValidationException(['name' => 'required']);
		}
		$steps = $b['steps'] ?? [];
		if (!is_array($steps) || $steps === [] || count($steps) > 10) {
			throw new ValidationException(['steps' => 'invalid']);
		}
		foreach ($steps as $s) {
			$type = (string)($s['step_type'] ?? '');
			if (!in_array($type, ChainRoutingService::STEP_TYPES, true)) {
				throw new ValidationException(['steps' => 'invalid_type']);
			}
			if ($type === 'group' && !$this->access->groupExists((string)($s['group_id'] ?? ''))) {
				throw new ValidationException(['steps' => 'unknown_group']);
			}
			if ($type === 'user' && !$this->access->userExists((string)($s['user_id'] ?? ''))) {
				throw new ValidationException(['steps' => 'unknown_user']);
			}
		}
		$chain = $id !== null ? $this->chains->findById($id) : new Chain();
		if ($chain === null) {
			throw new DomainException('CHAIN_NOT_FOUND', 'Chain not found.', 404);
		}
		$chain->setName($name);
		$chain->setActive(in_array((string)($b['active'] ?? '1'), ['1', 'true'], true) ? 1 : 0);
		$chain->setMinTotalMinor(isset($b['min_total']) && $b['min_total'] !== '' ? MoneyMathService::toMinor((string)$b['min_total'], 2) : null);
		$chain->setCategoryId(isset($b['category_id']) && $b['category_id'] !== '' ? (int)$b['category_id'] : null);
		$chain->setSort((int)($b['sort'] ?? 0));
		$chain = $id === null ? $this->chains->insert($chain) : $this->chains->update($chain);
		$this->chainSteps->deleteByChain($chain->getId());
		$no = 1;
		foreach ($steps as $s) {
			$step = new ChainStep();
			$step->setChainId($chain->getId());
			$step->setStepNo($no++);
			$step->setStepType((string)$s['step_type']);
			$step->setGroupId($s['step_type'] === 'group' ? (string)$s['group_id'] : null);
			$step->setUserId($s['step_type'] === 'user' ? (string)$s['user_id'] : null);
			$this->chainSteps->insert($step);
		}
		$this->audit->record($this->uid(), $id === null ? 'chain_created' : 'chain_updated', ['name' => $name]);
		return new JSONResponse(['ok' => true, 'id' => $chain->getId()]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function deleteChain(): JSONResponse
	{
		$this->assertAdmin();
		$chain = $this->chains->findById($this->routeId());
		if ($chain === null) {
			throw new DomainException('CHAIN_NOT_FOUND', 'Chain not found.', 404);
		}
		$chain->setActive(0);
		$this->chains->update($chain);
		$this->audit->record($this->uid(), 'chain_deactivated', ['name' => $chain->getName()]);
		return new JSONResponse(['ok' => true]);
	}

	// ── Delegates ─────────────────────────────────────────────────

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function listDelegates(): JSONResponse
	{
		$this->assertAdmin();
		return new JSONResponse(['ok' => true, 'delegates' => array_map(fn ($d) => [
			'id' => $d->getId(), 'manager_uid' => $d->getManagerUid(), 'delegate_uid' => $d->getDelegateUid(),
			'valid_from' => $d->getValidFrom()?->format('Y-m-d'), 'valid_to' => $d->getValidTo()?->format('Y-m-d'),
			'revoked' => $d->getRevokedAt() !== null,
		], $this->delegates->findAll())]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function saveDelegate(): JSONResponse
	{
		$this->assertAdmin();
		$b = $this->body();
		$manager = $this->str($b, 'manager_uid', 64);
		$delegate = $this->str($b, 'delegate_uid', 64);
		if (!$this->access->userExists($manager) || !$this->access->userExists($delegate)) {
			throw new ValidationException(['delegate_uid' => 'unknown_user']);
		}
		if ($manager === $delegate) {
			throw new ValidationException(['delegate_uid' => 'invalid']);
		}
		try {
			$from = \DateTime::createFromFormat('Y-m-d', $this->str($b, 'valid_from', 10));
			$to = \DateTime::createFromFormat('Y-m-d', $this->str($b, 'valid_to', 10));
			if (!$from || !$to || $to < $from) {
				throw new \Exception();
			}
		} catch (\Throwable) {
			throw new ValidationException(['valid_from' => 'invalid_range']);
		}
		$d = new Delegate();
		$d->setManagerUid($manager);
		$d->setDelegateUid($delegate);
		$d->setValidFrom($from);
		$d->setValidTo($to);
		$d->setCreatedBy($this->uid());
		$d->setCreatedAt(new \DateTime());
		$d = $this->delegates->insert($d);
		$this->audit->record($this->uid(), 'delegate_created', ['manager' => $manager, 'delegate' => $delegate]);
		$this->postCommit->runAfterCurrentCommit(function () use ($delegate): void {
			$this->notify->delegationGranted($delegate);
		});
		return new JSONResponse(['ok' => true, 'id' => $d->getId()], 201);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function revokeDelegate(): JSONResponse
	{
		$this->assertAdmin();
		$d = $this->delegates->findById($this->routeId());
		if ($d === null) {
			throw new DomainException('DELEGATE_NOT_FOUND', 'Delegation not found.', 404);
		}
		$d->setRevokedAt(new \DateTime());
		$this->delegates->update($d);
		$this->audit->record($this->uid(), 'delegate_revoked', ['delegate' => $d->getDelegateUid()]);
		return new JSONResponse(['ok' => true]);
	}

	// ── Manager map ───────────────────────────────────────────────

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function listManagerMap(): JSONResponse
	{
		$this->assertAdmin();
		return new JSONResponse(['ok' => true, 'manager_map' => array_map(fn ($m) => [
			'id' => $m->getId(), 'user_id' => $m->getUserId(),
			'manager_uids' => json_decode($m->getManagerUids(), true) ?? [],
		], $this->managerMap->findAll())]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function saveManagerMap(): JSONResponse
	{
		$this->assertAdmin();
		$b = $this->body();
		$user = $this->str($b, 'user_id', 64);
		$managers = $b['manager_uids'] ?? [];
		if (!$this->access->userExists($user)) {
			throw new ValidationException(['user_id' => 'unknown_user']);
		}
		if (!is_array($managers)) {
			$managers = [$managers];
		}
		$managers = array_values(array_unique(array_filter(array_map('strval', $managers))));
		foreach ($managers as $m) {
			if (!$this->access->userExists($m)) {
				throw new ValidationException(['manager_uids' => 'unknown_user']);
			}
			if ($m === $user) {
				throw new ValidationException(['manager_uids' => 'invalid']);
			}
		}
		$map = $this->managerMap->findByUser($user) ?? new ManagerMap();
		$map->setUserId($user);
		$map->setManagerUids(json_encode($managers));
		if ($map->getCreatedBy() === '') {
			// Fresh entity carries '' (not null) — a no-op setter would leave
			// the column out of the INSERT and violate the notnull schema.
			$map->setCreatedBy($this->uid());
		}
		$map->setCreatedAt($map->getCreatedAt() ?? new \DateTime());
		$map->setUpdatedAt(new \DateTime());
		$map = $map->getId() === null ? $this->managerMap->insert($map) : $this->managerMap->update($map);
		$this->audit->record($this->uid(), 'manager_map_saved', ['user' => $user]);
		return new JSONResponse(['ok' => true, 'id' => $map->getId()]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function deleteManagerMap(): JSONResponse
	{
		$this->assertAdmin();
		$m = $this->managerMap->findById($this->routeId());
		if ($m === null) {
			throw new DomainException('MANAGER_MAP_NOT_FOUND', 'Entry not found.', 404);
		}
		$this->managerMap->delete($m);
		$this->audit->record($this->uid(), 'manager_map_deleted', ['user' => $m->getUserId()]);
		return new JSONResponse(['ok' => true]);
	}

	// ── FX rates (admin-seeded suggestions) ───────────────────────

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function listFxRates(): JSONResponse
	{
		$this->assertAdmin();
		return new JSONResponse(['ok' => true, 'fx_rates' => array_map(fn ($r) => [
			'id' => $r->getId(), 'currency' => $r->getCurrency(), 'rate' => $r->getRate(),
			'effective_from' => $r->getEffectiveFrom()?->format('Y-m-d'),
		], $this->fxRates->findAll())]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function saveFxRate(): JSONResponse
	{
		$this->assertAdmin();
		$b = $this->body();
		$cur = strtoupper($this->str($b, 'currency', 3));
		if (!preg_match('/^[A-Z]{3}$/', $cur)) {
			throw new ValidationException(['currency' => 'invalid']);
		}
		if (MoneyMathService::decimalToScaledInt($this->str($b, 'rate', 20), 1000000) <= 0) {
			throw new ValidationException(['rate' => 'invalid_fx']);
		}
		try {
			$eff = \DateTime::createFromFormat('Y-m-d', $this->str($b, 'effective_from', 10));
			if (!$eff) {
				throw new \Exception();
			}
		} catch (\Throwable) {
			throw new ValidationException(['effective_from' => 'invalid_date']);
		}
		$r = new FxRate();
		$r->setCurrency($cur);
		$r->setRate($this->str($b, 'rate', 20));
		$r->setEffectiveFrom($eff);
		$r->setCreatedBy($this->uid());
		$r->setCreatedAt(new \DateTime());
		try {
			$r = $this->fxRates->insert($r);
		} catch (\OCP\DB\Exception) {
			throw new DomainException('DUPLICATE_CODE', 'An FX rate for this currency and date exists.', 409);
		}
		$this->audit->record($this->uid(), 'fx_rate_created', ['currency' => $cur]);
		return new JSONResponse(['ok' => true, 'id' => $r->getId()], 201);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function deleteFxRate(): JSONResponse
	{
		$this->assertAdmin();
		$r = $this->fxRates->findById($this->routeId());
		if ($r === null) {
			throw new DomainException('FX_NOT_FOUND', 'FX rate not found.', 404);
		}
		$this->fxRates->delete($r);
		return new JSONResponse(['ok' => true]);
	}

	// ── Access door ───────────────────────────────────────────────

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function getAccess(): JSONResponse
	{
		$this->assertAdmin();
		return new JSONResponse(['ok' => true, 'access' => [
			'restriction_enabled' => $this->access->isAccessRestrictionEnabled(),
			'allowed_user_ids' => $this->access->getAllowedUserIds(),
			'allowed_group_ids' => $this->access->getAllowedGroupIds(),
			'app_admin_user_ids' => $this->access->getAppAdminIds(),
		]]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function saveAccess(): JSONResponse
	{
		$this->assertAdmin();
		$b = $this->body();
		try {
			$this->access->writeAccessPolicy(
				in_array((string)($b['restriction_enabled'] ?? ''), ['1', 'true'], true),
				(array)($b['allowed_user_ids'] ?? []),
				(array)($b['allowed_group_ids'] ?? []),
				(array)($b['app_admin_user_ids'] ?? []),
			);
		} catch (\InvalidArgumentException $e) {
			throw new ValidationException(['access' => 'unknown_principal']);
		}
		$this->audit->record($this->uid(), 'access_policy_saved');
		return $this->getAccess();
	}

	// ── Directory pickers (never raw UID entry) ───────────────────

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function directoryUsers(): JSONResponse
	{
		$this->assertAdmin();
		$q = strtolower((string)$this->request->getParam('q', ''));
		// UID search alone misses people findable only by display name
		// (e.g. 'Marie' → uid 'm.schulz') — merge both, dedup by uid.
		$out = [];
		$seen = [];
		$push = function ($u) use (&$out, &$seen) {
			$uid = $u->getUID();
			if (!isset($seen[$uid])) {
				$seen[$uid] = true;
				$out[] = [
					'uid' => $uid,
					'name' => $u->getDisplayName(),
					'displayName' => $u->getDisplayName(),
					'hasSeat' => $this->license->hasSeat($uid),
				];
			}
		};
		foreach ($this->userManager->search($q, 25) as $u) {
			$push($u);
		}
		foreach (($this->userManager->searchDisplayName($q, 25) ?: []) as $u) {
			$push($u);
		}
		return new JSONResponse(['ok' => true, 'users' => array_slice($out, 0, 25)]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function directoryGroups(): JSONResponse
	{
		$this->assertAdmin();
		$q = strtolower((string)$this->request->getParam('q', ''));
		$out = [];
		foreach ($this->groupManager->search($q, 25) as $g) {
			$out[] = ['gid' => $g->getGID(), 'name' => $g->getDisplayName()];
		}
		return new JSONResponse(['ok' => true, 'groups' => $out]);
	}

	// ── Journal verification + diagnostics ────────────────────────

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function verifyJournal(): JSONResponse
	{
		$this->assertAdmin();
		$claimId = (int)$this->request->getParam('claimId');
		if ($this->claimMapper->findByIdRaw($claimId) === null) {
			throw new DomainException('CLAIM_NOT_FOUND', 'Claim not found.', 404);
		}
		return new JSONResponse(['ok' => true, 'verification' => $this->chain->verify($claimId)]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function diagnostics(): JSONResponse
	{
		$this->assertAdmin();
		return new JSONResponse(['ok' => true, 'diagnostics' => [
			'app' => Application::APP_ID,
			'version' => '1.0.0',
			'license' => $this->license->state() !== null
				? ['valid' => $this->license->state()['valid'], 'seats' => $this->license->seatInventory()]
				: null,
			'audit_tail' => array_slice($this->audit->list(20), 0, 20),
		]]);
	}
}
