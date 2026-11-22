<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\SettlementBatch;
use OCA\ExpenseCheck\Db\SettlementBatchMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Settlement export contract (spec §11.10 + edge 44).
 *
 * - `format=csv|ods`; two channels per batch (reimbursable/nonreimbursable).
 * - Row count ≤ `export_async_rows` → synchronous payload.
 * - Above threshold → pending entry in the internal `export_job_state`
 *   appconfig map + a `request_id`/`token` pair. `ExportGenerateJob`
 *   renders and stamps `file_hash` on the batch (sha256 of the canonical
 *   payload — deterministic, not of container bytes whose zip mtimes
 *   differ); `EXPORT_NOT_READY` until then.
 * - Download: single-use, 15-min window measured from `ready_at` — the
 *   bytes are REGENERATED on serve (spec: artifacts are never persisted;
 *   the batch + journal are the book of record) so nothing sits on disk
 *   to leak. Consumed and expired entries are deleted.
 * - Filenames carry no PII: settlement-{batch}-{channel}.{ext}.
 */
class ExportService
{
	public const FORMATS = ['csv', 'ods'];
	public const CHANNELS = ['reimbursable', 'nonreimbursable'];
	private const TOKEN_TTL = 900;
	private const PENDING_TTL = 86400;

	public function __construct(
		private readonly SettlementService $settlement,
		private readonly SettlementBatchMapper $batches,
		private readonly SettingsService $settings,
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
	}

