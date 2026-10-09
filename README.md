# 📚 Manga Tracker

A personal manga collection manager built with PHP, MySQL and vanilla JavaScript: track what you read, archive chapters as ZIP files, and read them in a built-in reader that remembers where you stopped.

## ✨ Features

- **Collection** – add / edit / delete manga, status (reading / completed), reading language, rating (1–5 ★), personal notes, cover upload or URL
- **Views & filters** – grid or list view, sort (date, title, chapter, rating), filter by status and language, live search (title, notes, chapter), 3 themes (dark, light, AMOLED)
- **Built-in reader** – chapters are stored as ZIP files and read straight from the archive (nothing is extracted); *scroll* mode and *page by page* mode, keyboard arrows, swipe on touch screens, continuous reading across chapters
- **Resume reading** – the last page of every chapter is saved; the **📖 Reprendre** button reopens the last chapter at the right page (or starts the next one if you finished it). Chapters show `📍 p. 12/30` or `✓ Lu`
- **Automatic current chapter** – when you reach the last page of a chapter, the manga's *current chapter* moves up (prefix/suffix such as `Chap. ` is kept; only if the new chapter is higher). Can be turned off in ⚙️
- **Chapter archive** – single or multiple ZIP upload with progress bar, duplicate detection (offers to replace), ZIP validation, automatic WebP optimization of every page
- **Continue reading strip** – the 6 manga you read most recently, with chapter, page and progress bar, one click to resume
- **Statistics** (⚙️ > Statistiques) – *Reading* tab: chapters finished, pages read, last 7 / 30 days, 14-day chart, current and record streak, favourite weekday, pages per active day, completion rate, most-read manga. *Collection* tab: totals, average rating, archive size, split by status / language / rating, manga added per month, heaviest archives
- **Duplicate detection** – adding a title that already exists asks for confirmation; JSON import skips duplicates
- **Full backup** – ⚙️ > *Sauvegarde complète (ZIP)* exports every manga **and their local cover images**; *Restaurer une sauvegarde* adds back what is missing without overwriting anything (chapter archives are not included - they are too heavy)
- **Quick actions** – `+1 ch.` button, 🌐 button to open the external reading site, import / export of the collection (JSON)
- **Security** – see the *Security* section below (session hardening, security headers, CSRF + Origin check, login lock, change password from the UI, security journal, protected folders, upload hardening)

## 🚀 Installation

### Prerequisites

- PHP 7.4+ (8.x recommended) with the `zip`, `fileinfo`, `mbstring`, `pdo_mysql` and `gd` (with WebP) extensions
- MySQL 5.7+ / MariaDB, Apache with `mod_rewrite` (XAMPP has everything)

### Steps (XAMPP)

1. Put the project folder in `C:\xampp\htdocs\` (e.g. `C:\xampp\htdocs\Gestionnaire`).
2. Create the database: import `database/schema.sql` in phpMyAdmin (or `mysql -u root -p < database/schema.sql`).
3. Copy `config/mysql.example.php` to `config/mysql.php` and fill in your credentials.
4. Copy `config/auth.example.php` to `config/auth.php` (temporary password `ChangeMe-2026!`).
5. Open `http://localhost/Gestionnaire/` (redirects to `public/`), log in, and change the password: **⚙️ > Changer le mot de passe**.

`public/img/manga/`, `storage/archives/chapters/` and `storage/logs/` are created automatically if missing.

### Better: serve only `public/`

With the project folder served as is, the rewrite rules of the root `.htaccess` hide everything except `public/`. The cleanest setup is a virtual host whose `DocumentRoot` is the `public/` folder - then `config/`, `src/` and `storage/` are not reachable at all.

