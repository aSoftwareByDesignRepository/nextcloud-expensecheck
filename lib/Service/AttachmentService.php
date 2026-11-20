<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\Db\Attachment;
use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Db\Claim;
use OCA\ExpenseCheck\Db\ClaimLine;
use OCA\ExpenseCheck\Exception\DomainException;
use OCA\ExpenseCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;

/**
 * Receipt files (spec §12): stored in the claimant's Nextcloud Files under
 * `ExpenseCheck/<claim_no>/` — Files ACLs govern access, URLs are never
 * public. Images are re-encoded server-side (EXIF metadata stripped)
 * before persisting. Capture-first inbox holds unfiled receipts; filing is
 * an explicit attach step. 1–10 files per line, ≤5 MB each (admin-tunable
 * DOWN only).
 */
class AttachmentService
{
	public const ALLOWED_MIME = [
		'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
		'application/pdf', 'application/xml', 'text/xml',
	];
	private const ROOT_DIR = 'ExpenseCheck';
	private const INBOX_DIR = 'ExpenseCheck/.inbox';

	public function __construct(
		private readonly AttachmentMapper $attachments,
		private readonly IRootFolder $rootFolder,
		private readonly SettingsService $settings,
		private readonly ExpChainService $chain,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	private function userFolder(string $uid): Folder
	{
		return $this->rootFolder->getUserFolder($uid);
	}

	/**
	 * Store one upload. Returns the persisted Attachment row.
	 * $contents is raw bytes (already validated non-empty by caller).
	 * @param 'inbox'|'line' $target inbox capture or direct line filing
	 */
	public function store(string $uid, ?Claim $claim, ?ClaimLine $line, string $target, string $filename, string $mime, string $contents): Attachment
	{
		$mime = strtolower(trim($mime));
		if (!in_array($mime, self::ALLOWED_MIME, true)) {
			throw new ValidationException(['file' => 'invalid_type']);
		}
		$size = strlen($contents);
		if ($size === 0) {
			throw new ValidationException(['file' => 'empty']);
		}
		if ($size > $this->settings->attachmentMaxMb() * 1048576) {
			throw new ValidationException(['file' => 'too_large']);
		}
		if ($target === 'line') {
			if ($claim === null || $line === null) {
				throw new ValidationException(['file' => 'invalid']);
			}
			$count = count($this->attachments->findByLine($line->getId()));
			if ($count >= $this->settings->attachmentMaxFiles()) {
				throw new DomainException('TOO_MANY_FILES', 'A line can hold at most ' . $this->settings->attachmentMaxFiles() . ' receipts.', 422);
			}
			$claimBytes = 0;
			foreach ($this->attachments->findByClaim($claim->getId()) as $a) {
				$claimBytes += $a->getSize();
			}
			if ($claimBytes + $size > $this->settings->attachmentMaxClaimMb() * 1048576) {
				throw new ValidationException(['file' => 'claim_quota']);
			}
		}

		// EXIF/GPS privacy: re-encode raster images so no metadata survives.
		if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
			$contents = $this->reencodeImage($contents, $mime);
		}

		$folder = $this->ensureDir($uid, $target === 'inbox' ? self::INBOX_DIR : self::ROOT_DIR . '/' . $claim->getClaimNo());
		$safe = $this->safeName($filename);
		if ($folder->nodeExists($safe)) {
			$safe = bin2hex(random_bytes(4)) . '_' . $safe;
		}
		$file = $folder->newFile($safe);
		$file->putContent($contents);

		$a = new Attachment();
		$a->setClaimId($claim?->getId());
		$a->setLineId($line?->getId());
		$a->setInbox($target === 'inbox' ? 1 : 0);
		$a->setFileId($file->getId());
		$a->setName($safe);
		$a->setMime($mime);
		$a->setSize($size);
		$a->setSha256(hash('sha256', $contents));
		$a->setUploadedBy($uid);
		$a->setCreatedAt($this->timeFactory->getDateTime());
		return $this->attachments->insert($a);
	}

