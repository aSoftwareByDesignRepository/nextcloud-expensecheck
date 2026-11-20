<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="settlement-detail" data-exp-batch-id="<?php p((string)($_['batchId'] ?? '')); ?>" aria-labelledby="exp-batch-h">
	<h2 id="exp-batch-h"><?php p($l->t('Settlement batch')); ?></h2>
	<div id="exp-batch-body" aria-live="polite"></div>
	<div class="exp-actions">
		<a class="exp-btn exp-btn--secondary" id="exp-export-reimb" href="#"><?php p($l->t('Export reimbursable CSV')); ?></a>
		<a class="exp-btn exp-btn--secondary" id="exp-export-nonreimb" href="#"><?php p($l->t('Export card-reconciliation CSV')); ?></a>
	</div>
</section>
