<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="all" aria-labelledby="exp-all-h">
	<h2 id="exp-all-h"><?php p($l->t('All claims')); ?> <span class="exp-list-head__count" id="exp-all-count" hidden></span></h2>
	<div class="exp-filter-bar" data-exp-filter="status" role="radiogroup" aria-label="<?php p($l->t('Filter by status')); ?>">
		<button type="button" class="exp-filter" data-value="" aria-pressed="true"><?php p($l->t('All statuses')); ?></button>
		<button type="button" class="exp-filter" data-value="draft" aria-pressed="false"><?php p($l->t('Draft')); ?></button>
		<button type="button" class="exp-filter" data-value="submitted" aria-pressed="false"><?php p($l->t('Submitted')); ?></button>
		<button type="button" class="exp-filter" data-value="changes_requested" aria-pressed="false"><?php p($l->t('Changes requested')); ?></button>
		<button type="button" class="exp-filter" data-value="approved" aria-pressed="false"><?php p($l->t('Approved')); ?></button>
		<button type="button" class="exp-filter" data-value="rejected" aria-pressed="false"><?php p($l->t('Rejected')); ?></button>
		<button type="button" class="exp-filter" data-value="settled" aria-pressed="false"><?php p($l->t('Settled')); ?></button>
	</div>
	<div id="exp-all-list" aria-live="polite"></div>
</section>
