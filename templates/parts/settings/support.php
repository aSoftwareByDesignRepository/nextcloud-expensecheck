<?php

declare(strict_types=1);

/**
 * Settings sub-page: Support & us.
 *
 * Informational CTAs only; never gates AGPL use. The SupportUsLinks value
 * object is built by PageController::settingsSection() and passed via $_.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$supportUsLinks = $_['supportUsLinks'] ?? null;
if (!$supportUsLinks instanceof \OCA\ExpenseCheck\Support\SupportUsLinks) {
	return;
}
$supportUsLanguageCode = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
$supportUsCssPrefix = 'exp';
$supportUsBtnPrimaryClass = 'exp-btn exp-btn--primary';
$supportUsBtnSecondaryClass = 'exp-btn exp-btn--secondary';

include dirname(__DIR__) . '/support-us-section.php';
