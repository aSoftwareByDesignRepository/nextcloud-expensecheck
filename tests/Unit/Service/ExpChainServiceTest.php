<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Service\ExpChainService;
use PHPUnit\Framework\TestCase;

/**
 * Spec §11.5 — hash-chained append-only journal. Pins the canonicalization
 * vectors: if the hash input or canonical JSON changes, these tests break —
 * which is exactly what an auditor needs (chain integrity is the claim
 * history's only tamper-evidence).
 */
final class ExpChainServiceTest extends TestCase
{
	public function testGenesisHash(): void
	{
		$this->assertSame(str_repeat('0', 64), ExpChainService::GENESIS_HASH);
	}

	public function testJcsIsDeterministicAndSorted(): void
	{
		$a = ['b' => 2, 'a' => ['y' => 1, 'x' => [3, 2, 1]]];
		$b = ['a' => ['x' => [3, 2, 1], 'y' => 1], 'b' => 2];
		$this->assertSame(ExpChainService::jcs($a), ExpChainService::jcs($b));
		$this->assertSame('{"a":{"x":[3,2,1],"y":1},"b":2}', ExpChainService::jcs($a));
	}

	public function testJcsScalars(): void
	{
		$this->assertSame('{"x":true}', ExpChainService::jcs(['x' => true]));
		$this->assertSame('{"x":false}', ExpChainService::jcs(['x' => false]));
		$this->assertSame('{"x":null}', ExpChainService::jcs(['x' => null]));
		$this->assertSame('{"x":5}', ExpChainService::jcs(['x' => 5.0])); // integral float → int
		$this->assertSame('{"x":"ü"}', ExpChainService::jcs(['x' => 'ü'])); // UTF-8 passthrough
	}

	public function testPayloadHashBindsCanonicalForm(): void
	{
		$k1 = ['a' => 1, 'b' => 2];
		$k2 = ['b' => 2, 'a' => 1];
		$this->assertSame(ExpChainService::payloadHashFor($k1), ExpChainService::payloadHashFor($k2));
		$this->assertNotSame(ExpChainService::payloadHashFor($k1), ExpChainService::payloadHashFor(['a' => 2]));
		$this->assertSame(hash('sha256', '{}'), ExpChainService::payloadHashFor(null));
	}

