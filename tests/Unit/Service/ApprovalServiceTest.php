<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimMapper;
use OCA\ExpenseCheck\Db\DelegateMapper;
use OCA\ExpenseCheck\Db\StepDecision;
use OCA\ExpenseCheck\Db\StepDecisionMapper;
use OCA\ExpenseCheck\Exception\ClaimNotFoundException;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AccessControlService;
use OCA\ExpenseCheck\Service\ApprovalService;
use OCA\ExpenseCheck\Service\ChainRoutingService;
use OCA\ExpenseCheck\Service\ExpChainService;
use OCA\ExpenseCheck\Service\NotificationService;
use OCA\ExpenseCheck\Service\PostCommitEffectQueue;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ApprovalServiceTest extends TestCase
{
	private StepDecisionMapper&MockObject $decisions;
	private ClaimMapper&MockObject $claims;
	private ChainRoutingService&MockObject $routing;
	private IDBConnection&MockObject $db;
	private ExpChainService&MockObject $chain;
	private SettingsService&MockObject $settings;
	private ApprovalService $svc;

	protected function setUp(): void
	{
		$this->decisions = $this->createStub(StepDecisionMapper::class);
		$this->claims = $this->createStub(ClaimMapper::class);
		$this->routing = $this->createStub(ChainRoutingService::class);
		$this->db = $this->createStub(IDBConnection::class);
		$this->settings = $this->createStub(SettingsService::class);
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-01-01 00:00:00'));
		$this->svc = new ApprovalService(
			$this->decisions,
			$this->claims,
			$this->createStub(DelegateMapper::class),
			$this->routing,
			$this->chain = $this->createStub(ExpChainService::class),
			$this->createStub(AccessControlService::class),
			$this->db,
			$time,
			new PostCommitEffectQueue($this->createStub(IDBConnection::class), $this->createStub(LoggerInterface::class)),
			$this->createStub(NotificationService::class),
			$this->settings,
		);
	}

	private function submittedClaim(string $owner = 'alice'): Claim
	{
		$c = new Claim();
		$c->setId(9);
		$c->setUserId($owner);
		$c->setStatus('submitted');
		$c->setVersion(4);
		$c->setCurrentStep(1);
		$c->setChainSnapshot(json_encode(['chain_id' => 1, 'steps' => [
			['step_no' => 1, 'resolved_uids' => ['boss']],
		]]));
		return $c;
	}

	public function testInvalidDecisionRejected(): void
	{
		try {
			$this->svc->decide($this->submittedClaim(), 'maybe', 'boss', null);
			$this->fail('invalid decision accepted');
		} catch (ValidationException $e) {
			$this->assertSame('invalid', $e->getFields()['decision']);
		}
	}

	public function testRejectRequiresNote(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->decide($this->submittedClaim(), 'rejected', 'boss', '');
	}

	public function testRequestChangesRequiresNote(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->decide($this->submittedClaim(), 'changes_requested', 'boss', 'no');
	}

	/** Kills mb_strlen→strlen: 'ää' is 2 chars / 4 bytes — must be rejected. */
	public function testMultibyteNoteLengthCountedInChars(): void
	{
		try {
			$this->svc->decide($this->submittedClaim(), 'rejected', 'boss', 'ää');
			$this->fail('2-char multibyte note accepted');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['note']);
		}
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$this->decisions->method('findForStep')->willReturn(null);
		$this->claims->method('updateWithVersion')->willReturn(true);
		$this->decisions->method('insert')->willReturnArgument(0);
		$claim = $this->svc->decide($this->submittedClaim(), 'rejected', 'boss', 'äää');
		$this->assertSame('rejected', $claim->getStatus());
		$this->assertSame('äää', $claim->getDecisionNote());
	}

	public function testApproveWithoutNoteAllowed(): void
	{
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$this->decisions->method('findForStep')->willReturn(null);
		// Post-conditions: version +1 CAS, transaction boundary, journal append,
		// decidedBy/At set (final step), currentStep reset to 0, note nulled.
		$this->claims->expects($this->once())->method('updateWithVersion')
			->with($this->callback(fn ($c) => $c->getVersion() === 5), 4)
			->willReturn(true);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$inserted = null;
		$this->decisions->method('insert')->willReturnCallback(function ($d) use (&$inserted) {
			$inserted = $d;
			return $d;
		});
		$claim = $this->svc->decide($this->submittedClaim(), 'approved', 'boss', null);
		$this->assertSame('approved', $claim->getStatus());
		$this->assertSame(5, $claim->getVersion());
		$this->assertSame(0, $claim->getCurrentStep());
		$this->assertSame('boss', $claim->getDecidedBy());
		$this->assertNotNull($claim->getDecidedAt());
		$this->assertNotNull($claim->getUpdatedAt());
		$this->assertNull($claim->getDecisionNote()); // empty note stored as null
		$this->assertInstanceOf(StepDecision::class, $inserted);
		$this->assertSame('boss', $inserted->getDecidedBy());
		$this->assertSame('approved', $inserted->getDecision());
		$this->assertNotNull($inserted->getCreatedAt());
		$this->assertNull($inserted->getNote());
	}

	/** Final approve WITH a note must store it on the claim. */
	public function testApproveWithNoteStoresNote(): void
	{
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$this->decisions->method('findForStep')->willReturn(null);
		$this->claims->method('updateWithVersion')->willReturn(true);
		$this->decisions->method('insert')->willReturnArgument(0);
		$claim = $this->svc->decide($this->submittedClaim(), 'approved', 'boss', 'looks fine');
		$this->assertSame('approved', $claim->getStatus());
		$this->assertSame('looks fine', $claim->getDecisionNote());
	}

	public function testVersionConflictStatusCode(): void
	{
		try {
			$this->svc->decide($this->submittedClaim(), 'approved', 'boss', null, 999);
			$this->fail('stale version accepted');
		} catch (DomainException $e) {
			$this->assertSame('VERSION_CONFLICT', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testNotPendingStatusCode(): void
	{
		$claim = $this->submittedClaim();
		$claim->setStatus('approved');
		try {
			$this->svc->decide($claim, 'approved', 'boss', null);
			$this->fail('approved claim re-decided');
		} catch (DomainException $e) {
			$this->assertSame('CLAIM_NOT_PENDING', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testNotPendingRejected(): void
	{
		$draft = $this->submittedClaim();
		$draft->setStatus('draft');
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Claim is not awaiting a decision.');
		$this->svc->decide($draft, 'approved', 'boss', null);
	}

	public function testEmptyStepsSnapshotFailsClosed(): void
	{
		$claim = $this->submittedClaim();
		$claim->setChainSnapshot(json_encode(['chain_id' => 1, 'steps' => []]));
		try {
			$this->svc->decide($claim, 'approved', 'boss', null);
			$this->fail('empty steps accepted');
		} catch (DomainException $e) {
			$this->assertSame('CHAIN_UNROUTABLE', $e->getErrorCode());
			$this->assertSame(500, $e->getHttpStatus());
		}
	}

	public function testMissingChainSnapshotFailsClosed(): void
	{
		$claim = $this->submittedClaim();
		$claim->setChainSnapshot(null);
		$this->expectException(DomainException::class);
		$this->svc->decide($claim, 'approved', 'boss', null);
	}

	public function testUnauthorizedApproverGetsUniform404(): void
	{
		$this->routing->method('decisionRights')->willReturn(['allowed' => false, 'on_behalf_of' => null]);
		$this->expectException(ClaimNotFoundException::class);
		$this->svc->decide($this->submittedClaim(), 'approved', 'stranger', null);
	}

	public function testSelfApproveDeniedWhenFlagOff(): void
	{
		// Routed as approver of own claim but org forbids self-approval →
		// SELF_APPROVAL_DENIED 403 (claimant knows the claim exists; spec §10.4).
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$this->settings->method('getBool')->with(SettingsService::KEY_ALLOW_SELF_APPROVE)->willReturn(false);
		try {
			$this->svc->decide($this->submittedClaim('alice'), 'approved', 'alice', null);
			$this->fail('self-approval accepted with flag off');
		} catch (DomainException $e) {
			$this->assertSame('SELF_APPROVAL_DENIED', $e->getErrorCode());
			$this->assertSame(403, $e->getHttpStatus());
		}
	}

	public function testSelfApprovePermittedWhenFlagOn(): void
	{
		// flag on + routed approver = claimant → decision proceeds (single-person
		// org path; spec §10.7 allow_self_approve). Previously this deadlocked:
		// routing let the claim in but decide() hard-404'd forever.
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$this->settings->method('getBool')->with(SettingsService::KEY_ALLOW_SELF_APPROVE)->willReturn(true);
		$this->decisions->method('findForStep')->willReturn(null);
		$this->decisions->method('insert')->willReturnArgument(0);
		$this->claims->method('updateWithVersion')->willReturn(true);
		$this->chain->method('append')->willReturn($this->createStub(\OCA\ExpenseCheck\Db\ClaimEvent::class));
		$this->db->expects($this->once())->method('commit');
		$out = $this->svc->decide($this->submittedClaim('alice'), 'approved', 'alice', null);
		$this->assertSame('approved', $out->getStatus());
		$this->assertSame('alice', $out->getDecidedBy());
	}

	public function testStepAlreadyDecidedStatusCode(): void
	{
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$existing = new StepDecision();
		$this->decisions->method('findForStep')->willReturn($existing);
		try {
			$this->svc->decide($this->submittedClaim(), 'approved', 'boss', null);
			$this->fail('double decision accepted');
		} catch (DomainException $e) {
			$this->assertSame('STEP_ALREADY_DECIDED', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testSecondApproverGetsStepAlreadyDecided(): void
	{
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$existing = new StepDecision();
		$existing->setDecidedBy('other');
		$this->decisions->method('findForStep')->willReturn($existing);
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Step was already decided.');
		$this->svc->decide($this->submittedClaim(), 'approved', 'boss', null);
	}

	public function testCasFailureRollsBack(): void
	{
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$this->decisions->method('findForStep')->willReturn(null);
		$this->claims->method('updateWithVersion')->willReturn(false);
		$this->decisions->method('insert')->willReturnArgument(0);
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		try {
			$this->svc->decide($this->submittedClaim(), 'approved', 'boss', null);
			$this->fail('CAS failure accepted');
		} catch (DomainException $e) {
			$this->assertSame('VERSION_CONFLICT', $e->getErrorCode());
			$this->assertSame(409, $e->getHttpStatus());
		}
	}

	public function testCasFailureMapsToVersionConflict(): void
	{
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$this->decisions->method('findForStep')->willReturn(null);
		$this->decisions->method('insert')->willReturnArgument(0);
		$this->claims->method('updateWithVersion')->willReturn(false);
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Claim changed concurrently');
		$this->svc->decide($this->submittedClaim(), 'approved', 'boss', null);
	}

	public function testClientVersionEnforced(): void
	{
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Claim changed since you loaded it');
		$this->svc->decide($this->submittedClaim(), 'approved', 'boss', null, 2); // entity is v4
	}

	public function testMultiStepApproveAdvancesNotFinalizes(): void
	{
		$claim = $this->submittedClaim();
		$claim->setChainSnapshot(json_encode(['chain_id' => 1, 'steps' => [
			['step_no' => 1, 'resolved_uids' => ['boss']],
			['step_no' => 2, 'resolved_uids' => ['finance']],
		]]));
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$this->decisions->method('findForStep')->willReturn(null);
		$this->decisions->method('insert')->willReturnArgument(0);
		$this->claims->method('updateWithVersion')->willReturn(true);
		$out = $this->svc->decide($claim, 'approved', 'boss', null);
		$this->assertSame('submitted', $out->getStatus());
		$this->assertSame(2, $out->getCurrentStep());
		$this->assertNull($out->getDecidedBy());
		$this->assertNull($out->getDecidedAt());
	}

	/** Kills: note-storage, status, currentStep, step_no, chain-payload mutants. */
	public function testRejectStoresNoteAndFinalizes(): void
	{
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$this->decisions->method('findForStep')->willReturn(null);
		$this->claims->method('updateWithVersion')->willReturn(true);
		$inserted = null;
		$this->decisions->method('insert')->willReturnCallback(function ($d) use (&$inserted) {
			$inserted = $d; return $d;
		});
		$this->chain->expects($this->once())->method('append')
			->with(9, 'decision', 'boss', 'submitted', 'rejected',
				$this->callback(fn ($p) => $p['step_no'] === 1 && $p['decision'] === 'rejected'
					&& $p['on_behalf_of'] === null && $p['has_note'] === true));
		$claim = $this->svc->decide($this->submittedClaim(), 'rejected', 'boss', 'not reimbursable');
		$this->assertSame('rejected', $claim->getStatus());
		$this->assertSame(0, $claim->getCurrentStep());
		$this->assertSame('boss', $claim->getDecidedBy());
		$this->assertNotNull($claim->getDecidedAt());
		$this->assertSame('not reimbursable', $claim->getDecisionNote());
		$this->assertSame(1, $inserted->getStepNo());
		$this->assertSame(9, $inserted->getClaimId());
		$this->assertSame('rejected', $inserted->getDecision());
		$this->assertSame('not reimbursable', $inserted->getNote());
	}

	public function testChangesRequestedStoresNoteAndResetsStep(): void
	{
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => 'carol']);
		$this->decisions->method('findForStep')->willReturn(null);
		$this->claims->method('updateWithVersion')->willReturn(true);
		$this->decisions->method('insert')->willReturnArgument(0);
		$this->chain->expects($this->once())->method('append')
			->with(9, 'decision', 'boss', 'submitted', 'changes_requested',
				$this->callback(fn ($p) => $p['on_behalf_of'] === 'carol' && $p['has_note'] === true));
		$claim = $this->svc->decide($this->submittedClaim(), 'changes_requested', 'boss', 'needs receipts');
		$this->assertSame('changes_requested', $claim->getStatus());
		$this->assertSame(0, $claim->getCurrentStep());
		$this->assertSame('needs receipts', $claim->getDecisionNote());
	}

	/** Note whitespace is trimmed; exactly-3-char notes pass the floor. */
	public function testNoteMinLengthBoundary(): void
	{
		$this->routing->method('decisionRights')->willReturn(['allowed' => true, 'on_behalf_of' => null]);
		$this->decisions->method('findForStep')->willReturn(null);
		$this->claims->method('updateWithVersion')->willReturn(true);
		$this->decisions->method('insert')->willReturnArgument(0);
		// ' no ' trims to 'no' (len 2) → rejected
		try {
			$this->svc->decide($this->submittedClaim(), 'rejected', 'boss', ' no ');
			$this->fail('2-char note accepted');
		} catch (ValidationException $e) {
			$this->assertSame('required', $e->getFields()['note']);
		}
		$claim = $this->svc->decide($this->submittedClaim(), 'rejected', 'boss', 'abc');
		$this->assertSame('rejected', $claim->getStatus());
		$this->assertSame('abc', $claim->getDecisionNote());
	}
}
