<?php

declare(strict_types=1);

/**
 * ExpenseCheck — access denied surface (parity with sibling Check apps).
 * Rendered by AppAccessMiddleware for page requests; API requests get
 * the JSON envelope instead.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$l = \OCP\Util::getL10N('expensecheck');
$message = (string)($_['message'] ?? $l->t('You do not have access to ExpenseCheck.'));
$hint = (string)($_['hint'] ?? '');
$homeUrl = (string)($_['homeUrl'] ?? '/');
\OCP\Util::addStyle('expensecheck', 'app');
?>
<div id="app-content" class="exp-app exp-app--access-denied">
	<main id="exp-denied-main" class="exp-shell exp-shell--minimal" tabindex="-1" aria-labelledby="exp-denied-title">
		<section class="exp-card exp-access-denied" role="alert">
			<h1 id="exp-denied-title"><?php p($l->t('Access denied')); ?></h1>
			<p class="exp-access-denied__message"><?php p($message); ?></p>
			<?php if ($hint !== ''): ?>
				<p class="exp-access-denied__hint"><?php p($hint); ?></p>
			<?php endif; ?>
			<p class="exp-access-denied__actions">
				<a class="exp-btn exp-btn--primary" href="<?php p($homeUrl); ?>"><?php p($l->t('Back to Nextcloud')); ?></a>
			</p>
		</section>
	</main>
</div>
