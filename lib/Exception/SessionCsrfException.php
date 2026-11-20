<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Exception;

class SessionCsrfException extends DomainException
{
	public function __construct()
	{
		parent::__construct('CSRF_FAILED', 'CSRF check failed', 412);
	}
}