	/** Serialize read-modify-write of the export_job_state appconfig map. */
	private function locked(callable $fn): mixed
	{
		$this->locking->acquireLock('exp_export_state', ILockingProvider::LOCK_EXCLUSIVE);
		try {
			return $fn();
		} finally {
			$this->locking->releaseLock('exp_export_state', ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * @return array{async:false,payload:string,filename:string,mime:string,file_hash:string}
	 *      |array{async:true,request_id:string,token:string}
	 */
	public function requestExport(SettlementBatch $batch, string $channel, string $format, string $uid): array
	{
		if (!in_array($format, self::FORMATS, true) || !in_array($channel, self::CHANNELS, true)) {
			throw new DomainException('VALIDATION_FAILED', 'Unknown export format/channel.', 422);
		}
		$rowCount = count($this->settlement->exportRows($batch->getId())[$channel]);
		if ($rowCount <= max(1, $this->settings->getInt(SettingsService::KEY_EXPORT_ASYNC_ROWS))) {
			$payload = $this->render($batch, $channel, $format);
			return [
				'async' => false,
				'payload' => $payload['bytes'],
				'filename' => $this->filename($batch->getId(), $channel, $format),
				'mime' => $payload['mime'],
				'file_hash' => $payload['hash'],
			];
		}
		$requestId = 'exr-' . bin2hex(random_bytes(12));
		$token = 'expt-' . bin2hex(random_bytes(24));
		$this->locked(function () use ($requestId, $batch, $channel, $format, $uid, $token): void {
			$state = $this->loadState();
			$state[$requestId] = [
				'batch_id' => $batch->getId(),
				'channel' => $channel,
				'format' => $format,
				'requester' => $uid,
				'state' => 'pending',
				'token_hash' => hash('sha256', $token),
				'created_at' => $this->timeFactory->getTime(),
			];
			$this->saveState($state);
		});
		return ['async' => true, 'request_id' => $requestId, 'token' => $token];
	}

	/**
	 * @return array{state:string,ready_at:?int,expires_at:?int}
	 */
	public function poll(string $requestId): array
	{
		$entry = $this->loadState()[$requestId] ?? null;
		if ($entry === null) {
			throw new DomainException('NOT_FOUND', 'Export not found.', 404);
		}
		if ($entry['state'] === 'ready') {
			if (($entry['expires_at'] ?? 0) < $this->timeFactory->getTime()) {
				$this->drop($requestId);
				throw new DomainException('NOT_FOUND', 'Export expired.', 404);
			}
			return ['state' => 'ready', 'ready_at' => $entry['ready_at'], 'expires_at' => $entry['expires_at']];
		}
		if ($entry['state'] === 'failed') {
			throw new DomainException('EXPORT_FAILED', 'The export could not be generated.', 409);
		}
		throw new DomainException('EXPORT_NOT_READY', 'Export is still being generated.', 409);
	}

	/**
	 * Single-use download: token + freshness gate, then regenerate and
	 * consume. Finance role is asserted by the controller before this.
	 * @return array{payload:string,filename:string,mime:string}
	 */
	public function download(string $requestId, string $token, string $uid): array
	{
		$entry = $this->locked(function () use ($requestId, $token, $uid): ?array {
			$state = $this->loadState();
			$entry = $state[$requestId] ?? null;
			if ($entry === null || $entry['state'] !== 'ready'
				|| ($entry['expires_at'] ?? 0) < $this->timeFactory->getTime()
				|| $entry['requester'] !== $uid
				|| !hash_equals((string)$entry['token_hash'], hash('sha256', $token))) {
				return null;
			}
			unset($state[$requestId]); // single-use — consumed before bytes leave
			$this->saveState($state);
			return $entry;
		});
		if ($entry === null) {
			throw new DomainException('NOT_FOUND', 'Export not found.', 404);
		}
		$batch = $this->batches->findById((int)$entry['batch_id']);
		if ($batch === null) {
			throw new DomainException('BATCH_NOT_FOUND', 'Batch not found.', 404);
		}
		$payload = $this->render($batch, $entry['channel'], $entry['format']);
		return [
			'payload' => $payload['bytes'],
			'filename' => $this->filename($batch->getId(), $entry['channel'], $entry['format']),
			'mime' => $payload['mime'],
		];
	}

	/**
	 * Cron sweep: finish pending exports (render once → file_hash on the
	 * batch, ready_at/expires set) and drop stale entries.
	 * @return array{finished:int,expired:int,ready_uids:list<string>}
	 */
	public function runPending(): array
	{
		return $this->locked(function (): array {
			return $this->runPendingInner();
		});
	}

	/** @return array{finished:int,expired:int,ready_uids:list<string>} */
	private function runPendingInner(): array
	{
		$state = $this->loadState();
		$now = $this->timeFactory->getTime();
		$finished = 0;
		$expired = 0;
		$readyUids = [];
		foreach ($state as $id => $entry) {
			if ((($entry['state'] === 'pending' || $entry['state'] === 'failed')
					&& ($entry['created_at'] + self::PENDING_TTL) < $now)
				|| ($entry['state'] === 'ready' && ($entry['expires_at'] ?? 0) < $now)) {
				unset($state[$id]);
				$expired++;
				continue;
			}
			if ($entry['state'] !== 'pending') {
				continue;
			}
			$batch = $this->batches->findById((int)$entry['batch_id']);
			if ($batch === null) {
				unset($state[$id]);
				$expired++;
				continue;
			}
			try {
				$payload = $this->render($batch, $entry['channel'], $entry['format']);
				$batch->setFileHash($payload['hash']);
				$this->batches->update($batch);
				$state[$id]['state'] = 'ready';
				$state[$id]['ready_at'] = $now;
				$state[$id]['expires_at'] = $now + self::TOKEN_TTL;
				$finished++;
				if (is_string($entry['requester'] ?? null) && $entry['requester'] !== '') {
					$readyUids[] = $entry['requester'];
				}
			} catch (\Throwable $e) {
				// non-fatal per entry: one bad export must not stall the sweep
				// — mark it failed so poll() reports the truth and a broken
				// render is not retried on every cron run.
				$this->logger->warning('expensecheck async export failed', [
					'request_id' => $id, 'exception' => $e,
				]);
				$state[$id]['state'] = 'failed';
			}
		}
		$this->saveState($state);
		return ['finished' => $finished, 'expired' => $expired, 'ready_uids' => array_values(array_unique($readyUids))];
	}

	/** @return array{bytes:string,mime:string,hash:string} */
	private function render(SettlementBatch $batch, string $channel, string $format): array
	{
		$rows = $this->settlement->exportRows($batch->getId())[$channel];
		$header = ['claim_no', 'owner', 'title', 'currency', 'amount_minor', 'submitted', 'decided', 'paid_ref', 'paid_at'];
		$body = array_map(fn ($r) => [
			$r['claim_no'], $r['owner'], $r['title'], $r['currency'],
			number_format($r['amount_minor'] / 100, 2, '.', ''),
			$r['submitted'], $r['decided'], $r['paid_ref'], $r['paid_at'],
		], $rows);
		if ($format === 'ods') {
			$createdIso = $batch->getCreatedAt()?->format('Y-m-d\TH:i:s\Z');
			$bytes = (new OdsBuilder())->build(
				[['name' => 'settlement', 'columns' => $header, 'rows' => $body]],
				'ExpenseCheck', $createdIso);
			// Hash the canonical payload — zip mtimes are nondeterministic.
			$hash = hash('sha256', json_encode([$header, $body], JSON_UNESCAPED_UNICODE));
			return ['bytes' => $bytes, 'mime' => 'application/vnd.oasis.opendocument.spreadsheet', 'hash' => $hash];
		}
		$bytes = CsvExporter::render($header, $body);
		return ['bytes' => $bytes, 'mime' => 'text/csv', 'hash' => hash('sha256', $bytes)];
	}

	private function filename(int $batchId, string $channel, string $format): string
	{
		return 'settlement-' . $batchId . '-' . $channel . '.' . $format;
	}

	/** @return array<string,array<string,mixed>> */
	private function loadState(): array
	{
		$raw = $this->settings->get(SettingsService::KEY_EXPORT_JOB_STATE);
		$state = $raw === '' ? [] : json_decode($raw, true);
		return is_array($state) ? $state : [];
	}

	/** @param array<string,array<string,mixed>> $state */
	private function saveState(array $state): void
	{
		$this->settings->set(SettingsService::KEY_EXPORT_JOB_STATE,
			$state === [] ? '' : json_encode($state, JSON_UNESCAPED_SLASHES));
	}

	private function drop(string $requestId): void
	{
		$state = $this->loadState();
		unset($state[$requestId]);
		$this->saveState($state);
	}
}
