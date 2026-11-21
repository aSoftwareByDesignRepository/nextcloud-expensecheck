<?php /** @var \OCP\IL10N $l */ ?>
<section class="exp-surface" data-exp-view="claim-detail" data-exp-claim-id="<?php p((string)($_['claimId'] ?? '')); ?>" aria-labelledby="exp-detail-h">
	<h2 id="exp-detail-h"><?php p($l->t('Claim')); ?> <span id="exp-detail-no"></span></h2>
	<nav class="exp-chipbar exp-chipbar--sections" aria-label="<?php p($l->t('On this page')); ?>">
		<a class="exp-chipbar__chip" href="#exp-sec-summary"><?php p($l->t('Summary')); ?></a>
		<a class="exp-chipbar__chip" href="#exp-sec-lines"><?php p($l->t('Lines')); ?></a>
		<a class="exp-chipbar__chip" href="#exp-sec-attachments"><?php p($l->t('Attachments')); ?></a>
		<a class="exp-chipbar__chip" href="#exp-sec-decisions"><?php p($l->t('Decisions')); ?></a>
		<a class="exp-chipbar__chip" href="#exp-sec-audit"><?php p($l->t('Audit trail')); ?></a>
	</nav>
	<div id="exp-detail-actions" class="exp-actions"></div>
	<section id="exp-sec-summary" aria-labelledby="exp-sec-summary-h">
		<h3 id="exp-sec-summary-h" class="exp-section-h"><?php p($l->t('Summary')); ?></h3>
		<div id="exp-detail-body" aria-live="polite"></div>
	</section>
	<section id="exp-sec-lines" aria-labelledby="exp-sec-lines-h">
		<h3 id="exp-sec-lines-h" class="exp-section-h"><?php p($l->t('Lines')); ?></h3>
		<div id="exp-detail-lines" class="exp-lines"></div>
	</section>
	<section id="exp-sec-attachments" aria-labelledby="exp-sec-attachments-h">
		<h3 id="exp-sec-attachments-h" class="exp-section-h"><?php p($l->t('Attachments')); ?></h3>
		<div id="exp-detail-attachments" class="exp-lines"></div>
	</section>
	<section id="exp-sec-decisions" aria-labelledby="exp-sec-decisions-h">
		<h3 id="exp-sec-decisions-h" class="exp-section-h"><?php p($l->t('Decisions')); ?></h3>
		<div id="exp-detail-decisions" class="exp-timeline"></div>
	</section>
	<section id="exp-sec-audit" aria-labelledby="exp-sec-audit-h">
		<h3 id="exp-sec-audit-h" class="exp-section-h"><?php p($l->t('Audit trail')); ?></h3>
		<div id="exp-detail-events" class="exp-timeline"></div>
	</section>
</section>
