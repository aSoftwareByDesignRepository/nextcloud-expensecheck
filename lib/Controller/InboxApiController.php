<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\ApprovalService;
use OCA\ExpenseCheck\Service\ClaimSerializer;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Approver inbox — claims awaiting the caller's decision. */
class InboxApiController extends AApiController
{
	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly ApprovalService $approvals,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function list(): JSONResponse
	{
		return new JSONResponse([
			'ok' => true,
			'claims' => array_map([ClaimSerializer::class, 'summary'], $this->approvals->inboxFor($this->uid())),
		]);
	}
}
