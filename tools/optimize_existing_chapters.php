<?php
/**
 * Optimize Existing Chapters Script
 *
 * One-off maintenance script: goes through every chapter already stored
 * in the database and re-encodes its images to WebP (resized if too
 * wide) using webp_optimizer.php, which runs each page's encoding in an
 * isolated PHP CLI subprocess. This means a crash on one problematic
 * image only affects that one page — it's kept as-is and the run
 * continues.
 *
 * COMMAND LINE ONLY (it lives outside the web-accessible `public/` folder):
 *
 *   C:\xampp\php\php.exe tools\optimize_existing_chapters.php          (from the project folder)
 *   php tools/optimize_existing_chapters.php                              (Linux / macOS)
 *
 * RESUMING A RUN
 * ---------------
 * Every processed chapter prints its id, e.g. "(id=2434)". If a run is
 * interrupted, resume AFTER that chapter by passing the id as argument:
 *
 *   php tools/optimize_existing_chapters.php 2434
 *
 * Chapters keep the same processing order every run (ascending chapter id), so
 * this picks up right where you left off; chapters before the given id are
 * skipped entirely (not even opened), so resuming is instant.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../src/security.php';       // mt_abs()
require_once __DIR__ . '/../config/mysql.php';       // $pdo
require_once __DIR__ . '/../src/webp_optimizer.php';

$isCli = true;

// Allow this script to run long on big libraries.
set_time_limit(0);
ini_set('memory_limit', '512M');

// ── Resume support ──────────────────────────────────────────────────────

/**
 * Chapter id to resume AFTER (that chapter and everything before it in
 * the processing order is skipped). Null means start from the very
 * first chapter.
 */
$startAfterId = null;
if ($isCli) {
    if (isset($argv[1]) && ctype_digit($argv[1])) {
        $startAfterId = (int)$argv[1];
    }
} else {
    if (isset($_GET['start_after_id']) && ctype_digit($_GET['start_after_id'])) {
        $startAfterId = (int)$_GET['start_after_id'];
    }
}

/**
 * Formats a byte count for readability.
 */
function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
    return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}

// ── Main run ─────────────────────────────────────────────────────────────

echo "=== Chapter archive optimization ===\n";
echo "Max width: " . CHAPTER_OPTIMIZE_MAX_WIDTH . "px, WebP quality: " . CHAPTER_OPTIMIZE_QUALITY . "\n";

if (!extension_loaded('gd') || !function_exists('imagewebp')) {
    echo "ERROR: GD extension (with WebP support) is not enabled on this PHP install.\n";
    echo "Enable 'extension=gd' in php.ini and restart Apache before running this script.\n";
    exit(1);
}

$phpBinaryCheck = findPhpBinary();
if ($phpBinaryCheck !== null) {
    echo "Isolated encoding via: $phpBinaryCheck\n";
} else {
    echo "WARNING: no PHP CLI binary found — falling back to in-process encoding,\n";
    echo "which CANNOT survive a hard GD/WebP crash on a bad image.\n";
}

$stmt = $pdo->query("
    SELECT c.id, c.chapter_number, c.file_path, c.file_size, m.title
    FROM manga_chapters c
    JOIN mangas m ON m.id = c.manga_id
    ORDER BY c.id ASC
");
$chapters = $stmt->fetchAll();

if (empty($chapters)) {
    echo "No chapters found in the database. Nothing to do.\n";
    exit(0);
}

$totalChapterCount = count($chapters);

// If resuming, skip everything up to and including $startAfterId.
if ($startAfterId !== null) {
    $foundIndex = null;
    foreach ($chapters as $idx => $chapter) {
        if ((int)$chapter['id'] === $startAfterId) {
            $foundIndex = $idx;
            break;
        }
    }

    if ($foundIndex === null) {
        echo "WARNING: id=$startAfterId not found in the chapter list — processing from the start instead.\n";
    } else {
        $chapters = array_slice($chapters, $foundIndex + 1);
        echo "Resuming after id=$startAfterId — skipping " . ($foundIndex + 1) . " of $totalChapterCount already-processed chapters.\n";
    }
}

echo "Chapters to process this run: " . count($chapters) . "\n\n";

if (empty($chapters)) {
    echo "Nothing left to process — all chapters are already done.\n";
    exit(0);
}

$totalBefore = 0;
$totalAfter = 0;
$processedCount = 0;
$skippedCount = 0;
$lastId = null;

foreach ($chapters as $chapter) {
    $label = "[{$chapter['title']} - Chapter {$chapter['chapter_number']}] (id={$chapter['id']})";

    if (!file_exists(mt_abs($chapter['file_path']))) {
        echo "$label\n  [skip] File missing on disk: {$chapter['file_path']}\n\n";
        $skippedCount++;
        $lastId = $chapter['id'];
        continue;
    }

    $sizeBefore = filesize(mt_abs($chapter['file_path']));
    echo "$label\n  Current size: " . formatBytes($sizeBefore) . "\n";

    $sizeAfter = optimizeChapterZip(mt_abs($chapter['file_path']), function (string $msg) {
        echo $msg . "\n";
    });

    if ($sizeAfter < $sizeBefore) {
        $update = $pdo->prepare("UPDATE manga_chapters SET file_size = ? WHERE id = ?");
        $update->execute([$sizeAfter, $chapter['id']]);

        $saved = $sizeBefore - $sizeAfter;
        $pct = $sizeBefore > 0 ? round(($saved / $sizeBefore) * 100, 1) : 0;
        echo "  New size: " . formatBytes($sizeAfter) . " (saved " . formatBytes($saved) . ", -{$pct}%)\n";
        $processedCount++;
    } else {
        echo "  No change.\n";
        $skippedCount++;
    }

    $totalBefore += $sizeBefore;
    $totalAfter += $sizeAfter;
    $lastId = $chapter['id'];

    echo "\n";

    // Flush output progressively when run from a browser.
    if (!$isCli) {
        @ob_flush();
        @flush();
    }
}

$totalSaved = $totalBefore - $totalAfter;
$totalPct = $totalBefore > 0 ? round(($totalSaved / $totalBefore) * 100, 1) : 0;

echo "=== Done (this run) ===\n";
echo "Chapters optimized: $processedCount\n";
echo "Chapters unchanged/skipped: $skippedCount\n";
echo "Total before: " . formatBytes($totalBefore) . "\n";
echo "Total after:  " . formatBytes($totalAfter) . "\n";
echo "Total saved:  " . formatBytes($totalSaved) . " (-{$totalPct}%)\n";

if ($lastId !== null) {
    echo "\nIf you need to resume later, use:\n";
    if ($isCli) {
        echo "  php tools/optimize_existing_chapters.php $lastId\n";
    } else {
        echo "  optimize_existing_chapters.php?start_after_id=$lastId\n";
    }
}
?>