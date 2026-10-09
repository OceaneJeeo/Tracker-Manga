<?php
/**
 * Shared Chapter ZIP Optimization Logic
 *
 * Re-encodes every image inside a chapter ZIP to WebP (resized if too
 * wide) to minimize storage size while keeping good reading quality.
 *
 * Each page is encoded in its OWN PHP CLI subprocess (webp_worker.php),
 * spawned via exec(). This is deliberate: some GD/libwebp builds raise a
 * true PHP Fatal Error (not a catchable warning/exception) on certain
 * problematic images (oversized, odd color profile, corrupt, etc.), which
 * would otherwise kill the entire optimization run. Isolating each page
 * in its own process means a crash there only affects that one page —
 * its original bytes are kept and everything else keeps going.
 *
 * webp_worker.php itself also tries several fallback strategies (resize
 * limits, flattening alpha, alternate quality, further downscale) before
 * giving up on a page, so most problem images end up converting anyway.
 *
 * If no PHP CLI binary can be found, this falls back to slower in-process
 * encoding, which is NOT crash-proof against true fatal errors.
 *
 * Required alongside this file: webp_worker.php (same directory).
 */

if (!defined('CHAPTER_OPTIMIZE_MAX_WIDTH')) {
    /** Max width (px) for a page image; wider pages are downscaled proportionally. */
    define('CHAPTER_OPTIMIZE_MAX_WIDTH', 1800);
}
if (!defined('CHAPTER_OPTIMIZE_QUALITY')) {
    /** WebP quality (0-100). 80-85 is visually near-lossless for manga pages. */
    define('CHAPTER_OPTIMIZE_QUALITY', 82);
}

/**
 * Tries to locate a working PHP CLI binary, needed to run the isolated
 * webp_worker.php subprocess for each page. Returns null if none could
 * be found or if exec() is disabled, in which case the caller should
 * fall back to (less safe) in-process encoding.
 */
function findPhpBinary(): ?string
{
    if (!function_exists('exec')) {
        return null;
    }

    // Running as CLI already? PHP_BINARY is reliable in that case.
    if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && PHP_BINARY !== '') {
        return PHP_BINARY;
    }

    // Try to derive the XAMPP root from this script's own path
    // (…\xampp\htdocs\... -> …\xampp\php\php.exe). PHP_BINARY under the
    // Apache module SAPI does NOT reliably point to php.exe, so we can't
    // rely on it there.
    $dir = __DIR__;
    if (preg_match('/^(.*[\\\\\/]xampp)[\\\\\/]htdocs/i', $dir, $m)) {
        $candidate = $m[1] . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe';
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    foreach (['C:\\xampp\\php\\php.exe', '/usr/bin/php', '/usr/local/bin/php'] as $fallback) {
        if (is_file($fallback)) {
            return $fallback;
        }
    }

    return null;
}

/**
 * Encodes one image (raw bytes) to WebP by running the isolated worker
 * script in its own PHP CLI process.
 *
 * @return array{data: ?string, debug: string[]} 'data' is the WebP bytes
 *         on success or null on failure; 'debug' holds the worker's
 *         diagnostic output lines (dimensions tried, which strategy
 *         worked or why everything failed), useful for logging.
 */
function encodePageToWebpIsolated(string $data, string $phpBinary): array
{
    $workerPath = __DIR__ . DIRECTORY_SEPARATOR . 'webp_worker.php';
    if (!is_file($workerPath)) {
        return ['data' => null, 'debug' => ['webp_worker.php not found next to webp_optimizer.php']];
    }

    $inPath = tempnam(sys_get_temp_dir(), 'pgin_');
    $outPath = tempnam(sys_get_temp_dir(), 'pgout_');

    if ($inPath === false || $outPath === false) {
        return ['data' => null, 'debug' => ['Could not create temp files']];
    }

    file_put_contents($inPath, $data);

    $cmd = sprintf(
        '%s -d display_errors=0 -d html_errors=0 -d memory_limit=512M %s %s %s %d %d 2>&1',
        escapeshellarg($phpBinary),
        escapeshellarg($workerPath),
        escapeshellarg($inPath),
        escapeshellarg($outPath),
        CHAPTER_OPTIMIZE_QUALITY,
        CHAPTER_OPTIMIZE_MAX_WIDTH
    );

    $returnCode = 1;
    $outputLines = [];
    @exec($cmd, $outputLines, $returnCode);

    $webpData = null;
    if ($returnCode === 0 && is_file($outPath) && filesize($outPath) > 0) {
        $contents = file_get_contents($outPath);
        $webpData = ($contents !== false) ? $contents : null;
    }

    @unlink($inPath);
    @unlink($outPath);

    return ['data' => $webpData, 'debug' => $outputLines];
}

