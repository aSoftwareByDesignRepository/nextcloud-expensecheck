<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="receipts" aria-labelledby="exp-receipts-h">
	<h2 id="exp-receipts-h"><?php p($l->t('Receipts inbox')); ?> <span class="exp-list-head__count" id="exp-receipts-count" hidden></span></h2>
	<form id="exp-receipt-upload" class="exp-toolbar" enctype="multipart/form-data">
		<label class="exp-btn exp-btn--primary" for="exp-receipt-file"><?php p($l->t('Upload receipt')); ?></label>
		<input id="exp-receipt-file" name="file" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf,application/xml,text/xml" class="exp-sr-only">
	</form>
	<div id="exp-receipts-list" class="exp-card-list" role="list" aria-live="polite"></div>
	<p class="exp-empty" id="exp-receipts-empty" hidden><?php p($l->t('No unfiled receipts.')); ?></p>
</section>
