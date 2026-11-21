<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Db;

/**
 * Cross-DB driver-error taxonomy (MariaDB, PostgreSQL, Oracle).
 *
 * Message-text sniffing is NOT portable: pg serialization failures say
 * "could not serialize access" (not "serialization"), and Oracle wording
 * differs again. Doctrine normalizes driver errors onto typed exceptions —
 * check those first, then SQLSTATEs, then the message only as last resort.
 */
final class SqlErrors
{
	/** SQLSTATEs that mean "a concurrent writer won / collided". */
	private const CONCURRENCY_STATES = [
		'23000', // generic integrity constraint (mysql unique fallback)
		'23505', // pgsql unique_violation
		'40001', // serialization_failure / mysql deadlock
		'40P01', // pgsql deadlock_detected
		'61000', // oracle generic
	];
	/** MySQL vendor error codes (errorInfo[1] / PDO code as int). */
	private const CONCURRENCY_VENDOR_CODES = [1062, 1205, 1213];

	private function __construct()
	{
	}

	/**
	 * Unique-key collision OR deadlock/serialization — i.e. a sibling
	 * request won the race and this one must replay/dedupe, not 500.
	 */
	public static function isConcurrencyViolation(\Throwable $e): bool
	{
		for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
			if ($cur instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException
				|| $cur instanceof \Doctrine\DBAL\Exception\DeadlockException) {
				return true;
			}
			if ($cur instanceof \Doctrine\DBAL\Driver\Exception) {
				$state = $cur->getSQLState();
				if ($state !== null && in_array($state, self::CONCURRENCY_STATES, true)) {
					return true;
				}
			}
			if ($cur instanceof \PDOException) {
				$info = $cur->errorInfo ?? null;
				if (is_array($info)) {
					if (in_array((string)($info[0] ?? ''), self::CONCURRENCY_STATES, true)) {
						return true;
					}
					if (in_array((int)($info[1] ?? 0), self::CONCURRENCY_VENDOR_CODES, true)) {
						return true;
					}
				}
				if (in_array((int)$cur->getCode(), self::CONCURRENCY_VENDOR_CODES, true)) {
					return true;
				}
			}
		}
		// Legacy message fallback — 'serializ' covers both pg's "could not
		// serialize access" and MySQL's "serialization failure" wording.
		$msg = strtolower($e->getMessage());
		return str_contains($msg, 'unique') || str_contains($msg, 'duplicate')
			|| str_contains($msg, 'deadlock') || str_contains($msg, 'serializ');
	}

	/** Unique-key collision specifically (subset of the above). */
	public static function isUniqueViolation(\Throwable $e): bool
	{
		for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
			if ($cur instanceof \Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
				return true;
			}
			if ($cur instanceof \Doctrine\DBAL\Driver\Exception
				&& in_array($cur->getSQLState() ?? '', ['23000', '23505'], true)) {
				return true;
			}
			if ($cur instanceof \PDOException) {
				$info = $cur->errorInfo ?? null;
				if (is_array($info)
					&& (in_array((string)($info[0] ?? ''), ['23000', '23505'], true)
						|| (int)($info[1] ?? 0) === 1062)) {
					return true;
				}
				if ((int)$cur->getCode() === 1062) {
					return true;
				}
			}
		}
		$msg = strtolower($e->getMessage());
		return str_contains($msg, 'unique') || str_contains($msg, 'duplicate');
	}
}
