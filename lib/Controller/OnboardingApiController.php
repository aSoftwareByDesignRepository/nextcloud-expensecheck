<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * §9.3 first-run checklist state — per-user dismiss flag. Stored in
 * preference scope (user value), not org scope: dismissing must never
 * hide the card for a colleague.
 */
class OnboardingApiController extends AApiController
{
	private const KEY = 'onboarding_dismissed';

	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function show(): JSONResponse
	{
		return new JSONResponse([
			'ok' => true,
			'dismissed' => $this->config->getUserValue($this->uid(), Application::APP_ID, self::KEY, '0') === '1',
		]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function dismiss(): JSONResponse
	{
		$this->config->setUserValue($this->uid(), Application::APP_ID, self::KEY, '1');
		return new JSONResponse(['ok' => true, 'dismissed' => true]);
	}
}
