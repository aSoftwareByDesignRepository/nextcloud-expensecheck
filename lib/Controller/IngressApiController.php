<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Controller;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\ClaimLifecycleService;
use OCA\ExpenseCheck\Service\ClaimLineService;
use OCA\ExpenseCheck\Service\ClaimSerializer;
use OCA\ExpenseCheck\Service\MoneyMathService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Sibling draft-ingress (`check:expense-claims`, spec §15): MobilityCheck
 * drive-segments and other Check apps may propose draft lines into a NEW
 * draft claim owned by the calling user. Provenance is recorded on
 * source_app/source_ref; nothing from a sibling is trusted — every field is
 * revalidated here exactly as if typed into the web form.
 */
class IngressApiController extends AApiController
{
	public function __construct(
		IRequest $request,
		AccessControlService $access,
		private readonly ClaimLifecycleService $lifecycle,
		private readonly ClaimLineService $lineService,
		private readonly ClaimSerializer $serializer,
	) {
		parent::__construct(Application::APP_ID, $request, $access);
	}

	/**
	 * Create a draft claim + lines on behalf of the caller. The sibling is
	 * identified by X-Check-Source (advisory label only — auth is the NC
	 * session; a logged-in user may always create their own drafts).
	 *
	 * Body: {title, currency?, lines:[{line params…}], request_key?}
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function createDraft(): JSONResponse
	{
		$b = $this->body();
		$source = mb_substr((string)$this->request->getHeader('X-Check-Source'), 0, 32);
		$sourceRef = mb_substr((string)$this->request->getHeader('X-Check-Source-Ref'), 0, 64);
		$lines = $b['lines'] ?? [];
		if (!is_array($lines) || $lines === [] || count($lines) > 50) {
			throw new ValidationException(['lines' => 'invalid']);
		}
		$requestKey = mb_substr(trim((string)($b['request_key'] ?? '')), 0, 64);
		if ($requestKey !== '') {
			$existing = \OCP\Server::get(\OCA\ExpenseCheck\Db\ClaimMapper::class)->findByRequestKey($this->uid() . ':ingress:' . $requestKey);
			if ($existing !== null) {
				return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($existing), 'deduplicated' => true]);
			}
		}
		$claim = $this->lifecycle->createDraft(
			$this->uid(),
			$this->str($b, 'title', 191) !== '' ? $this->str($b, 'title', 191) : 'Imported claim',
			strtoupper($this->str($b, 'currency', 3)) ?: 'EUR',
			$requestKey !== '' ? $this->uid() . ':ingress:' . $requestKey : null,
		);
		foreach ($lines as $lp) {
			$lp = (array)$lp;
			if ($source !== '') {
				$lp['source_app'] = $source;
				if ($sourceRef !== '') {
					$lp['source_ref'] = $sourceRef;
				}
			}
			$this->lineService->addLine($claim, $lp, (array)($lp['allocations'] ?? []), $this->uid());
		}
		return new JSONResponse(['ok' => true, 'claim' => $this->serializer->detail($claim)], Http::STATUS_CREATED);
	}
}
