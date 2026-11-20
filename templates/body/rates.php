<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="admin-rates" aria-labelledby="exp-rates-h">
	<h2 id="exp-rates-h"><?php p($l->t('Rates')); ?></h2>
	<div id="exp-rates-list" class="exp-card-list"></div>
	<form id="exp-rate-form" class="exp-form">
		<select class="exp-select" name="rate_type"><option value="mileage"><?php p($l->t('Mileage')); ?></option><option value="per_diem"><?php p($l->t('Per diem')); ?></option><option value="flat"><?php p($l->t('Flat rate')); ?></option></select>
		<input class="exp-input" name="code" placeholder="<?php p($l->t('Code')); ?>" required maxlength="64">
		<input class="exp-input" name="name" placeholder="<?php p($l->t('Name')); ?>" required maxlength="191">
		<input class="exp-input" name="rate" placeholder="0.00" required inputmode="decimal">
		<input class="exp-input" name="currency" value="EUR" maxlength="3" required>
		<input class="exp-input" name="effective_from" type="date" required>
		<button class="exp-btn exp-btn--primary" type="submit"><?php p($l->t('Save')); ?></button>
	</form>
</section>
