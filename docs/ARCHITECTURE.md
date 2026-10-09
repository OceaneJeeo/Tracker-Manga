# Architecture

## Folders

```
public/      the ONLY web-facing folder
  index.php          login + page shell (HTML)
  api/               JSON / file endpoints (see API.md)
  assets/css, js     stylesheet and the single-page script
  img/manga/         cover images
src/         PHP logic, no HTML, never served
  bootstrap.php      common start-up of the API endpoints ($pdo, session, auth, CSRF)
  security.php       sessions, headers, CSRF, login lock, journal, error helper, path helper
  schema.php         automatic migrations (rating column, reading_progress table)
  webp_optimizer.php ZIP → WebP conversion      webp_worker.php  one page, isolated process
config/      database credentials + password hash (never served, never committed)
storage/     private data: archives/chapters/*.zip, logs/security.log
tools/       command-line maintenance (optimize_existing_chapters.php)
database/    schema.sql
docs/        this documentation
```

`config/`, `src/`, `storage/` are protected twice: they are outside `public/`, and a rewrite rule in the root `.htaccess` (plus a `.htaccess` in each) answers 403 when the site is served from the project folder (XAMPP without a virtual host).

## Stored paths

The database and the page keep short, stable paths; `mt_abs()` (in `security.php`) maps them to the real folders:

| Stored / used as | Real folder |
|---|---|
| `archives/chapters/x.zip` | `storage/archives/chapters/x.zip` |
| `logs/security.log` | `storage/logs/security.log` |
| `img/manga/x.jpg` | `public/img/manga/x.jpg` (also the URL, relative to `public/`) |

Never `unlink()` a path from the database without `mt_is_chapter_path()` / `mt_abs()`.

## Life of a request

1. `assets/js/manga.js` wraps `fetch()`: it adds the `X-CSRF-Token` header to every POST and reloads the page on a 401 (expired session).
2. The endpoint includes `src/bootstrap.php` and calls `mt_boot_json()` (GET) or `mt_boot_json(true)` (POST):
   read-only session (released at once, so 30 page images load in parallel) → JSON header → authentication check → CSRF + Origin check.
3. The endpoint does its work with `$pdo` (prepared statements only) and echoes JSON.
4. Any `Throwable` goes to `mt_json_fail()`: only messages from our own `throw new Exception(...)` reach the browser.

`index.php` and `change_password.php` are the only scripts that open a **writable** session (login/logout, new CSRF token, new password epoch).

## Chapter pipeline

`upload` → checks (ZIP type, size, entries, zip-bomb limits, ≥ 1 image) → duplicate check → move to `storage/archives/chapters/<mangaId>_<Title>_Chapter_<n>.zip` → `optimizeChapterZip()` (each page re-encoded to WebP in its own PHP process, so a corrupt image cannot kill the request; a failing page keeps its original bytes) → INSERT/UPDATE → journal.

Reading: `get_chapter_pages` lists the images of the ZIP, `get_chapter_image` streams one entry. Nothing is ever extracted to disk.

## Reading progress

`reading_progress` has one row per chapter: last page, total pages, `finished` (sticky). The reader saves with a short debounce, on chapter change, on close and when the tab is hidden. "Reprendre" reopens the most recently touched chapter at its page, or the next chapter if that one was read to the end.

## Conventions

- Functions of the shared layer are prefixed `mt_`.
- Endpoints answer `{success, error?}`; HTTP status is used only for 401 / 403 / file endpoints.
- Code comments are in English, the user interface is in French.
- No framework, no build step: PHP + MySQL + one JavaScript file.

## Known limits / ideas

- `manga.js` is one large file with global functions and inline `onclick` handlers (this is why the CSP still allows `'unsafe-inline'` scripts). Splitting it into modules would allow a strict CSP.
- WebP conversion runs during the upload request (no background queue).
- No automated tests; the API was checked by hand with curl scenarios.
- Statistics count only reading done in the built-in reader.
