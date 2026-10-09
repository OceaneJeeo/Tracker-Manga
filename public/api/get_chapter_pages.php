<?php
/**
 * Get Chapter Pages Script
 *
 * Lists the readable image pages inside a chapter's ZIP (nothing is extracted),
 * naturally sorted, plus the saved reading progress for this chapter.
 *
 * GET params: chapter_id
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_boot_json();

try {
    mt_ensure_schema($pdo);

    $chapterId = (int)($_GET['chapter_id'] ?? 0);
    if (!$chapterId) {
        throw new Exception('Missing chapter_id');
    }

    $stmt = $pdo->prepare("SELECT * FROM manga_chapters WHERE id = ?");
    $stmt->execute([$chapterId]);
    $chapter = $stmt->fetch();

    if (!$chapter) {
        throw new Exception('Chapter not found');
    }
    if (!mt_is_chapter_path($chapter['file_path']) || !is_file(mt_abs($chapter['file_path']))) {
        throw new Exception('Archive file missing on disk');
    }

    $zip = new ZipArchive();
    if ($zip->open(mt_abs($chapter['file_path'])) !== true) {
        throw new Exception('Cannot open archive');
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if ($name === false || substr($name, -1) === '/') continue;
        if (strpos($name, '__MACOSX') !== false) continue;
        $base = basename($name);
        if ($base === '' || $base[0] === '.') continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) continue;

        $entries[] = ['index' => $i, 'name' => $name];
    }
    $zip->close();

    if (empty($entries)) {
        throw new Exception('No readable images found in this archive');
    }

    usort($entries, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    $pages = [];
    foreach (array_values($entries) as $pos => $e) {
        $pages[] = ['page' => $pos + 1, 'zip_index' => $e['index']];
    }

    $progress = null;
    if (mt_table_exists($pdo, 'reading_progress')) {
        $p = $pdo->prepare("SELECT last_page, total_pages, finished FROM reading_progress WHERE chapter_id = ?");
        $p->execute([$chapterId]);
        $progress = $p->fetch() ?: null;
    }

    echo json_encode([
        'success'        => true,
        'chapter_number' => $chapter['chapter_number'],
        'manga_id'       => $chapter['manga_id'],
        'total_pages'    => count($pages),
        'pages'          => $pages,
        'progress'       => $progress,
    ]);

} catch (Throwable $e) {
    mt_json_fail($e);
}
