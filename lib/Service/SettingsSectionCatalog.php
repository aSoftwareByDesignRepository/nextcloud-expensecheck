<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCP\IL10N;

/**
 * Settings-section SSoT (check-family settings-pages standard): every
 * `/settings/{section}` page that renders real content. The routes enum,
 * the chip bar and the template dispatcher all read from this list —
 * a section exists only when it has a body (no dead-end nav entries).
 */
final class SettingsSectionCatalog
{
	public const SLUGS = ['general', 'access', 'transfer', 'support'];

	/**
	 * @return array<string,string> slug => localized label
	 */
	public static function labels(IL10N $l10n): array
	{
		return [
			'general' => $l10n->t('General'),
			'access' => $l10n->t('Access'),
			'transfer' => $l10n->t('Transfer'),
			'support' => $l10n->t('Support & us'),
		];
	}
}
