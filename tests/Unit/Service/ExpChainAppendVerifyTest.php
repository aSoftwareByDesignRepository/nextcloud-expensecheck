<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Service\ExpChainService;
use PHPUnit\Framework\TestCase;

/**
 * Instance-side chain: append + verify. Separate class so the pure-vector
 * file above stays constructor-free.
 */
final class ExpChainAppendVerifyTest extends TestCase
{
	private \OCA\ExpenseCheck\Db\ClaimEventMapper&\PHPUnit\Framework\MockObject\MockObject $events;
	private \OCP\Lock\ILockingProvider&\PHPUnit\Framework\MockObject\MockObject $locking;
	private ExpChainService $svc;

	protected function setUp(): void
	{
		$this->events = $this->createMock(\OCA\ExpenseCheck\Db\ClaimEventMapper::class);
		$this->locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$time = $this->createStub(\OCP\AppFramework\Utility\ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-12-01 10:00:00'));
		$this->svc = new ExpChainService($this->events, $this->locking, $time, new \Psr\Log\NullLogger());
	}

	private function event(int $seq, string $actor, string $event, ?string $from, ?string $to, ?array $payload, string $prev, int $tomb = 0): \OCA\ExpenseCheck\Db\ClaimEvent
	{
		$e = new \OCA\ExpenseCheck\Db\ClaimEvent();
		$e->setClaimId(7);
		$e->setSeq($seq);
		$e->setActor($actor);
		$e->setEvent($event);
		$e->setFromStatus($from);
		$e->setToStatus($to);
		$e->setPrevHash($prev);
		$e->setTombstoned($tomb);
		if ($tomb === 1) {
			$e->setPayload(null);
			$e->setPayloadHash('');
			$e->setEntryHash(ExpChainService::tombstoneHashFor($prev));
			return $e;
		}
		$e->setPayload($payload === null ? null : ExpChainService::jcs($payload));
		$ph = ExpChainService::payloadHashFor($payload);
		$e->setPayloadHash($ph);
		$e->setEntryHash(ExpChainService::entryHashFor($seq, $actor, $event, $from, $to, $ph, $prev));
		return $e;
	}

	public function testAppendGenesisUsesSeqOneAndGenesisPrev(): void
	{
		$this->events->method('findHead')->willReturn(null);
		$this->events->expects($this->once())->method('insert')->willReturnCallback(function (\OCA\ExpenseCheck\Db\ClaimEvent $e) {
			$this->assertSame(1, $e->getSeq());
			$this->assertSame(ExpChainService::GENESIS_HASH, $e->getPrevHash());
			$this->assertSame(0, $e->getTombstoned());
			$this->assertSame('submitted', $e->getEvent());
			$this->assertSame(64, strlen($e->getEntryHash()));
			$this->assertSame(64, strlen($e->getPayloadHash()));
			return $e;
		});
		$this->svc->append(7, 'submitted', 'alice', 'draft', 'submitted', ['total' => 5]);
	}

	public function testAppendContinuesFromHead(): void
	{
		$head = new \OCA\ExpenseCheck\Db\ClaimEvent();
		$head->setSeq(4); $head->setEntryHash(str_repeat('ab', 32));
		$this->events->method('findHead')->willReturn($head);
		$this->events->expects($this->once())->method('insert')->willReturnCallback(function (\OCA\ExpenseCheck\Db\ClaimEvent $e) {
			$this->assertSame(5, $e->getSeq());
			$this->assertSame(str_repeat('ab', 32), $e->getPrevHash());
			return $e;
		});
		$this->svc->append(7, 'approved', 'boss', 'pending', 'approved');
	}

	public function testAppendLockFailureThrowsJournalBusy(): void
	{
		$this->locking->method('acquireLock')->willThrowException(new \OCP\Lock\LockedException('x'));
		try {
			$this->svc->append(7, 'submitted', 'a', null, null);
			$this->fail('expected JOURNAL_BUSY');
		} catch (\OCA\ExpenseCheck\Exception\DomainException $e) {
			$this->assertSame('JOURNAL_BUSY', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testAppendReleasesLockOnSuccess(): void
	{
		$this->events->method('findHead')->willReturn(null);
		$this->events->method('insert')->willReturnArgument(0);
		$this->locking->expects($this->once())->method('releaseLock')
			->with('expensecheck/chain/7', \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
		$this->svc->append(7, 'x', 'a', null, null);
	}

	public function testAppendDbExceptionThrowsJournalBusy(): void
	{
		$this->events->method('findHead')->willReturn(null);
		$this->events->method('insert')->willThrowException($this->createStub(\OCP\DB\Exception::class));
		try {
			$this->svc->append(7, 'x', 'a', null, null);
			$this->fail('expected JOURNAL_BUSY');
		} catch (\OCA\ExpenseCheck\Exception\DomainException $e) {
			$this->assertSame('JOURNAL_BUSY', $e->getErrorCode());
		}
	}

	public function testVerifyEmptyChainIsOk(): void
	{
		$this->events->method('findAllForClaim')->willReturn([]);
		$v = $this->svc->verify(7);
		$this->assertSame('OK', $v['verdict']);
		$this->assertSame(0, $v['entries']);
		$this->assertNull($v['head']);
	}

	public function testVerifyValidChainOkWithHead(): void
	{
		$e1 = $this->event(1, 'alice', 'created', null, 'draft', ['t' => 1], ExpChainService::GENESIS_HASH);
		$e2 = $this->event(2, 'alice', 'submitted', 'draft', 'submitted', null, $e1->getEntryHash());
		$this->events->method('findAllForClaim')->willReturn([$e1, $e2]);
		$v = $this->svc->verify(7);
		$this->assertSame('OK', $v['verdict']);
		$this->assertSame(2, $v['entries']);
		$this->assertSame($e2->getEntryHash(), $v['head']);
		$this->assertNull($v['break_at_seq']);
	}

	public function testVerifySeqGapTampered(): void
	{
		$e1 = $this->event(1, 'a', 'created', null, 'draft', null, ExpChainService::GENESIS_HASH);
		$e3 = $this->event(3, 'a', 'x', null, null, null, 'whatever');
		$this->events->method('findAllForClaim')->willReturn([$e1, $e3]);
		$v = $this->svc->verify(7);
		$this->assertSame('TAMPERED', $v['verdict']);
		$this->assertSame('seq_gap', $v['reason']);
		$this->assertSame(2, $v['break_at_seq']); // expected seq, not stored
	}

	public function testVerifyPrevMismatchTampered(): void
	{
		$e1 = $this->event(1, 'a', 'created', null, 'draft', null, ExpChainService::GENESIS_HASH);
		$e2 = $this->event(2, 'a', 'submitted', 'draft', 'submitted', null, str_repeat('ff', 32));
		$this->events->method('findAllForClaim')->willReturn([$e1, $e2]);
		$v = $this->svc->verify(7);
		$this->assertSame('TAMPERED', $v['verdict']);
		$this->assertSame('prev_mismatch', $v['reason']);
		$this->assertSame(2, $v['break_at_seq']);
	}

	public function testVerifyRewrittenHashTampered(): void
	{
		$e1 = $this->event(1, 'a', 'created', null, 'draft', null, ExpChainService::GENESIS_HASH);
		$e1->setEntryHash(str_repeat('ee', 32)); // attacker rewrote hash field
		$this->events->method('findAllForClaim')->willReturn([$e1]);
		$v = $this->svc->verify(7);
		$this->assertSame('TAMPERED', $v['verdict']);
		$this->assertSame('hash_mismatch', $v['reason']);
	}

	public function testVerifyPayloadMismatchTampered(): void
	{
		$e1 = $this->event(1, 'a', 'created', null, 'draft', ['x' => 1], ExpChainService::GENESIS_HASH);
		$e1->setPayload('{"x":999}'); // payload edited without re-hashing
		$this->events->method('findAllForClaim')->willReturn([$e1]);
		$v = $this->svc->verify(7);
		$this->assertSame('TAMPERED', $v['verdict']);
		$this->assertSame('payload_mismatch', $v['reason']);
	}

	public function testVerifyUndecodablePayloadTampered(): void
	{
		$e1 = $this->event(1, 'a', 'created', null, 'draft', ['x' => 1], ExpChainService::GENESIS_HASH);
		$e1->setPayload('not-json{');
		$this->events->method('findAllForClaim')->willReturn([$e1]);
		$v = $this->svc->verify(7);
		$this->assertSame('TAMPERED', $v['verdict']);
		$this->assertSame('payload_decode', $v['reason']);
	}

	public function testVerifyValidTombstoneVerdict(): void
	{
		$e1 = $this->event(1, 'a', 'created', null, 'draft', null, ExpChainService::GENESIS_HASH);
		$t = $this->event(2, 'sys', 'erased', null, null, null, $e1->getEntryHash(), 1);
		$this->events->method('findAllForClaim')->willReturn([$e1, $t]);
		$v = $this->svc->verify(7);
		$this->assertSame('TOMBSTONED', $v['verdict']);
		$this->assertSame(1, $v['tombstoned']);
	}

	public function testVerifyBadTombstoneHashTampered(): void
	{
		$e1 = $this->event(1, 'a', 'created', null, 'draft', null, ExpChainService::GENESIS_HASH);
		$t = $this->event(2, 'sys', 'erased', null, null, null, $e1->getEntryHash(), 1);
		$t->setEntryHash(str_repeat('aa', 32));
		$this->events->method('findAllForClaim')->willReturn([$e1, $t]);
		$v = $this->svc->verify(7);
		$this->assertSame('TAMPERED', $v['verdict']);
		$this->assertSame('tombstone_hash_mismatch', $v['reason']);
	}
}
