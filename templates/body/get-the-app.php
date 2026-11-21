<?php

declare(strict_types=1);

/**
 * ExpenseCheck → Get the App (ExpenseCheck Mobile companion).
 *
 * Same surface siblings ship under /get-the-app — hero, feature tiles,
 * external links. PLAY_LISTED=false while the Play listing is not public,
 * so no live store badge renders. Product/privacy links only render when
 * the website pages exist (MobileAppLinks returns '' until then).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\ExpenseCheck\Service\IconCatalog;
use OCA\ExpenseCheck\Support\MobileAppLinks;

$playListed = MobileAppLinks::PLAY_LISTED;
$playStore = (string)($_['mobilePlayStoreUrl'] ?? MobileAppLinks::PLAY_STORE_URL);
if (!str_starts_with($playStore, 'https://play.google.com/')) {
	$playStore = MobileAppLinks::PLAY_STORE_URL;
}
$productPage = (string)($_['mobileProductPage'] ?? '');
$privacyPage = (string)($_['mobilePrivacyPage'] ?? '');
if ($productPage !== '' && !str_starts_with($productPage, 'https://')) {
	$productPage = '';
}
if ($privacyPage !== '' && !str_starts_with($privacyPage, 'https://')) {
	$privacyPage = '';
}

$features = [
	[
		'icon' => 'inbox',
		'title' => $l->t('Receipts in your pocket'),
		'hint' => $l->t('Snap a receipt with your phone — it lands in the Receipts inbox on this Nextcloud, ready to file to a claim.'),
	],
	[
		'icon' => 'file-check',
		'title' => $l->t('Claims on the go'),
		'hint' => $l->t('Draft and submit expense claims from your phone — the same claims you see in the browser.'),
	],
	[
		'icon' => 'check-circle',
		'title' => $l->t('Approvals anywhere'),
		'hint' => $l->t('Approvers see the same inbox on the phone and decide without waiting to be back at the desk.'),
	],
	[
		'icon' => 'lock',
		'title' => $l->t('Sign in safely'),
		'hint' => $l->t('Uses Nextcloud Login Flow — your main password is never stored in the app.'),
	],
];
?>
<div class="exp-get-app-page exp-page-stack">
	<section class="exp-get-app__hero" aria-labelledby="exp-get-app-intro-title">
		<p class="exp-get-app__eyebrow"><?php p($l->t('Official Android companion')); ?></p>
		<h2 id="exp-get-app-intro-title" class="exp-get-app__title"><?php p($l->t('ExpenseCheck Mobile')); ?></h2>
		<p class="exp-get-app__lead">
			<?php p($l->t('The official Android app connects to this Nextcloud. Capture receipts and handle expenses on the go — your data stays on your server.')); ?>
		</p>
		<div class="exp-get-app__cta">
			<?php if ($playListed): ?>
				<a class="exp-btn exp-btn--primary exp-get-app__play" href="<?php p($playStore); ?>" target="_blank" rel="noopener noreferrer">
					<span class="exp-get-app__play-icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('download')); ?></span>
					<span class="exp-get-app__play-label"><?php p($l->t('Get it on Google Play')); ?></span>
				</a>
			<?php endif; ?>
			<p class="exp-get-app__price-hint">
				<?php if ($playListed): ?>
					<?php p($l->t('On Google Play. Your organisation licenses official mobile seats — the web app stays free.')); ?>
				<?php else: ?>
					<?php p($l->t('Not yet publicly listed on Google Play. Until then the web app covers the feature set. Your IT assigns official mobile seats on the License page.')); ?>
				<?php endif; ?>
			</p>
		</div>
	</section>

	<section class="exp-get-app__features-block" aria-labelledby="exp-get-app-features-title">
		<h2 id="exp-get-app-features-title" class="exp-get-app__section-title"><?php p($l->t('What you can do')); ?></h2>
		<ul class="exp-get-app__features">
			<?php foreach ($features as $feature): ?>
				<li class="exp-get-app__feature">
					<span class="exp-get-app__icon-well exp-get-app__icon-well--feature" aria-hidden="true">
						<?php print_unescaped(IconCatalog::render((string)$feature['icon'])); ?>
					</span>
					<div class="exp-get-app__feature-copy">
						<span class="exp-get-app__feature-title"><?php p((string)$feature['title']); ?></span>
						<span class="exp-get-app__feature-hint"><?php p((string)$feature['hint']); ?></span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<?php
	$actionRows = [];
	if ($productPage !== '') {
		$actionRows[] = ['href' => $productPage, 'label' => $l->t('Product page')];
	}
	if ($privacyPage !== '') {
		$actionRows[] = ['href' => $privacyPage, 'label' => $l->t('Privacy policy for the mobile app')];
	}
	?>
	<?php if ($actionRows !== []): ?>
		<nav class="exp-get-app__actions" aria-label="<?php p($l->t('More information')); ?>">
			<?php foreach ($actionRows as $row): ?>
				<a class="exp-get-app__action" href="<?php p((string)$row['href']); ?>" target="_blank" rel="noopener noreferrer">
					<span class="exp-get-app__action-label"><?php p((string)$row['label']); ?></span>
					<span class="exp-get-app__action-external" aria-hidden="true">↗</span>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
</div>
