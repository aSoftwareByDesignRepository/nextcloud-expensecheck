<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
use OCA\ExpenseCheck\Service\IconCatalog;

$dash = is_array($_['dash'] ?? null) ? $_['dash'] : [];
$queue = $dash['queue'] ?? [];
$recent = $dash['recent'] ?? [];
$statusLabels = [
	'draft' => $l->t('Draft'), 'submitted' => $l->t('Submitted'),
	'changes_requested' => $l->t('Changes requested'), 'approved' => $l->t('Approved'),
	'rejected' => $l->t('Rejected'), 'settled' => $l->t('Settled'),
];
?>
<section class="exp-surface" data-exp-view="index" aria-labelledby="exp-index-h">
	<h2 id="exp-index-h" class="exp-sr-only"><?php p($l->t('Overview')); ?></h2>

	<?php $ob = $_['onboarding'] ?? null; ?>
	<?php if ($ob !== null): ?>
		<section class="exp-panel" id="exp-onboarding" aria-labelledby="exp-ob-h">
			<div class="exp-list-head">
				<h3 class="exp-list-head__title" id="exp-ob-h"><?php p((string)$ob['role']); ?></h3>
				<div class="exp-list-head__actions">
					<button type="button" class="exp-btn exp-btn--ghost exp-btn--sm" id="exp-ob-dismiss"><?php p($l->t('Dismiss')); ?></button>
				</div>
			</div>
			<ol class="exp-steps">
				<?php foreach ($ob['steps'] as $step): ?>
					<li><?php p((string)$step); ?></li>
				<?php endforeach; ?>
			</ol>
		</section>
	<?php endif; ?>

	<div class="exp-stat-strip" role="list" aria-label="<?php p($l->t('Your numbers')); ?>">
		<a class="exp-stat-tile exp-stat-tile--primary" role="listitem"
			href="<?php p($_['urls']['inbox'] ?? '#'); ?>">
			<span class="exp-stat-tile__label"><?php p($l->t('Awaiting your approval')); ?></span>
			<span class="exp-stat-tile__value"><?php p((string)($dash['pendingApprovals'] ?? 0)); ?></span>
		</a>
		<a class="exp-stat-tile" role="listitem" href="<?php p($_['urls']['claims'] ?? '#'); ?>">
			<span class="exp-stat-tile__label"><?php p($l->t('Open claims')); ?></span>
			<span class="exp-stat-tile__value"><?php p((string)($dash['openClaims'] ?? 0)); ?></span>
		</a>
		<a class="exp-stat-tile" role="listitem" href="<?php p($_['urls']['claims'] ?? '#'); ?>">
			<span class="exp-stat-tile__label"><?php p($l->t('Open total')); ?></span>
			<span class="exp-stat-tile__value"><?php p((string)($dash['openTotal'] ?? '0.00 EUR')); ?></span>
		</a>
	</div>

	<div class="exp-card-grid">
		<section class="exp-panel" aria-labelledby="exp-queue-h">
			<div class="exp-list-head">
				<h3 class="exp-list-head__title" id="exp-queue-h"><?php p($l->t('Needs your attention')); ?></h3>
				<div class="exp-list-head__actions">
					<a class="exp-btn exp-btn--ghost exp-btn--sm" href="<?php p($_['urls']['inbox'] ?? '#'); ?>"><?php p($l->t('View all')); ?></a>
				</div>
			</div>
			<?php if ($queue === []): ?>
				<p class="exp-empty"><?php p($l->t('All caught up — nothing needs your attention right now.')); ?></p>
			<?php else: ?>
				<ul class="exp-deadline-list">
					<?php foreach ($queue as $q): ?>
						<li class="exp-deadline-list__item">
							<a class="exp-deadline-list__link" href="<?php p((string)$q['href']); ?>">
								<span class="exp-deadline-list__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render((string)$q['icon'])); ?></span>
								<span class="exp-deadline-list__title"><?php p((string)$q['label']); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<section class="exp-panel" aria-labelledby="exp-recent-h">
			<div class="exp-list-head">
				<h3 class="exp-list-head__title" id="exp-recent-h"><?php p($l->t('Recent claims')); ?></h3>
				<div class="exp-list-head__actions">
					<a class="exp-btn exp-btn--primary exp-btn--sm" href="<?php p($_['urls']['newClaim'] ?? '#'); ?>"><?php p($l->t('New claim')); ?></a>
				</div>
			</div>
			<?php if ($recent === []): ?>
				<p class="exp-empty"><?php p($l->t('No claims yet — create your first one.')); ?></p>
			<?php else: ?>
				<ul class="exp-deadline-list">
					<?php foreach ($recent as $r): ?>
						<li class="exp-deadline-list__item">
							<a class="exp-deadline-list__link" href="<?php p((string)$r['href']); ?>">
								<span class="exp-deadline-list__title"><?php p((string)$r['title']); ?></span>
								<span class="exp-deadline-list__meta"><?php p((string)$r['no']); ?></span>
								<span class="exp-badge exp-badge--neutral"><?php p($statusLabels[$r['status']] ?? (string)$r['status']); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	</div>
</section>
