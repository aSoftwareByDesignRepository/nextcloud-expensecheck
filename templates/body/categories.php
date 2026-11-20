<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="admin-categories" aria-labelledby="exp-cat-h">
	<h2 id="exp-cat-h"><?php p($l->t('Categories')); ?></h2>
	<div id="exp-cat-list" class="exp-card-list"></div>
	<form id="exp-cat-form" class="exp-form">
		<input class="exp-input" name="code" placeholder="<?php p($l->t('Code')); ?>" required maxlength="32">
		<input class="exp-input" name="name" placeholder="<?php p($l->t('Name')); ?>" required maxlength="191">
		<button class="exp-btn exp-btn--primary" type="submit"><?php p($l->t('Save')); ?></button>
	</form>
</section>
