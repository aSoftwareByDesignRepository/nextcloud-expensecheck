<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="settlements" aria-labelledby="exp-settle-h">
	<h2 id="exp-settle-h"><?php p($l->t('Settlement queue')); ?> <span class="exp-list-head__count" id="exp-settle-count" hidden></span></h2>
	<div id="exp-settle-list" aria-live="polite"></div>
	<form id="exp-settle-form" class="exp-form" hidden>
		<div class="exp-field-row">
			<div class="exp-field">
				<label class="exp-label" for="exp-paid-ref"><?php p($l->t('Payment reference')); ?></label>
				<input class="exp-input" id="exp-paid-ref" name="paid_ref" required maxlength="191">
			</div>
			<div class="exp-field">
				<label class="exp-label" for="exp-settle-format"><?php p($l->t('Export format')); ?></label>
				<select class="exp-select" id="exp-settle-format" name="format">
					<option value="csv"><?php p($l->t('CSV')); ?></option>
					<option value="ods"><?php p($l->t('ODS (LibreOffice)')); ?></option>
				</select>
			</div>
		</div>
		<div class="exp-form-actions">
			<button class="exp-btn exp-btn--primary" type="submit"><?php p($l->t('Settle selected')); ?></button>
		</div>
	</form>
	<h3 class="exp-section-h" id="exp-batches-h"><?php p($l->t('Batches')); ?></h3>
	<div id="exp-batches-list" aria-live="polite" aria-labelledby="exp-batches-h"></div>
</section>