	public function testEntryHashShape(): void
	{
		$h = ExpChainService::entryHashFor(1, 'alice', 'SUBMIT', 'draft', 'submitted',
			hash('sha256', '{}'), ExpChainService::GENESIS_HASH);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h);
	}

	public function testEntryHashBindsAllFields(): void
	{
		$ph = hash('sha256', '{}');
		$base = ExpChainService::entryHashFor(1, 'alice', 'SUBMIT', 'draft', 'submitted', $ph, ExpChainService::GENESIS_HASH);
		// Any field change must change the hash (tamper-evidence).
		$this->assertNotSame($base, ExpChainService::entryHashFor(2, 'alice', 'SUBMIT', 'draft', 'submitted', $ph, ExpChainService::GENESIS_HASH));
		$this->assertNotSame($base, ExpChainService::entryHashFor(1, 'mallory', 'SUBMIT', 'draft', 'submitted', $ph, ExpChainService::GENESIS_HASH));
		$this->assertNotSame($base, ExpChainService::entryHashFor(1, 'alice', 'APPROVE', 'draft', 'submitted', $ph, ExpChainService::GENESIS_HASH));
		$this->assertNotSame($base, ExpChainService::entryHashFor(1, 'alice', 'SUBMIT', 'submitted', 'approved', $ph, ExpChainService::GENESIS_HASH));
		$this->assertNotSame($base, ExpChainService::entryHashFor(1, 'alice', 'SUBMIT', 'draft', 'submitted', hash('sha256', 'x'), ExpChainService::GENESIS_HASH));
		$this->assertNotSame($base, ExpChainService::entryHashFor(1, 'alice', 'SUBMIT', 'draft', 'submitted', $ph, str_repeat('a', 64)));
	}

	public function testNullFieldsContributeEmptyBytes(): void
	{
		$ph = hash('sha256', '{}');
		$withNull = ExpChainService::entryHashFor(1, 'alice', 'CREATE', null, 'draft', $ph, ExpChainService::GENESIS_HASH);
		$withEmpty = ExpChainService::entryHashFor(1, 'alice', 'CREATE', '', 'draft', $ph, ExpChainService::GENESIS_HASH);
		$this->assertSame($withNull, $withEmpty);
	}

	public function testKnownVector(): void
	{
		// Golden vector: sha256(seq_be64 ‖ actor ‖ 00 ‖ event ‖ 00 ‖ from ‖ 00
		// ‖ to ‖ 00 ‖ payload_hash_hex ‖ prev_hash_hex). If this holds, the
		// implementation IS the spec — not a lookalike.
		$payload = ExpChainService::jcs(['a' => 1]); // {"a":1}
		$ph = hash('sha256', $payload);
		$expected = hash('sha256',
			pack('N2', 0, 1) . 'alice' . "\x00" . 'CREATE' . "\x00" . '' . "\x00" . 'draft' . "\x00"
			. $ph . ExpChainService::GENESIS_HASH);
		$actual = ExpChainService::entryHashFor(1, 'alice', 'CREATE', null, 'draft', $ph, ExpChainService::GENESIS_HASH);
		$this->assertSame($expected, $actual);
	}

	public function testTombstoneHashIsDistinct(): void
	{
		$prev = str_repeat('ab', 32);
		$t = ExpChainService::tombstoneHashFor($prev);
		$this->assertSame(hash('sha256', 'tombstoned' . $prev), $t);
		// tombstone can never collide with a real entry hash over same prev —
		// its preimage includes the literal prefix, which no entry frame does.
		$this->assertNotSame($t, ExpChainService::entryHashFor(1, null, '', null, null, '', $prev));
	}

	/** Kills i64be shift/direction mutants: seq crossing the 32-bit boundary. */
	public function testEntryHashSeqAbove32Bit(): void
	{
		$h32 = ExpChainService::entryHashFor(0xffffffff, 'a', 'e', null, null, str_repeat('0', 64), str_repeat('0', 64));
		$h33 = ExpChainService::entryHashFor(0x100000000, 'a', 'e', null, null, str_repeat('0', 64), str_repeat('0', 64));
		$h33plus = ExpChainService::entryHashFor(0x100000001, 'a', 'e', null, null, str_repeat('0', 64), str_repeat('0', 64));
		$this->assertNotSame($h32, $h33);
		$this->assertNotSame($h33, $h33plus);
		// Pinned vector: seq=0x100000001, actor 'a', event 'e'
		$buf = pack('N2', 1, 1) . 'a' . "\x00" . 'e' . "\x00" . "\x00" . "\x00" . str_repeat('0', 64) . str_repeat('0', 64);
		$this->assertSame(hash('sha256', $buf), $h33plus);
	}

	/** Kills floor→ceil/round, Identical, LessThan mutants in float collapse. */
	public function testJcsFloatHandling(): void
	{
		// Integral float collapses to int.
		$this->assertSame('{"n":2}', ExpChainService::jcs(['n' => 2.0]));
		// Negative integral float too.
		$this->assertSame('{"n":-2}', ExpChainService::jcs(['n' => -2.0]));
		// Non-integral float stays JSON-encoded.
		$this->assertSame('{"n":1.5}', ExpChainService::jcs(['n' => 1.5]));
		// ≥1e15 bypasses the int-collapse and goes through json_encode
		// (PHP prints integral floats as digit strings here).
		$this->assertSame('{"n":1000000000000000}', ExpChainService::jcs(['n' => 1.0e15]));
		// Small integral float inside a list — array_map must still recurse.
		$this->assertSame('{"a":[1,2.5,3]}', ExpChainService::jcs(['a' => [1.0, 2.5, 3.0]]));
	}

	/** Kills UnwrapArrayMap: non-scalar list elements must be canonicalized. */
	public function testJcsNestedListCanonicalizes(): void
	{
		$this->assertSame('{"a":[{"a":1,"b":2}]}', ExpChainService::jcs(['a' => [['b' => 2, 'a' => 1]]]));
	}

	/** Kills CastString removal on object keys (int keys → quoted strings). */
	public function testJcsIntKeysBecomeStrings(): void
	{
		$this->assertSame('{"1":"x","2":"y"}', ExpChainService::jcs([2 => 'y', 1 => 'x']));
	}

	/**
	 * Kills concat/coalesce mutants hidden by null fields: BOTH status fields
	 * populated means removing either operand changes the buf.
	 */
	public function testKnownVectorAllFields(): void
	{
		$ph = hash('sha256', ExpChainService::jcs(['x' => 'y']));
		$prev = str_repeat('ab', 32);
		$expected = hash('sha256',
			pack('N2', 0, 2) . 'bob' . "\x00" . 'TRANSITION' . "\x00" . 'submitted' . "\x00" . 'approved' . "\x00"
			. $ph . $prev);
		$this->assertSame($expected,
			ExpChainService::entryHashFor(2, 'bob', 'TRANSITION', 'submitted', 'approved', $ph, $prev));
		// Field order matters: swapped operands must change the hash.
		$swapped = hash('sha256',
			pack('N2', 0, 2) . 'bob' . "\x00" . 'TRANSITION' . "\x00" . 'approved' . "\x00" . 'submitted' . "\x00"
			. $ph . $prev);
		$this->assertNotSame($swapped,
			ExpChainService::entryHashFor(2, 'bob', 'TRANSITION', 'submitted', 'approved', $ph, $prev));
	}

	/** Kills BitwiseOr on json_encode flags: slashes + non-ASCII must survive. */
	public function testJcsUnicodeAndSlashKeys(): void
	{
		$out = ExpChainService::jcs(['a/b' => 'v', 'ä' => 'u']);
		$this->assertSame('{"a/b":"v","ä":"u"}', $out);
		// UTF-16 sort order: 'a/b' < 'ä' — keys must sort by code unit.
	}
}
