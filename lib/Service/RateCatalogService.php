<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\RateEntry;
use OCA\ExpenseCheck\Db\RateEntryMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Versioned rate catalog (spec §6.6): effective-dated rows, immutable once
 * effective; claims snapshot code/name/rate_minor at write time. No rate
 * effective on the claimed date → RATE_MISSING (fail-closed, never a guess).
 */
class RateCatalogService
{
	public const RATE_TYPES = ['mileage', 'per_diem', 'flat'];

	public function __construct(
		private readonly RateEntryMapper $rates,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Resolve the rate in effect on $occurredOn for (type, code).
	 * @throws DomainException RATE_MISSING
	 */
	public function resolve(string $rateType, string $code, \DateTimeInterface $occurredOn): RateEntry
	{
		if (!in_array($rateType, self::RATE_TYPES, true)) {
			throw new ValidationException(['rate_type' => 'invalid']);
		}
		$rate = $this->rates->findEffective($rateType, $code, $occurredOn);
		if ($rate === null) {
			throw new DomainException('RATE_MISSING', 'No ' . $rateType . ' rate "' . $code . '" is effective on ' . $occurredOn->format('Y-m-d') . '.', 422);
		}
		return $rate;
	}

	/**
	 * Snapshot payload frozen onto a claim line.
	 * @return array{rate_id:int,rate_code:string,rate_minor:int,rate_name:string}
	 */
	public static function snapshot(RateEntry $rate): array
	{
		return [
			'rate_id' => $rate->getId(),
			'rate_code' => $rate->getCode(),
			'rate_minor' => $rate->getRateMinor(),
			'rate_name' => $rate->getName(),
		];
	}

	/**
	 * Create or update a catalog row. Immutable once effective_from <= today
	 * (spec edge 4 / RATE_IMMUTABLE) — a new effective-dated row is the only
	 * legal correction. Future-dated rows may be edited/deleted.
	 */
	public function save(?int $id, string $rateType, string $code, string $name, string $currency, int $rateMinor, \DateTimeInterface $effectiveFrom, string $actor, bool $active = true): RateEntry
	{
		$fields = [];
		if (!in_array($rateType, self::RATE_TYPES, true)) {
			$fields['rate_type'] = 'invalid';
		}
		if ($code === '' || mb_strlen($code) > 64 || !preg_match('/^[A-Za-z0-9_.-]+$/', $code)) {
			$fields['code'] = 'invalid';
		}
		if ($name === '' || mb_strlen($name) > 191) {
			$fields['name'] = 'required';
		}
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			$fields['currency'] = 'invalid';
		}
		if ($rateMinor <= 0) {
			$fields['rate_minor'] = 'invalid_amount';
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}

		$today = $this->timeFactory->getDateTime()->format('Y-m-d');
		if ($id !== null) {
			$existing = $this->rates->findById($id);
			if ($existing->getEffectiveFrom()->format('Y-m-d') <= $today) {
				throw new DomainException('RATE_IMMUTABLE', 'Effective rates are immutable — add a new effective-dated entry.', 409);
			}
			$entry = $existing;
		} else {
			$entry = new RateEntry();
			$entry->setCreatedBy($actor);
			$entry->setCreatedAt($this->timeFactory->getDateTime());
		}
		$entry->setRateType($rateType);
		$entry->setCode($code);
		$entry->setName($name);
		$entry->setCurrency(strtoupper($currency));
		$entry->setRateMinor($rateMinor);
		$entry->setEffectiveFrom(\DateTime::createFromFormat('Y-m-d', $effectiveFrom->format('Y-m-d')));
		$entry->setActive($active ? 1 : 0);
		if ($id === null) {
			return $this->rates->insert($entry);
		}
		return $this->rates->update($entry);
	}

	public function delete(int $id): void
	{
		$entry = $this->rates->findById($id);
		if ($entry === null) {
			throw new DomainException('RATE_NOT_FOUND', 'Rate not found.', 404);
		}
		$today = $this->timeFactory->getDateTime()->format('Y-m-d');
		if ($entry->getEffectiveFrom()->format('Y-m-d') <= $today) {
			throw new DomainException('RATE_IMMUTABLE', 'Effective rates cannot be deleted — add a new effective-dated entry.', 409);
		}
		$this->rates->delete($entry);
	}

	/** @return list<RateEntry> */
	public function list(): array
	{
		return $this->rates->findAll();
	}
}
