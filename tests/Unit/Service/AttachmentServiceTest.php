<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Service;

use OCA\ExpenseCheck\Db\Attachment;
use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimLine;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCA\ExpenseCheck\Service\AttachmentService;
use OCA\ExpenseCheck\Service\ExpChainService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttachmentServiceTest extends TestCase
{
	private AttachmentMapper&MockObject $attachments;
	private IRootFolder&MockObject $root;
	private SettingsService&MockObject $settings;
	private AttachmentService $svc;

	protected function setUp(): void
	{
		$this->attachments = $this->createMock(AttachmentMapper::class);
		$this->root = $this->createMock(IRootFolder::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->svc = new AttachmentService(
			$this->attachments,
			$this->root,
			$this->settings,
			$this->createMock(ExpChainService::class),
			$this->createMock(ITimeFactory::class),
		);
	}

	private function attachment(array $over = []): Attachment
	{
		$a = new Attachment();
		$a->setFileId($over['fileId'] ?? 42);
		$a->setUploadedBy($over['uploadedBy'] ?? 'alice');
		$a->setInbox($over['inbox'] ?? 1);
		$a->setClaimId($over['claimId'] ?? null);
		$a->setLineId($over['lineId'] ?? null);
		$a->setName($over['name'] ?? 'r.png');
		$a->setSize($over['size'] ?? 100);
		return $a;
	}

	private function folderWithNodes(array $nodes): Folder
	{
		$f = $this->createMock(Folder::class);
		$f->method('getById')->willReturn($nodes);
		return $f;
	}

	public function testContentsRejectsEmptyNodeList(): void
	{
		$this->root->method('getUserFolder')->with('alice')->willReturn($this->folderWithNodes([]));
		try {
			$this->svc->contents($this->attachment(), 'alice');
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame(404, $e->getHttpStatus());
			$this->assertSame('ATTACHMENT_NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testContentsRejectsFolderNode(): void
	{
		$this->root->method('getUserFolder')->willReturn(
			$this->folderWithNodes([$this->createMock(Folder::class)])
		);
		$this->expectException(DomainException::class);
		$this->svc->contents($this->attachment(), 'alice');
	}

	public function testContentsReturnsFileBytes(): void
	{
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn('PNG-BYTES');
		$this->root->method('getUserFolder')->willReturn($this->folderWithNodes([$file]));
		$this->assertSame('PNG-BYTES', $this->svc->contents($this->attachment(), 'alice'));
	}

	public function testContentsSurvivesFolderThrow(): void
	{
		$f = $this->createMock(Folder::class);
		$f->method('getById')->willThrowException(new \RuntimeException('gone'));
		$this->root->method('getUserFolder')->willReturn($f);
		try {
			$this->svc->contents($this->attachment(), 'alice');
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame(404, $e->getHttpStatus());
			$this->assertSame('ATTACHMENT_NOT_FOUND', $e->getErrorCode());
		}
	}

	private function claimLine(): ClaimLine
	{
		$l = new ClaimLine();
		$l->setId(7);
		return $l;
	}

	private function claim(): Claim
	{
		$c = new Claim();
		$c->setId(9);
		$c->setClaimNo('EXP-2026-00009');
		return $c;
	}

	public function testFileFromInboxRejectsNonInbox(): void
	{
		try {
			$this->svc->fileFromInbox($this->attachment(['inbox' => 0]), $this->claim(), $this->claimLine(), 'alice');
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame('ATTACHMENT_NOT_FOUND', $e->getErrorCode());
			$this->assertSame(404, $e->getHttpStatus());
		}
	}

	public function testFileFromInboxRejectsOtherUploader(): void
	{
		try {
			$this->svc->fileFromInbox($this->attachment(['uploadedBy' => 'bob']), $this->claim(), $this->claimLine(), 'alice');
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame('ATTACHMENT_NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testFileFromInboxRejectsAlreadyFiled(): void
	{
		try {
			$this->svc->fileFromInbox($this->attachment(['claimId' => 5]), $this->claim(), $this->claimLine(), 'alice');
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame('ATTACHMENT_NOT_FOUND', $e->getErrorCode());
		}
	}

	public function testFileFromInboxEnforcesLineCap(): void
	{
		$this->attachments->method('findByLine')->willReturn([new Attachment()]);
		$this->settings->method('attachmentMaxFiles')->willReturn(1);
		try {
			$this->svc->fileFromInbox($this->attachment(), $this->claim(), $this->claimLine(), 'alice');
			$this->fail('expected DomainException');
		} catch (DomainException $e) {
			$this->assertSame('TOO_MANY_FILES', $e->getErrorCode());
			$this->assertSame(422, $e->getHttpStatus());
		}
	}

	public function testFileFromInboxEnforcesClaimQuota(): void
	{
		$existing = new Attachment();
		$existing->setSize(900);
		$this->attachments->method('findByLine')->willReturn([]);
		$this->attachments->method('findByClaim')->willReturn([$existing]);
		$this->settings->method('attachmentMaxFiles')->willReturn(10);
		$this->settings->method('attachmentMaxClaimMb')->willReturn(1);
		$this->expectException(ValidationException::class);
		$this->svc->fileFromInbox($this->attachment(['size' => 2000000]), $this->claim(), $this->claimLine(), 'alice');
	}
}
