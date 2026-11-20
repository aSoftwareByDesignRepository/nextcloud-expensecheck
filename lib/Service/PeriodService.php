<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\Period;
use OCA\ExpenseCheck\Db\PeriodMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Period close/reopen (spec §8): closing blocks while submitted, approved or
 * changes_requested claims touch the range, unless explicitly carried over.
 * Drafts do not block but appear in the close report (audit log payload).
 * Reopen needs a reason, is recorded in exp_audit_log; settled claims stay
 * settled.
 */
class PeriodService
{
	public function __construct(
		private readonly PeriodMapper $periods,
		private readonly ClaimLineMapper $lines,
		private readonly AuditLogService $audit,
		private readonly AccessControlService $access,
		private readonly IDBConnection $db,
		private readonly ITimeFactory $timeFactory,
		private readonly PostCommitEffectQueue $postCommit,
		private readonly NotificationService $notify,
	) {
	}

	/**
	 * Close a period → PERIOD_BLOCKED when open claims occupy the range
	 * (unless carry_over=true — they keep their period and are listed in the
	 * close report).
	 */
	public function close(string $name, \DateTimeInterface $from, \DateTimeInterface $to, string $actorUid, bool $carryOver = false): Period
	{
		if (mb_strlen(trim($name)) < 1 || mb_strlen($name) > 191) {
			throw new ValidationException(['name' => 'required']);
		}
		if ($to < $from) {
			throw new ValidationException(['to' => 'invalid_range']);
		}
		$open = $this->lines->countOpenClaimsInRange($from, $to);
		if ($open > 0 && !$carryOver) {
			throw new DomainException('PERIOD_BLOCKED', $open . ' open claim(s) occupy this period.', 409);
		}
		$drafts = $this->lines->draftClaimIdsInRange($from, $to);

		$period = new Period();
		$period->setName(trim($name));
		$period->setStartOn(\DateTime::createFromFormat('Y-m-d', $from->format('Y-m-d')));
		$period->setEndOn(\DateTime::createFromFormat('Y-m-d', $to->format('Y-m-d')));
		$period->setStatus('closed');
		$period->setClosedBy($actorUid);
		$period->setClosedAt($this->timeFactory->getDateTime());
		$period = $this->periods->insert($period);
		$this->audit->record($actorUid, 'period_closed', [
			'period_id' => $period->getId(), 'name' => $period->getName(),
			'open_carried' => $open, 'drafts' => $drafts,
		]);
		$this->postCommit->runAfterCurrentCommit(function () use ($period, $open): void {
			$this->notify->periodClosed($period->getId(), $open);
		});
		return $period;
	}

	public function reopen(int $id, string $reason, string $actorUid): Period
	{
		$period = $this->periods->findById($id);
		if ($period === null) {
			throw new DomainException('PERIOD_NOT_FOUND', 'Period not found.', 404);
		}
		if ($period->getStatus() !== 'closed') {
			throw new DomainException('PERIOD_NOT_CLOSED', 'Only a closed period can be reopened.', 409);
		}
		if (mb_strlen(trim($reason)) < 3) {
			throw new ValidationException(['reason' => 'required']);
		}
		$period->setStatus('open');
		$period->setReopenReason(trim($reason));
		$period = $this->periods->update($period);
		$this->audit->record($actorUid, 'period_reopened', [
			'period_id' => $period->getId(), 'name' => $period->getName(), 'reason' => trim($reason),
		]);
		return $period;
	}

	/** Fail-closed: a line date inside a closed period is rejected. */
	public function assertLineDateAllowed(\DateTimeInterface $occurredOn): void
	{
		if ($this->periods->isDateClosed($occurredOn)) {
			throw new DomainException('PERIOD_CLOSED', 'The expense date falls in a closed period.', 422);
		}
	}

	/** @return list<Period> */
	public function list(): array
	{
		return $this->periods->findAll();
	}
}
