<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Exception;

/**
 * Mobile-gate failures (spec §13.1/§13.6): LICENSE_REQUIRED / LICENSE_EXPIRED /
 * NO_MOBILE_SEAT / CONTRACT_VERSION_MISMATCH. 402/426 per the error dictionary.
 */
class CompanionException extends DomainException
{
	public static function licenseRequired(): self
	{
		return new self('LICENSE_REQUIRED', 'An ExpenseCheck mobile seat license is required.', 402);
	}

	public static function licenseExpired(): self
	{
		return new self('LICENSE_EXPIRED', 'The mobile license could not be verified (expired or unverifiable).', 402);
	}

	public static function noSeat(): self
	{
		return new self('NO_MOBILE_SEAT', 'No mobile seat is assigned to this account.', 402);
	}

	public static function contractMismatch(int $serverFloor, int $clientVersion): self
	{
		return new self(
			'CONTRACT_VERSION_MISMATCH',
			'Companion contract ' . $clientVersion . ' is below the server floor ' . $serverFloor . ' — update the app.',
			426
		);
	}
}
