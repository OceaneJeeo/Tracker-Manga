-- MangaTracker — database schema (fresh install)
-- Usage:  mysql -u root -p < database/schema.sql      (or paste it into phpMyAdmin > SQL)
--
-- The application also creates/updates `rating` and `reading_progress` by itself
-- (src/schema.php), so an older database needs no manual migration.

CREATE DATABASE IF NOT EXISTS manga_collection
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE manga_collection;

CREATE TABLE IF NOT EXISTS mangas (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    image           VARCHAR(500),                          -- local path (img/manga/…) or http(s) URL
    reading_link    VARCHAR(500) NOT NULL,                 -- external reading site
    current_chapter VARCHAR(100) NOT NULL,                 -- free text: "12", "Chap. 12", "Vol. 3"…
    status          ENUM('reading', 'completed') DEFAULT 'reading',
    language        VARCHAR(10) DEFAULT 'fr',
    notes           TEXT,
    rating          TINYINT UNSIGNED DEFAULT 0,            -- 0 = not rated, 1..5
    date_added      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_updated    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manga_chapters (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    manga_id       INT NOT NULL,
    chapter_number VARCHAR(50) NOT NULL,
    file_path      VARCHAR(255) NOT NULL,                  -- archives/chapters/<id>_<title>_Chapter_<n>.zip
    file_size      BIGINT NOT NULL,
    date_added     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manga_id) REFERENCES mangas(id) ON DELETE CASCADE,
    INDEX idx_manga_id (manga_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reading_progress (
    chapter_id   INT NOT NULL PRIMARY KEY,
    manga_id     INT NOT NULL,
    last_page    INT UNSIGNED NOT NULL DEFAULT 0,          -- 0-based
    total_pages  INT UNSIGNED NOT NULL DEFAULT 0,
    finished     TINYINT(1) NOT NULL DEFAULT 0,            -- sticky: re-reading never un-reads
    date_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_manga (manga_id),
    FOREIGN KEY (chapter_id) REFERENCES manga_chapters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
