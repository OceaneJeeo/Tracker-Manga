<?php
/**
 * WebP Worker (isolated process)
 *
 * Encodes a single image to WebP. Meant to be run as its own PHP CLI
 * process (spawned via exec() from webp_optimizer.php), so that a hard
 * GD/libwebp crash on one problematic image only kills this small
 * subprocess instead of the whole optimization script.
 *
 * Tries several strategies in order before giving up, because some
 * images trip up GD's WebP encoder for reasons that are hard to detect
 * up front (extreme dimensions, unusual color/alpha state, certain
 * quality values). Each strategy is attempted fresh so a failure in one
 * doesn't leave the image object in a bad state for the next.
 *
 * Usage: php webp_worker.php <input_image_path> <output_webp_path> <quality> <max_width>
 * Exit codes: 0 = success (output file written and non-empty)
 *             1 = failure (bad args, unreadable image, or all strategies failed)
 *
 * On failure, diagnostic info (image dimensions, which strategies were
 * tried) is written to STDERR so the caller can log something useful.
 */

if (PHP_SAPI !== 'cli') {
    // Not meant to be accessed over the web.
    http_response_code(403);
    exit('CLI only');
}

if ($argc < 5) {
    fwrite(STDERR, "Usage: php webp_worker.php <in> <out> <quality> <maxWidth>\n");
    exit(1);
}

[$script, $inPath, $outPath, $quality, $maxWidth] = $argv;
$quality = (int)$quality;
$maxWidth = (int)$maxWidth;

// WebP's hard per-side limit is 16383px. Stay a bit under that for safety.
const WEBP_HARD_MAX_DIMENSION = 16000;

if (!is_file($inPath)) {
    fwrite(STDERR, "Input file not found: $inPath\n");
    exit(1);
}

$data = file_get_contents($inPath);
if ($data === false || $data === '') {
    fwrite(STDERR, "Cannot read input file\n");
    exit(1);
}

$info = @getimagesizefromstring($data);
if ($info !== false && ($info[0] * $info[1]) > 100000000) {   // > 100 megapixels
    fwrite(STDERR, "Image too large ({$info[0]}x{$info[1]}), page kept as-is\n");
    exit(1);
}

$original = @imagecreatefromstring($data);
if (!$original) {
    fwrite(STDERR, "imagecreatefromstring failed\n");
    exit(1);
}

$origWidth = imagesx($original);
$origHeight = imagesy($original);
fwrite(STDERR, "Source image: {$origWidth}x{$origHeight}\n");

/**
 * Builds a resized truecolor copy of $src at the given target size.
 * Always starts from $src fresh, so previous failed attempts can't leave
 * corrupted state behind.
 */
function makeResizedCopy($src, int $srcW, int $srcH, int $targetW, int $targetH, bool $flatten)
{
    $out = imagecreatetruecolor($targetW, $targetH);

    if ($flatten) {
        // Fill with white and discard alpha entirely — sidesteps any
        // alpha/palette edge cases that can trip up some GD/WebP builds.
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefill($out, 0, 0, $white);
    } else {
        imagealphablending($out, false);
        imagesavealpha($out, true);
    }

    imagecopyresampled($out, $src, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);
    return $out;
}

/**
 * Computes target dimensions honoring both the desired reading max-width
 * and WebP's hard per-side dimension limit.
 */
function computeTargetSize(int $width, int $height, int $maxWidth, int $hardMax): array
{
    $targetW = $width;
    $targetH = $height;

    if ($maxWidth > 0 && $targetW > $maxWidth) {
        $targetH = (int)round($targetH * ($maxWidth / $targetW));
        $targetW = $maxWidth;
    }

    $scale = 1.0;
    if ($targetW > $hardMax) {
        $scale = min($scale, $hardMax / $targetW);
    }
    if ($targetH > $hardMax) {
        $scale = min($scale, $hardMax / $targetH);
    }
    if ($scale < 1.0) {
        $targetW = max(1, (int)round($targetW * $scale));
        $targetH = max(1, (int)round($targetH * $scale));
    }

    return [$targetW, $targetH];
}

/**
 * Tries to encode $img to WebP at $outPath. Returns true on success.
 */
function tryEncode($img, string $outPath, int $quality): bool
{
    // Remove any previous (failed) attempt's leftover file so we don't
    // mistake a stale non-empty file for success.
    if (is_file($outPath)) {
        @unlink($outPath);
    }
    $ok = @imagewebp($img, $outPath, $quality);
    return $ok && is_file($outPath) && filesize($outPath) > 0;
}

[$baseTargetW, $baseTargetH] = computeTargetSize($origWidth, $origHeight, $maxWidth, WEBP_HARD_MAX_DIMENSION);
fwrite(STDERR, "Target size: {$baseTargetW}x{$baseTargetH}\n");

$success = false;

// Strategy 1: normal resize (or no resize if already small enough), keep alpha.
$attempt = makeResizedCopy($original, $origWidth, $origHeight, $baseTargetW, $baseTargetH, false);
if (tryEncode($attempt, $outPath, $quality)) {
    $success = true;
    fwrite(STDERR, "Succeeded on strategy 1 (normal resize)\n");
}
imagedestroy($attempt);

// Strategy 2: same size, but flatten onto white (drops alpha channel
// entirely) — sidesteps alpha/palette edge cases some builds choke on.
if (!$success) {
    $attempt = makeResizedCopy($original, $origWidth, $origHeight, $baseTargetW, $baseTargetH, true);
    if (tryEncode($attempt, $outPath, $quality)) {
        $success = true;
        fwrite(STDERR, "Succeeded on strategy 2 (flattened, no alpha)\n");
    }
    imagedestroy($attempt);
}

// Strategy 3: flattened + a different quality value. Some GD/libwebp
// builds have a known encoder bug tied to specific quality settings.
if (!$success) {
    foreach ([90, 75, 100] as $altQuality) {
        $attempt = makeResizedCopy($original, $origWidth, $origHeight, $baseTargetW, $baseTargetH, true);
        if (tryEncode($attempt, $outPath, $altQuality)) {
            $success = true;
            fwrite(STDERR, "Succeeded on strategy 3 (flattened, quality=$altQuality)\n");
            imagedestroy($attempt);
            break;
        }
        imagedestroy($attempt);
    }
}

// Strategy 4: last resort — shrink further (half the target size). Some
// encoder failures are tied to very large total pixel counts rather than
// per-side dimensions.
if (!$success) {
    $smallW = max(1, (int)round($baseTargetW / 2));
    $smallH = max(1, (int)round($baseTargetH / 2));
    $attempt = makeResizedCopy($original, $origWidth, $origHeight, $smallW, $smallH, true);
    if (tryEncode($attempt, $outPath, $quality)) {
        $success = true;
        fwrite(STDERR, "Succeeded on strategy 4 (half size: {$smallW}x{$smallH})\n");
    }
    imagedestroy($attempt);
}

imagedestroy($original);

if (!$success) {
    fwrite(STDERR, "All encoding strategies failed for this image ({$origWidth}x{$origHeight}).\n");
    exit(1);
}

exit(0);