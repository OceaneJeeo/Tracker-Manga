<?php
/**
 * Get Chapter Image Script
 *
 * Streams a single page image out of a chapter's ZIP (read in memory, nothing
 * written to disk). The session is released immediately (read_and_close), so the
 * reader can load many pages in parallel instead of one after the other.
 *
 * GET params: chapter_id, index (zip_index from get_chapter_pages.php)
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_session_start();

if (!mt_is_authenticated()) {
    http_response_code(403);
    exit('Not authenticated');
}

$chapterId = (int)($_GET['chapter_id'] ?? 0);
$zipIndex  = isset($_GET['index']) ? (int)$_GET['index'] : -1;

if (!$chapterId || $zipIndex < 0) {
    http_response_code(400);
    exit('Bad request');
}

try {
    $stmt = $pdo->prepare("SELECT file_path FROM manga_chapters WHERE id = ?");
    $stmt->execute([$chapterId]);
    $filePath = $stmt->fetchColumn();

    if (!$filePath || !mt_is_chapter_path($filePath) || !is_file(mt_abs($filePath))) {
        http_response_code(404);
        exit('Not found');
    }

    $zip = new ZipArchive();
    if ($zip->open(mt_abs($filePath)) !== true) {
        http_response_code(500);
        exit('Cannot open archive');
    }

    $name = $zip->getNameIndex($zipIndex);
    if ($name === false) {
        $zip->close();
        http_response_code(404);
        exit('Page not found');
    }

    $data = $zip->getFromIndex($zipIndex);
    $zip->close();

    if ($data === false) {
        http_response_code(500);
        exit('Cannot read page data');
    }

    $mimeTypes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif',  'webp' => 'image/webp',
    ];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset($mimeTypes[$ext])) {
        http_response_code(415);
        exit('Unsupported type');
    }

    header('Content-Type: ' . $mimeTypes[$ext]);
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo $data;

} catch (Exception $e) {
    http_response_code(500);
    exit('Server error');
}
