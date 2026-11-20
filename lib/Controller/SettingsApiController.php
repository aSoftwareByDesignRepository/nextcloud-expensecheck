<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\AuditLogService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Settings API — GET returns every non-secret registry key (ui-api-client
 * contract rule 4: clients render gated UX from this, not from POST-only
 * state). POST is app-admin only.
 */
class SettingsApiController extends AApiController
{
	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly SettingsService $settings,
		private readonly AuditLogService $audit,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function get(): JSONResponse
	{
		return new JSONResponse([
			'ok' => true,
			'settings' => $this->settings->exportPublic(),
			'roles' => [
				'app_admin' => $this->access->isAppAdmin($this->uid()),
				'finance' => $this->access->isFinance($this->uid()),
				'auditor' => $this->access->isAuditor($this->uid()),
			],
		]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function save(): JSONResponse
	{
		if (!$this->access->isAppAdmin($this->uid())) {
			throw new ClaimNotFoundException();
		}
		$b = $this->body();
		$fields = [];
		foreach ($b as $key => $value) {
			$def = SettingsService::REGISTRY[$key] ?? null;
			if ($def === null || $def[2] !== 'plain') {
				continue; // unknown keys ignored; secrets via dedicated endpoints
			}
			try {
				$this->settings->set($key, $this->settings->normalizeForWrite(
					$key, is_scalar($value) ? (string)$value : ''));
			} catch (\InvalidArgumentException) {
				$fields[$key] = 'invalid';
			}
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		$this->audit->record($this->uid(), 'settings_saved', ['keys' => array_keys($b)]);
		return $this->get();
	}
}
