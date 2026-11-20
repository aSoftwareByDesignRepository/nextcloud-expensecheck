<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\Category;
use OCA\ExpenseCheck\Db\CategoryMapper;
use OCA\ExpenseCheck\Db\CostCenter;
use OCA\ExpenseCheck\Db\CostCenterMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Admin catalog CRUD: categories + cost centers (spec §10.2). Soft-delete
 * (deleted=1) — historical claims keep their snapshot; the catalog row stays
 * referentially stable.
 */
class CategoryService
{
	public function __construct(
		private readonly CategoryMapper $categories,
		private readonly CostCenterMapper $costCenters,
		private readonly AuditLogService $audit,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param array<string,mixed> $p
	 */
	public function saveCategory(?int $id, array $p, string $actor): Category
	{
		$fields = [];
		$code = trim((string)($p['code'] ?? ''));
		$name = trim((string)($p['name'] ?? ''));
		if ($code === '' || mb_strlen($code) > 32 || !preg_match('/^[A-Za-z0-9_.-]+$/', $code)) {
			$fields['code'] = 'invalid';
		}
		if ($name === '' || mb_strlen($name) > 191) {
			$fields['name'] = 'required';
		}
		$allowLines = trim((string)($p['allow_lines'] ?? 'receipt,mileage,per_diem,flat'));
		$maxAmt = isset($p['max_amount']) && $p['max_amount'] !== '' ? MoneyMathService::toMinor((string)$p['max_amount'], 2) : null;
		$defAmt = isset($p['default_amount']) && $p['default_amount'] !== '' ? MoneyMathService::toMinor((string)$p['default_amount'], 2) : null;
		$thresh = isset($p['justification_threshold']) && $p['justification_threshold'] !== '' ? MoneyMathService::toMinor((string)$p['justification_threshold'], 2) : null;
		if ($fields !== []) {
			throw new ValidationException($fields);
		}

		$existing = $this->categories->findByCode($code);
		if ($existing !== null && $existing->getId() !== $id) {
			throw new DomainException('DUPLICATE_CODE', 'Category code is already in use.', 409);
		}
		$cat = $id !== null ? $this->categories->findById($id) : new Category();
		if ($cat === null) {
			throw new DomainException('CATEGORY_NOT_FOUND', 'Category not found.', 404);
		}
		$cat->setCode($code);
		$cat->setName($name);
		$cat->setReceiptRequired(!empty($p['receipt_required']) ? 1 : 0);
		$cat->setJustificationThresholdMinor($thresh);
		$cat->setJustificationMinLength(max(0, min(2000, (int)($p['justification_min_length'] ?? 0))));
		$cat->setDefaultAmountMinor($defAmt);
		$cat->setRequiresAttendees(!empty($p['requires_attendees']) ? 1 : 0);
		$cat->setMaxAmountMinor($maxAmt);
		$cat->setMaxIsHard(!empty($p['max_is_hard']) ? 1 : 0);
		$cat->setAllowLines($allowLines);
		$cat->setActive(!empty($p['active']) ? 1 : 0);
		$cat->setSort((int)($p['sort'] ?? 0));
		$cat = $id === null ? $this->categories->insert($cat) : $this->categories->update($cat);
		$this->audit->record($actor, $id === null ? 'category_created' : 'category_updated', ['code' => $code]);
		return $cat;
	}

	public function deleteCategory(int $id, string $actor): void
	{
		$cat = $this->categories->findById($id);
		if ($cat === null) {
			throw new DomainException('CATEGORY_NOT_FOUND', 'Category not found.', 404);
		}
		$cat->setDeleted(1);
		$cat->setActive(0);
		$this->categories->update($cat);
		$this->audit->record($actor, 'category_deleted', ['code' => $cat->getCode()]);
	}

	/**
	 * Cost center save. Cycle guard: parent chain is walked to the root;
	 * pointing a node at itself or its descendant is rejected.
	 * @param array<string,mixed> $p
	 */
	public function saveCostCenter(?int $id, array $p, string $actor): CostCenter
	{
		$fields = [];
		$code = trim((string)($p['code'] ?? ''));
		$name = trim((string)($p['name'] ?? ''));
		if ($code === '' || mb_strlen($code) > 32 || !preg_match('/^[A-Za-z0-9_.-]+$/', $code)) {
			$fields['code'] = 'invalid';
		}
		if ($name === '' || mb_strlen($name) > 191) {
			$fields['name'] = 'required';
		}
		$parentId = isset($p['parent_id']) && $p['parent_id'] !== '' ? (int)$p['parent_id'] : null;
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		if ($parentId !== null) {
			if ($parentId === $id) {
				throw new ValidationException(['parent_id' => 'cycle']);
			}
			$cur = $parentId;
			$seen = 0;
			while ($cur !== null && $seen++ < 100) {
				if ($cur === $id) {
					throw new ValidationException(['parent_id' => 'cycle']);
				}
				$parent = $this->costCenters->findById($cur);
				if ($parent === null) {
					throw new ValidationException(['parent_id' => 'invalid']);
				}
				$cur = $parent->getParentId();
			}
		}

		$cc = $id !== null ? $this->costCenters->findById($id) : new CostCenter();
		if ($cc === null) {
			throw new DomainException('COST_CENTER_NOT_FOUND', 'Cost center not found.', 404);
		}
		$existing = null;
		foreach ($this->costCenters->findAll(true) as $c) {
			if ($c->getCode() === $code && $c->getId() !== $id) {
				$existing = $c;
				break;
			}
		}
		if ($existing !== null) {
			throw new DomainException('DUPLICATE_CODE', 'Cost-center code is already in use.', 409);
		}
		$cc->setCode($code);
		$cc->setName($name);
		$cc->setParentId($parentId);
		$cc->setAzcTeamId(isset($p['azc_team_id']) && $p['azc_team_id'] !== '' ? (int)$p['azc_team_id'] : null);
		$cc->setBudgetMinor(isset($p['budget']) && $p['budget'] !== '' ? MoneyMathService::toMinor((string)$p['budget'], 2) : null);
		$cur = strtoupper(trim((string)($p['currency'] ?? '')));
		$cc->setCurrency(preg_match('/^[A-Z]{3}$/', $cur) ? $cur : null);
		$cc->setActive(!empty($p['active']) ? 1 : 0);
		$cc = $id === null ? $this->costCenters->insert($cc) : $this->costCenters->update($cc);
		$this->audit->record($actor, $id === null ? 'cost_center_created' : 'cost_center_updated', ['code' => $code]);
		return $cc;
	}
}
