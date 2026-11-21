<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="admin-categories" aria-labelledby="exp-cat-h">
	<h2 id="exp-cat-h"><?php p($l->t('Categories')); ?></h2>
	<div id="exp-cat-list" class="exp-card-list"></div>
	<form id="exp-cat-form" class="exp-form">
		<div class="exp-field">
			<label class="exp-label" for="exp-cat-code"><?php p($l->t('Code')); ?></label>
			<input class="exp-input" id="exp-cat-code" name="code" required maxlength="32">
		</div>
		<div class="exp-field">
			<label class="exp-label" for="exp-cat-name"><?php p($l->t('Name')); ?></label>
			<input class="exp-input" id="exp-cat-name" name="name" required maxlength="191">
		</div>
		<button class="exp-btn exp-btn--primary" type="submit"><?php p($l->t('Save')); ?></button>
	</form>
</section>
