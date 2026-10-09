<?php
/**
 * Download Chapter Script
 *
 * Authenticated download of a chapter ZIP. The archives/ folder is blocked
 * for direct HTTP access (archives/.htaccess), so this is the only way in.
 *
 * GET params: chapter_id
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_session_start();

if (!mt_is_authenticated()) {
    http_response_code(403);
    exit('Not authenticated');
}

$chapterId = (int)($_GET['chapter_id'] ?? 0);
if (!$chapterId) {
    http_response_code(400);
    exit('Bad request');
}

$stmt = $pdo->prepare("SELECT file_path, chapter_number FROM manga_chapters WHERE id = ?");
$stmt->execute([$chapterId]);
$chapter = $stmt->fetch();

if (!$chapter || !mt_is_chapter_path($chapter['file_path']) || !is_file(mt_abs($chapter['file_path']))) {
    http_response_code(404);
    exit('Not found');
}

$abs  = mt_abs($chapter['file_path']);
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($chapter['file_path']));

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
readfile($abs);
