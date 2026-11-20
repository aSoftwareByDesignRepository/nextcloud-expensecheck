<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="settlements" aria-labelledby="exp-settle-h">
	<h2 id="exp-settle-h"><?php p($l->t('Settlement queue')); ?></h2>
	<div id="exp-settle-list" class="exp-card-list" role="list" aria-live="polite"></div>
	<form id="exp-settle-form" class="exp-actions" hidden>
		<label class="exp-label" for="exp-paid-ref"><?php p($l->t('Payment reference')); ?></label>
		<input class="exp-input" id="exp-paid-ref" name="paid_ref" required maxlength="191">
		<button class="exp-btn exp-btn--primary" type="submit"><?php p($l->t('Settle selected')); ?></button>
	</form>
	<h3><?php p($l->t('Batches')); ?></h3>
	<div id="exp-batches-list" class="exp-card-list" role="list"></div>
</section>