```apache
<VirtualHost *:80>
    ServerName manga.local
    DocumentRoot "C:/xampp/htdocs/Gestionnaire/public"
    <Directory "C:/xampp/htdocs/Gestionnaire/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
(and `127.0.0.1 manga.local` in the Windows `hosts` file).

### Upgrading from the old flat layout (v2.x)

| Old location | New location |
|---|---|
| `index.php` | `public/index.php` |
| `add_manga.php`, `get_*.php`, `delete_manga.php`, `manage_chapters.php`, `save_progress.php`, `download_chapter.php`, `backup.php`, `restore_backup.php`, `change_password.php` | `public/api/` |
| `js/manga.js`, `style/manga.css` | `public/assets/js/`, `public/assets/css/` |
| `config/security.php`, `config/schema.php`, `webp_optimizer.php`, `webp_worker.php` | `src/` |
| `optimize_existing_chapters.php` | `tools/` (command line only) |
| `config/mysql.php`, `config/auth.php` | unchanged (`config/`) - **keep yours** |
| **your covers** `img/manga/*` | `public/img/manga/` |
| **your chapters** `archives/chapters/*` | `storage/archives/chapters/` |
| `logs/security.log` | `storage/logs/` |
| `.htaccess` (root) | replaced by the new one (same upload limits + protection rules) |

The paths stored in the database do **not** change.

### Project structure

```
manga-tracker/
├── public/                 # the only web-facing folder
│   ├── index.php           # login + application page
│   ├── api/                # JSON endpoints (documented in docs/API.md)
│   ├── assets/css/ js/     # stylesheet + script
│   └── img/manga/          # cover images
├── src/                    # PHP logic (security, schema, WebP pipeline)
├── config/                 # database credentials + password hash (not committed)
├── storage/                # chapter ZIPs + security journal (not committed)
├── tools/                  # command-line maintenance scripts
├── database/schema.sql
├── docs/                   # API.md, ARCHITECTURE.md
├── .htaccess  .gitignore  LICENSE  README.md
└── index.php               # redirects to public/ (XAMPP convenience)
```
See `docs/ARCHITECTURE.md` for how the pieces work together.

### Optimizing chapters stored before WebP optimization

Command line only, from the project folder:
```bash
C:\xampp\php\php.exe tools\optimize_existing_chapters.php
```
Interrupted? Resume after a chapter id with `php tools/optimize_existing_chapters.php <id>`.

## 📖 Usage

- **Add a manga** – *+ Ajouter*: title, chapter and reading link are required.
- **Read** – on a card, **📖 Reprendre** opens the built-in reader when chapters are archived (otherwise the external site). The hover button **🌐** always opens the external reading site.
- **Archive chapters** – 📦 on a card → choose one or several ZIP files (images inside; the chapter number is guessed from the file name and editable). Uploading an existing chapter number asks whether to replace it.
- **Reader** – arrows ← → (page mode), swipe on mobile, `Esc` to close, ⏮ ⏭ to change chapter, 📜 / 📄 to change mode. Progress is saved automatically.
- **Import / export** – ⚙️ menu. The JSON export contains the manga entries only; local cover images and reading progress are not in it - use the **ZIP backup** to keep the covers. Reading progress is tied to the chapter archives, so it is not part of the backup.

## 🔒 Security

| Protection | How |
|---|---|
| Login | Password hash (bcrypt). 5 wrong passwords lock the login for 5 minutes (per IP) and every failure is slowed down |
| Sessions | Own cookie name, `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, new id at login, **12 h maximum**, tied to the browser, signed out when the password changes |
| CSRF | Token header **and** same-origin check on every POST |
| Headers | Content-Security-Policy (no framing, no plugins, no requests to other sites), `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS on HTTPS, `no-store` |
| Files | `config/`, `src/`, `storage/` are outside `public/` and also closed by `.htaccess` + rewrite rules (`.git` too); nothing can execute in `public/img/manga/`; chapters are downloaded through an authenticated script |
| Uploads | Covers: real image type + decodable image; ZIPs: valid, max 200 MB, max 3000 files, max 1.5 GB once unpacked; pages above 100 megapixels are left untouched |
| Errors | SQL errors, file paths and PHP messages never reach the browser (they go to the PHP error log) |
| Journal | ⚙️ > *Journal de sécurité* (`storage/logs/security.log`): logins, failures, locks, password changes, backups, deletions, rejected requests |

Still up to you if the site leaves your own machine: use **HTTPS**, and keep PHP / Apache up to date.

## 🐛 Troubleshooting

| Symptom | Cause / fix |
|---|---|
| *Forbidden* on the whole site | A `.htaccess` containing `Require all denied` is at the site root. Those files belong only in `config/`, `src/` and `storage/` (the root `.htaccess` has no *deny* line). |
| `Call to undefined function mt_…()` | An old copy of `config/security.php`. Replace it with the current one. |
| *Réponse invalide du serveur…* in the reader | The server returned a PHP error instead of JSON; the message shown tells which. Check `xampp\php\logs\php_error_log`. |
| Upload fails | Check the root `.htaccess` limits, free disk space and write permissions on `storage/archives/chapters/`. |
| Pages stay un-optimized | Enable `extension=gd` (with WebP) in `php.ini`, restart Apache, then run `tools/optimize_existing_chapters.php`. |
| Logged out on every click | Cookies blocked, or the PHP session folder is not writable. |
| Signed out after an update | Normal once: sessions made by an older version are refused. Log in again. |
| *Invalid CSRF token - reload the page* | The page was opened a long time ago or on another address (`localhost` vs `127.0.0.1`). Reload it. |
| *Forbidden* on a page you could open before | A *deny* `.htaccess` ended up in the wrong folder, or you opened a private folder (`config/`, `src/`, `storage/`) - the site is `public/`. |

## 📝 Version history

- **3.0** – restructured project: `public/` web root, `src/` logic, `storage/` private data, `tools/`, `database/schema.sql`, `docs/`, shared `bootstrap.php`, MIT license.
- **2.3** – extended statistics (collection, habits, records), change password from the UI, security journal, session hardening, CSP and security headers, Origin check, hardened uploads, no internal error leaks.
- **2.2** – continue-reading strip, reading statistics, duplicate detection, full ZIP backup / restore.
- **2.1** – reading progress + resume, automatic current chapter, authenticated downloads, protected archives, CSRF, login lock, duplicate-chapter handling, ZIP cleanup on delete, input validation, XSS fixes, parallel page loading in the reader.
- **2.0** – grid/list views, sort/filter, ratings, themes, built-in reader, WebP optimization, import/export.
- **1.1** – reading language, search bar, chapter-count badge.

## 📝 License

MIT - see `LICENSE`.
