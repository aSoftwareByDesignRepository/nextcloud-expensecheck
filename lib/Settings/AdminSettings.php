<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Settings;

use OCA\ExpenseCheck\Support\AssetRegistrar;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\Settings\ISettings;
use OCP\Util;

/** NC Settings → Administration → ExpenseCheck — links into the app's settings surface. */
class AdminSettings implements ISettings
{
	public function __construct(private readonly IL10N $l10n)
	{
	}

	public function getForm(): TemplateResponse
	{
		AssetRegistrar::registerStyles();
		Util::addScript('expensecheck', 'app');
		return new TemplateResponse('expensecheck', 'admin-settings', ['l' => $this->l10n]);
	}

	public function getSection(): string
	{
		return 'expensecheck';
	}

	public function getPriority(): int
	{
		return 50;
	}
}
