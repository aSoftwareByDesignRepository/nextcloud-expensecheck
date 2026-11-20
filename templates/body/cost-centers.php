<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="admin-cost-centers" aria-labelledby="exp-cc-h">
	<h2 id="exp-cc-h"><?php p($l->t('Cost centers')); ?></h2>
	<div id="exp-cc-list" class="exp-card-list"></div>
	<form id="exp-cc-form" class="exp-form">
		<input class="exp-input" name="code" placeholder="<?php p($l->t('Code')); ?>" required maxlength="32">
		<input class="exp-input" name="name" placeholder="<?php p($l->t('Name')); ?>" required maxlength="191">
		<button class="exp-btn exp-btn--primary" type="submit"><?php p($l->t('Save')); ?></button>
	</form>
</section>