	/**
	 * File an inbox receipt onto a claim line (explicit filing step — inbox
	 * files are never implicitly attached).
	 */
	public function fileFromInbox(Attachment $inbox, Claim $claim, ClaimLine $line, string $uid): Attachment
	{
		if ($inbox->getInbox() !== 1 || $inbox->getUploadedBy() !== $uid) {
			throw new DomainException('ATTACHMENT_NOT_FOUND', 'Attachment not found.', 404);
		}
		if ($inbox->getClaimId() !== null) {
			throw new DomainException('ATTACHMENT_NOT_FOUND', 'Attachment not found.', 404);
		}
		$count = count($this->attachments->findByLine($line->getId()));
		if ($count >= $this->settings->attachmentMaxFiles()) {
			throw new DomainException('TOO_MANY_FILES', 'A line can hold at most ' . $this->settings->attachmentMaxFiles() . ' receipts.', 422);
		}
		$claimBytes = 0;
		foreach ($this->attachments->findByClaim($claim->getId()) as $a) {
			$claimBytes += $a->getSize();
		}
		if ($claimBytes + $inbox->getSize() > $this->settings->attachmentMaxClaimMb() * 1048576) {
			throw new ValidationException(['file' => 'claim_quota']);
		}
		// Move the file node into the claim dir.
		$node = $this->nodeFor($inbox);
		$destDir = $this->ensureDir($uid, self::ROOT_DIR . '/' . $claim->getClaimNo());
		$dest = $inbox->getName();
		if ($destDir->nodeExists($dest)) {
			$dest = bin2hex(random_bytes(4)) . '_' . $dest;
		}
		$node->move($destDir->getPath() . '/' . $dest);
		$inbox->setName($dest);
		$inbox->setClaimId($claim->getId());
		$inbox->setLineId($line->getId());
		$inbox->setInbox(0);
		return $this->attachments->update($inbox);
	}

	/**
	 * Read bytes for download — caller enforces claim visibility. Rejects
	 * inbox files of other users outright.
	 */
	public function contents(Attachment $a, string $uid): string
	{
		return $this->nodeFor($a)->getContent();
	}

	public function delete(Attachment $a, string $uid): void
	{
		try {
			$this->nodeFor($a)->delete();
		} catch (DomainException) {
			// File already gone — the row still goes.
		}
		$this->attachments->delete($a);
	}

	/**
	 * Folder::getById() returns a node LIST (0..n matches), never a single
	 * node — an empty result or a Folder must surface as not-found.
	 */
	private function nodeFor(Attachment $a): File
	{
		try {
			$nodes = $this->userFolder($a->getUploadedBy())->getById($a->getFileId());
		} catch (\Throwable) {
			$nodes = [];
		}
		$node = $nodes[0] ?? null;
		if (!$node instanceof File) {
			throw new DomainException('ATTACHMENT_NOT_FOUND', 'Attachment not found.', 404);
		}
		return $node;
	}

	/** @return list<Attachment> */
	public function inbox(string $uid): array
	{
		return $this->attachments->findInbox($uid);
	}

	/**
	 * Re-encode raster images through GD → all metadata (EXIF, GPS, XMP,
	 * comments) is dropped by construction. Returns re-encoded bytes;
	 * fails closed (ValidationException) if the image can't be decoded —
	 * we never persist an unstripped image.
	 */
	public function reencodeImage(string $contents, string $mime): string
	{
		if (!function_exists('imagecreatefromstring')) {
			// No GD → refuse the upload rather than store unstripped pixels.
			throw new ValidationException(['file' => 'image_processing_unavailable']);
		}
		$img = @imagecreatefromstring($contents);
		if ($img === false) {
			throw new ValidationException(['file' => 'invalid_image']);
		}
		ob_start();
		$ok = match ($mime) {
			'image/png' => imagepng($img),
			'image/webp' => imagewebp($img, null, 90),
			default => imagejpeg($img, null, 90),
		};
		imagedestroy($img);
		$out = ob_get_clean();
		if ($ok === false || $out === false || $out === '') {
			throw new ValidationException(['file' => 'invalid_image']);
		}
		return $out;
	}

	private function ensureDir(string $uid, string $rel): Folder
	{
		$folder = $this->userFolder($uid);
		$cur = $folder;
		foreach (explode('/', $rel) as $part) {
			if (!$cur->nodeExists($part)) {
				try {
					$cur = $cur->newFolder($part);
				} catch (NotPermittedException $e) {
					throw new DomainException('STORAGE_DENIED', 'Cannot create receipt storage.', 500);
				}
			} else {
				$cur = $cur->get($part);
			}
			if (!($cur instanceof Folder)) {
				throw new DomainException('STORAGE_DENIED', 'Receipt storage path is not a folder.', 500);
			}
		}
		return $cur;
	}

	private function safeName(string $name): string
	{
		$name = basename($name);
		$name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'receipt';
		return mb_substr($name, 0, 150);
	}
}
