<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\LicenseService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** EXP2 license management — app-admin only. */
class LicenseController extends AApiController
{
	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly LicenseService $license,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	private function assertAdmin(): void
	{
		if (!$this->access->isAppAdmin($this->uid())) {
			throw new ClaimNotFoundException();
		}
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function show(): JSONResponse
	{
		$this->assertAdmin();
		$state = $this->license->state();
		return new JSONResponse(['ok' => true, 'license' => $state === null ? null : [
			'valid' => $state['valid'],
			'valid_until' => $state['validUntil'],
			'mobile_seats' => $state['mobileSeats'],
			'customer_id' => $state['customerId'],
		]]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function apply(): JSONResponse
	{
		$this->assertAdmin();
		$key = $this->str($this->body(), 'license_key', 4096);
		if ($key === '') {
			throw new ValidationException(['license_key' => 'required']);
		}
		$state = $this->license->apply($key, $this->uid());
		return new JSONResponse(['ok' => true, 'license' => [
			'valid' => $state['valid'], 'valid_until' => $state['validUntil'],
			'mobile_seats' => $state['mobileSeats'], 'customer_id' => $state['customerId'],
		]]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function remove(): JSONResponse
	{
		$this->assertAdmin();
		$this->license->remove($this->uid());
		return new JSONResponse(['ok' => true]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function seats(): JSONResponse
	{
		$this->assertAdmin();
		return new JSONResponse(['ok' => true] + $this->license->seatInventory());
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function assignSeat(): JSONResponse
	{
		$this->assertAdmin();
		$uid = mb_substr(trim((string)$this->request->getParam('uid')), 0, 64);
		if (!$this->access->userExists($uid)) {
			throw new ValidationException(['uid' => 'unknown_user']);
		}
		$this->license->assignSeat($uid, $this->uid());
		return new JSONResponse(['ok' => true] + $this->license->seatInventory(), 201);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function removeSeat(): JSONResponse
	{
		$this->assertAdmin();
		$this->license->removeSeat((string)$this->request->getParam('uid'), $this->uid());
		return new JSONResponse(['ok' => true] + $this->license->seatInventory());
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function removeSeatPost(): JSONResponse
	{
		$this->assertAdmin();
		$this->license->removeSeat(mb_substr(trim((string)$this->request->getParam('uid')), 0, 64), $this->uid());
		return new JSONResponse(['ok' => true] + $this->license->seatInventory());
	}
}
