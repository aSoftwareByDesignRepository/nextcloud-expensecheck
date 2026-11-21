<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="admin-cost-centers" aria-labelledby="exp-cc-h">
	<h2 id="exp-cc-h"><?php p($l->t('Cost centers')); ?></h2>
	<div id="exp-cc-list" class="exp-card-list"></div>
	<form id="exp-cc-form" class="exp-form">
		<div class="exp-field">
			<label class="exp-label" for="exp-cc-code"><?php p($l->t('Code')); ?></label>
			<input class="exp-input" id="exp-cc-code" name="code" required maxlength="32">
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-cc-name"><?php p($l->t('Name')); ?></label>
			<input class="exp-input" id="exp-cc-name" name="name" required maxlength="191">
		</div>
		<button class="exp-btn exp-btn--primary" type="submit"><?php p($l->t('Save')); ?></button>
	</form>
</section>
