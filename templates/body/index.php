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
		<div class="exp-card exp-card--tint" id="exp-onboarding" role="group" aria-labelledby="exp-ob-h">
			<div class="exp-toolbar exp-toolbar--between">
				<h3 class="exp-section-h" id="exp-ob-h" style="margin:0"><?php p((string)$ob['role']); ?></h3>
				<button type="button" class="exp-btn exp-btn--secondary" id="exp-ob-dismiss"><?php p($l->t('Dismiss')); ?></button>
			</div>
			<ol class="exp-queue" style="counter-reset: none; padding-left: 0; list-style-position: inside;">
				<?php foreach ($ob['steps'] as $step): ?>
					<li style="padding: var(--exp-space-2, 8px) 0;"><?php p((string)$step); ?></li>
				<?php endforeach; ?>
			</ol>
		</div>
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

	<h3 class="exp-section-h" id="exp-queue-h"><?php p($l->t('Needs your attention')); ?></h3>
	<?php if ($queue === []): ?>
		<p class="exp-empty"><?php p($l->t('All caught up — nothing needs your attention right now.')); ?></p>
	<?php else: ?>
		<ul class="exp-queue" aria-labelledby="exp-queue-h">
			<?php foreach ($queue as $q): ?>
				<li>
					<a class="exp-queue__item" href="<?php p((string)$q['href']); ?>">
						<span class="exp-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render((string)$q['icon'])); ?></span>
						<span><?php p((string)$q['label']); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<div class="exp-toolbar exp-toolbar--between">
		<h3 class="exp-section-h" id="exp-recent-h"><?php p($l->t('Recent claims')); ?></h3>
		<a class="exp-btn exp-btn--primary" href="<?php p($_['urls']['newClaim'] ?? '#'); ?>"><?php p($l->t('New claim')); ?></a>
	</div>
	<?php if ($recent === []): ?>
		<p class="exp-empty"><?php p($l->t('No claims yet — create your first one.')); ?></p>
	<?php else: ?>
		<ul class="exp-queue" aria-labelledby="exp-recent-h">
			<?php foreach ($recent as $r): ?>
				<li>
					<a class="exp-queue__item" href="<?php p((string)$r['href']); ?>">
						<span class="exp-queue__main">
							<strong><?php p((string)$r['no']); ?></strong>
							<span><?php p((string)$r['title']); ?></span>
						</span>
						<span class="exp-queue__meta"><?php p($statusLabels[$r['status']] ?? (string)$r['status']); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
