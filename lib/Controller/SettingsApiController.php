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
			if ($def === null) {
				continue; // unknown keys are ignored, never stored
			}
			[$type, , $class] = $def;
			if ($class === 'secret' || $class === 'internal') {
				continue; // secrets via dedicated endpoints only
			}
			$v = is_scalar($value) ? (string)$value : '';
			if ($type === 'bool') {
				$v = in_array(strtolower($v), ['1', 'true', 'on'], true) ? '1' : '0';
			} elseif ($type === 'int') {
				if (!preg_match('/^\d+$/', $v)) {
					$fields[$key] = 'invalid';
					continue;
				}
			} elseif (str_starts_with($type, 'enum:')) {
				$allowed = explode(',', substr($type, 5));
				if (!in_array($v, $allowed, true)) {
					$fields[$key] = 'invalid';
					continue;
				}
			} elseif ($type === 'currency') {
				$v = strtoupper($v);
				if (!preg_match('/^[A-Z]{3}$/', $v)) {
					$fields[$key] = 'invalid';
					continue;
				}
			}
			$this->settings->set($key, $v);
		}
		if ($fields !== []) {
			throw new ValidationException($fields);
		}
		$this->audit->record($this->uid(), 'settings_saved', ['keys' => array_keys($b)]);
		return $this->get();
	}
}
