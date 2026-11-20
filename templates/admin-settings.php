<?php /** @var \OCP\IL10N $l */ ?>
<div class="section">
	<h2><?php p($l->t('ExpenseCheck')); ?></h2>
	<p><?php p($l->t('Configure ExpenseCheck inside the app: Settings and License in the sidebar.')); ?></p>
	<p><a class="button" href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('expensecheck.page.settings')); ?>"><?php p($l->t('Open ExpenseCheck settings')); ?></a></p>
</div>