/**
 * Fallback in-process encoder, used only if no PHP CLI binary is
 * available. NOTE: this cannot protect against a true engine-level
 * fatal error (those are uncatchable in PHP) — prefer the isolated path.
 */
function encodePageToWebpInProcess(string $data): ?string
{
    try {
        $img = @imagecreatefromstring($data);
        if (!$img) {
            return null;
        }

        imagepalettetotruecolor($img);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $width = imagesx($img);
        $height = imagesy($img);

        if ($width > CHAPTER_OPTIMIZE_MAX_WIDTH) {
            $newWidth = CHAPTER_OPTIMIZE_MAX_WIDTH;
            $newHeight = (int)round($height * ($newWidth / $width));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($img);
            $img = $resized;
        }

        ob_start();
        $ok = imagewebp($img, null, CHAPTER_OPTIMIZE_QUALITY);
        $webpData = ob_get_clean();
        imagedestroy($img);

        if (!$ok || $webpData === false || $webpData === '') {
            return null;
        }

        return $webpData;
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        return null;
    }
}

/**
 * Optimises a chapter ZIP archive in place: every image entry is
 * re-encoded to WebP (resized if too wide). Non-image entries and pages
 * that fail to convert (for any reason, including a subprocess crash)
 * are copied through unchanged, so no page is ever lost.
 *
 * @param string $sourcePath Path to the ZIP file to optimize (in place).
 * @param callable|null $logger Optional callback(string $message) for progress output.
 * @return int Final file size in bytes (equals the original if
 *             optimization was skipped or didn't help).
 */
function optimizeChapterZip(string $sourcePath, ?callable $logger = null): int
{
    $log = $logger ?? function (string $msg) {};
    $originalSize = filesize($sourcePath);

    if (!extension_loaded('gd') || !function_exists('imagewebp')) {
        $log('  [skip] GD/WebP not available, skipping optimization.');
        return $originalSize;
    }

    $phpBinary = findPhpBinary();
    if ($phpBinary === null) {
        $log('  [warn] No isolated PHP CLI binary found; using in-process fallback (not crash-proof).');
    }

    $zip = new ZipArchive();
    if ($zip->open($sourcePath) !== true) {
        $log('  [skip] Cannot open archive.');
        return $originalSize;
    }

    $tmpZipPath = $sourcePath . '.optimized_' . uniqid() . '.zip';
    $outZip = new ZipArchive();
    if ($outZip->open($tmpZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $zip->close();
        $log('  [skip] Cannot create temp archive.');
        return $originalSize;
    }

    $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $pagesProcessed = 0;
    $pagesSkipped = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if ($name === false || substr($name, -1) === '/') {
            continue;
        }
        if (strpos($name, '__MACOSX') !== false) {
            continue;
        }
        $base = basename($name);
        if ($base === '' || $base[0] === '.') {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $data = $zip->getFromIndex($i);

        if ($data === false) {
            continue;
        }

        if (!in_array($ext, $imageExt, true)) {
            $outZip->addFromString($name, $data);
            continue;
        }

        if ($phpBinary !== null) {
            $result = encodePageToWebpIsolated($data, $phpBinary);
            $webpData = $result['data'];
            $debugLines = $result['debug'];
        } else {
            $webpData = encodePageToWebpInProcess($data);
            $debugLines = [];
        }

        if ($webpData === null) {
            $detail = !empty($debugLines) ? ' (' . end($debugLines) . ')' : '';
            $log("  [warn] Page '$name' failed to re-encode$detail, keeping original.");
            $outZip->addFromString($name, $data);
            $pagesSkipped++;
            continue;
        }

        $dir = pathinfo($name, PATHINFO_DIRNAME);
        $filenameNoExt = pathinfo($name, PATHINFO_FILENAME);
        $newName = ($dir !== '.' ? $dir . '/' : '') . $filenameNoExt . '.webp';

        $outZip->addFromString($newName, $webpData);
        $pagesProcessed++;
    }

    $zip->close();
    $outZip->close();

    $log("  Pages re-encoded: $pagesProcessed, pages kept as-is: $pagesSkipped");

    $optimizedSize = file_exists($tmpZipPath) ? filesize($tmpZipPath) : PHP_INT_MAX;

    if ($optimizedSize > 0 && $optimizedSize < $originalSize) {
        if (unlink($sourcePath) && rename($tmpZipPath, $sourcePath)) {
            return $optimizedSize;
        }
        @unlink($tmpZipPath);
        $log('  [warn] Could not swap in the optimized file, keeping original.');
        return $originalSize;
    }

    @unlink($tmpZipPath);
    $log('  Optimization did not reduce size further, keeping original.');
    return $originalSize;
}