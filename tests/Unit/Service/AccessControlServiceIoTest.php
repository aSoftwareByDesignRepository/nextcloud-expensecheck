<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Exception\AppAccessDeniedException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** IO-shaped methods: session, group listing, existence, policy writer. */
final class AccessControlServiceIoTest extends TestCase
{
	private IConfig&MockObject $config;
	private IGroupManager&MockObject $groups;
	private IUserManager&MockObject $users;
	private IUserSession&MockObject $session;
	private AccessControlService $svc;
	/** @var array<string,string> */
	private array $appValues = [];

	protected function setUp(): void
	{
		$this->appValues = [];
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $this->appValues[$key] ?? $default
		);
		$this->config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->appValues[$key] = $value;
			}
		);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->svc = new AccessControlService(
			$this->createStub(IDBConnection::class), $this->config,
			$this->groups, $this->users, $this->session
		);
	}

	public function testCurrentUserIdThrowsWhenUnauthenticated(): void
	{
		$this->session->method('getUser')->willReturn(null);
		$this->expectException(\RuntimeException::class);
		$this->svc->currentUserId();
	}

	public function testCurrentUserIdReturnsUid(): void
	{
		$u = $this->createStub(IUser::class);
		$u->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($u);
		$this->assertSame('alice', $this->svc->currentUserId());
	}

	public function testIsAuditorViaGroup(): void
	{
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturnMap([
			['aud1', 'expensecheck_auditor', true],
			['aud1', 'expensecheck_finance', false],
		]);
		$this->assertTrue($this->svc->isAuditor('aud1'));
		$this->assertFalse($this->svc->isFinance('aud1'));
	}

	public function testUsersInGroupEmptyForMissingGroup(): void
	{
		$this->groups->method('get')->willReturn(null);
		$this->assertSame([], $this->svc->usersInGroup('ghost'));
	}

	public function testUsersInGroupListsUids(): void
	{
		$g = $this->createStub(IGroup::class);
		$u1 = $this->createStub(IUser::class); $u1->method('getUID')->willReturn('a');
		$u2 = $this->createStub(IUser::class); $u2->method('getUID')->willReturn('b');
		$g->method('getUsers')->willReturn([$u1, $u2]);
		$this->groups->method('get')->willReturn($g);
		$this->assertSame(['a', 'b'], $this->svc->usersInGroup('g1'));
	}

	public function testUserExistsRejectsEmptyAndDelegates(): void
	{
		$this->users->method('userExists')->willReturnCallback(fn (string $u) => $u === 'alice');
		$this->assertFalse($this->svc->userExists(''));
		$this->assertTrue($this->svc->userExists('alice'));
		$this->assertFalse($this->svc->userExists('ghost'));
	}

	public function testGroupExistsRejectsEmptyAndDelegates(): void
	{
		$this->groups->method('groupExists')->willReturnCallback(fn (string $g) => $g === 'g1');
		$this->assertFalse($this->svc->groupExists(''));
		$this->assertTrue($this->svc->groupExists('g1'));
		$this->assertFalse($this->svc->groupExists('gone'));
	}

	public function testWriteAccessPolicyRejectsUnknownUser(): void
	{
		$this->users->method('userExists')->willReturn(false);
		$this->expectException(\InvalidArgumentException::class);
		$this->svc->writeAccessPolicy(true, ['ghost'], [], []);
	}

	public function testWriteAccessPolicyRejectsUnknownGroup(): void
	{
		$this->users->method('userExists')->willReturn(true);
		$this->groups->method('groupExists')->willReturn(false);
		$this->expectException(\InvalidArgumentException::class);
		$this->svc->writeAccessPolicy(true, [], ['gone'], []);
	}

	public function testWriteAccessPolicyPersistsAndInvalidatesCache(): void
	{
		$this->users->method('userExists')->willReturn(true);
		$this->groups->method('groupExists')->willReturn(true);
		$this->svc->writeAccessPolicy(true, ['alice'], ['g1'], ['root']);
		$this->assertSame('1', $this->appValues[AccessControlService::KEY_ACCESS_RESTRICTION]);
		$this->assertSame('["alice"]', $this->appValues[AccessControlService::KEY_ACCESS_ALLOWED_USER_IDS]);
		$this->assertSame('["g1"]', $this->appValues[AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS]);
		$this->assertSame('["root"]', $this->appValues[AccessControlService::KEY_APP_ADMINS]);
		// Cache invalidated: a follow-up denial check re-reads fresh values.
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
		$this->assertFalse($this->svc->canUseApp('mallory'));
		$this->assertTrue($this->svc->canUseApp('alice'));
		$this->assertTrue($this->svc->canUseApp('root')); // app admin bypass
	}

	public function testAssertAccessThrowsForDenied(): void
	{
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('isInGroup')->willReturn(false);
		$this->appValues[AccessControlService::KEY_ACCESS_RESTRICTION] = '1';
		$this->expectException(AppAccessDeniedException::class);
		$this->svc->assertAccess('mallory');
	}

	public function testAllowedGroupMemberGetsIn(): void
	{
		$this->groups->method('isAdmin')->willReturn(false);
		$this->appValues[AccessControlService::KEY_ACCESS_RESTRICTION] = '1';
		$this->appValues[AccessControlService::KEY_ACCESS_ALLOWED_GROUP_IDS] = '["staff"]';
		$this->groups->method('isInGroup')->willReturnMap([['bob', 'staff', true]]);
		$this->assertTrue($this->svc->canUseApp('bob'));
	}
}
