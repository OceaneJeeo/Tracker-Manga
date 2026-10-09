<?php
/**
 * MangaTracker v2.1 — Get Mangas
 * Also returns chapter_count and the last-read chapter/page/date (for "Reprendre").
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_boot_json();

try {
    mt_ensure_schema($pdo);

    $chaptersExist = mt_table_exists($pdo, 'manga_chapters');
    $progressExist = $chaptersExist && mt_table_exists($pdo, 'reading_progress');

    if ($chaptersExist) {
        if ($progressExist) {
            // Most recent reading-progress row of the manga (ties broken by chapter id)
            $last = "FROM reading_progress p WHERE p.manga_id = m.id ORDER BY p.date_updated DESC, p.chapter_id DESC LIMIT 1";
            $lastRead = "
                (SELECT p.chapter_id   $last) AS last_read_chapter_id,
                (SELECT p.last_page    $last) AS last_read_page,
                (SELECT p.total_pages  $last) AS last_read_total,
                (SELECT p.date_updated $last) AS last_read_at,
                (SELECT c2.chapter_number FROM reading_progress p
                    JOIN manga_chapters c2 ON c2.id = p.chapter_id
                    WHERE p.manga_id = m.id ORDER BY p.date_updated DESC, p.chapter_id DESC LIMIT 1) AS last_read_chapter_number";
        } else {
            $lastRead = "NULL AS last_read_chapter_id, NULL AS last_read_page, NULL AS last_read_total,
                         NULL AS last_read_at, NULL AS last_read_chapter_number";
        }
        $stmt = $pdo->query("
            SELECT m.*,
                COALESCE(m.rating, 0) AS rating,
                (SELECT COUNT(*) FROM manga_chapters c WHERE c.manga_id = m.id) AS chapter_count,
                $lastRead
            FROM mangas m
            ORDER BY m.date_added DESC
        ");
    } else {
        $stmt = $pdo->query("SELECT *, COALESCE(rating, 0) AS rating, 0 AS chapter_count,
            NULL AS last_read_chapter_id, NULL AS last_read_page, NULL AS last_read_total,
            NULL AS last_read_at, NULL AS last_read_chapter_number
            FROM mangas ORDER BY date_added DESC");
    }

    echo json_encode(['success' => true, 'mangas' => $stmt->fetchAll()]);

} catch (Throwable $e) {
    mt_json_fail($e);
}
