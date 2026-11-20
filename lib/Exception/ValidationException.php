<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Exception;

/**
 * 422 VALIDATION_FAILED with a field → reason map. The web UI renders each
 * entry inline next to its control (aria-invalid + .exp-field-error); the
 * companion maps them onto its form fields — the ui-api-client contract.
 */
class ValidationException extends DomainException
{
	/** @param array<string,string> $fields */
	public function __construct(array $fields, string $message = 'Validation failed')
	{
		parent::__construct('VALIDATION_FAILED', $message, 422, $fields);
	}
}
