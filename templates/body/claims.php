<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="claims" aria-labelledby="exp-claims-h">
	<h2 id="exp-claims-h" class="exp-sr-only"><?php p($l->t('My claims')); ?></h2>
	<div class="exp-toolbar">
		<a class="exp-btn exp-btn--primary" href="<?php p($_['urls']['newClaim'] ?? '#'); ?>"><?php p($l->t('New claim')); ?></a>
		<select class="exp-select" data-exp-filter="status" aria-label="<?php p($l->t('Filter by status')); ?>">
			<option value=""><?php p($l->t('All statuses')); ?></option>
			<option value="draft"><?php p($l->t('Draft')); ?></option>
			<option value="submitted"><?php p($l->t('Submitted')); ?></option>
			<option value="changes_requested"><?php p($l->t('Changes requested')); ?></option>
			<option value="approved"><?php p($l->t('Approved')); ?></option>
			<option value="rejected"><?php p($l->t('Rejected')); ?></option>
			<option value="settled"><?php p($l->t('Settled')); ?></option>
		</select>
	</div>
	<div id="exp-claims-list" class="exp-card-list" role="list" aria-live="polite"></div>
	<p class="exp-empty" id="exp-claims-empty" hidden><?php p($l->t('No claims yet — create your first one.')); ?></p>
</section>
