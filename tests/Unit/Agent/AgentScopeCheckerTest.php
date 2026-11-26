<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Agent;

use OCA\ExpenseCheck\Agent\AgentException;
use OCA\ExpenseCheck\Agent\AgentScopeChecker;
use PHPUnit\Framework\TestCase;

/**
 * Scope vocabulary is exact-match only — the checks an attacker would
 * probe first (wildcards, prefix tricks, case games) must all fail closed.
 */
class AgentScopeCheckerTest extends TestCase
{
	public function testValidFormatsAccepted(): void
	{
		$this->assertTrue(AgentScopeChecker::isValidFormat('expensecheck:time_entries:read'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('expensecheck:invoices:write'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('a1_b2:c3_d4:write'));
	}

	public function testInvalidFormatsRejected(): void
	{
		$cases = [
			'',
			'expensecheck:time_entries',                 // missing action
			'expensecheck:time_entries:read:extra',      // too many segments
			'expensecheck:Time_Entries:READ',            // case games
			'expensecheck:*:read',                       // wildcard resource
			'expensecheck:time_entries:*',               // wildcard action
			'*:*:*',
			'expensecheck:*',                            // app-wide wildcard
			'expensecheck :time_entries:read',           // embedded space
			"expensecheck:time_entries:read\n",          // control chars
			'expensecheck:time-entries:read',            // dash not allowed
			'expensecheck::read',                        // empty resource
			':time_entries:read',                      // empty app
			'expensecheck:time_entries:delete',          // v1 action set is read|write only
		];
		foreach ($cases as $scope) {
			$this->assertFalse(
				AgentScopeChecker::isValidFormat($scope),
				"scope should be invalid: " . var_export($scope, true)
			);
		}
	}

	public function testPrefixGrantIsNotSatisfaction(): void
	{
		// granted scope that is a string-prefix of the requirement must NOT
		// satisfy — exact match is the whole security model.
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['expensecheck:time_ent'],
				'expensecheck:time_entries:write',
			)
		);
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['expensecheck:time_entries:write_extra'],
				'expensecheck:time_entries:write',
			)
		);
	}

	public function testReadDoesNotSatisfyWrite(): void
	{
		$granted = ['expensecheck:time_entries:read'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'expensecheck:time_entries:write'),
			'read scope must never satisfy a write requirement'
		);
		$this->assertTrue(AgentScopeChecker::satisfied($granted, 'expensecheck:time_entries:read'));
	}

	public function testPrefixMatchIsNotSatisfaction(): void
	{
		$granted = ['expensecheck:time_entr:write', 'expensecheck:time_entries_extended:write'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'expensecheck:time_entries:write'),
			'prefix/partial matches must not satisfy'
		);
	}

	public function testValidateGrantRequestDeduplicatesAndValidates(): void
	{
		$declared = ['expensecheck:a:read', 'expensecheck:b:write'];
		$grants = AgentScopeChecker::validateGrantRequest(
			['expensecheck:a:read', ' expensecheck:a:read ', 'expensecheck:b:write'],
			$declared,
		);
		$this->assertSame(['expensecheck:a:read', 'expensecheck:b:write'], $grants);
	}

	public function testValidateGrantRequestRejectsUndeclared(): void
	{
		$this->expectException(AgentException::class);
		AgentScopeChecker::validateGrantRequest(
			['expensecheck:secret:read'],
			['expensecheck:a:read'],
		);
	}

	public function testValidateGrantRequestRejectsWildcard(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['expensecheck:*:read'], ['expensecheck:a:read']);
			$this->fail('wildcard grant must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsEmpty(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['  '], ['expensecheck:a:read']);
			$this->fail('empty grant list must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsNonString(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest([['nested']], ['expensecheck:a:read']);
			$this->fail('non-string scope must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}
}
