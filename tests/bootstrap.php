<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Bypass finals so OCP interfaces/classes can be doubled in unit tests.
if (class_exists(\DG\BypassFinals::class)) {
	\DG\BypassFinals::enable();
}
