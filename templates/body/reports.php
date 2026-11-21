<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="reports" aria-labelledby="exp-rep-h">
	<h2 id="exp-rep-h"><?php p($l->t('Reports')); ?> <span class="exp-list-head__count" id="exp-rep-month-label" hidden></span></h2>
	<div class="exp-toolbar" role="group" aria-label="<?php p($l->t('Month')); ?>">
		<button type="button" id="exp-rep-prev" class="exp-btn exp-btn--secondary" aria-label="<?php p($l->t('Previous month')); ?>">&larr;</button>
		<button type="button" id="exp-rep-today" class="exp-btn exp-btn--secondary"><?php p($l->t('This month')); ?></button>
		<button type="button" id="exp-rep-next" class="exp-btn exp-btn--secondary" aria-label="<?php p($l->t('Next month')); ?>">&rarr;</button>
	</div>
	<div id="exp-reports-body" aria-live="polite"></div>
</section>
