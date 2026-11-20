<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\AttachmentService;
use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCA\ExpenseCheck\Service\ClaimSerializer;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Receipts inbox (capture-first, spec §12): unfiled uploads live here until
 * the claimant explicitly files them onto a claim line.
 */
class ReceiptsApiController extends AApiController
{
	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly AttachmentMapper $attachments,
		private readonly ClaimLineMapper $lines,
		private readonly AttachmentService $attachmentService,
		private readonly ClaimLifecycleService $lifecycle,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function list(): JSONResponse
	{
		return new JSONResponse([
			'ok' => true,
			'receipts' => array_map([ClaimSerializer::class, 'attachmentDto'], $this->attachmentService->inbox($this->uid())),
		]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function upload(): JSONResponse
	{
		$file = $this->request->getUploadedFile('file');
		if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			throw new ValidationException(['file' => 'required']);
		}
		$contents = file_get_contents($file['tmp_name']);
		if ($contents === false) {
			throw new ValidationException(['file' => 'invalid']);
		}
		$a = $this->attachmentService->store($this->uid(), null, null, 'inbox', (string)$file['name'], (string)$file['type'], $contents);
		return new JSONResponse(['ok' => true, 'receipt' => ClaimSerializer::attachmentDto($a)], Http::STATUS_CREATED);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function file(): JSONResponse
	{
		$inbox = $this->findInboxRow($this->routeId());
		$claim = $this->lifecycle->loadVisible((int)$this->request->getParam('claim_id'), $this->uid());
		$line = null;
		foreach ($this->lines->findByClaim($claim->getId()) as $l) {
			if ($l->getId() === (int)$this->request->getParam('line_id')) {
				$line = $l;
				break;
			}
		}
		if ($line === null) {
			throw new ValidationException(['line_id' => 'invalid']);
		}
		$a = $this->attachmentService->fileFromInbox($inbox, $claim, $line, $this->uid());
		return new JSONResponse(['ok' => true, 'attachment' => ClaimSerializer::attachmentDto($a)]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function delete(): JSONResponse
	{
		$this->attachmentService->delete($this->findInboxRow($this->routeId()), $this->uid());
		return new JSONResponse(['ok' => true]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function download()
	{
		$a = $this->findInboxRow($this->routeId());
		return new DataDownloadResponse(
			$this->attachmentService->contents($a, $this->uid()),
			$a->getName(),
			$a->getMime()
		);
	}

	private function findInboxRow(int $id): \OCA\ExpenseCheck\Db\Attachment
	{
		foreach ($this->attachments->findInbox($this->uid()) as $a) {
			if ($a->getId() === $id) {
				return $a;
			}
		}
		throw new DomainException('ATTACHMENT_NOT_FOUND', 'Attachment not found.', 404);
	}
}
