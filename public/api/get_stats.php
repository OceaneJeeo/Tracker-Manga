<?php
/**
 * Reading statistics, computed from reading_progress.
 * Only reading done in the built-in reader is counted (progress is saved there).
 */

require_once __DIR__ . '/../../src/bootstrap.php';

mt_boot_json();

try {
    mt_ensure_schema($pdo);

    // ── Collection statistics (do not depend on reading progress) ────────────
    $collection = [];
    $collection['total']      = (int)$pdo->query("SELECT COUNT(*) FROM mangas")->fetchColumn();
    $collection['with_notes'] = (int)$pdo->query("SELECT COUNT(*) FROM mangas WHERE notes IS NOT NULL AND notes <> ''")->fetchColumn();
    $collection['by_status']  = $pdo->query("SELECT status, COUNT(*) AS n FROM mangas GROUP BY status")->fetchAll();
    $collection['by_language'] = $pdo->query("SELECT language, COUNT(*) AS n FROM mangas GROUP BY language ORDER BY n DESC")->fetchAll();

    $ratings = array_fill(0, 6, 0);
    foreach ($pdo->query("SELECT COALESCE(rating, 0) AS r, COUNT(*) AS n FROM mangas GROUP BY r")->fetchAll() as $r) {
        $ratings[max(0, min(5, (int)$r['r']))] = (int)$r['n'];
    }
    $collection['ratings'] = $ratings;
    $avg = $pdo->query("SELECT AVG(rating) FROM mangas WHERE rating > 0")->fetchColumn();
    $collection['avg_rating'] = $avg !== null && $avg !== false ? round((float)$avg, 2) : null;

    // Manga added per month (12 months)
    $perMonth = [];
    foreach ($pdo->query("
        SELECT DATE_FORMAT(date_added, '%Y-%m') AS ym, COUNT(*) AS n
        FROM mangas
        WHERE date_added >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01')
        GROUP BY ym")->fetchAll() as $r) {
        $perMonth[$r['ym']] = (int)$r['n'];
    }
    $collection['added_per_month'] = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
        $collection['added_per_month'][] = ['month' => $ym, 'n' => $perMonth[$ym] ?? 0];
    }

    // Archive storage
    $collection['storage'] = ['chapters' => 0, 'bytes' => 0, 'manga_with_chapters' => 0, 'heaviest' => []];
    if (mt_table_exists($pdo, 'manga_chapters')) {
        $st = $pdo->query("SELECT COUNT(*) AS chapters, COALESCE(SUM(file_size), 0) AS bytes, COUNT(DISTINCT manga_id) AS manga_with_chapters FROM manga_chapters")->fetch();
        $collection['storage'] = [
            'chapters'            => (int)$st['chapters'],
            'bytes'               => (int)$st['bytes'],
            'manga_with_chapters' => (int)$st['manga_with_chapters'],
            'heaviest'            => $pdo->query("
                SELECT m.id, m.title, COUNT(*) AS chapters, SUM(c.file_size) AS bytes
                FROM manga_chapters c JOIN mangas m ON m.id = c.manga_id
                GROUP BY m.id, m.title
                ORDER BY bytes DESC
                LIMIT 5")->fetchAll(),
        ];
    }

    $empty = [
        'success'    => true,
        'total'      => ['chapters_started' => 0, 'chapters_finished' => 0, 'pages' => 0, 'manga' => 0],
        'week'       => ['chapters' => 0, 'pages' => 0, 'manga' => 0],
        'month'      => ['chapters' => 0, 'pages' => 0, 'manga' => 0],
        'daily'      => [],
        'top'        => [],
        'streak'     => 0,
        'habits'     => ['weekday' => array_fill(0, 7, 0), 'longest_streak' => 0, 'active_days' => 0,
                         'avg_pages_per_day' => 0, 'completion_rate' => null, 'first_read' => null],
        'collection' => $collection,
    ];
    if (!mt_table_exists($pdo, 'reading_progress')) {
        echo json_encode($empty);
        exit;
    }

    // Pages read in a chapter: all of them if finished, otherwise up to the last page reached
    $pages = "CASE WHEN finished = 1 THEN total_pages ELSE last_page + 1 END";

    $total = $pdo->query("
        SELECT COUNT(*) AS chapters_started,
               COALESCE(SUM(finished), 0) AS chapters_finished,
               COALESCE(SUM($pages), 0)   AS pages,
               COUNT(DISTINCT manga_id)   AS manga
        FROM reading_progress")->fetch();

    $period = function (int $days) use ($pdo, $pages): array {
        $st = $pdo->prepare("
            SELECT COUNT(*) AS chapters,
                   COALESCE(SUM($pages), 0) AS pages,
                   COUNT(DISTINCT manga_id) AS manga
            FROM reading_progress
            WHERE date_updated >= (NOW() - INTERVAL $days DAY)");
        $st->execute();
        return $st->fetch();
    };

    // Chapters last touched per day (14 days). date_updated holds the LAST time a chapter was read.
    $rows = $pdo->query("
        SELECT DATE(date_updated) AS d, COUNT(*) AS chapters, COALESCE(SUM($pages), 0) AS pages
        FROM reading_progress
        WHERE date_updated >= (CURDATE() - INTERVAL 13 DAY)
        GROUP BY DATE(date_updated)")->fetchAll();
    $byDay = [];
    foreach ($rows as $r) {
        $byDay[$r['d']] = ['chapters' => (int)$r['chapters'], 'pages' => (int)$r['pages']];
    }
    $daily = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $daily[] = ['date' => $d] + ($byDay[$d] ?? ['chapters' => 0, 'pages' => 0]);
    }

    // Streaks (current + longest ever) from every distinct reading day
    $dates = $pdo->query("SELECT DISTINCT DATE(date_updated) AS d FROM reading_progress ORDER BY d ASC")
                 ->fetchAll(PDO::FETCH_COLUMN);
    $set = array_flip($dates);
    $streak = 0;
    $cursor = isset($set[date('Y-m-d')]) ? 0 : 1;   // nothing read yet today: the streak can still be alive
    while (isset($set[date('Y-m-d', strtotime("-$cursor day"))])) {
        $streak++;
        $cursor++;
    }
    $longest = 0; $run = 0; $prev = null;
    foreach ($dates as $d) {
        $run = ($prev !== null && strtotime($d) - strtotime($prev) === 86400) ? $run + 1 : 1;
        $longest = max($longest, $run);
        $prev = $d;
    }

    // Habits: which weekday you read most (0 = Monday), pages per reading day, completion rate
    $weekday = array_fill(0, 7, 0);
    foreach ($pdo->query("SELECT WEEKDAY(date_updated) AS wd, COUNT(*) AS n FROM reading_progress GROUP BY wd")->fetchAll() as $r) {
        $weekday[(int)$r['wd']] = (int)$r['n'];
    }
    $m30 = $pdo->query("
        SELECT COUNT(DISTINCT DATE(date_updated)) AS days, COALESCE(SUM($pages), 0) AS pages
        FROM reading_progress WHERE date_updated >= (NOW() - INTERVAL 30 DAY)")->fetch();
    $started = (int)$total['chapters_started'];

    $top = $pdo->query("
        SELECT m.id, m.title, COUNT(*) AS chapters, SUM(p.total_pages) AS pages
        FROM reading_progress p
        JOIN mangas m ON m.id = p.manga_id
        WHERE p.finished = 1
        GROUP BY m.id, m.title
        ORDER BY chapters DESC, pages DESC
        LIMIT 5")->fetchAll();

    echo json_encode([
        'success'    => true,
        'total'      => array_map('intval', $total),
        'week'       => array_map('intval', $period(7)),
        'month'      => array_map('intval', $period(30)),
        'daily'      => $daily,
        'top'        => $top,
        'streak'     => $streak,
        'habits'     => [
            'weekday'           => $weekday,
            'longest_streak'    => $longest,
            'active_days'       => count($dates),
            'avg_pages_per_day' => (int)$m30['days'] > 0 ? (int)round($m30['pages'] / $m30['days']) : 0,
            'completion_rate'   => $started > 0 ? round(100 * (int)$total['chapters_finished'] / $started) : null,
            'first_read'        => $dates[0] ?? null,
        ],
        'collection' => $collection,
    ]);

} catch (Throwable $e) {
    mt_json_fail($e);
}
