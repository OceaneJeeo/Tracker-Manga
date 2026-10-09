<?php
/**
 * Save Reading Progress
 *
 * POST: chapter_id, page (0-based), total (pages in the chapter), auto_update (0|1)
 *
 * - stores the last page read for this chapter (resume reading)
 * - once the LAST page is reached the chapter is flagged as finished, and if
 *   auto_update=1 the manga's current_chapter moves up to this chapter
 *   (only if it is higher; any prefix/suffix such as "Chap. " is preserved).
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_boot_json(true);

try {
    mt_ensure_schema($pdo);

    $chapterId  = (int)($_POST['chapter_id'] ?? 0);
    $page       = max(0, (int)($_POST['page'] ?? 0));
    $total      = max(0, (int)($_POST['total'] ?? 0));
    $autoUpdate = ($_POST['auto_update'] ?? '0') === '1';

    if (!$chapterId) {
        throw new Exception('Missing chapter_id');
    }
    if ($total > 0) {
        $page = min($page, $total - 1);
    }

    $stmt = $pdo->prepare("SELECT manga_id, chapter_number FROM manga_chapters WHERE id = ?");
    $stmt->execute([$chapterId]);
    $chapter = $stmt->fetch();
    if (!$chapter) {
        throw new Exception('Chapter not found');
    }

    $finished = ($total > 0 && $page >= $total - 1) ? 1 : 0;

    // `finished` is sticky: re-reading a chapter never un-reads it
    $pdo->prepare("
        INSERT INTO reading_progress (chapter_id, manga_id, last_page, total_pages, finished)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            manga_id     = VALUES(manga_id),
            last_page    = VALUES(last_page),
            total_pages  = VALUES(total_pages),
            finished     = GREATEST(finished, VALUES(finished)),
            date_updated = NOW()
    ")->execute([$chapterId, $chapter['manga_id'], $page, $total, $finished]);

    $newCurrent = null;

    if ($finished && $autoUpdate && is_numeric($chapter['chapter_number'])) {
        $stmt = $pdo->prepare("SELECT current_chapter FROM mangas WHERE id = ?");
        $stmt->execute([$chapter['manga_id']]);
        $current = (string)$stmt->fetchColumn();

        if (preg_match('/^(\D*)(\d+(?:\.\d+)?)(\D*)$/', $current, $m)
            && (float)$chapter['chapter_number'] > (float)$m[2]) {
            $newCurrent = $m[1] . $chapter['chapter_number'] . $m[3];
            $pdo->prepare("UPDATE mangas SET current_chapter = ?, date_updated = NOW() WHERE id = ?")
                ->execute([$newCurrent, $chapter['manga_id']]);
        }
    }

    echo json_encode([
        'success'         => true,
        'finished'        => (bool)$finished,
        'current_chapter' => $newCurrent,
        'manga_id'        => (int)$chapter['manga_id'],
    ]);

} catch (Throwable $e) {
    mt_json_fail($e);
}
