<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="claim-form" aria-labelledby="exp-new-h">
	<h2 id="exp-new-h"><?php p($l->t('New expense claim')); ?></h2>
	<form id="exp-claim-form" class="exp-form" novalidate>
		<div class="exp-field">
			<label class="exp-label" for="exp-f-title"><?php p($l->t('Title')); ?></label>
			<input class="exp-input" id="exp-f-title" name="title" required maxlength="191" autocomplete="off">
			<p class="exp-field-error" data-field-error="title" hidden></p>
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-f-purpose"><?php p($l->t('Purpose')); ?></label>
			<textarea class="exp-input" id="exp-f-purpose" name="purpose" rows="2" maxlength="2000"></textarea>
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-f-currency"><?php p($l->t('Currency')); ?></label>
			<input class="exp-input" id="exp-f-currency" name="currency" value="EUR" required maxlength="3" pattern="[A-Z]{3}">
			<p class="exp-field-error" data-field-error="currency" hidden></p>
		</div>
		<fieldset class="exp-fieldset">
			<legend><?php p($l->t('Lines')); ?></legend>
			<div id="exp-lines" class="exp-lines"></div>
			<button type="button" class="exp-btn exp-btn--secondary" id="exp-add-line"><?php p($l->t('Add line')); ?></button>
		</fieldset>
		<div class="exp-actions">
			<button type="submit" class="exp-btn exp-btn--primary"><?php p($l->t('Create claim')); ?></button>
		</div>
	</form>
</section>
