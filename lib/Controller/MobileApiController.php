<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\ApprovalService;
use OCA\ExpenseCheck\Service\AttachmentService;
use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCA\ExpenseCheck\Service\ClaimSerializer;
use OCA\ExpenseCheck\Service\LicenseService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Companion API (spec §13.4). Reached ONLY via Basic app-password +
 * EXP2 gate (ClientLicenseMiddleware) — bootstrap is the single exempt
 * endpoint so the app can render honest license state. Contract version is
 * checked: below-floor clients get 426 CONTRACT_VERSION_MISMATCH.
 *
 * Offline decisions are impossible by design — decisions mutate server
 * state and always round-trip here.
 */
class MobileApiController extends AApiController
{
	/** Server contract floor — companions below this must update. */
	public const CONTRACT_FLOOR = 1;
	public const CONTRACT_VERSION = 1;

	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly ClaimMapper $claims,
		private readonly ClaimLineMapper $lines,
		private readonly AttachmentMapper $attachments,
		private readonly \OCA\ExpenseCheck\Service\ClaimLineService $lineService,
		private readonly \OCP\IDBConnection $db,
		private readonly ClaimLifecycleService $lifecycle,
		private readonly ApprovalService $approvals,
		private readonly AttachmentService $attachmentService,
		private readonly ClaimSerializer $serializer,
		private readonly LicenseService $license,
		private readonly SettingsService $settings,
		private readonly \OCA\ExpenseCheck\Service\LookupService $lookupService,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	private function contractCheck(): void
	{
		$v = (int)$this->request->getParam('contract_version', self::CONTRACT_FLOOR);
		if ($v < self::CONTRACT_FLOOR) {
			throw \OCA\ExpenseCheck\Exception\CompanionException::contractMismatch(self::CONTRACT_FLOOR, $v);
		}
	}

	/**
	 * Always reachable (exempt from the 402 gate): tells the app whether it
	 * may proceed — active license, seat held, contract floor.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		$this->contractCheck();
		$uid = $this->uid();
		$state = $this->license->state();
		return new JSONResponse([
			'ok' => true,
			'contract_version' => self::CONTRACT_VERSION,
			'contract_floor' => self::CONTRACT_FLOOR,
			'uid' => $uid,
			'license' => [
				'active' => $state !== null && $state['valid'],
				'valid_until' => $state['validUntil'] ?? null,
				'seat' => $this->license->hasSeat($uid),
			],
			'settings' => $this->settings->exportPublic(),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listClaims(): JSONResponse
	{
		$this->contractCheck();
		$status = $this->request->getParam('status');
		$rows = $this->claims->findByUser($this->uid(), is_string($status) && $status !== '' ? $status : null);
		return new JSONResponse(['ok' => true, 'claims' => array_map([ClaimSerializer::class, 'summary'], $rows)]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createClaim(): JSONResponse
	{
		$this->contractCheck();
		$b = $this->body();
		$requestKey = mb_substr(trim((string)($b['request_key'] ?? '')), 0, 64);
		if ($requestKey !== '') {
			$existing = $this->claims->findByRequestKey($this->uid() . ':' . $requestKey);
			if ($existing !== null) {
				return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($existing), 'deduplicated' => true]);
			}
		}
		// Identical body contract to web create() (AC-M1): lines supplied
		// atomically, same validation path — never silently dropped.
		$this->db->beginTransaction();
		try {
			$claim = $this->lifecycle->createDraft(
				$this->uid(),
				$this->str($b, 'title', 191),
				strtoupper($this->str($b, 'currency', 3)) ?: 'EUR',
				$requestKey !== '' ? $this->uid() . ':' . $requestKey : null,
			);
			if (isset($b['lines'])) {
				if (!is_array($b['lines'])) {
					throw new ValidationException(['lines' => 'invalid']);
				}
				foreach ($b['lines'] as $lp) {
					$this->lineService->addLine($claim, (array)$lp, (array)($lp['allocations'] ?? []), $this->uid());
				}
				$claim = $this->claims->findByIdRaw($claim->getId());
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim)], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function showClaim(): JSONResponse
	{
		$this->contractCheck();
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim)]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function submitClaim(): JSONResponse
	{
		$this->contractCheck();
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		$b = $this->body();
		$ver = isset($b['version']) ? (int)$b['version'] : null;
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($this->lifecycle->submit($claim, $this->uid(), $ver))]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function withdrawClaim(): JSONResponse
	{
		$this->contractCheck();
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		$b = $this->body();
		$ver = isset($b['version']) ? (int)$b['version'] : null;
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($this->lifecycle->withdraw($claim, $this->uid(), $ver))]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function decide(): JSONResponse
	{
		$this->contractCheck();
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		$b = $this->body();
		$ver = isset($b['version']) ? (int)$b['version'] : null;
		$claim = $this->approvals->decide($claim, (string)($b['decision'] ?? ''), $this->uid(), $b['note'] ?? null, $ver);
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim)]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function inbox(): JSONResponse
	{
		$this->contractCheck();
		return new JSONResponse([
			'ok' => true,
			'claims' => array_map([ClaimSerializer::class, 'summary'], $this->approvals->inboxFor($this->uid())),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function lookups(): JSONResponse
	{
		$this->contractCheck();
		return new JSONResponse(['ok' => true] + $this->lookupService->data());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function receiptsInbox(): JSONResponse
	{
		$this->contractCheck();
		return new JSONResponse([
			'ok' => true,
			'receipts' => array_map([ClaimSerializer::class, 'attachmentDto'], $this->attachmentService->inbox($this->uid())),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadReceipt(): JSONResponse
	{
		$this->contractCheck();
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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function fileReceipt(): JSONResponse
	{
		$this->contractCheck();
		$inbox = null;
		foreach ($this->attachments->findInbox($this->uid()) as $a) {
			if ($a->getId() === $this->routeId()) {
				$inbox = $a;
				break;
			}
		}
		if ($inbox === null) {
			throw new DomainException('ATTACHMENT_NOT_FOUND', 'Attachment not found.', 404);
		}
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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function uploadAttachment(): JSONResponse
	{
		$this->contractCheck();
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		if ($claim->getUserId() !== $this->uid()) {
			throw new ClaimNotFoundException();
		}
		if (!in_array($claim->getStatus(), [ClaimLifecycleService::STATUS_DRAFT, ClaimLifecycleService::STATUS_CHANGES_REQUESTED], true)) {
			throw new DomainException('CLAIM_READ_ONLY', 'Receipts can only be added to editable claims.', 409);
		}
		$file = $this->request->getUploadedFile('file');
		if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			throw new ValidationException(['file' => 'required']);
		}
		$line = null;
		$lineId = (int)$this->request->getParam('line_id', 0);
		if ($lineId > 0) {
			foreach ($this->lines->findByClaim($claim->getId()) as $l) {
				if ($l->getId() === $lineId) {
					$line = $l;
					break;
				}
			}
			if ($line === null) {
				throw new ValidationException(['line_id' => 'invalid']);
			}
		}
		$contents = file_get_contents($file['tmp_name']);
		if ($contents === false) {
			throw new ValidationException(['file' => 'invalid']);
		}
		$a = $this->attachmentService->store($this->uid(), $claim, $line, $line !== null ? 'line' : 'inbox', (string)$file['name'], (string)$file['type'], $contents);
		return new JSONResponse(['ok' => true, 'attachment' => ClaimSerializer::attachmentDto($a)], Http::STATUS_CREATED);
	}
}
