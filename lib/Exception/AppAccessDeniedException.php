<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Exception;

/**
 * Access-door rejection (spec §3.4). The denial reason is carried for
 * diagnostics — it is NEVER rendered into the 403 body (no existence oracle).
 */
class AppAccessDeniedException extends DomainException
{
	public function __construct(private readonly string $denialReason = 'restriction')
	{
		parent::__construct('APP_ACCESS_DENIED', 'Access denied', 403);
	}

	public function getDenialReason(): string
	{
		return $this->denialReason;
	}
}
