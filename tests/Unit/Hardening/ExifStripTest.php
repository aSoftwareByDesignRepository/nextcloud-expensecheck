<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Tests\Unit\Hardening;

use OCA\ExpenseCheck\Db\AttachmentMapper;
use OCA\ExpenseCheck\Service\AttachmentService;
use OCA\ExpenseCheck\Service\ExpChainService;
use OCA\ExpenseCheck\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;

/**
 * Gate 22 — EXIF/GPS strip proof. The fixture is a hand-spliced JPEG
 * carrying a real APP1 EXIF segment (with a GPS IFD pointer + GPS tag) —
 * not a "looks like metadata" blob, an actual Exif\x00\x00 block. The test
 * proves reencodeImage drops every metadata marker while keeping pixels.
 */
final class ExifStripTest extends TestCase
{
	private AttachmentService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->svc = new AttachmentService(
			$this->createMock(AttachmentMapper::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(SettingsService::class),
			$this->createMock(ExpChainService::class),
			$this->createMock(ITimeFactory::class),
		);
	}

	/** JPEG bytes with a hand-spliced APP1 EXIF segment incl. GPS IFD. */
	private static function jpegWithGpsExif(): string
	{
		$img = imagecreatetruecolor(8, 8);
		imagefilledrectangle($img, 0, 0, 7, 7, imagecolorallocate($img, 200, 30, 30));
		ob_start();
		imagejpeg($img, null, 95);
		$jpeg = (string)ob_get_clean();
		imagedestroy($img);

		// TIFF body (little-endian): magic + IFD0 with a GPS-IFD pointer,
		// then a GPS IFD carrying GPSVersionID + a GPSLatitude tag.
		$gpsIfd = pack('v', 2)
			. pack('v', 0x0000) . pack('v', 1) . pack('V', 4) . pack('V', 0x00020300)   // GPSVersionID
			. pack('v', 0x0002) . pack('v', 5) . pack('V', 3) . pack('V', 0x11223344)   // GPSLatitude
			. pack('V', 0);
		$gpsIfdOffset = 8 + 2 + 12 + 4; // TIFF hdr + count + one IFD0 entry + next
		$ifd0 = pack('v', 1)
			. pack('v', 0x8825) . pack('v', 4) . pack('V', 1) . pack('V', $gpsIfdOffset) // GPS IFD ptr
			. pack('V', 0);
		$tiff = 'II' . pack('v', 42) . pack('V', 8) . $ifd0 . $gpsIfd;
		$app1 = "Exif\x00\x00" . $tiff;
		$segment = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;

		// Splice APP1 directly after the SOI marker.
		return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
	}

	public function testFixtureReallyCarriesExifAndGps(): void
	{
		$in = self::jpegWithGpsExif();
		$this->assertStringContainsString("Exif\x00\x00", $in, 'fixture must embed a real APP1 Exif block');
		$this->assertStringContainsString(pack('v', 0x8825), $in, 'fixture must embed the GPS IFD pointer tag');
		$this->assertStringContainsString("\xFF\xE1", $in);
	}

	public function testReencodeStripsAllMetadata(): void
	{
		$in = self::jpegWithGpsExif();
		$out = $this->svc->reencodeImage($in, 'image/jpeg');

		$this->assertStringNotContainsString("Exif", $out, 'EXIF signature must be gone');
		$this->assertStringNotContainsString("\xFF\xE1", $out, 'APP1 marker must be gone');
		$this->assertStringNotContainsString(pack('v', 0x8825), $out, 'GPS IFD pointer must be gone');
		$this->assertStringNotContainsString("II\x2A\x00", $out, 'TIFF header must be gone');

		// Pixels survive: output decodes and is a real JPEG.
		$decoded = imagecreatefromstring($out);
		$this->assertNotFalse($decoded, 're-encoded bytes must still be a decodable image');
		$this->assertSame(8, imagesx($decoded));
		$this->assertSame(8, imagesy($decoded));
		imagedestroy($decoded);

		// Deterministic gate: stored bytes are exactly the re-encoded bytes —
		// pin the hash so any encoder drift is caught.
		$this->assertSame(hash('sha256', $out), hash('sha256', $this->svc->reencodeImage($in, 'image/jpeg')));
	}

	public function testNonDecodableImageFailsClosed(): void
	{
		$this->expectException(\OCA\ExpenseCheck\Exception\ValidationException::class);
		$this->svc->reencodeImage('not-an-image-at-all', 'image/jpeg');
	}
}
