<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="settlement-detail" data-exp-batch-id="<?php p((string)($_['batchId'] ?? '')); ?>" aria-labelledby="exp-batch-h">
	<h2 id="exp-batch-h"><?php p($l->t('Settlement batch')); ?></h2>
	<div id="exp-batch-body" aria-live="polite"></div>
	<div class="exp-actions">
		<label class="exp-field" for="exp-export-format">
			<span class="exp-label"><?php p($l->t('Format')); ?></span>
			<select id="exp-export-format" class="exp-select">
				<option value="csv">CSV</option>
				<option value="ods">ODS</option>
			</select>
		</label>
		<button class="exp-btn exp-btn--secondary" id="exp-export-reimb" type="button" data-channel="reimbursable"><?php p($l->t('Export reimbursable')); ?></button>
		<button class="exp-btn exp-btn--secondary" id="exp-export-nonreimb" type="button" data-channel="nonreimbursable"><?php p($l->t('Export card-reconciliation')); ?></button>
	</div>
	<p id="exp-export-status" class="exp-muted" role="status" aria-live="polite"></p>
</section>
