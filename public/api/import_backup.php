<?php
/**
 * Import a backup ZIP created by backup.php — covers, chapters and reading progress included.
 *
 * The browser sends the file in small chunks (so PHP's upload limits don't matter, even for
 * several GB), then asks the server to restore it in short steps (so no request is long enough
 * to time out). Everything is automatic from the user's point of view.
 *
 * Non-destructive: a manga with the same title is never overwritten (its missing chapters are
 * added though) and a chapter that already exists is skipped. Running the same import twice is safe.
 * Old backups (v2.x, no chapters) are accepted too.
 *
 * POST actions:
 *   chunk   upload_id, index, total, chunk (file)   append one chunk
 *   start   upload_id                               read collection.json, restore manga + covers
 *   step    upload_id                               restore the next chapters (~15 s per call)
 *   cancel  upload_id                               delete the temporary files
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_boot_json(true);

const ALLOWED_LANGS    = ['fr', 'en', 'ja', 'es', 'de', 'it', 'pt', 'ko', 'zh', 'other'];
const ALLOWED_STATUSES = ['reading', 'completed'];
const STEP_SECONDS     = 15;
const MAX_CHUNK_BYTES  = 64 * 1024 * 1024;

function imp_dir(): string
{
    return mt_ensure_dir('storage/tmp', MT_DENY_ALL_HTACCESS);
}

function imp_id(): string
{
    $id = (string)($_POST['upload_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{16,64}$/', $id)) {
        throw new Exception('Identifiant d\'import invalide');
    }
    return $id;
}

function imp_files(string $id): array
{
    $d = imp_dir() . DIRECTORY_SEPARATOR . 'import_' . $id;
    return ['zip' => $d . '.zip', 'tasks' => $d . '.tasks.json', 'cursor' => $d . '.cursor.json'];
}

function imp_cleanup(string $id): void
{
    foreach (imp_files($id) as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

function imp_valid_date($d): ?string
{
    return (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $d)) ? $d : null;
}

try {
    mt_ensure_schema($pdo);
    @set_time_limit(0);
    @ignore_user_abort(true);
    @ini_set('memory_limit', '512M');

    $action = $_POST['action'] ?? '';

    switch ($action) {

        // ─────────────────────────────────────────────────────────────────────
        case 'chunk':
            $id    = imp_id();
            $index = (int)($_POST['index'] ?? -1);
            $total = (int)($_POST['total'] ?? 0);
            $f     = imp_files($id);

            if ($index < 0 || $total < 1 || $index >= $total) {
                throw new Exception('Morceau invalide');
            }
            if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Morceau non reçu (limite PHP ou disque plein ?)');
            }
            if ($_FILES['chunk']['size'] > MAX_CHUNK_BYTES) {
                throw new Exception('Morceau trop gros');
            }

            if ($index === 0) {
                // New import: forget interrupted ones older than a day
                foreach (glob(imp_dir() . DIRECTORY_SEPARATOR . 'import_*') ?: [] as $old) {
                    if (filemtime($old) < time() - 86400) {
                        @unlink($old);
                    }
                }
                imp_cleanup($id);
            }

            $in  = fopen($_FILES['chunk']['tmp_name'], 'rb');
            $out = fopen($f['zip'], $index === 0 ? 'wb' : 'ab');
            if (!$in || !$out) {
                throw new Exception('Impossible d\'écrire le fichier temporaire');
            }
            $ok = stream_copy_to_stream($in, $out) !== false;
            fclose($in);
            fclose($out);
            if (!$ok) {
                throw new Exception('Écriture impossible (disque plein ?)');
            }
            echo json_encode(['success' => true]);
            break;

        // ─────────────────────────────────────────────────────────────────────
        case 'start':
            $id = imp_id();
            $f  = imp_files($id);
            if (!is_file($f['zip'])) {
                throw new Exception('Fichier de sauvegarde introuvable (envoi incomplet ?)');
            }

            $zip = new ZipArchive();
            if ($zip->open($f['zip']) !== true) {
                imp_cleanup($id);
                throw new Exception('Ce fichier n\'est pas une archive ZIP valide');
            }
            $json = $zip->getFromName('collection.json');
            $data = $json === false ? null : json_decode($json, true);
            if (!is_array($data) || !isset($data['mangas']) || !is_array($data['mangas'])) {
                $zip->close();
                imp_cleanup($id);
                throw new Exception('Sauvegarde invalide : collection.json introuvable');
            }

            $imageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $coverDir   = 'img/manga/';
            $stats = ['imported' => 0, 'skipped' => 0, 'invalid' => 0, 'covers' => 0,
                      'chapters_imported' => 0, 'chapters_skipped' => 0, 'chapters_failed' => 0];
            $tasks = [];

            $find   = $pdo->prepare("SELECT id FROM mangas WHERE LOWER(TRIM(title)) = LOWER(?) LIMIT 1");
            $insert = $pdo->prepare("
                INSERT INTO mangas (title, image, reading_link, current_chapter, status, language, notes, rating, date_added, date_updated)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($data['mangas'] as $m) {
                if (!is_array($m)) {
                    $stats['invalid']++;
                    continue;
                }
                $title   = trim((string)($m['title'] ?? ''));
                $link    = trim((string)($m['reading_link'] ?? ''));
                $chapter = trim((string)($m['current_chapter'] ?? ''));
                if ($title === '' || $chapter === '' || !mt_valid_http_url($link) || mb_strlen($title) > 255 || mb_strlen($chapter) > 100) {
                    $stats['invalid']++;
                    continue;
                }

                $find->execute([$title]);
                $mangaId = $find->fetchColumn();

                if ($mangaId) {
                    $mangaId = (int)$mangaId;
                    $stats['skipped']++;          // already in the collection: left untouched
                } else {
                    $status = in_array($m['status'] ?? '', ALLOWED_STATUSES, true) ? $m['status'] : 'reading';
                    $lang   = in_array($m['language'] ?? '', ALLOWED_LANGS, true) ? $m['language'] : 'other';
                    $rating = max(0, min(5, (int)($m['rating'] ?? 0)));
                    $added  = imp_valid_date($m['date_added'] ?? null) ?? date('Y-m-d H:i:s');
                    $upd    = imp_valid_date($m['date_updated'] ?? null) ?? $added;

                    // Cover: a file from the ZIP, or an http(s) URL
                    $image = '';
                    $img = (string)($m['image'] ?? '');
                    if (strpos($img, 'covers/') === 0 && strpos($img, '..') === false) {
                        $bytes = $zip->getFromName($img);
                        if ($bytes !== false && strlen($bytes) <= 5 * 1024 * 1024) {
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mime  = finfo_buffer($finfo, $bytes);
                            finfo_close($finfo);
                            if (isset($imageTypes[$mime]) && @getimagesizefromstring($bytes) !== false) {
                                mt_ensure_dir($coverDir, MT_IMAGES_HTACCESS);
                                $safe = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $title), 0, 60);
                                $rel  = $coverDir . uniqid() . '_' . $safe . '.' . $imageTypes[$mime];
                                if (@file_put_contents(mt_abs($rel), $bytes) !== false) {
                                    $image = $rel;
                                    $stats['covers']++;
                                }
                            }
                        }
                    } elseif (mt_valid_http_url($img)) {
                        $image = $img;
                    }

                    $insert->execute([$title, $image, $link, $chapter, $status, $lang, (string)($m['notes'] ?? ''), $rating, $added, $upd]);
                    $mangaId = (int)$pdo->lastInsertId();
                    $stats['imported']++;
                }

                // Chapters to restore (done afterwards, a few at a time)
                foreach (($m['chapters'] ?? []) as $c) {
                    $file = (string)($c['file'] ?? '');
                    $num  = trim((string)($c['chapter_number'] ?? ''));
                    if (!is_array($c) || $num === '' || strlen($num) > 50
                        || strpos($file, 'chapters/') !== 0 || strpos($file, '..') !== false
                        || $zip->locateName($file) === false) {
                        $stats['chapters_failed']++;
                        continue;
                    }
                    $tasks[] = [
                        'manga_id' => $mangaId,
                        'title'    => $title,
                        'number'   => $num,
                        'file'     => $file,
                        'added'    => imp_valid_date($c['date_added'] ?? null),
                        'progress' => is_array($c['progress'] ?? null) ? [
                            'last_page'   => max(0, (int)($c['progress']['last_page'] ?? 0)),
                            'total_pages' => max(0, (int)($c['progress']['total_pages'] ?? 0)),
                            'finished'    => !empty($c['progress']['finished']) ? 1 : 0,
                            'updated'     => imp_valid_date($c['progress']['updated'] ?? null),
                        ] : null,
                    ];
                }
            }
            $zip->close();

            file_put_contents($f['tasks'], json_encode($tasks, JSON_UNESCAPED_UNICODE));
            file_put_contents($f['cursor'], json_encode(['cursor' => 0, 'stats' => $stats]));

            if (!$tasks) {
                imp_cleanup($id);
                mt_log('backup_restored', "imported={$stats['imported']} skipped={$stats['skipped']} covers={$stats['covers']} chapters=0");
            }
            echo json_encode(['success' => true, 'total_chapters' => count($tasks), 'done' => !$tasks, 'stats' => $stats]);
            break;

        // ─────────────────────────────────────────────────────────────────────
        case 'step':
            $id = imp_id();
            $f  = imp_files($id);
            if (!is_file($f['zip']) || !is_file($f['tasks']) || !is_file($f['cursor'])) {
                throw new Exception('Import introuvable (déjà terminé ou annulé)');
            }
            $tasks = json_decode((string)file_get_contents($f['tasks']), true) ?: [];
            $state = json_decode((string)file_get_contents($f['cursor']), true) ?: ['cursor' => 0, 'stats' => []];
            $stats = $state['stats'];
            $cur   = (int)$state['cursor'];

            $zip = new ZipArchive();
            if ($zip->open($f['zip']) !== true) {
                throw new Exception('Archive illisible');
            }

            mt_ensure_dir('archives', MT_DENY_ALL_HTACCESS);
            $relDir = 'archives/chapters/';
            $absDir = mt_abs($relDir);
            if (!is_dir($absDir) && !mkdir($absDir, 0755, true)) {
                throw new Exception("Impossible de créer le dossier $relDir");
            }

            $exists   = $pdo->prepare("SELECT 1 FROM manga_chapters WHERE manga_id = ? AND chapter_number = ? LIMIT 1");
            $taken    = $pdo->prepare("SELECT COUNT(*) FROM manga_chapters WHERE file_path = ?");
            $insChap  = $pdo->prepare("INSERT INTO manga_chapters (manga_id, chapter_number, file_path, file_size, date_added) VALUES (?, ?, ?, ?, ?)");
            $hasProg  = mt_table_exists($pdo, 'reading_progress');
            $insProg  = $hasProg ? $pdo->prepare("INSERT INTO reading_progress (chapter_id, manga_id, last_page, total_pages, finished, date_updated) VALUES (?, ?, ?, ?, ?, ?)") : null;

            $deadline = microtime(true) + STEP_SECONDS;
            $total    = count($tasks);

            while ($cur < $total && microtime(true) < $deadline) {
                $t = $tasks[$cur];
                $cur++;

                $exists->execute([$t['manga_id'], $t['number']]);
                if ($exists->fetchColumn()) {
                    $stats['chapters_skipped']++;
                    continue;
                }

                // Unique target name (same scheme as a normal upload)
                $cleanTitle   = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $t['title']), 0, 80);
                $cleanChapter = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $t['number']);
                $base    = $t['manga_id'] . '_' . $cleanTitle . '_Chapter_' . $cleanChapter;
                $relPath = $relDir . $base . '.zip';
                $taken->execute([$relPath]);
                if ((int)$taken->fetchColumn() > 0 || is_file(mt_abs($relPath))) {
                    $relPath = $relDir . $base . '_' . uniqid() . '.zip';
                }
                $absPath = mt_abs($relPath);
                $part    = $absPath . '.part';

                $ok = false;
                $in = $zip->getStream($t['file']);
                if ($in) {
                    $out = fopen($part, 'wb');
                    if ($out) {
                        $ok = stream_copy_to_stream($in, $out) !== false;
                        fclose($out);
                    }
                    fclose($in);
                }
                if ($ok) {
                    // Must be a readable ZIP with something inside
                    $check = new ZipArchive();
                    $ok = $check->open($part) === true && $check->numFiles > 0;
                    if ($ok) {
                        $check->close();
                    }
                }
                if (!$ok || !@rename($part, $absPath)) {
                    @unlink($part);
                    $stats['chapters_failed']++;
                    continue;
                }

                try {
                    $size = (int)filesize($absPath);
                    $insChap->execute([$t['manga_id'], $t['number'], $relPath, $size, $t['added'] ?? date('Y-m-d H:i:s')]);
                    $chapterId = (int)$pdo->lastInsertId();
                    if ($insProg && $t['progress']) {
                        $p = $t['progress'];
                        $insProg->execute([$chapterId, $t['manga_id'], $p['last_page'], $p['total_pages'], $p['finished'], $p['updated'] ?? date('Y-m-d H:i:s')]);
                    }
                    $stats['chapters_imported']++;
                } catch (Throwable $e) {
                    @unlink($absPath);   // no orphan file behind a failed insert
                    error_log('MangaTracker import chapter: ' . $e->getMessage());
                    $stats['chapters_failed']++;
                }
            }
            $zip->close();

            $done = $cur >= $total;
            if ($done) {
                imp_cleanup($id);
                mt_log('backup_restored', "imported={$stats['imported']} skipped={$stats['skipped']} covers={$stats['covers']} "
                    . "chapters={$stats['chapters_imported']} chapters_skipped={$stats['chapters_skipped']} chapters_failed={$stats['chapters_failed']}");
            } else {
                file_put_contents($f['cursor'], json_encode(['cursor' => $cur, 'stats' => $stats]));
            }
            echo json_encode(['success' => true, 'done' => $done, 'processed' => $cur, 'total_chapters' => $total, 'stats' => $stats]);
            break;

        // ─────────────────────────────────────────────────────────────────────
        case 'cancel':
            imp_cleanup(imp_id());
            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Action invalide');
    }

} catch (Throwable $e) {
    mt_json_fail($e);
}
