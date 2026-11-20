<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Exception;

/**
 * Uniform-404 (spec §3.3 / NN-04): thrown identically for "does not exist"
 * and "exists but not visible to this caller". The body and timing reveal
 * nothing about which case it was.
 */
class ClaimNotFoundException extends DomainException
{
	public function __construct()
	{
		parent::__construct('CLAIM_NOT_FOUND', 'Claim not found', 404);
	}
}
