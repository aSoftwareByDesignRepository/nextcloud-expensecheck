<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Db;

use OCA\ExpenseCheck\Db\SqlErrors;
use PHPUnit\Framework\TestCase;

/**
 * Cross-DB driver-error detection — the pg/MariaDB portability contract.
 * Message sniffing alone misses pg's "could not serialize access" wording;
 * these tests pin class + SQLSTATE + vendor-code paths so dedupe replay
 * never 500s on a non-MySQL backend.
 */
final class SqlErrorsTest extends TestCase
{
	public function testPgUniqueViolationDetected(): void
	{
		$e = new \PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "exp_claims_rk_idx"');
		$this->assertTrue(SqlErrors::isConcurrencyViolation($e));
		$this->assertTrue(SqlErrors::isUniqueViolation($e));
	}

	public function testPgSerializationFailureDetected(): void
	{
		// THE regression guard: pg says "serialize", not "serialization" —
		// the old message-grep returned false here and the dedupe path died.
		$e = new \PDOException('SQLSTATE[40001]: Serialization failure: 7 ERROR:  could not serialize access due to concurrent update');
		$e->errorInfo = ['40001', 0, 'could not serialize access'];
		$this->assertTrue(SqlErrors::isConcurrencyViolation($e));
		$this->assertFalse(SqlErrors::isUniqueViolation($e));
	}

	public function testPgDeadlockDetected(): void
	{
		$e = new \PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
		$e->errorInfo = ['40P01', 0, 'deadlock detected'];
		$this->assertTrue(SqlErrors::isConcurrencyViolation($e));
		$this->assertFalse(SqlErrors::isUniqueViolation($e));
	}

	public function testMysql1062Detected(): void
	{
		$e = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'u:k' for key 'exp_claims_rk_idx'", 1062);
		$e->errorInfo = ['23000', 1062, "Duplicate entry 'u:k'"];
		$this->assertTrue(SqlErrors::isConcurrencyViolation($e));
		$this->assertTrue(SqlErrors::isUniqueViolation($e));
	}

	public function testMysqlDeadlock1213Detected(): void
	{
		$e = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 1213);
		$e->errorInfo = ['40001', 1213, 'Deadlock found'];
		$this->assertTrue(SqlErrors::isConcurrencyViolation($e));
		$this->assertFalse(SqlErrors::isUniqueViolation($e));
	}

	public function testDoctrineClassDetectedThroughWrap(): void
	{
		// A wrapper exception must still expose the typed cause.
		$inner = $this->createMock(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
		$outer = new \RuntimeException('wrapped', 0, $inner);
		$this->assertTrue(SqlErrors::isConcurrencyViolation($outer));
		$this->assertTrue(SqlErrors::isUniqueViolation($outer));
	}

	public function testUnrelatedErrorIsNotConcurrency(): void
	{
		$e = new \PDOException('SQLSTATE[42S02]: Base table or view not found');
		$e->errorInfo = ['42S02', 1146, 'table missing'];
		$this->assertFalse(SqlErrors::isConcurrencyViolation($e));
		$this->assertFalse(SqlErrors::isUniqueViolation($e));
	}
}
