<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="admin-rates" aria-labelledby="exp-rates-h">
	<h2 id="exp-rates-h"><?php p($l->t('Rates')); ?></h2>
	<div id="exp-rates-list"></div>
	<section class="exp-panel" aria-labelledby="exp-rate-form-h">
	<h3 class="exp-panel__title" id="exp-rate-form-h"><?php p($l->t('Add rate')); ?></h3>
	<form id="exp-rate-form" class="exp-form">
		<div class="exp-field">
			<label class="exp-label" for="exp-rate-type"><?php p($l->t('Rate type')); ?></label>
			<select class="exp-select" id="exp-rate-type" name="rate_type"><option value="mileage"><?php p($l->t('Mileage')); ?></option><option value="per_diem"><?php p($l->t('Per diem')); ?></option><option value="flat"><?php p($l->t('Flat rate')); ?></option></select>
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-rate-code"><?php p($l->t('Code')); ?></label>
			<input class="exp-input" id="exp-rate-code" name="code" required maxlength="64">
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-rate-name"><?php p($l->t('Name')); ?></label>
			<input class="exp-input" id="exp-rate-name" name="name" required maxlength="191">
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-rate-rate"><?php p($l->t('Rate')); ?></label>
			<input class="exp-input" id="exp-rate-rate" name="rate" placeholder="0.00" required inputmode="decimal">
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-rate-currency"><?php p($l->t('Currency')); ?></label>
			<input class="exp-input" id="exp-rate-currency" name="currency" value="EUR" maxlength="3" required>
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-rate-from"><?php p($l->t('Effective from')); ?></label>
			<input class="exp-input" id="exp-rate-from" name="effective_from" type="date" required>
		</div>
		<button class="exp-btn exp-btn--primary" type="submit"><?php p($l->t('Save')); ?></button>
	</form>
	</section>
</section>
