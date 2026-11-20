<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Exception\AppAccessDeniedException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Access door + role resolution (spec §3.1/§3.4).
 *
 * Evaluation order for canUseApp (stable, tested):
 *   1. NC admin → allow
 *   2. app_admin_user_ids → allow
 *   3. restriction disabled ('0') → allow
 *   4. uid ∈ allowed users → allow
 *   5. uid ∈ member of an allowed group → allow → else deny
 *
 * Roles: finance + auditor resolve through dedicated NC groups
 * (expensecheck_finance / expensecheck_auditor); approver resolves through
 * routed chain steps and delegates; app admin via app_admin_user_ids.
 */
class AccessControlService
{
	public const KEY_APP_ADMINS = 'app_admin_user_ids';
	public const KEY_ACCESS_RESTRICTION = 'access_restriction_enabled';
	public const KEY_ACCESS_ALLOWED_USER_IDS = 'access_allowed_user_ids';
	public const KEY_ACCESS_ALLOWED_GROUP_IDS = 'access_allowed_group_ids';

	public const GROUP_FINANCE = 'expensecheck_finance';
	public const GROUP_AUDITOR = 'expensecheck_auditor';

	public const DENIAL_RESTRICTION = 'restriction';

	/** @var array<string, bool> */
	private array $systemAdminCache = [];

	/** @var array<string, bool> */
	private array $groupMembershipCache = [];

	/** @var array<string, list<string>> */
	private array $jsonIdListCache = [];

	private ?bool $restrictionEnabledCache = null;

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IUserSession $userSession,
	) {
	}

	public function currentUserId(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('not_authenticated');
		}
		return $user->getUID();
	}

	public function isSystemAdmin(string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		if (!array_key_exists($userId, $this->systemAdminCache)) {
			$this->systemAdminCache[$userId] = (bool)$this->groupManager->isAdmin($userId);
		}
		return $this->systemAdminCache[$userId];
	}

	public function isAppAdmin(string $userId): bool
	{
		return $this->isSystemAdmin($userId) || in_array($userId, $this->getJsonIdList(self::KEY_APP_ADMINS), true);
	}

	/** @return list<string> */
	public function getAppAdminIds(): array
	{
		return $this->getJsonIdList(self::KEY_APP_ADMINS);
	}

	/** @return list<string> */
	public function getAllowedUserIds(): array
	{
		return $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_USER_IDS);
	}

	/** @return list<string> */
	public function getAllowedGroupIds(): array
	{
		return $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_GROUP_IDS);
	}

	public function isAccessRestrictionEnabled(): bool
	{
		if ($this->restrictionEnabledCache === null) {
			$this->restrictionEnabledCache = $this->config->getAppValue(Application::APP_ID, self::KEY_ACCESS_RESTRICTION, '0') === '1';
		}
		return $this->restrictionEnabledCache;
	}

	/**
	 * The door: can this uid use the app at all? Returns a denial reason or null.
	 */
	public function denialReason(string $userId): ?string
	{
		if ($userId === '') {
			return self::DENIAL_RESTRICTION;
		}
		if ($this->isSystemAdmin($userId)) {
			return null;
		}
		if (in_array($userId, $this->getJsonIdList(self::KEY_APP_ADMINS), true)) {
			return null;
		}
		if (!$this->isAccessRestrictionEnabled()) {
			return null;
		}
		if (in_array($userId, $this->getJsonIdList(self::KEY_ACCESS_ALLOWED_USER_IDS), true)) {
			return null;
		}
		foreach ($this->getJsonIdList(self::KEY_ACCESS_ALLOWED_GROUP_IDS) as $gid) {
			if ($this->isInGroup($userId, $gid)) {
				return null;
			}
		}
		return self::DENIAL_RESTRICTION;
	}

	public function canUseApp(string $userId): bool
	{
		return $this->denialReason($userId) === null;
	}

	public function assertAccess(string $userId): void
	{
		$reason = $this->denialReason($userId);
		if ($reason !== null) {
			throw new AppAccessDeniedException($reason);
		}
	}

	public function isFinance(string $userId): bool
	{
		return $this->isAppAdmin($userId) || $this->isInGroup($userId, self::GROUP_FINANCE);
	}

	public function isAuditor(string $userId): bool
	{
		return $this->isAppAdmin($userId) || $this->isInGroup($userId, self::GROUP_AUDITOR);
	}

	public function isInGroup(string $userId, string $groupId): bool
	{
		// NUL separator: '|' is legal in group display names and would collide
		// ('a','b|c') vs ('a|b','c') → cross-user cache poisoning.
		$k = $userId . "\x00" . $groupId;
		if (!array_key_exists($k, $this->groupMembershipCache)) {
			$this->groupMembershipCache[$k] = $this->groupManager->isInGroup($userId, $groupId);
		}
		return $this->groupMembershipCache[$k];
	}

	public function userExists(string $uid): bool
	{
		return $uid !== '' && $this->userManager->userExists($uid);
	}

	public function groupExists(string $gid): bool
	{
		return $gid !== '' && $this->groupManager->groupExists($gid);
	}

	/** @return list<string> */
	public function usersInGroup(string $gid): array
	{
		$group = $this->groupManager->get($gid);
		if ($group === null) {
			return [];
		}
		$out = [];
		foreach ($group->getUsers() as $user) {
			$out[] = $user->getUID();
		}
		return $out;
	}

	/**
	 * Mirrored-class permission writer (spec §3.4): the only legal way to
	 * persist access-door keys — never raw setAppValue on these keys.
	 *
	 * @param list<string> $allowedUsers
	 * @param list<string> $allowedGroups
	 * @param list<string> $appAdmins
	 */
	public function writeAccessPolicy(bool $restrictionEnabled, array $allowedUsers, array $allowedGroups, array $appAdmins): void
	{
		foreach ($allowedUsers as $uid) {
			if (!$this->userExists($uid)) {
				throw new \InvalidArgumentException('unknown user: ' . $uid);
			}
		}
		foreach ($allowedGroups as $gid) {
			if (!$this->groupExists($gid)) {
				throw new \InvalidArgumentException('unknown group: ' . $gid);
			}
		}
		foreach ($appAdmins as $uid) {
			if (!$this->userExists($uid)) {
				throw new \InvalidArgumentException('unknown app admin: ' . $uid);
			}
		}
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_RESTRICTION, $restrictionEnabled ? '1' : '0');
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_ALLOWED_USER_IDS, json_encode(array_values($allowedUsers)));
		$this->config->setAppValue(Application::APP_ID, self::KEY_ACCESS_ALLOWED_GROUP_IDS, json_encode(array_values($allowedGroups)));
		$this->config->setAppValue(Application::APP_ID, self::KEY_APP_ADMINS, json_encode(array_values($appAdmins)));
		$this->restrictionEnabledCache = null;
		$this->jsonIdListCache = [];
	}

	/** @return list<string> */
	private function getJsonIdList(string $key): array
	{
		if (!array_key_exists($key, $this->jsonIdListCache)) {
			$raw = $this->config->getAppValue(Application::APP_ID, $key, '[]');
			$decoded = json_decode($raw, true);
			$this->jsonIdListCache[$key] = is_array($decoded)
				? array_values(array_filter(array_map('strval', $decoded), fn (string $v): bool => $v !== ''))
				: [];
		}
		return $this->jsonIdListCache[$key];
	}
}
