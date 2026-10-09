# API reference

All endpoints live in `public/api/` and return **JSON** (`{"success": true, …}` or `{"success": false, "error": "…"}`), except the three that return a file (`get_chapter_image`, `download_chapter`, `backup`).

## Rules for every endpoint

| Rule | Detail |
|---|---|
| Authentication | Session cookie `mangatracker_sid` (set by logging in on `index.php`). Not logged in → **HTTP 401** (files: 403) |
| CSRF | Every **POST** must send the header `X-CSRF-Token` (value of `<meta name="csrf-token">` on the page) from the same origin. Otherwise → **HTTP 403** |
| Errors | Messages written by the application are returned as-is; anything unexpected (SQL, PHP) returns `"Erreur interne du serveur"` and the detail goes to the PHP error log |
| Duplicates | `add_manga` and `manage_chapters` (upload) answer `"code": "duplicate"` when the title / chapter number already exists |

## Collection

### `GET get_mangas.php`
Returns `{"success": true, "mangas": [...]}`. Each manga has the table columns (`id, title, image, reading_link, current_chapter, status, language, notes, rating, date_added, date_updated`) plus `chapter_count` and the last reading position: `last_read_chapter_id`, `last_read_chapter_number`, `last_read_page` (0-based), `last_read_total`, `last_read_at`.

### `POST add_manga.php` (multipart)
Creates a manga, or updates it when `id` is given.

| Field | Required | Notes |
|---|---|---|
| `id` | no | present = update |
| `title` | yes | max 255 |
| `readingLink` | yes | `http(s)://` only |
| `currentChapter` | yes | free text, max 100 |
| `status` | no | `reading` (default) / `completed` |
| `language` | no | `fr en ja es de it pt ko zh other` (anything else → `other`) |
| `notes`, `rating` (0–5) | no | |
| `imageFile` | no | jpeg / png / gif / webp, max 5 MB, must be a real image |
| `imageUrl` | no | `http(s)://` only; ignored if `imageFile` is sent |
| `force` | no | `1` = add even if a manga with the same title exists |

Response: `{"success": true, "id": 12}`.

### `POST delete_manga.php`
`id` → deletes the manga, its local cover, **all its chapter ZIP files** and its reading progress.

## Chapters

### `POST manage_chapters.php`
Field `action` selects the operation.

| `action` | Fields | Result |
|---|---|---|
| `upload` | `manga_id`, `chapter_number`, `chapterFile` (ZIP), optional `replace=1` | Validates the ZIP, converts pages to WebP, stores it. `{"success": true, "replaced": false, "chapter": {id, chapter_number, file_path, file_size}}` |
| `list` | `manga_id` | `{"chapters": [...]}` sorted by number, with `last_page`, `total_pages`, `finished` (internal `file_path` is not exposed) |
| `delete` | `chapter_id` | Deletes the ZIP, the row and its progress |

Upload limits: 200 MB, ≤ 3000 files, ≤ 1.5 GB unpacked, at least one image.

### `GET get_chapter_pages.php?chapter_id=ID`
`{"total_pages": 30, "pages": [{"page": 1, "zip_index": 0}, …], "progress": {last_page, total_pages, finished} | null}` — pages are naturally sorted (`page2` before `page10`).

### `GET get_chapter_image.php?chapter_id=ID&index=ZIP_INDEX`
Streams one page (read in memory from the ZIP, nothing is extracted). Cacheable for 24 h by the browser only.

### `GET download_chapter.php?chapter_id=ID`
Downloads the chapter ZIP.

## Reading

### `POST save_progress.php`
`chapter_id`, `page` (0-based), `total`, `auto_update` (`0`/`1`).
Stores the position. When the **last page** is reached the chapter is marked `finished` (never un-finished), and if `auto_update=1` the manga's `current_chapter` moves up to this chapter — only if it is higher; a prefix/suffix such as `Chap. ` is kept.
Response: `{"success": true, "finished": true, "current_chapter": "Chap. 8" | null, "manga_id": 3}`.

### `GET get_stats.php`
Reading statistics (`total`, `week`, `month`, `daily`, `top`, `streak`, `habits`) and collection statistics (`collection`: by status / language / rating, added per month, archive storage). See the source for the exact shape.

## Backup & account

| Endpoint | Method | Description |
|---|---|---|
| `backup.php` | GET | ZIP with `collection.json` + local covers (chapters are not included) |
| `restore_backup.php` | POST `backup` (ZIP) | Adds the manga that are missing (same title = skipped). `{"imported", "skipped", "invalid", "covers"}` |
| `change_password.php` | POST `current`, `new` (≥ 10 chars), `confirm` | Rewrites `config/auth.php`; all other sessions are signed out |
| `get_security_log.php` | GET | Last 100 entries of the security journal |
