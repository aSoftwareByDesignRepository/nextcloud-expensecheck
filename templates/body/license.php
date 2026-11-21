<?php

declare(strict_types=1);

/**
 * ExpenseCheck → License (EXP2 mobile companion).
 *
 * Renders the canonical shared Check-family license page
 * (templates/common/license-page.php, synced from _shared/license-page).
 * PageController::license() feeds every canonical param — this template
 * only renders it. Do not put markup here.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

include __DIR__ . '/../common/license-page.php';
