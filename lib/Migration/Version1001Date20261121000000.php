<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * F22 — billed marker on claims: the invoicing app marks an approved,
 * billable claim as invoiced via ExpBillableReadFacade::markBilled().
 * Nullable; financial state only (ref = IC invoice number, no PII).
 */
class Version1001Date20261121000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('exp_claims')) {
			$t = $schema->getTable('exp_claims');
			if (!$t->hasColumn('billed_at')) {
				$t->addColumn('billed_at', Types::DATETIME, ['notnull' => false]);
			}
			if (!$t->hasColumn('billed_ref')) {
				$t->addColumn('billed_ref', Types::STRING, ['length' => 191, 'notnull' => false]);
			}
		}

		return $schema;
	}
}
