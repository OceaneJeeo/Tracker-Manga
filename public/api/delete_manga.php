<?php
/**
 * Delete Manga Script
 *
 * Deletes a manga, its local cover AND all its chapter ZIP files
 * (previously the ZIPs stayed orphaned on disk).
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_boot_json(true);

try {
    mt_ensure_schema($pdo);

    $id = (int)($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT title, image FROM mangas WHERE id = ?");
    $stmt->execute([$id]);
    $manga = $stmt->fetch();

    if (!$manga) {
        throw new Exception('Manga not found');
    }

    // Collect chapter files BEFORE the rows disappear (ON DELETE CASCADE)
    $chapterFiles = [];
    if (mt_table_exists($pdo, 'manga_chapters')) {
        $stmt = $pdo->prepare("SELECT file_path FROM manga_chapters WHERE manga_id = ?");
        $stmt->execute([$id]);
        $chapterFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Database first: if this fails, no file has been touched
    $pdo->beginTransaction();
    if (mt_table_exists($pdo, 'reading_progress')) {
        $pdo->prepare("DELETE FROM reading_progress WHERE manga_id = ?")->execute([$id]);
    }
    if (mt_table_exists($pdo, 'manga_chapters')) {
        $pdo->prepare("DELETE FROM manga_chapters WHERE manga_id = ?")->execute([$id]);
    }
    $pdo->prepare("DELETE FROM mangas WHERE id = ?")->execute([$id]);
    $pdo->commit();

    // Then files
    if ($manga['image'] && strpos($manga['image'], 'img/') === 0 && strpos($manga['image'], '..') === false
        && is_file(mt_abs($manga['image']))) {
        @unlink(mt_abs($manga['image']));
    }
    $deletedZips = 0;
    foreach ($chapterFiles as $path) {
        if (mt_is_chapter_path($path) && is_file(mt_abs($path)) && @unlink(mt_abs($path))) {
            $deletedZips++;
        }
    }

    mt_log('manga_deleted', "id=$id title={$manga['title']} archives=$deletedZips");
    echo json_encode(['success' => true, 'deleted_archives' => $deletedZips]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    mt_json_fail($e);
}
