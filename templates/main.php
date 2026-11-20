<?php
/**
 * ExpenseCheck page — check-shell chrome + body per pageId.
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';

$pageId = (string)($_['pageId'] ?? 'claims');
$body = __DIR__ . '/body/' . basename($pageId) . '.php';
if (is_file($body)) {
	include $body;
} else {
	?>
	<section class="exp-empty">
		<p><?php p($l->t('This page is not available.')); ?></p>
	</section>
	<?php
}

include __DIR__ . '/common/page-end.php';
