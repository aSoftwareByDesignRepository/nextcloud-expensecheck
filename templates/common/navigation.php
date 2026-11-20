<?php
/**
 * ExpenseCheck sidebar — Check-family chrome (family parity).
 * Shared component: apps/_shared/check-shell (synced — do not edit in-app).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 *
 * Params:
 *   navGroups  list of {label: string, items: list<{id,label,hint?,href,icon,active}>}
 *   settingsNav list of {id,label,hint,href,icon,active} — rendered as its own
 *               group at the end when non-empty
 *   roleLabel, brandIcon (IconCatalog name), brandTitle, brandSubtitle,
 *   footerPartial (optional absolute template path, e.g. app-feedback nav footer)
 */

use OCA\ExpenseCheck\Service\IconCatalog;

$navGroups = $_['navGroups'] ?? [];
$settingsItems = $_['settingsNav'] ?? [];
$roleLabel = (string)($_['roleLabel'] ?? $l->t('Viewer'));
$brandIcon = (string)($_['brandIcon'] ?? 'shield');
$appTitle = (string)($_['brandTitle'] ?? $l->t('ExpenseCheck'));
$appSubtitle = (string)($_['brandSubtitle'] ?? '');

$renderItems = static function (array $items) use ($l): void {
	?>
	<ul class="exp-nav__list">
		<?php foreach ($items as $item): ?>
			<li class="exp-nav__item<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>">
				<a class="exp-nav__link<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>"
					href="<?php p($item['href']); ?>"
					<?php if (!empty($item['active'])): ?>aria-current="page"<?php endif; ?>>
					<span class="exp-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render((string)($item['icon'] ?? 'list'))); ?></span>
					<span class="exp-nav__label">
						<span class="exp-nav__name"><?php p($item['label']); ?></span>
						<?php if (!empty($item['hint'])): ?>
							<span class="exp-nav__hint"><?php p((string)$item['hint']); ?></span>
						<?php endif; ?>
					</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
};
?>
<a class="skip-link exp-skip-link--nav" href="#app-navigation"><?php p($l->t('Skip to navigation')); ?></a>
<nav id="app-navigation" class="exp-nav" role="navigation" aria-label="<?php p($appTitle); ?>">
	<div class="exp-brand">
		<span class="exp-brand__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render($brandIcon, 'exp-brand__icon-svg')); ?>
		</span>
		<div class="exp-brand__text">
			<h2 class="exp-brand__title"><?php p($appTitle); ?></h2>
			<?php if ($appSubtitle !== ''): ?>
				<p class="exp-brand__subtitle"><?php p($appSubtitle); ?></p>
			<?php endif; ?>
			<span class="exp-badge exp-badge--neutral"><?php p($roleLabel); ?></span>
		</div>
	</div>
	<div class="exp-nav__body">
		<?php foreach ($navGroups as $i => $group): ?>
			<?php if ($i > 0): ?><hr class="exp-nav__divider" role="separator" /><?php endif; ?>
			<section class="exp-nav__group" aria-labelledby="exp-nav-group-<?php p((string)$i); ?>">
				<h3 class="exp-nav__group-label" id="exp-nav-group-<?php p((string)$i); ?>"><?php p((string)($group['label'] ?? '')); ?></h3>
				<?php $renderItems($group['items'] ?? []); ?>
			</section>
		<?php endforeach; ?>
		<?php if ($settingsItems !== []): ?>
			<hr class="exp-nav__divider" role="separator" />
			<section class="exp-nav__group" aria-labelledby="exp-nav-settings">
				<h3 class="exp-nav__group-label" id="exp-nav-settings"><?php p($l->t('Settings')); ?></h3>
				<?php $renderItems($settingsItems); ?>
			</section>
		<?php endif; ?>
	</div>
	<?php if (!empty($_['footerPartial'])): ?>
		<?php include $_['footerPartial']; ?>
	<?php endif; ?>
</nav>
