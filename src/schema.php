<?php
/**
 * Graceful schema migrations (run automatically, idempotent).
 *  - mangas.rating column
 *  - reading_progress table (resume reading + auto chapter update)
 */
function mt_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->query("SELECT rating FROM mangas LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE mangas ADD COLUMN rating TINYINT UNSIGNED DEFAULT 0 AFTER notes");
    }

    if ($pdo->query("SHOW TABLES LIKE 'manga_chapters'")->rowCount() === 0) {
        return;
    }

    $cols = "
        chapter_id   INT NOT NULL PRIMARY KEY,
        manga_id     INT NOT NULL,
        last_page    INT UNSIGNED NOT NULL DEFAULT 0,
        total_pages  INT UNSIGNED NOT NULL DEFAULT 0,
        finished     TINYINT(1) NOT NULL DEFAULT 0,
        date_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_manga (manga_id)";
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reading_progress ($cols,
            FOREIGN KEY (chapter_id) REFERENCES manga_chapters(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        // FK impossible (engine/type mismatch): create without it — deletes are handled in PHP too.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS reading_progress ($cols) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e2) {
            error_log('mt_ensure_schema: ' . $e2->getMessage());
        }
    }
}

function mt_table_exists(PDO $pdo, string $table): bool
{
    return $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->rowCount() > 0;
}
