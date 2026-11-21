<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\AppInfo\Application;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * §9.3 — Inline glossary. Expense work carries domain words (Bewirtung,
 * per-diem, Standardkosten, payout channel …) that a claimant or approver
 * should be able to look up right where they appear. Same contract as the
 * sibling apps: PHP is the single source, a JS mirror renders tooltips.
 */
final class GlossaryService
{
	public const TERMS = [
		'claim',
		'claim_line',
		'receipt',
		'receipts_inbox',
		'reimbursable',
		'per_diem',
		'mileage',
		'standard_amount',
		'entertainment_expense',
		'cost_center',
		'approval_chain',
		'line_manager',
		'settlement',
		'settlement_batch',
		'period_close',
		'revert',
		'admin_review',
		'payout_channel',
	];

	private IL10N $l10n;

	public function __construct(IFactory $factory)
	{
		$this->l10n = $factory->get(Application::APP_ID);
	}

	public function define(string $term): string
	{
		return match ($term) {
			'claim' => $this->l10n->t('Claim — one reimbursement request with its own number, status trail and attachments. A claim is drafted, submitted, decided and settled; every step is journaled.'),
			'claim_line' => $this->l10n->t('Claim line — a single expense item inside a claim: a receipt, a mileage distance, a per-diem range or a flat amount from the rate catalog.'),
			'receipt' => $this->l10n->t('Receipt — the photo or document that proves a line. Files are attached to a line explicitly; nothing is attached automatically.'),
			'receipts_inbox' => $this->l10n->t('Receipts inbox — a capture-first bucket for photos and files you have not filed yet. File an inbox item onto a claim line when you are ready; unfiled items are purged automatically.'),
			'reimbursable' => $this->l10n->t('Reimbursable — money you paid out of pocket and get back through payroll. Non-reimbursable lines (company card, advance) are documented but not paid out.'),
			'per_diem' => $this->l10n->t('Per diem — a flat daily allowance for travel days, claimed per date range and destination. The amount comes from the admin rate catalog effective on your travel date.'),
			'mileage' => $this->l10n->t('Mileage — a distance claim: kilometres times the catalog rate effective on the trip date. GPS-derived distance is marked as such for the approver.'),
			'standard_amount' => $this->l10n->t('Standard amount — a category default that prefills a receipt line. You can override it; the approver sees a flag when you do.'),
			'entertainment_expense' => $this->l10n->t('Entertainment expense — a category-flagged receipt (Bewirtung) that requires the names of all entertained persons and the business occasion.'),
			'cost_center' => $this->l10n->t('Cost center — the accounting bucket a line is charged to. Lines can be split across several cost centers by percent or amount.'),
			'approval_chain' => $this->l10n->t('Approval chain — the ordered decision steps a submitted claim routes through: groups, named approvers or your line manager, chosen by amount or category.'),
			'line_manager' => $this->l10n->t('Line manager — a chain step resolved per claimant to their actual manager: via ArbeitszeitCheck when installed, else the admin-maintained manager map.'),
			'settlement' => $this->l10n->t('Settlement — finance marking approved claims paid with a payment reference. Settling is documented and irreversible; corrections go through a counter-claim.'),
			'settlement_batch' => $this->l10n->t('Settlement batch — the set of claims paid together plus the exported CSV/ODS artifact and its file hash for bookkeeping.'),
			'period_close' => $this->l10n->t('Period close — locking an accounting period so settled claims can never change. Reopening needs a reason and is audited.'),
			'revert' => $this->l10n->t('Revert — finance sending an approved claim back to submitted before settlement, with a mandatory reason. Settled claims can never be reverted.'),
			'admin_review' => $this->l10n->t('Admin review — the queue a claim lands in when routing fails (empty group, missing manager). It is never auto-approved; an app admin assigns a decider.'),
			'payout_channel' => $this->l10n->t('Payout channel — how a line is settled: payroll for out-of-pocket money, card reconciliation for company-card or advance lines.'),
			default => '',
		};
	}

	public function label(string $term): string
	{
		return match ($term) {
			'claim' => $this->l10n->t('Claim'),
			'claim_line' => $this->l10n->t('Claim line'),
			'receipt' => $this->l10n->t('Receipt'),
			'receipts_inbox' => $this->l10n->t('Receipts inbox'),
			'reimbursable' => $this->l10n->t('Reimbursable'),
			'per_diem' => $this->l10n->t('Per diem'),
			'mileage' => $this->l10n->t('Mileage'),
			'standard_amount' => $this->l10n->t('Standard amount'),
			'entertainment_expense' => $this->l10n->t('Entertainment expense'),
			'cost_center' => $this->l10n->t('Cost center'),
			'approval_chain' => $this->l10n->t('Approval chain'),
			'line_manager' => $this->l10n->t('Line manager'),
			'settlement' => $this->l10n->t('Settlement'),
			'settlement_batch' => $this->l10n->t('Settlement batch'),
			'period_close' => $this->l10n->t('Period close'),
			'revert' => $this->l10n->t('Revert'),
			'admin_review' => $this->l10n->t('Admin review'),
			'payout_channel' => $this->l10n->t('Payout channel'),
			default => '',
		};
	}

	/**
	 * @return array<string, array{term:string, definition:string}>
	 */
	public function all(): array
	{
		$out = [];
		foreach (self::TERMS as $term) {
			$out[$term] = [
				'term' => $this->label($term),
				'definition' => $this->define($term),
			];
		}
		return $out;
	}
}
