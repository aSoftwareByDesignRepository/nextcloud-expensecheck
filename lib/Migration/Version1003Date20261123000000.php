<?php
declare(strict_types=1);

namespace OCA\ExpenseCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Claim `kind` marker + edge-20 `corrects_claim_no` reference.
 *
 * - kind = 'standard'   — normal expense claim (default)
 * - kind = 'correction' — storno/counter-claim; carries corrects_claim_no
 *   and may hold negative lines (edge 20)
 * - kind = 'deduction'  — org-owed money flow (F26 MC charge-back); may
 *   hold negative lines; provenance sits on the line's source_app/ref
 */
class Version1003Date20261123000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('exp_claims')) {
			$t = $schema->getTable('exp_claims');
			if (!$t->hasColumn('kind')) {
				$t->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'standard']);
			}
			if (!$t->hasColumn('corrects_claim_no')) {
				$t->addColumn('corrects_claim_no', Types::STRING, ['length' => 16, 'notnull' => false]);
			}
		}

		return $schema;
	}
}
