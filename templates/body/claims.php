<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="claims" aria-labelledby="exp-claims-h">
	<h2 id="exp-claims-h" class="exp-sr-only"><?php p($l->t('My claims')); ?></h2>
	<div class="exp-toolbar">
		<a class="exp-btn exp-btn--primary" href="<?php p($_['urls']['newClaim'] ?? '#'); ?>"><?php p($l->t('New claim')); ?></a>
		<div class="exp-filter-bar" data-exp-filter="status" role="radiogroup" aria-label="<?php p($l->t('Filter by status')); ?>">
			<button type="button" class="exp-filter" data-value="" aria-pressed="true"><?php p($l->t('All statuses')); ?></button>
			<button type="button" class="exp-filter" data-value="draft" aria-pressed="false"><?php p($l->t('Draft')); ?></button>
			<button type="button" class="exp-filter" data-value="submitted" aria-pressed="false"><?php p($l->t('Submitted')); ?></button>
			<button type="button" class="exp-filter" data-value="changes_requested" aria-pressed="false"><?php p($l->t('Changes requested')); ?></button>
			<button type="button" class="exp-filter" data-value="approved" aria-pressed="false"><?php p($l->t('Approved')); ?></button>
			<button type="button" class="exp-filter" data-value="rejected" aria-pressed="false"><?php p($l->t('Rejected')); ?></button>
			<button type="button" class="exp-filter" data-value="settled" aria-pressed="false"><?php p($l->t('Settled')); ?></button>
		</div>
		<span class="exp-list-head__count" id="exp-claims-count" hidden></span>
	</div>
	<div id="exp-claims-list" class="exp-card-list" role="list" aria-live="polite"></div>
	<p class="exp-empty" id="exp-claims-empty" hidden><?php p($l->t('No claims yet — create your first one.')); ?></p>
</section>
