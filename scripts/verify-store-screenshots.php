<?php

declare(strict_types=1);

/**
 * Pre-flight verification for App Store screenshot metadata.
 *
 * Run this before building a release tarball (`make verify-store`). It
 * confirms appinfo/info.xml is well-formed XML, that the referenced assets
 * exist in the local repository, and (unless --skip-http is passed) that
 * every screenshot URL is reachable and returns an image content type.
 * Non-image or non-200 responses make the script exit with a non-zero
 * status so CI/release builds fail early.
 *
 * Unlike the original per-app copies, the expected raw.githubusercontent
 * prefix is derived from the <repository> element, so this file can be
 * dropped into any sibling app unchanged.
 *
 * Note: The App Store reorders info.xml elements via XSLT before validating
 * against its XSD, so a strict element-order check is intentionally not
 * performed here.
 */

$root = dirname(__DIR__);
$infoXml = $root . '/appinfo/info.xml';
$skipHttp = in_array('--skip-http', $argv ?? [], true);

if (!is_file($infoXml)) {
	fwrite(STDERR, "Missing {$infoXml}\n");
	exit(1);
}

$xml = (string)file_get_contents($infoXml);
$dom = new DOMDocument();
if (!@$dom->loadXML($xml)) {
	fwrite(STDERR, "Failed to parse appinfo/info.xml\n");
	exit(1);
}

// The App Store reorders elements via XSLT before validating against XSD, so
// we only require well-formed XML here. Use xmllint or the store's own upload
// flow for full schema validation.
echo "info.xml is well-formed XML.\n";

$xp = new DOMXPath($dom);
/** @var list<DOMElement> $screenshots */
$screenshots = iterator_to_array($xp->query('/info/screenshot'));

if ($screenshots === []) {
	fwrite(STDERR, "No <screenshot> elements found in info.xml\n");
	exit(1);
}

if (count($screenshots) > 10) {
	fwrite(STDERR, 'App Store allows at most 10 screenshots; found ' . count($screenshots) . "\n");
	exit(1);
}

$expectedPrefix = expectedScreenshotPrefix($xp);
if ($expectedPrefix === null) {
	fwrite(STDERR, "Could not derive screenshot URL prefix from <repository> in info.xml\n");
	exit(1);
}
echo "Expecting screenshots under {$expectedPrefix}\n";

$failed = 0;
foreach ($screenshots as $index => $screenshot) {
	$position = $index + 1;
	$url = trim((string)$screenshot->textContent);
	$smallThumbnail = $screenshot->getAttribute('small-thumbnail');

	if ($url === '') {
		fwrite(STDERR, "Screenshot {$position}: empty URL\n");
		$failed++;
		continue;
	}

	if (!str_starts_with($url, 'https://')) {
		fwrite(STDERR, "Screenshot {$position}: URL must use HTTPS ({$url})\n");
		$failed++;
		continue;
	}

	if ($smallThumbnail !== '' && trim($smallThumbnail) !== $url) {
		fwrite(STDERR, "Screenshot {$position}: small-thumbnail must match the main URL\n");
		$failed++;
	}

	if (!verifyLocalAsset($url, $position, $root, $expectedPrefix)) {
		$failed++;
	}

	if ($skipHttp) {
		echo "Screenshot {$position}: skipping HTTP reachability check (--skip-http)\n";
		continue;
	}

	if (!verifyImageUrl($url, $position)) {
		$failed++;
	}

	if ($smallThumbnail !== '' && !verifyImageUrl(trim($smallThumbnail), $position, 'small-thumbnail')) {
		$failed++;
	}
}

if ($failed > 0) {
	fwrite(STDERR, "\n{$failed} screenshot(s) failed verification.\n");
	exit(1);
}

echo 'All ' . count($screenshots) . " screenshot URLs are reachable and return image content.\n";
exit(0);

/**
 * Turns <repository>https://github.com/ORG/REPO(.git)</repository> into the
 * raw.githubusercontent prefix that store screenshots must live under.
 */
function expectedScreenshotPrefix(DOMXPath $xp): ?string
{
	$repository = trim((string)($xp->query('/info/repository')->item(0)?->textContent ?? ''));
	if ($repository === '') {
		return null;
	}

	if (!preg_match('~^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?/?$~', $repository, $m)) {
		return null;
	}

	return 'https://raw.githubusercontent.com/' . $m[1] . '/main/screenshots/appstore/';
}

function verifyLocalAsset(string $url, int $position, string $root, string $expectedPrefix): bool
{
	if (!str_starts_with($url, $expectedPrefix)) {
		fwrite(STDERR, "Screenshot {$position}: URL does not start with the expected repository prefix ({$url})\n");
		return false;
	}

	$relativePath = substr($url, strlen($expectedPrefix));
	$localFile = $root . '/screenshots/appstore/' . basename($relativePath);

	if (!is_file($localFile)) {
		fwrite(STDERR, "Screenshot {$position}: local asset missing at {$localFile}\n");
		return false;
	}

	$info = @getimagesize($localFile);
	if ($info === false || $info['mime'] !== 'image/png') {
		fwrite(STDERR, "Screenshot {$position}: local asset is not a valid PNG ({$localFile})\n");
		return false;
	}

	echo "Screenshot {$position}: local asset OK ({$localFile}, {$info[0]}x{$info[1]})\n";
	return true;
}

function verifyImageUrl(string $url, int $position, string $label = 'screenshot'): bool
{
	$ch = curl_init($url);
	if ($ch === false) {
		fwrite(STDERR, "Screenshot {$position} {$label}: failed to initialize request for {$url}\n");
		return false;
	}

	curl_setopt_array($ch, [
		CURLOPT_NOBODY => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS => 5,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_USERAGENT => 'Check-screenshot-verify/1.0',
	]);

	$response = curl_exec($ch);
	$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	$curlError = curl_error($ch);
	curl_close($ch);

	if ($response === false && $curlError !== '') {
		fwrite(STDERR, "Screenshot {$position} {$label}: request error for {$url}: {$curlError}\n");
		return false;
	}

	if ($httpCode !== 200) {
		fwrite(STDERR, "Screenshot {$position} {$label}: HTTP {$httpCode} for {$url}\n");
		return false;
	}

	if (!str_starts_with($contentType, 'image/')) {
		fwrite(STDERR, "Screenshot {$position} {$label}: non-image content type '{$contentType}' for {$url}\n");
		return false;
	}

	echo "Screenshot {$position} {$label}: OK ({$contentType}) {$url}\n";
	return true;
}
