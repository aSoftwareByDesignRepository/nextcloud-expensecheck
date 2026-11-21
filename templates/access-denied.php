<?php

declare(strict_types=1);

/**
 * Rendered when AppAccessMiddleware denies the current user (page
 * requests only — API denials keep the JSON envelope). Parity surface
 * with sibling Check apps.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

\OCA\ExpenseCheck\Support\AssetRegistrar::registerStyles();

use OCA\ExpenseCheck\Service\IconCatalog;

$message = (string)($_['message'] ?? $l->t('You are not allowed to use ExpenseCheck right now.'));
$hint = (string)($_['hint'] ?? $l->t('If you believe this is a mistake, contact your ExpenseCheck administrator.'));
$homeUrl = (string)($_['homeUrl'] ?? '/');
?>
<div id="app-content" class="exp-app exp-app--access-denied">
	<a class="exp-skip-link" href="#exp-denied-main"><?php p($l->t('Skip to main content')); ?></a>
	<div class="exp-access-denied">
		<section id="exp-denied-main" class="exp-card" role="alert" aria-labelledby="exp-denied-title" tabindex="-1">
			<div class="exp-page-header__icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('lock', 'exp-page-header__icon-svg')); ?>
			</div>
			<h1 id="exp-denied-title"><?php p($l->t('ExpenseCheck access denied')); ?></h1>
			<p><?php p($message); ?></p>
			<?php if ($hint !== ''): ?>
				<p class="exp-callout__hint"><?php p($hint); ?></p>
			<?php endif; ?>
			<p>
				<a class="exp-btn exp-btn--primary" href="<?php p($homeUrl); ?>"><?php p($l->t('Back to Nextcloud')); ?></a>
			</p>
		</section>
	</div>
</div>
