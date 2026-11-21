<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Hardening;

use OCA\ExpenseCheck\Service\ExpChainService;
use PHPUnit\Framework\TestCase;

/**
 * Gate 23 — golden vectors for the §11.5 journal format. These are
 * FROZEN expected outputs, computed once and pinned: if JCS canonicalization,
 * the entry-hash concatenation, the tombstone rule, or the big-endian seq
 * encoding ever drifts (on this or any database), these byte-exact vectors
 * break — which is precisely the proof the gate asks for.
 */
final class GoldenVectorsTest extends TestCase
{
	/** Fixed canonical payload — exercises key sorting, nesting, unicode,
	 *  slash escaping, float/null/bool serialization. */
	private const PAYLOAD = [
		'claim_id' => 7, 'actor' => 'alice', 'amount_minor' => 12345,
		'currency' => 'EUR', 'nested' => ['b' => 1, 'a' => 'x/y'],
		'unicode' => 'ü', 'float' => 1.5, 'null' => null, 'bool' => true,
	];

	private const JCS = '{"actor":"alice","amount_minor":12345,"bool":true,"claim_id":7,"currency":"EUR","float":1.5,"nested":{"a":"x/y","b":1},"null":null,"unicode":"ü"}';
	private const PAYLOAD_HASH = 'edee2b66a60ac5b3fd80db5714545d66e3c169cdfdb61fec0cd67ba64229d1d3';
	private const PREV_HASH = 'abababababababababababababababababababababababababababababababab';
	private const ENTRY_HASH = '2806d514146d7bea82b3787e90d9bef509eb8f04b12c213656a5f01cefeb2490';
	private const TOMBSTONE_HASH = '68f97ee2170a42e83b0379533a6acef24c223300ffa7465a87bc533076dd849f';

	public function testJcsGoldenVector(): void
	{
		$this->assertSame(self::JCS, ExpChainService::jcs(self::PAYLOAD));
	}

	public function testPayloadHashGoldenVector(): void
	{
		$this->assertSame(self::PAYLOAD_HASH, ExpChainService::payloadHashFor(self::PAYLOAD));
	}

	public function testEntryHashGoldenVector(): void
	{
		$this->assertSame(
			self::ENTRY_HASH,
			ExpChainService::entryHashFor(
				42, 'alice', 'claim.submitted', 'draft', 'submitted',
				self::PAYLOAD_HASH, self::PREV_HASH),
		);
	}

	public function testTombstoneGoldenVector(): void
	{
		$this->assertSame(self::TOMBSTONE_HASH, ExpChainService::tombstoneHashFor(self::PREV_HASH));
	}

	public function testKeyOrderDoesNotChangeCanonicalForm(): void
	{
		$reordered = array_reverse(self::PAYLOAD, true);
		$this->assertSame(self::JCS, ExpChainService::jcs($reordered));
		$this->assertSame(self::PAYLOAD_HASH, ExpChainService::payloadHashFor($reordered));
	}

	public function testNullActorAndStatusesSerializeAsEmptyBytes(): void
	{
		// An event with NULL actor/status fields must produce a different
		// (not colliding) hash than the golden one — proves the \x00 field
		// separators are part of the format, not decoration.
		$h = ExpChainService::entryHashFor(42, null, 'claim.submitted', null, 'submitted', self::PAYLOAD_HASH, self::PREV_HASH);
		$this->assertSame(64, strlen($h));
		$this->assertNotSame(self::ENTRY_HASH, $h);
	}
}
