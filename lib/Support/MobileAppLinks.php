<?php

declare(strict_types=1);

/**
 * Canonical store / product links for the in-app "Get the App" page.
 *
 * ExpenseCheck Mobile exists; the Play listing is not public yet.
 * PLAY_LISTED stays false so the page never presents a live store badge.
 * Product/privacy pages are not published on the website yet — the paths
 * stay empty and the page renders no links for them until they exist.
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

namespace OCA\ExpenseCheck\Support;

final class MobileAppLinks
{
	public const PLAY_STORE_PACKAGE_ID = 'de.softwarebydesign.expensecheck';
	public const PLAY_STORE_URL = 'https://play.google.com/store/apps/details?id=de.softwarebydesign.expensecheck';
	public const PRODUCT_PAGE_PATH = '';
	public const PRODUCT_PAGE_PATH_DE = '';
	public const PRIVACY_PAGE_PATH = '';
	public const PRIVACY_PAGE_PATH_DE = '';
	public const PLAY_LISTED = false;

	public function playStoreUrl(): string
	{
		return self::PLAY_STORE_URL;
	}

	public function playStorePackageId(): string
	{
		return self::PLAY_STORE_PACKAGE_ID;
	}

	public function playListed(): bool
	{
		return self::PLAY_LISTED;
	}

	public function productPageUrl(string $languageCode): string
	{
		$path = $this->isGermanLocale($languageCode)
			? self::PRODUCT_PAGE_PATH_DE
			: self::PRODUCT_PAGE_PATH;
		return $path === '' ? '' : SupportUsLinks::SITE_ORIGIN . $path;
	}

	public function privacyPageUrl(string $languageCode): string
	{
		$path = $this->isGermanLocale($languageCode)
			? self::PRIVACY_PAGE_PATH_DE
			: self::PRIVACY_PAGE_PATH;
		return $path === '' ? '' : SupportUsLinks::SITE_ORIGIN . $path;
	}

	public function isGermanLocale(string $languageCode): bool
	{
		$lang = strtolower(str_replace('_', '-', trim($languageCode)));
		return $lang === 'de' || str_starts_with($lang, 'de-');
	}
}
