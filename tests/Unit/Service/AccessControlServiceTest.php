<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The access door (spec §security): app admin list, optional restriction,
 * allowed users/groups, finance/auditor role groups. These tests pin the
 * bypass hierarchy — misconfiguring the door is the biggest ACL risk.
 */
final class AccessControlServiceTest extends TestCase
{
	private IConfig&MockObject $config;
	private IGroupManager&MockObject $groupManager;
	private AccessControlService $svc;

	/** @var array<string,string> */
	private array $appValues = [];

	protected function setUp(): void
	{
		$this->appValues = [];
		$this->config = $this->createStub(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $this->appValues[$key] ?? $default
		);
		$this->groupManager = $this->createStub(IGroupManager::class);
		$this->svc = new AccessControlService(
			$this->createStub(IDBConnection::class),
			$this->config,
			$this->groupManager,
			$this->createStub(IUserManager::class),
			$this->createStub(IUserSession::class),
		);
	}

	public function testEmptyUidDenied(): void
	{
		$this->assertSame('restriction', $this->svc->denialReason(''));
	}

	public function testSystemAdminBypassesEverything(): void
	{
		$this->appValues['access_restriction_enabled'] = '1';
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->assertTrue($this->svc->canUseApp('root'));
		$this->assertTrue($this->svc->isAppAdmin('root'));
	}

	public function testAppAdminBypassesRestriction(): void
	{
		$this->appValues['access_restriction_enabled'] = '1';
		$this->appValues['app_admin_user_ids'] = '["appadmin"]';
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->assertTrue($this->svc->canUseApp('appadmin'));
		$this->assertTrue($this->svc->isAppAdmin('appadmin'));
	}

	public function testOpenModeLetsEveryoneIn(): void
	{
		$this->appValues['access_restriction_enabled'] = '0';
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->assertTrue($this->svc->canUseApp('anybody'));
	}

	public function testRestrictionDeniesUnknownUser(): void
	{
		$this->appValues['access_restriction_enabled'] = '1';
		$this->appValues['access_allowed_user_ids'] = '["allowed"]';
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->assertFalse($this->svc->canUseApp('stranger'));
		$this->assertTrue($this->svc->canUseApp('allowed'));
	}

	public function testRestrictionAllowsGroupMember(): void
	{
		$this->appValues['access_restriction_enabled'] = '1';
		$this->appValues['access_allowed_group_ids'] = '["staff"]';
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid) => $uid === 'member' && $gid === 'staff'
		);
		$this->assertTrue($this->svc->canUseApp('member'));
		$this->assertFalse($this->svc->canUseApp('outsider'));
	}

	public function testMalformedJsonListDoesNotGrant(): void
	{
		$this->appValues['access_restriction_enabled'] = '1';
		$this->appValues['access_allowed_user_ids'] = '{broken';
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->assertFalse($this->svc->canUseApp('allowed'));
	}

	public function testFinanceRole(): void
	{
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid) => $gid === 'expensecheck_finance' && $uid === 'fin'
		);
		$this->assertTrue($this->svc->isFinance('fin'));
		$this->assertFalse($this->svc->isFinance('fin2'));
	}

	public function testAppAdminCountsAsFinance(): void
	{
		$this->appValues['app_admin_user_ids'] = '["appadmin"]';
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->assertTrue($this->svc->isFinance('appadmin'));
	}

	/**
	 * Kills concat mutants on the membership cache key: distinct (uid,gid)
	 * pairs that share a '|'-joined string must NOT collide in the cache.
	 */
	public function testMembershipCacheKeyCannotCollide(): void
	{
		$calls = [];
		$this->groupManager->method('isInGroup')->willReturnCallback(
			function (string $uid, string $gid) use (&$calls) {
				$calls[] = "$uid/$gid";
				return $uid === 'a|b' && $gid === 'c'; // only this pair is a member
			}
		);
		$this->assertFalse($this->svc->isInGroup('a', 'b|c'));
		$this->assertTrue($this->svc->isInGroup('a|b', 'c'));
		// Repeat: second call for each pair hits cache — the mock must have
		// been asked exactly once per DISTINCT pair.
		$this->assertFalse($this->svc->isInGroup('a', 'b|c'));
		$this->assertTrue($this->svc->isInGroup('a|b', 'c'));
		$this->assertCount(2, $calls);
	}

	/**
	 * Kills UnwrapArrayMap/Filter/Values: non-string entries are coerced,
	 * empty strings dropped, list re-indexed.
	 */
	public function testJsonIdListCoercion(): void
	{
		$this->appValues['access_restriction_enabled'] = '1';
		$this->appValues['access_allowed_user_ids'] = '[42, "", "alice", null, 7]';
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->assertTrue($this->svc->canUseApp('alice'));
		$this->assertTrue($this->svc->canUseApp('42'));
		$this->assertTrue($this->svc->canUseApp('7'));
		$this->assertFalse($this->svc->canUseApp(''));
		$this->assertFalse($this->svc->canUseApp('mallory'));
	}
}
