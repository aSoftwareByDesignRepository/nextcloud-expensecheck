<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection
{
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return 'expensecheck';
	}

	public function getName(): string
	{
		return $this->l10n->t('ExpenseCheck');
	}

	public function getPriority(): int
	{
		return 50;
	}

	public function getIcon(): string
	{
		return $this->urlGenerator->imagePath('expensecheck', 'app-dark.svg');
	}
}
