<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="claim-detail" data-exp-claim-id="<?php p((string)($_['claimId'] ?? '')); ?>" aria-labelledby="exp-detail-h">
	<h2 id="exp-detail-h"><?php p($l->t('Claim')); ?> <span id="exp-detail-no"></span></h2>
	<div id="exp-detail-body" aria-live="polite"></div>
	<div id="exp-detail-lines" class="exp-lines"></div>
	<div id="exp-detail-events" class="exp-timeline"></div>
	<div id="exp-detail-actions" class="exp-actions"></div>
</section>
