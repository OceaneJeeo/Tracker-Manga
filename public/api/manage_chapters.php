<?php
/**
 * Chapter Management Script (upload / list / delete)
 *
 * Uploaded ZIPs are validated, then optimized to WebP (see webp_optimizer.php).
 * v2.1:
 *  - ZIP must be valid and contain at least one image
 *  - duplicate chapter number => error code "duplicate" (client asks, then resends with replace=1)
 *  - file name contains the manga id => no collisions between similar titles
 *  - no PHP time limit during optimization, and the request survives a closed tab
 *  - `list` also returns the saved reading progress of each chapter
 *  - deleting a chapter also deletes its reading progress
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/webp_optimizer.php';

mt_boot_json(true);

$action = $_POST['action'] ?? '';

try {
    mt_ensure_schema($pdo);

    switch ($action) {

        // ─────────────────────────────────────────────────────────────────────
        case 'upload':
            // Optimization can take a while on big chapters: lift the limits and
            // keep going even if the browser tab is closed.
            @set_time_limit(0);
            @ignore_user_abort(true);
            @ini_set('memory_limit', '512M');

            $mangaId       = (int)($_POST['manga_id'] ?? 0);
            $chapterNumber = trim($_POST['chapter_number'] ?? '');
            $replace       = ($_POST['replace'] ?? '') === '1';

            if (!$mangaId)       throw new Exception('Manga ID missing');
            if ($chapterNumber === '') throw new Exception('Chapter number missing');
            if (strlen($chapterNumber) > 50) throw new Exception('Chapter number too long (max 50)');
            if (!isset($_FILES['chapterFile'])) throw new Exception('No file in request');

            $fileError = $_FILES['chapterFile']['error'];
            if ($fileError !== UPLOAD_ERR_OK) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE   => 'File too large (PHP limit)',
                    UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
                    UPLOAD_ERR_PARTIAL    => 'File partially uploaded',
                    UPLOAD_ERR_NO_FILE    => 'No file uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Temporary directory missing',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
                    UPLOAD_ERR_EXTENSION  => 'PHP extension stopped upload',
                ];
                throw new Exception('Upload error: ' . ($errorMessages[$fileError] ?? "Unknown error ($fileError)"));
            }

            $tmp = $_FILES['chapterFile']['tmp_name'];

            // Must really be a ZIP
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tmp);
            finfo_close($finfo);
            if ($mimeType !== 'application/zip' && $mimeType !== 'application/x-zip-compressed') {
                throw new Exception("Invalid file type: $mimeType (ZIP required)");
            }

            $fileSize = $_FILES['chapterFile']['size'];
            if ($fileSize > 200 * 1024 * 1024) {
                throw new Exception('File too large (max 200MB)');
            }

            // ...and must contain at least one image
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new Exception('Corrupted or unreadable ZIP');
            }
            // Zip-bomb guard: limit entries and the total uncompressed size
            if ($zip->numFiles > 3000) {
                $zip->close();
                throw new Exception('ZIP trop volumineux (plus de 3000 fichiers)');
            }
            $imageCount = 0;
            $uncompressed = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $uncompressed += $st ? (int)$st['size'] : 0;
                if ($uncompressed > 1536 * 1024 * 1024) {
                    $zip->close();
                    throw new Exception('ZIP trop volumineux une fois décompressé (max 1,5 Go)');
                }
                $n = $zip->getNameIndex($i);
                if ($n === false || substr($n, -1) === '/' || strpos($n, '__MACOSX') !== false) continue;
                if (in_array(strtolower(pathinfo($n, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    $imageCount++;
                }
            }
            $zip->close();
            if ($imageCount === 0) {
                throw new Exception('This ZIP contains no images (jpg, png, gif, webp)');
            }

            $stmt = $pdo->prepare("SELECT title FROM mangas WHERE id = ?");
            $stmt->execute([$mangaId]);
            $manga = $stmt->fetch();
            if (!$manga) {
                throw new Exception("Manga not found (ID: $mangaId)");
            }

            // Duplicate chapter?
            $stmt = $pdo->prepare("SELECT id, file_path FROM manga_chapters WHERE manga_id = ? AND chapter_number = ?");
            $stmt->execute([$mangaId, $chapterNumber]);
            $existing = $stmt->fetch();

            if ($existing && !$replace) {
                echo json_encode([
                    'success' => false,
                    'code'    => 'duplicate',
                    'error'   => "Le chapitre $chapterNumber existe déjà",
                ]);
                break;
            }

            // Target folder (archives/ is closed to direct HTTP access by its .htaccess)
            mt_ensure_dir('archives', MT_DENY_ALL_HTACCESS);
            $relDir = 'archives/chapters/';
            $absDir = mt_abs($relDir);
            if (!is_dir($absDir) && !mkdir($absDir, 0755, true)) {
                throw new Exception("Cannot create directory: $relDir");
            }
            if (!is_writable($absDir)) {
                throw new Exception("Directory $relDir is not writable");
            }

            // File name: replacing => reuse the existing name; new => unique name
            $cleanTitle   = substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $manga['title']), 0, 80);
            $cleanChapter = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $chapterNumber);

            if ($existing && mt_is_chapter_path($existing['file_path'])) {
                $relPath = $existing['file_path'];
            } else {
                $base    = $mangaId . '_' . $cleanTitle . '_Chapter_' . $cleanChapter;
                $relPath = $relDir . $base . '.zip';
                $taken = $pdo->prepare("SELECT COUNT(*) FROM manga_chapters WHERE file_path = ?");
                $taken->execute([$relPath]);
                if ((int)$taken->fetchColumn() > 0 || is_file(mt_abs($relPath))) {
                    $relPath = $relDir . $base . '_' . uniqid() . '.zip';
                }
            }
            $absPath = mt_abs($relPath);

            if (!move_uploaded_file($tmp, $absPath)) {
                throw new Exception("Failed to move file to: $relPath");
            }

            try {
                // Re-encode pages to WebP. Problem pages keep their original bytes.
                $optimizedSize = optimizeChapterZip($absPath, function (string $msg) {
                    error_log('optimizeChapterZip: ' . trim($msg));
                });
                clearstatcache(true, $absPath);
                $fileSize = is_file($absPath) ? filesize($absPath) : $optimizedSize;

                if ($existing) {
                    $pdo->prepare("UPDATE manga_chapters SET file_path = ?, file_size = ?, date_added = NOW() WHERE id = ?")
                        ->execute([$relPath, $fileSize, $existing['id']]);
                    // Pages changed => old reading progress is meaningless
                    $pdo->prepare("DELETE FROM reading_progress WHERE chapter_id = ?")->execute([$existing['id']]);
                    $chapterId = (int)$existing['id'];
                } else {
                    $pdo->prepare("INSERT INTO manga_chapters (manga_id, chapter_number, file_path, file_size) VALUES (?, ?, ?, ?)")
                        ->execute([$mangaId, $chapterNumber, $relPath, $fileSize]);
                    $chapterId = (int)$pdo->lastInsertId();
                }
            } catch (Throwable $e) {
                // Don't leave an orphan ZIP behind a failed insert
                if (!$existing && is_file($absPath)) {
                    @unlink($absPath);
                }
                throw $e;
            }

            mt_log($existing ? 'chapter_replaced' : 'chapter_uploaded', "manga=$mangaId chapter=$chapterNumber");

            echo json_encode([
                'success'  => true,
                'replaced' => (bool)$existing,
                'chapter'  => [
                    'id'             => $chapterId,
                    'chapter_number' => $chapterNumber,
                    'file_path'      => $relPath,
                    'file_size'      => $fileSize,
                ],
            ]);
            break;

        // ─────────────────────────────────────────────────────────────────────
        case 'list':
            $mangaId = (int)($_POST['manga_id'] ?? 0);

            $hasProgress = mt_table_exists($pdo, 'reading_progress');
            $sql = $hasProgress
                ? "SELECT c.*, p.last_page, p.total_pages, p.finished
                   FROM manga_chapters c
                   LEFT JOIN reading_progress p ON p.chapter_id = c.id
                   WHERE c.manga_id = ?
                   ORDER BY CAST(c.chapter_number AS DECIMAL(10,2)) ASC, c.id ASC"
                : "SELECT * FROM manga_chapters WHERE manga_id = ?
                   ORDER BY CAST(chapter_number AS DECIMAL(10,2)) ASC, id ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$mangaId]);

            // file_path is internal: the browser downloads through download_chapter.php
            $chapters = array_map(function ($c) {
                unset($c['file_path']);
                return $c;
            }, $stmt->fetchAll());

            echo json_encode(['success' => true, 'chapters' => $chapters]);
            break;

        // ─────────────────────────────────────────────────────────────────────
        case 'delete':
            $chapterId = (int)($_POST['chapter_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT file_path FROM manga_chapters WHERE id = ?");
            $stmt->execute([$chapterId]);
            $chapter = $stmt->fetch();
            if (!$chapter) {
                throw new Exception('Chapter not found');
            }

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM reading_progress WHERE chapter_id = ?")->execute([$chapterId]);
            $pdo->prepare("DELETE FROM manga_chapters WHERE id = ?")->execute([$chapterId]);
            $pdo->commit();

            if (mt_is_chapter_path($chapter['file_path']) && is_file(mt_abs($chapter['file_path']))) {
                @unlink(mt_abs($chapter['file_path']));
            }

            mt_log('chapter_deleted', "id=$chapterId");
            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception("Invalid action: $action");
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in manage_chapters.php: " . $e->getMessage());
    mt_json_fail($e);
}
