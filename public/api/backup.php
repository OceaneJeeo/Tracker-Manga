<?php
/**
 * Full backup (ZIP): collection.json (every manga) + the local cover images.
 * Chapter archives are NOT included (too heavy) — the cover files are.
 * Restore with restore_backup.php (⚙️ menu > Restaurer une sauvegarde).
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_session_start();

if (!mt_is_authenticated()) {
    http_response_code(403);
    exit('Not authenticated');
}

try {
    mt_ensure_schema($pdo);
    @set_time_limit(0);

    $mangas = $pdo->query("SELECT * FROM mangas ORDER BY id ASC")->fetchAll();

    $tmp = tempnam(sys_get_temp_dir(), 'mtbk_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Cannot create backup archive');
    }

    $out = [];
    $coverCount = 0;
    foreach ($mangas as $m) {
        $image = $m['image'] ?? '';
        if ($image && strpos($image, 'img/') === 0 && strpos($image, '..') === false && is_file(mt_abs($image))) {
            $inZip = 'covers/' . $m['id'] . '_' . basename($image);
            $zip->addFile(mt_abs($image), $inZip);
            $image = $inZip;
            $coverCount++;
        } elseif ($image && strpos($image, 'img/') === 0) {
            $image = '';   // local file already missing
        }
        $out[] = [
            'title'           => $m['title'],
            'image'           => $image,
            'reading_link'    => $m['reading_link'],
            'current_chapter' => $m['current_chapter'],
            'status'          => $m['status'],
            'language'        => $m['language'],
            'notes'           => $m['notes'],
            'rating'          => (int)($m['rating'] ?? 0),
            'date_added'      => $m['date_added'],
        ];
    }

    $zip->addFromString('collection.json', json_encode([
        'app'        => 'MangaTracker',
        'version'    => '2.2-backup',
        'exportedAt' => date('c'),
        'count'      => count($out),
        'covers'     => $coverCount,
        'mangas'     => $out,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->close();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="manga-tracker-backup-' . date('Y-m-d') . '.zip"');
    header('X-Content-Type-Options: nosniff');
    mt_log('backup_downloaded', 'mangas=' . count($out) . ' covers=' . $coverCount);
    readfile($tmp);
    @unlink($tmp);

} catch (Throwable $e) {
    if (isset($tmp) && is_file($tmp)) {
        @unlink($tmp);
    }
    http_response_code(500);
    error_log('MangaTracker backup: ' . $e->getMessage());
    exit('Backup failed (see the PHP error log)');
}
