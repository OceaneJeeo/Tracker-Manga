<?php
/**
 * Restore a backup ZIP created by backup.php.
 *
 * Adds the manga that are NOT already in the collection (same title = skipped),
 * so a restore can never overwrite or delete anything. Cover images are restored too.
 * POST: backup (ZIP file)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_boot_json(true);

const ALLOWED_LANGS    = ['fr', 'en', 'ja', 'es', 'de', 'it', 'pt', 'ko', 'zh', 'other'];
const ALLOWED_STATUSES = ['reading', 'completed'];

try {
    mt_ensure_schema($pdo);
    @set_time_limit(0);

    if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Aucun fichier reçu (ou fichier trop gros)');
    }

    $zip = new ZipArchive();
    if ($zip->open($_FILES['backup']['tmp_name']) !== true) {
        throw new Exception('Ce fichier n\'est pas une archive ZIP valide');
    }

    $json = $zip->getFromName('collection.json');
    $data = $json === false ? null : json_decode($json, true);
    if (!is_array($data) || !isset($data['mangas']) || !is_array($data['mangas'])) {
        throw new Exception('Sauvegarde invalide : collection.json introuvable');
    }

    $imageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $coverDir   = 'img/manga/';
    $imported = 0; $skipped = 0; $invalid = 0; $covers = 0;

    $exists = $pdo->prepare("SELECT 1 FROM mangas WHERE LOWER(TRIM(title)) = LOWER(?) LIMIT 1");
    $insert = $pdo->prepare("
        INSERT INTO mangas (title, image, reading_link, current_chapter, status, language, notes, rating, date_added)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($data['mangas'] as $m) {
        $title   = trim((string)($m['title'] ?? ''));
        $link    = trim((string)($m['reading_link'] ?? ''));
        $chapter = trim((string)($m['current_chapter'] ?? ''));
        if ($title === '' || $chapter === '' || !mt_valid_http_url($link) || mb_strlen($title) > 255 || mb_strlen($chapter) > 100) {
            $invalid++;
            continue;
        }

        $exists->execute([$title]);
        if ($exists->fetchColumn()) {
            $skipped++;
            continue;
        }

        $status = in_array($m['status'] ?? '', ALLOWED_STATUSES, true) ? $m['status'] : 'reading';
        $lang   = in_array($m['language'] ?? '', ALLOWED_LANGS, true) ? $m['language'] : 'other';
        $rating = max(0, min(5, (int)($m['rating'] ?? 0)));
        $added  = (isset($m['date_added']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $m['date_added']))
                    ? $m['date_added'] : date('Y-m-d H:i:s');

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
                        $covers++;
                    }
                }
            }
        } elseif (mt_valid_http_url($img)) {
            $image = $img;
        }

        $insert->execute([$title, $image, $link, $chapter, $status, $lang, (string)($m['notes'] ?? ''), $rating, $added]);
        $imported++;
    }
    $zip->close();

    mt_log('backup_restored', "imported=$imported skipped=$skipped covers=$covers");
    echo json_encode([
        'success'  => true,
        'imported' => $imported,
        'skipped'  => $skipped,
        'invalid'  => $invalid,
        'covers'   => $covers,
    ]);

} catch (Throwable $e) {
    mt_json_fail($e);
}
