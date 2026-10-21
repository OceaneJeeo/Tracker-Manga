<?php
/**
 * Full backup (ZIP) — everything in one file:
 *   collection.json   every manga + its chapters + reading progress
 *   covers/           the local cover images
 *   chapters/         every chapter archive (stored, not re-compressed: they are already ZIPs)
 *
 *   GET backup.php               => the full backup
 *   GET backup.php?chapters=0    => light backup (manga + covers only, no chapter archives)
 *   GET backup.php?check=1       => JSON preflight (size, disk space) — the page calls it
 *                                   first so that an error never replaces the page
 *
 * Restore with import_backup.php (⚙️ menu > Restaurer une sauvegarde).
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_session_start();

$check = isset($_GET['check']);
if ($check) {
    header('Content-Type: application/json; charset=UTF-8');
}
if (!mt_is_authenticated()) {
    http_response_code(403);
    exit($check ? json_encode(['success' => false, 'error' => 'Not authenticated']) : 'Not authenticated');
}

$withChapters = ($_GET['chapters'] ?? '1') !== '0';

try {
    mt_ensure_schema($pdo);
    @set_time_limit(0);
    @ignore_user_abort(true);

    $mangas = $pdo->query("SELECT * FROM mangas ORDER BY id ASC")->fetchAll();

    // Chapters (+ reading progress), grouped by manga
    $chaptersByManga = [];
    if ($withChapters && mt_table_exists($pdo, 'manga_chapters')) {
        $hasProgress = mt_table_exists($pdo, 'reading_progress');
        $sql = $hasProgress
            ? "SELECT c.*, p.last_page, p.total_pages, p.finished, p.date_updated AS progress_updated
               FROM manga_chapters c LEFT JOIN reading_progress p ON p.chapter_id = c.id
               ORDER BY c.manga_id ASC, c.id ASC"
            : "SELECT c.* FROM manga_chapters c ORDER BY c.manga_id ASC, c.id ASC";
        foreach ($pdo->query($sql) as $c) {
            $chaptersByManga[(int)$c['manga_id']][] = $c;
        }
    }

    // ── Size estimate + disk space check ─────────────────────────────────────
    $bytes = 0; $chapterCount = 0; $coverCount = 0;
    foreach ($mangas as $m) {
        $image = $m['image'] ?? '';
        if ($image && strpos($image, 'img/') === 0 && strpos($image, '..') === false && is_file(mt_abs($image))) {
            $bytes += filesize(mt_abs($image));
            $coverCount++;
        }
        foreach ($chaptersByManga[(int)$m['id']] ?? [] as $c) {
            if (mt_is_chapter_path($c['file_path']) && is_file(mt_abs($c['file_path']))) {
                $bytes += filesize(mt_abs($c['file_path']));
                $chapterCount++;
            }
        }
    }

    $tmpDir = mt_ensure_dir('storage/tmp', MT_DENY_ALL_HTACCESS);
    $free   = @disk_free_space($tmpDir);
    $needed = (int)($bytes * 1.02) + 50 * 1024 * 1024;
    if ($free !== false && $free < $needed) {
        throw new Exception(sprintf(
            "Espace disque insuffisant pour préparer la sauvegarde (il faut environ %.1f Go libres, %.1f Go disponibles). "
            . "Libère de la place ou utilise l'export léger (sans chapitres).",
            $needed / 1073741824, $free / 1073741824
        ));
    }

    if ($check) {
        echo json_encode([
            'success'  => true,
            'mangas'   => count($mangas),
            'covers'   => $coverCount,
            'chapters' => $chapterCount,
            'bytes'    => $bytes,
        ]);
        exit;
    }

    // ── Build the ZIP ────────────────────────────────────────────────────────
    // Old temp files (interrupted exports) are cleaned up
    foreach (glob($tmpDir . DIRECTORY_SEPARATOR . 'export_*.zip') ?: [] as $old) {
        if (filemtime($old) < time() - 3600) {
            @unlink($old);
        }
    }

    $tmp = $tmpDir . DIRECTORY_SEPARATOR . 'export_' . bin2hex(random_bytes(6)) . '.zip';
    register_shutdown_function(function () use (&$tmp) {
        if ($tmp && is_file($tmp)) {
            @unlink($tmp);
        }
    });

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Impossible de créer l\'archive de sauvegarde');
    }

    // ZipArchive keeps every added file open until close(): on Windows that fails
    // after ~2000 files, so the archive is closed and reopened every 100 files.
    $pending = 0;
    $addFile = function (string $abs, string $inZip) use (&$zip, &$pending, $tmp) {
        $zip->addFile($abs, $inZip);
        $zip->setCompressionName($inZip, ZipArchive::CM_STORE);
        if (++$pending >= 100) {
            if (!$zip->close() || $zip->open($tmp) !== true) {
                throw new Exception('Erreur pendant l\'écriture de l\'archive');
            }
            $pending = 0;
        }
    };

    $out = [];
    $coverCount = 0; $chapterCount = 0; $missingChapters = 0;
    foreach ($mangas as $m) {
        $image = $m['image'] ?? '';
        if ($image && strpos($image, 'img/') === 0 && strpos($image, '..') === false && is_file(mt_abs($image))) {
            $inZip = 'covers/' . $m['id'] . '_' . basename($image);
            $addFile(mt_abs($image), $inZip);
            $image = $inZip;
            $coverCount++;
        } elseif ($image && strpos($image, 'img/') === 0) {
            $image = '';   // local file already missing
        }

        $chapters = [];
        foreach ($chaptersByManga[(int)$m['id']] ?? [] as $c) {
            $path = $c['file_path'];
            if (!mt_is_chapter_path($path) || !is_file(mt_abs($path))) {
                $missingChapters++;
                continue;   // database row without its file: nothing to save
            }
            $inZip = 'chapters/' . $c['id'] . '_' . basename($path);
            $addFile(mt_abs($path), $inZip);
            $chapterCount++;
            $chapters[] = [
                'chapter_number' => $c['chapter_number'],
                'file'           => $inZip,
                'file_size'      => (int)filesize(mt_abs($path)),
                'date_added'     => $c['date_added'],
                'progress'       => ($c['last_page'] ?? null) === null ? null : [
                    'last_page'   => (int)$c['last_page'],
                    'total_pages' => (int)$c['total_pages'],
                    'finished'    => (int)$c['finished'],
                    'updated'     => $c['progress_updated'] ?? null,
                ],
            ];
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
            'date_updated'    => $m['date_updated'] ?? null,
            'chapters'        => $chapters,
        ];
    }

    $zip->addFromString('collection.json', json_encode([
        'app'        => 'MangaTracker',
        'version'    => '3.0-full',
        'exportedAt' => date('c'),
        'count'      => count($out),
        'covers'     => $coverCount,
        'chapters'   => $chapterCount,
        'mangas'     => $out,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!$zip->close()) {
        throw new Exception('Erreur pendant la finalisation de l\'archive');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="manga-tracker-' . ($withChapters ? 'complet' : 'leger') . '-' . date('Y-m-d') . '.zip"');
    header('X-Content-Type-Options: nosniff');
    mt_log('backup_downloaded', "mangas=" . count($out) . " covers=$coverCount chapters=$chapterCount" . ($missingChapters ? " missing=$missingChapters" : ''));
    readfile($tmp);

} catch (Throwable $e) {
    error_log('MangaTracker backup: ' . $e->getMessage());
    http_response_code(500);
    $safe = get_class($e) === 'Exception' ? $e->getMessage() : 'Sauvegarde impossible (voir le journal d\'erreurs PHP)';
    exit($check ? json_encode(['success' => false, 'error' => $safe]) : $safe);
}
