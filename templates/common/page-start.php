<?php
/**
 * ExpenseCheck page chrome — design-system Check-family shell.
 * Shared component: apps/_shared/check-shell (synced — do not edit in-app).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 *
 * Expected params:
 *   pageId, pageTitle, pageHelp, pageIcon (IconCatalog name),
 *   roleLabel (scope-strip badge), urls.home (breadcrumb root),
 *   navGroups (list of {label, items}), settingsNav (optional),
 *   clientHints {locale, htmlLang, timezone}, hideScopeStrip (optional)
 */

use OCA\ExpenseCheck\Service\IconCatalog;

$pageId = (string)($_['pageId'] ?? 'home');
$pageTitle = (string)($_['pageTitle'] ?? $l->t('ExpenseCheck'));
$pageHelp = (string)($_['pageHelp'] ?? '');
$pageIcon = (string)($_['pageIcon'] ?? 'home');
$roleLabel = (string)($_['roleLabel'] ?? $l->t('Member'));
$appTitle = (string)($_['brandTitle'] ?? $l->t('ExpenseCheck'));
$urls = $_['urls'] ?? [];
$clientHints = $_['clientHints'] ?? ['locale' => 'en-US', 'htmlLang' => 'en-US', 'timezone' => 'UTC'];
$htmlLang = (string)($clientHints['htmlLang'] ?? 'en-US');
$locale = (string)($clientHints['locale'] ?? $htmlLang);
$timezone = (string)($clientHints['timezone'] ?? 'UTC');
$settingsSection = (string)($_['settingsSection'] ?? '');
$urlsJson = htmlspecialchars(json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$glossary = (array)($_['glossary'] ?? []);
$glossaryJson = htmlspecialchars(json_encode($glossary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$homeUrl = (string)($urls['home'] ?? '#');

include __DIR__ . '/navigation.php';
?>
<div id="app-content" class="exp-app exp-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-locale="<?php p($locale); ?>"
	data-exp-page="<?php p($pageId); ?>"
	data-exp-timezone="<?php p($timezone); ?>"
	data-exp-settings-section="<?php p($settingsSection); ?>"
	data-exp-urls="<?php print_unescaped($urlsJson); ?>"
	data-exp-glossary="<?php print_unescaped($glossaryJson); ?>">
	<a class="exp-skip-link" href="#exp-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="exp-live-region" class="exp-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="exp-alert-region" class="exp-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="exp-shell">
		<!-- div, not header: an unscoped <header> outside sectioning content
		     registers a second banner landmark (duplicate banner with NC
		     #header). -->
		<div class="exp-page-header" aria-labelledby="exp-page-title">
			<div class="exp-page-header__top">
				<?php require __DIR__ . '/nav-toggle.php'; ?>
				<nav class="exp-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
					<ol class="exp-breadcrumb__list">
						<li class="exp-breadcrumb__item">
							<a class="exp-breadcrumb__link" href="<?php p($homeUrl); ?>"><?php p($appTitle); ?></a>
						</li>
						<li class="exp-breadcrumb__item exp-breadcrumb__item--current" aria-current="page">
							<span class="exp-breadcrumb__current"><?php p($pageTitle); ?></span>
						</li>
					</ol>
				</nav>
			</div>
			<div class="exp-page-header__main">
				<div class="exp-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($pageIcon, 'exp-page-header__icon-svg')); ?>
				</div>
				<div class="exp-page-header__text">
					<h1 id="exp-page-title" class="exp-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== ''): ?>
						<p class="exp-page-header__lead"><?php p($pageHelp); ?></p>
					<?php endif; ?>
				</div>
				<div id="exp-page-actions" class="exp-page-header__actions">
					<?php foreach (($_['pageActions'] ?? []) as $action): ?>
						<?php
						$aClass = 'exp-btn ' . (!empty($action['danger']) ? 'exp-btn--danger' : (!empty($action['primary']) ? 'exp-btn--primary' : 'exp-btn--secondary'));
						?>
						<?php if (!empty($action['href'])): ?>
							<a class="<?php p($aClass); ?>" href="<?php p($action['href']); ?>"
								<?php if (!empty($action['id'])): ?>id="<?php p($action['id']); ?>"<?php endif; ?>>
								<?php if (!empty($action['icon'])): ?><?php print_unescaped(IconCatalog::render((string)$action['icon'])); ?><?php endif; ?>
								<?php p($action['label']); ?>
							</a>
						<?php else: ?>
							<button type="button" class="<?php p($aClass); ?>"
								<?php if (!empty($action['id'])): ?>id="<?php p($action['id']); ?>"<?php endif; ?>>
								<?php if (!empty($action['icon'])): ?><?php print_unescaped(IconCatalog::render((string)$action['icon'])); ?><?php endif; ?>
								<?php p($action['label']); ?>
							</button>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</div>
			<?php if (empty($_['hideScopeStrip'])): ?>
			<div class="exp-scope-strip" aria-label="<?php p($l->t('Active session context')); ?>">
				<span class="exp-scope-strip__label"><?php p($l->t('Role')); ?></span>
				<span class="exp-badge exp-badge--neutral exp-scope-strip__badge"><?php p($roleLabel); ?></span>
				<span class="exp-scope-strip__sep" aria-hidden="true">·</span>
				<span class="exp-scope-strip__label"><?php p($l->t('Timezone')); ?></span>
				<span class="exp-scope-strip__value"><?php p($timezone); ?></span>
			</div>
			<?php endif; ?>
		</div>
		<main id="exp-main-content" class="exp-main" tabindex="-1" aria-labelledby="exp-page-title">
