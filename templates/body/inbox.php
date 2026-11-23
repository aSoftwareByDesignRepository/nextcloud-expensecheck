<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="inbox" aria-labelledby="exp-inbox-h">
	<h2 id="exp-inbox-h"><?php p($l->t('Approvals')); ?> <span class="exp-list-head__count" id="exp-inbox-count" hidden></span></h2>
	<div id="exp-inbox-list" aria-live="polite"></div>
	<p class="exp-empty" id="exp-inbox-empty" hidden><?php p($l->t('Nothing awaiting your decision.')); ?></p>
</section>
