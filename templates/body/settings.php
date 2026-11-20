<?php
/** @var \OCP\IL10N $l */
$expSection = (string)($_['settingsSection'] ?? 'general');
$expSectionLabels = (array)($_['settingsSectionLabels'] ?? []);
$expSectionUrls = (array)($_['settingsSectionUrls'] ?? []);
?>
<section class="exp-surface" data-exp-view="settings" aria-labelledby="exp-set-h">
	<h2 id="exp-set-h"><?php p($l->t('Settings')); ?></h2>
	<?php if ($expSectionLabels !== []): ?>
		<nav class="exp-settings-nav" aria-label="<?php p($l->t('Settings pages')); ?>">
			<?php foreach ($expSectionLabels as $slug => $label):
				$slug = (string)$slug;
				$href = (string)($expSectionUrls[$slug] ?? '');
				if ($href === '') {
					continue;
				}
				$active = $expSection === $slug || ($slug === 'general' && $expSection === '');
				?>
				<a class="exp-settings-nav__link<?php p($active ? ' is-active' : ''); ?>"
					href="<?php p($href); ?>"
					<?php if ($active): ?>aria-current="page"<?php endif; ?>>
					<?php p((string)$label); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
	<?php if ($expSection === 'transfer'): ?>
		<?php include __DIR__ . '/../parts/settings/transfer.php'; ?>
	<?php else: ?>
		<div id="exp-settings-body" aria-live="polite"></div>
	<?php endif; ?>
</section>
