<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCP\AppFramework\Controller;
use OCP\IRequest;

/**
 * Shared controller plumbing: uid resolution, JSON/body param extraction,
 * pagination clamps. Every mutation surface reuses these — consistent
 * coercion means consistent security.
 */
abstract class AApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		protected readonly AccessControlService $access,
	) {
		parent::__construct($appName, $request);
	}

	protected function uid(): string
	{
		return $this->access->currentUserId();
	}

	/**
	 * Merge JSON body + form params. JSON wins — the companions send JSON;
	 * web forms send urlencoded. `requesttoken` and routing params are
	 * stripped so they can't smuggle into domain writes.
	 * @return array<string,mixed>
	 */
	protected function body(): array
	{
		$out = [];
		foreach ($this->request->getParams() as $k => $v) {
			if (in_array($k, ['requesttoken', '_route', 'id', 'attachmentId', 'batchId', 'claimId', 'uid'], true)) {
				continue;
			}
			$out[$k] = $v;
		}
		return $out;
	}

	protected function str(array $b, string $key, int $maxLen = 2000): string
	{
		$v = $b[$key] ?? '';
		if (is_array($v)) {
			throw new ValidationException([$key => 'invalid']);
		}
		return mb_substr(trim((string)$v), 0, $maxLen);
	}

	protected function intParam(string $name, int $default = 0): int
	{
		$v = $this->request->getParam($name);
		if ($v === null || $v === '') {
			return $default;
		}
		return (int)$v;
	}

	/** Normalizes the 'id' route param to int — route regex guarantees digits. */
	protected function routeId(string $name = 'id'): int
	{
		return (int)$this->request->getParam($name);
	}
}
