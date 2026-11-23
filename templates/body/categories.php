<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="admin-categories" aria-labelledby="exp-cat-h">
	<h2 id="exp-cat-h"><?php p($l->t('Categories')); ?></h2>
	<div id="exp-cat-list" class="exp-card-list"></div>
	<form id="exp-cat-form" class="exp-form">
		<input type="hidden" id="exp-cat-id" name="id">
		<div class="exp-field">
			<label class="exp-label" for="exp-cat-code"><?php p($l->t('Code')); ?></label>
			<input class="exp-input" id="exp-cat-code" name="code" required maxlength="32">
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-cat-name"><?php p($l->t('Name')); ?></label>
			<input class="exp-input" id="exp-cat-name" name="name" required maxlength="191">
		</div>
		<fieldset class="exp-fieldset">
			<legend class="exp-label"><?php p($l->t('Line types')); ?></legend>
			<label class="exp-checkbox"><input type="checkbox" id="exp-cat-al-receipt" checked> <?php p($l->t('Receipt')); ?></label>
			<label class="exp-checkbox"><input type="checkbox" id="exp-cat-al-mileage" checked> <?php p($l->t('Mileage')); ?></label>
			<label class="exp-checkbox"><input type="checkbox" id="exp-cat-al-per-diem" checked> <?php p($l->t('Per diem')); ?></label>
			<label class="exp-checkbox"><input type="checkbox" id="exp-cat-al-flat" checked> <?php p($l->t('Flat rate')); ?></label>
		</fieldset>
		<label class="exp-checkbox"><input type="checkbox" id="exp-cat-receipt-required"> <?php p($l->t('Receipt required')); ?></label>
		<label class="exp-checkbox"><input type="checkbox" id="exp-cat-requires-attendees"> <?php p($l->t('Requires attendees')); ?></label>
		<div class="exp-field">
			<label class="exp-label" for="exp-cat-just-threshold"><?php p($l->t('Justification threshold')); ?></label>
			<input class="exp-input" id="exp-cat-just-threshold" name="justification_threshold" inputmode="decimal" placeholder="0.00">
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-cat-just-minlen"><?php p($l->t('Minimum justification length')); ?></label>
			<input class="exp-input" id="exp-cat-just-minlen" name="justification_min_length" type="number" min="0" max="2000" step="1" inputmode="numeric">
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-cat-max-amount"><?php p($l->t('Max amount')); ?></label>
			<input class="exp-input" id="exp-cat-max-amount" name="max_amount" inputmode="decimal" placeholder="0.00">
		</div>
		<label class="exp-checkbox"><input type="checkbox" id="exp-cat-max-hard"> <?php p($l->t('Hard limit')); ?></label>
		<div class="exp-field">
			<label class="exp-label" for="exp-cat-default-amount"><?php p($l->t('Default amount')); ?></label>
			<input class="exp-input" id="exp-cat-default-amount" name="default_amount" inputmode="decimal" placeholder="0.00">
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-cat-sort"><?php p($l->t('Sort order')); ?></label>
			<input class="exp-input" id="exp-cat-sort" name="sort" type="number" min="0" step="1" inputmode="numeric">
		</div>
		<label class="exp-checkbox"><input type="checkbox" id="exp-cat-active" checked> <?php p($l->t('Active')); ?></label>
		<div class="exp-detail-actions">
			<button class="exp-btn exp-btn--primary" type="submit"><?php p($l->t('Save')); ?></button>
			<button class="exp-btn" type="button" id="exp-cat-cancel" hidden><?php p($l->t('Cancel')); ?></button>
		</div>
	</form>
</section>
