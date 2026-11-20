<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Db\CategoryMapper;
use OCA\ExpenseCheck\Db\ClaimEventMapper;
use OCA\ExpenseCheck\Db\ClaimLineMapper;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\CostCenterMapper;
use OCA\ExpenseCheck\Db\RateEntryMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\ApprovalService;
use OCA\ExpenseCheck\Service\AttachmentService;
use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCA\ExpenseCheck\Service\ClaimLineService;
use OCA\ExpenseCheck\Service\ClaimSerializer;
use OCA\ExpenseCheck\Service\ExpChainService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Claim API — web and app share this surface (mobile mirrors it under
 * /api/mobile/* with identical DTOs). Mutations are idempotent where a
 * request key is supplied; every claim-scoped route goes through the
 * visibility-scoped uniform-404 load.
 */
class ClaimApiController extends AApiController
{
	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly ClaimMapper $claims,
		private readonly ClaimLineMapper $lines,
		private readonly ClaimEventMapper $events,
		private readonly AttachmentMapper $attachments,
		private readonly CategoryMapper $categories,
		private readonly CostCenterMapper $costCenters,
		private readonly RateEntryMapper $rates,
		private readonly ClaimLifecycleService $lifecycle,
		private readonly ClaimLineService $lineService,
		private readonly ApprovalService $approvals,
		private readonly AttachmentService $attachmentService,
		private readonly ClaimSerializer $serializer,
		private readonly ExpChainService $chain,
		private readonly \OCA\ExpenseCheck\Service\LookupService $lookupService,
		private readonly \OCP\IDBConnection $db,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function list(): JSONResponse
	{
		$status = $this->request->getParam('status');
		$rows = $this->claims->findByUser($this->uid(), is_string($status) && $status !== '' ? $status : null);
		return new JSONResponse(['ok' => true, 'claims' => array_map([ClaimSerializer::class, 'summary'], $rows)]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function create(): JSONResponse
	{
		$b = $this->body();
		$requestKey = mb_substr(trim((string)($b['request_key'] ?? '')), 0, 64);
		if ($requestKey !== '') {
			$existing = $this->claims->findByRequestKey($this->uid() . ':' . $requestKey);
			if ($existing !== null) {
				return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($existing), 'deduplicated' => true]);
			}
		}
		$claim = $this->lifecycle->createDraft(
			$this->uid(),
			$this->str($b, 'title', 191),
			strtoupper($this->str($b, 'currency', 3)) ?: 'EUR',
			$requestKey !== '' ? $this->uid() . ':' . $requestKey : null,
		);
		// Lines may be supplied at create-time (web form + mobile companion both
		// send them atomically). Same validation path as the line endpoints.
		if (isset($b['lines'])) {
			if (!is_array($b['lines'])) {
				throw new ValidationException(['lines' => 'invalid']);
			}
			foreach ($b['lines'] as $lp) {
				$this->lineService->addLine($claim, (array)$lp, (array)($lp['allocations'] ?? []), $this->uid());
			}
			$claim = $this->claims->findByIdRaw($claim->getId());
		}
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim)], Http::STATUS_CREATED);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function show(): JSONResponse
	{
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim)]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function update(): JSONResponse
	{
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		if ($claim->getUserId() !== $this->uid()) {
			throw new ClaimNotFoundException();
		}
		if (!in_array($claim->getStatus(), [ClaimLifecycleService::STATUS_DRAFT, ClaimLifecycleService::STATUS_CHANGES_REQUESTED], true)) {
			throw new DomainException('CLAIM_READ_ONLY', 'Only drafts and change-requested claims are editable.', 409);
		}
		$b = $this->body();
		if (isset($b['version']) && (int)$b['version'] !== $claim->getVersion()) {
			throw new DomainException('VERSION_CONFLICT', 'Claim changed since you loaded it — reload and retry.', 409);
		}
		// One outer transaction: inner addLine/commits become nesting-level
		// no-ops (Doctrine), so a mid-loop failure rolls back ALL line writes
		// instead of leaving a partially-applied set. Inner rollback marks
		// rollback-only → outer commit fails closed.
		$this->db->beginTransaction();
		try {
			if (isset($b['title'])) {
				$title = $this->str($b, 'title', 191);
				if ($title === '') {
					throw new ValidationException(['title' => 'required']);
				}
				$claim->setTitle($title);
			}
			if (isset($b['purpose'])) {
				$claim->setPurpose($this->str($b, 'purpose', 2000) ?: null);
			}
			if (isset($b['currency'])) {
				$cur = strtoupper($this->str($b, 'currency', 3));
				if (!preg_match('/^[A-Z]{3}$/', $cur)) {
					throw new ValidationException(['currency' => 'invalid']);
				}
				$claim->setCurrency($cur);
			}
			if (isset($b['lines']) || isset($b['line'])) {
				$lineParams = $b['lines'] ?? [$b['line']];
				if (!is_array($lineParams)) {
					throw new ValidationException(['lines' => 'invalid']);
				}
				// Full line-set replacement is NOT allowed — lines are addressed
				// individually via add/update/delete endpoints below. A 'lines'
				// array here is treated as "add these lines".
				foreach ($lineParams as $lp) {
					$this->lineService->addLine($claim, (array)$lp, (array)($lp['allocations'] ?? []), $this->uid());
				}
			}
			if (isset($b['line_id']) && isset($b['action'])) {
				$this->applyLineAction($claim, $b);
			}
			$claim = $this->lifecycle->casUpdate($claim);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim)]);
	}

	private function applyLineAction(\OCA\ExpenseCheck\Db\Claim $claim, array $b): void
	{
		$lineId = (int)$b['line_id'];
		$line = null;
		foreach ($this->lines->findByClaim($claim->getId()) as $l) {
			if ($l->getId() === $lineId) {
				$line = $l;
				break;
			}
		}
		if ($line === null) {
			throw new ValidationException(['line_id' => 'invalid']);
		}
		$action = (string)$b['action'];
		if ($action === 'delete') {
			$this->lineService->deleteLine($claim, $line, $this->uid());
		} elseif ($action === 'update') {
			$this->lineService->updateLine($claim, $line, (array)($b['line'] ?? $b), (array)($b['allocations'] ?? []), $this->uid());
		} else {
			throw new ValidationException(['action' => 'invalid']);
		}
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function submit(): JSONResponse
	{
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		$b = $this->body();
		$ver = isset($b['version']) ? (int)$b['version'] : null;
		$claim = $this->lifecycle->submit($claim, $this->uid(), $ver);
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim)]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function withdraw(): JSONResponse
	{
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		$b = $this->body();
		$claim = $this->lifecycle->withdraw($claim, $this->uid(), isset($b['version']) ? (int)$b['version'] : null);
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim)]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function clone(): JSONResponse
	{
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		$new = $this->lifecycle->cloneToNewDraft($claim, $this->uid());
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($new)], Http::STATUS_CREATED);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function revert(): JSONResponse
	{
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		$b = $this->body();
		$claim = $this->lifecycle->revert($claim, $this->uid(), $this->str($b, 'reason', 2000),
			isset($b['version']) ? (int)$b['version'] : null);
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim)]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function decide(): JSONResponse
	{
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		$b = $this->body();
		$ver = isset($b['version']) ? (int)$b['version'] : null;
		$claim = $this->approvals->decide($claim, (string)($b['decision'] ?? ''), $this->uid(), $b['note'] ?? null, $ver);
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim)]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function uploadAttachment(): JSONResponse
	{
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
		$lineId = (int)($this->request->getParam('line_id') ?? 0);
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
		$a = $this->attachmentService->store(
			$this->uid(), $claim, $line, $line !== null ? 'line' : 'inbox',
			(string)$file['name'], (string)$file['type'], $contents
		);
		if ($line === null) {
			// No line → inbox capture, filed later explicitly.
			$this->chain->append($claim->getId(), 'receipt_captured', $this->uid(), null, null,
				['attachment_id' => $a->getId()]);
		}
		return new JSONResponse(['ok' => true, 'attachment' => ClaimSerializer::attachmentDto($a)], Http::STATUS_CREATED);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function downloadAttachment()
	{
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		$attId = (int)$this->request->getParam('attachmentId');
		foreach ($this->attachments->findByClaim($claim->getId()) as $a) {
			if ($a->getId() === $attId) {
				return new DataDownloadResponse(
					$this->attachmentService->contents($a, $this->uid()),
					$a->getName(),
					$a->getMime()
				);
			}
		}
		throw new DomainException('ATTACHMENT_NOT_FOUND', 'Attachment not found.', 404);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function deleteAttachment(): JSONResponse
	{
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		if ($claim->getUserId() !== $this->uid()) {
			throw new ClaimNotFoundException();
		}
		if (!in_array($claim->getStatus(), [ClaimLifecycleService::STATUS_DRAFT, ClaimLifecycleService::STATUS_CHANGES_REQUESTED], true)) {
			throw new DomainException('CLAIM_READ_ONLY', 'Receipts can only be removed from editable claims.', 409);
		}
		$attId = (int)$this->request->getParam('attachmentId');
		foreach ($this->attachments->findByClaim($claim->getId()) as $a) {
			if ($a->getId() === $attId) {
				$this->attachmentService->delete($a, $this->uid());
				return new JSONResponse(['ok' => true]);
			}
		}
		throw new DomainException('ATTACHMENT_NOT_FOUND', 'Attachment not found.', 404);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function events(): JSONResponse
	{
		$claim = $this->lifecycle->loadVisible($this->routeId(), $this->uid());
		$rows = $this->events->findAllForClaim($claim->getId());
		return new JSONResponse(['ok' => true, 'events' => array_map([ClaimSerializer::class, 'eventDto'], $rows)]);
	}

	/**
	 * Lookups for claim forms: active categories, active cost centers,
	 * currently-effective rates. Claimant-visible (access-door scope), no
	 * admin data leaks — categories/cost centers/rates are needed to fill
	 * lines.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function lookups(): JSONResponse
	{
		return new JSONResponse(['ok' => true] + $this->lookupService->data());
	}
}
