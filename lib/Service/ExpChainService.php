<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\ClaimEvent;
use OCA\ExpenseCheck\Db\ClaimEventMapper;
use OCA\ExpenseCheck\Exception\DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Per-claim hash-chained audit journal — spec §11.5, journal_version=1.
 *
 * Canonicalization contract (normative, byte-exact):
 *
 *   entry_hash  = sha256( seq_be64 ‖ actor_utf8 ‖ 0x00 ‖ event_utf8 ‖ 0x00
 *                         ‖ from_status ‖ 0x00 ‖ to_status ‖ 0x00
 *                         ‖ payload_hash ‖ prev_hash )
 *   payload_hash = sha256( JCS(payload) )
 *
 * where ‖ is byte concatenation, seq_be64 is the sequence number as 8-byte
 * big-endian unsigned, NULL fields contribute empty bytes, payload_hash and
 * prev_hash contribute their lowercase hex encodings as UTF-8 bytes, and JCS
 * is RFC 8785 canonical JSON (sorted keys, minimal whitespace, UTF-8).
 *
 * Genesis: the first entry of a claim chains from GENESIS_HASH (64 zero
 * nibbles) — deterministic on every database.
 *
 * Tombstone rewrite (erasure): payload → {"t":"erased","at":…} and
 * entry_hash = sha256("tombstoned" ‖ prev_hash_bytes? no — spec: sha256("tombstoned" ‖ prev_hash))
 * which verify() recognises and reports as TOMBSTONED, never TAMPERED.
 *
 * Concurrency: named lock `expensecheck/chain/<claimId>` + UNIQUE(claim_id,seq)
 * backstop → JOURNAL_BUSY. Lock order: claim row → chain head (deadlock-proof).
 */
class ExpChainService
{
	private const LOCK_PREFIX = 'expensecheck/chain/';
	public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';
	public const TOMBSTONE_PREFIX = 'tombstoned';

