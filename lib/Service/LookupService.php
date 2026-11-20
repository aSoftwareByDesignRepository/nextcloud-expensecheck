<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\CategoryMapper;
use OCA\ExpenseCheck\Db\CostCenterMapper;
use OCA\ExpenseCheck\Db\RateEntryMapper;

/**
 * Lookup payload for claim forms (categories, cost centers, rates).
 * Single source for web AND mobile — contract parity (spec §13.4).
 */
class LookupService
{
	public function __construct(
		private readonly CategoryMapper $categories,
		private readonly CostCenterMapper $costCenters,
		private readonly RateEntryMapper $rates,
	) {
	}

	/** @return array{categories:list<array>,cost_centers:list<array>,rates:list<array>} */
	public function data(): array
	{
		return [
			'categories' => array_map(fn ($c) => [
				'id' => $c->getId(), 'code' => $c->getCode(), 'name' => $c->getName(),
				'receipt_required' => $c->getReceiptRequired() === 1,
				'requires_attendees' => $c->getRequiresAttendees() === 1,
				'default_amount_minor' => $c->getDefaultAmountMinor(),
				'max_amount_minor' => $c->getMaxAmountMinor(),
				'max_is_hard' => $c->getMaxIsHard() === 1,
				'justification_threshold_minor' => $c->getJustificationThresholdMinor(),
				'justification_min_length' => $c->getJustificationMinLength(),
				'allow_lines' => $c->getAllowLines(),
			], array_values(array_filter($this->categories->findAll(), fn ($c) => $c->getActive() === 1))),
			'cost_centers' => array_map(fn ($c) => [
				'id' => $c->getId(), 'code' => $c->getCode(), 'name' => $c->getName(),
				'parent_id' => $c->getParentId(),
			], array_values(array_filter($this->costCenters->findAll(), fn ($c) => $c->getActive() === 1))),
			'rates' => array_map(fn ($r) => [
				'id' => $r->getId(), 'rate_type' => $r->getRateType(), 'code' => $r->getCode(),
				'name' => $r->getName(), 'currency' => $r->getCurrency(),
				'rate_minor' => $r->getRateMinor(),
				'effective_from' => $r->getEffectiveFrom()?->format('Y-m-d'),
			], array_values(array_filter($this->rates->findAll(), fn ($r) => $r->getActive() === 1))),
		];
	}
}
