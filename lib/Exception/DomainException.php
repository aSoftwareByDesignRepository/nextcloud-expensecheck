<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Exception;

/**
 * A domain failure carrying a stable machine-readable error code (spec §10.4)
 * and an HTTP status. The API layer serializes it as
 * {success:false,error:<code>,message,fields?} — codes are contract, messages
 * are localizable prose.
 */
class DomainException extends \Exception
{
	/** @var array<string,string>|null */
	private ?array $fields;

	/**
	 * @param array<string,string>|null $fields field → error-code map for 422s
	 */
	public function __construct(
		private readonly string $errorCode,
		string $message = '',
		private readonly int $httpStatus = 400,
		?array $fields = null,
	) {
		parent::__construct($message !== '' ? $message : $errorCode);
		$this->fields = $fields;
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	public function getHttpStatus(): int
	{
		return $this->httpStatus;
	}

	/** @return array<string,string>|null */
	public function getFields(): ?array
	{
		return $this->fields;
	}
}