	public function __construct(
		private readonly ClaimEventMapper $events,
		private readonly ILockingProvider $locking,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	private static function i64be(int $v): string
	{
		return pack('N2', ($v >> 32) & 0xffffffff, $v & 0xffffffff);
	}

	/**
	 * RFC 8785 canonical JSON: sorted keys recursively, no insignificant
	 * whitespace, UTF-8 passthrough, shortest round-trip numbers.
	 * @param array<string,mixed> $payload
	 */
	public static function jcs(array $payload): string
	{
		// Payloads are JSON objects by contract. PHP can't distinguish [] as
		// list vs map — at the root we always mean the empty object {}.
		if ($payload === []) {
			return '{}';
		}
		return self::jcsValue($payload);
	}

	private static function jcsValue(mixed $value): string
	{
		if (is_array($value)) {
			if (array_is_list($value)) {
				return '[' . implode(',', array_map(self::jcsValue(...), $value)) . ']';
			}
			$keys = array_keys($value);
			// RFC 8785 sorts on UTF-16 code units; our payloads use BMP-safe keys,
			// for which binary order equals the required order.
			sort($keys, SORT_STRING);
			$pairs = [];
			foreach ($keys as $k) {
				$pairs[] = json_encode((string)$k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
					. ':' . self::jcsValue($value[$k]);
			}
			return '{' . implode(',', $pairs) . '}';
		}
		if (is_int($value) || is_float($value)) {
			// JCS number rule: ES6 shortest round-trip. Integers stay plain;
			// integral floats collapse to ints.
			if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
				return (string)(int)$value;
			}
			return json_encode($value);
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if ($value === null) {
			return 'null';
		}
		return json_encode((string)$value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	public static function payloadHashFor(?array $payload): string
	{
		return hash('sha256', self::jcs($payload ?? []));
	}

	/**
	 * The exact §11.5 concatenation. $payloadHashHex and $prevHashHex enter as
	 * their lowercase hex text (not raw bytes).
	 */
	public static function entryHashFor(
		int $seq,
		?string $actor,
		string $event,
		?string $fromStatus,
		?string $toStatus,
		string $payloadHashHex,
		string $prevHashHex,
	): string {
		$buf = self::i64be($seq)
			. ($actor ?? '') . "\x00"
			. $event . "\x00"
			. ($fromStatus ?? '') . "\x00"
			. ($toStatus ?? '') . "\x00"
			. $payloadHashHex
			. $prevHashHex;
		return hash('sha256', $buf);
	}

	public static function tombstoneHashFor(string $prevHashHex): string
	{
		return hash('sha256', self::TOMBSTONE_PREFIX . $prevHashHex);
	}

	/**
	 * Append an event to a claim's chain. Must be called inside the caller's
	 * DB transaction so the journal can never outlive the business write.
	 *
	 * @param array<string,mixed>|null $payload digest of the referenced entity
	 *        fields the event describes (spec §11.5 — binds chain to content)
	 */
	public function append(int $claimId, string $event, ?string $actor, ?string $fromStatus, ?string $toStatus, ?array $payload = null): ClaimEvent
	{
		$lock = self::LOCK_PREFIX . $claimId;
		try {
			$this->locking->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new DomainException('JOURNAL_BUSY', 'Claim journal is busy. Retry.', 409);
		}
		try {
			return $this->appendLocked($claimId, $event, $actor, $fromStatus, $toStatus, $payload);
		} finally {
			$this->locking->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function appendLocked(int $claimId, string $event, ?string $actor, ?string $fromStatus, ?string $toStatus, ?array $payload): ClaimEvent
	{
		$head = $this->events->findHead($claimId);
		$seq = $head === null ? 1 : $head->getSeq() + 1;
		$prev = $head === null ? self::GENESIS_HASH : $head->getEntryHash();
		$payloadHash = self::payloadHashFor($payload);

		$entry = new ClaimEvent();
		$entry->setClaimId($claimId);
		$entry->setSeq($seq);
		$entry->setActor($actor);
		$entry->setEvent($event);
		$entry->setFromStatus($fromStatus);
		$entry->setToStatus($toStatus);
		$entry->setPayload($payload === null ? null : self::jcs($payload));
		$entry->setPayloadHash($payloadHash);
		$entry->setPrevHash($prev);
		$entry->setEntryHash(self::entryHashFor($seq, $actor, $event, $fromStatus, $toStatus, $payloadHash, $prev));
		$entry->setTombstoned(0);
		$entry->setCreatedAt($this->timeFactory->getDateTime());
		try {
			return $this->events->insert($entry);
		} catch (\OCP\DB\Exception) {
			$this->logger->warning('expensecheck journal seq collision', ['claimId' => $claimId, 'seq' => $seq]);
			throw new DomainException('JOURNAL_BUSY', 'Claim journal is busy. Retry.', 409);
		}
	}

	/**
	 * Verify one claim's chain: seq contiguity, prev_hash links, entry_hash
	 * recomputation. Tombstoned rows check against the tombstone rewrite rule.
	 *
	 * @return array{verdict:string,entries:int,break_at_seq:?int,reason:?string,tombstoned:int,head:?string}
	 *         verdict ∈ OK | TAMPERED | TOMBSTONED
	 */
	public function verify(int $claimId): array
	{
		$entries = $this->events->findAllForClaim($claimId);
		$prev = self::GENESIS_HASH;
		$expectSeq = 1;
		$tombstoned = 0;
		$fail = static function (int $entries, ?int $break, ?string $reason, int $tomb): array {
			return ['verdict' => 'TAMPERED', 'entries' => $entries, 'break_at_seq' => $break,
				'reason' => $reason, 'tombstoned' => $tomb, 'head' => null];
		};
		foreach ($entries as $row) {
			$seq = $row->getSeq();
			if ($seq !== $expectSeq) {
				return $fail(count($entries), $expectSeq, 'seq_gap', $tombstoned);
			}
			if (!hash_equals($prev, (string)$row->getPrevHash())) {
				return $fail(count($entries), $seq, 'prev_mismatch', $tombstoned);
			}
			if ($row->getTombstoned() === 1) {
				$tombstoned++;
				if (!hash_equals(self::tombstoneHashFor($prev), (string)$row->getEntryHash())) {
					return $fail(count($entries), $seq, 'tombstone_hash_mismatch', $tombstoned);
				}
			} else {
				$storedPayload = $row->getPayload();
				$payloadArr = $storedPayload === null ? null : json_decode($storedPayload, true);
				if ($storedPayload !== null && !is_array($payloadArr)) {
					return $fail(count($entries), $seq, 'payload_decode', $tombstoned);
				}
				$payloadHash = self::payloadHashFor($payloadArr);
				if (!hash_equals($payloadHash, (string)$row->getPayloadHash())) {
					return $fail(count($entries), $seq, 'payload_mismatch', $tombstoned);
				}
				$expect = self::entryHashFor($seq, $row->getActor(), $row->getEvent(), $row->getFromStatus(), $row->getToStatus(), $payloadHash, $prev);
				if (!hash_equals($expect, (string)$row->getEntryHash())) {
					return $fail(count($entries), $seq, 'hash_mismatch', $tombstoned);
				}
			}
			$prev = (string)$row->getEntryHash();
			$expectSeq++;
		}
		$head = $entries === [] ? null : (string)end($entries)->getEntryHash();
		return ['verdict' => $tombstoned > 0 ? 'TOMBSTONED' : 'OK', 'entries' => count($entries),
			'break_at_seq' => null, 'reason' => null, 'tombstoned' => $tombstoned, 'head' => $head];
	}
}
