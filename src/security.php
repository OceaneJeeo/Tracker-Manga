<?php
/**
 * MangaTracker — shared security helpers
 *
 *  Sessions   dedicated name, strict mode, HttpOnly + SameSite (+ Secure on HTTPS),
 *             12 h maximum age, bound to the browser, signed out on password change
 *  Headers    CSP, X-Frame-Options, nosniff, Referrer-Policy, HSTS (HTTPS), no-store
 *  CSRF       token header + Origin check on every POST
 *  Login      5 failures => 5 min lock (per IP) + small delay on each failure
 *  Journal    logs/security.log (logins, password change, backups, deletions…)
 *  Errors     internal details (SQL, paths) never reach the browser
 */

require_once dirname(__DIR__) . '/config/auth.php';
if (!defined('MT_AUTH_EPOCH')) {
    define('MT_AUTH_EPOCH', 0);   // auth.php written by an older version
}

const MT_SESSION_MAX_AGE = 43200;   // 12 hours

// ── Environment ──────────────────────────────────────────────────────────────

function mt_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function mt_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

// ── Headers ──────────────────────────────────────────────────────────────────

/** Sent with every response that goes through mt_session_start(). */
function mt_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-store');   // endpoints that want caching (page images) override it
    if (mt_is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/**
 * Content-Security-Policy for the HTML page. Inline handlers (onclick=…) are still
 * used by the UI, hence 'unsafe-inline' for scripts; everything else is locked down:
 * no plugins, no framing, no foreign forms, no requests to other sites.
 */
function mt_html_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header("Content-Security-Policy: default-src 'self'; "
         . "script-src 'self' 'unsafe-inline'; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com data:; "
         . "img-src 'self' data: blob: https: http:; "
         . "connect-src 'self'; object-src 'none'; base-uri 'self'; "
         . "form-action 'self'; frame-ancestors 'none'");
}

// ── Sessions ─────────────────────────────────────────────────────────────────

/**
 * Starts the session. API endpoints use $readOnly = true (session released at once,
 * $_SESSION stays readable, parallel requests don't block each other).
 * Only index.php and change_password.php need a writable session.
 */
function mt_session_start(bool $readOnly = true): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('mangatracker_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => mt_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if ($readOnly) {
        session_start(['read_and_close' => true]);
    } else {
        session_start();
    }
    mt_security_headers();
    mt_enforce_session_limits($readOnly);
}

/** Drops an authenticated session that is too old, bound to another browser, or from before a password change. */
function mt_enforce_session_limits(bool $writable): void
{
    if (($_SESSION['authenticated'] ?? false) !== true) {
        return;
    }
    $ok = ($_SESSION['epoch'] ?? null) === MT_AUTH_EPOCH
       && (time() - (int)($_SESSION['login_at'] ?? 0)) <= MT_SESSION_MAX_AGE
       && hash_equals((string)($_SESSION['ua'] ?? ''), mt_ua_hash());
    if ($ok) {
        return;
    }
    if ($writable) {
        $_SESSION = [];            // persisted when the session closes
    } else {
        $_SESSION['authenticated'] = false;   // in memory only (session already closed)
    }
}

function mt_ua_hash(): string
{
    return hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
}

/** Marks the (writable) session as logged in. Call after a successful password check. */
function mt_login_session(): void
{
    session_regenerate_id(true);   // prevents session fixation
    $_SESSION = [
        'authenticated' => true,
        'login_at'      => time(),
        'epoch'         => MT_AUTH_EPOCH,
        'ua'            => mt_ua_hash(),
        'csrf'          => bin2hex(random_bytes(32)),
    ];
}

function mt_logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function mt_is_authenticated(): bool
{
    return ($_SESSION['authenticated'] ?? false) === true;
}

/** JSON endpoints: stop with 401 if not logged in. */
function mt_require_auth_json(): void
{
    if (!mt_is_authenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
}

// ── CSRF ─────────────────────────────────────────────────────────────────────

/** Returns (and creates if needed) the CSRF token. Needs a writable session. */
function mt_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** POST endpoints: token header + same-origin check. */
function mt_require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $real = $_SESSION['csrf'] ?? '';
    $bad  = ($real === '' || !hash_equals($real, $sent));

    // A browser always sends Origin on cross-site POSTs: it must be this very site
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!$bad && $origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $got  = strtolower(($host ?: '') . ($port ? ':' . $port : ''));
        $bad  = ($got !== strtolower($_SERVER['HTTP_HOST'] ?? ''));
    }
    if ($bad) {
        mt_log('csrf_rejected', ($_SERVER['REQUEST_URI'] ?? '') . ' origin=' . $origin);
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token — reload the page']);
        exit;
    }
}

// ── Validation ───────────────────────────────────────────────────────────────

/** Only http(s) URLs are accepted (blocks javascript:, data:, etc.). */
function mt_valid_http_url(string $url): bool
{
    return $url !== ''
        && strlen($url) <= 500
        && preg_match('#^https?://#i', $url) === 1
        && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

// ── Errors ───────────────────────────────────────────────────────────────────

/**
 * JSON error response. Messages from our own `throw new Exception(...)` are shown;
 * anything else (SQL errors, TypeErrors, file paths…) is only written to the PHP
 * error log and replaced by a generic message.
 */
function mt_json_fail(Throwable $e): void
{
    error_log('MangaTracker: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    $safe = get_class($e) === 'Exception';
    echo json_encode(['success' => false, 'error' => $safe ? $e->getMessage() : 'Erreur interne du serveur']);
}

// ── Protected folders & security journal ─────────────────────────────────────

const MT_DENY_ALL_HTACCESS =
    "# Direct access forbidden.\n"
  . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
  . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

const MT_IMAGES_HTACCESS =
    "# Cover images only: never run scripts from this folder.\n"
  . "Options -Indexes -ExecCGI\n"
  . "<IfModule mod_authz_core.c>\n"
  . "    <FilesMatch \"(?i)\\.(php[0-9]?|phtml|phar|pl|py|cgi)$\">\n        Require all denied\n    </FilesMatch>\n"
  . "</IfModule>\n"
  . "<IfModule mod_headers.c>\n    Header set X-Content-Type-Options \"nosniff\"\n</IfModule>\n";

/** Creates a folder (relative to the project) and its .htaccess if missing. */
function mt_ensure_dir(string $rel, string $htaccess): string
{
    $abs = mt_abs($rel);
    if (!is_dir($abs) && !@mkdir($abs, 0755, true)) {
        throw new Exception("Impossible de créer le dossier $rel");
    }
    $guard = $abs . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, $htaccess);
    }
    return $abs;
}

function mt_log_file(): string
{
    return mt_abs('logs/security.log');
}

/** Appends one line to logs/security.log (never throws). */
function mt_log(string $event, string $detail = ''): void
{
    try {
        mt_ensure_dir('logs', MT_DENY_ALL_HTACCESS);
        $f = mt_log_file();

        // Keep the file small: when it grows past 512 KB, keep the last 1000 lines
        if (is_file($f) && filesize($f) > 524288) {
            $lines = file($f, FILE_IGNORE_NEW_LINES) ?: [];
            @file_put_contents($f, implode("\n", array_slice($lines, -1000)) . "\n", LOCK_EX);
        }

        $detail = substr(preg_replace('/[\r\n\t|]+/', ' ', $detail), 0, 200);
        $line = date('c') . ' | ' . mt_client_ip() . ' | ' . $event . ' | ' . $detail . "\n";
        @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // logging must never break a request
    }
}

/** Last $n journal entries, newest first. */
function mt_read_log(int $n = 100): array
{
    $f = mt_log_file();
    if (!is_file($f)) {
        return [];
    }
    $lines = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], -$n);
    $out = [];
    foreach (array_reverse($lines) as $l) {
        $p = explode(' | ', $l, 4);
        if (count($p) === 4) {
            $out[] = ['time' => $p[0], 'ip' => $p[1], 'event' => $p[2], 'detail' => $p[3]];
        }
    }
    return $out;
}

// ── Login throttling ─────────────────────────────────────────────────────────

const MT_LOGIN_MAX_FAILURES = 5;
const MT_LOGIN_LOCK_SECONDS = 300;
const MT_LOGIN_WINDOW       = 900;

function mt_throttle_file(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mangatracker_login_' . md5(mt_client_ip()) . '.json';
}

function mt_throttle_load(): array
{
    $f = mt_throttle_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($d) ? $d + ['count' => 0, 'first' => 0, 'locked_until' => 0]
                        : ['count' => 0, 'first' => 0, 'locked_until' => 0];
}

/** Seconds left on the lock (0 = not locked). */
function mt_login_locked_seconds(): int
{
    $d = mt_throttle_load();
    return max(0, (int)$d['locked_until'] - time());
}

function mt_login_record_failure(): void
{
    usleep(400000);   // slows down guessing even before the lock
    $d = mt_throttle_load();
    if (time() - (int)$d['first'] > MT_LOGIN_WINDOW) {
        $d = ['count' => 0, 'first' => time(), 'locked_until' => 0];
    }
    $d['count']++;
    if ($d['count'] >= MT_LOGIN_MAX_FAILURES) {
        $d['locked_until'] = time() + MT_LOGIN_LOCK_SECONDS;
        $d['count'] = 0;
        $d['first'] = time();
        mt_log('login_locked', 'too many failed attempts');
    }
    @file_put_contents(mt_throttle_file(), json_encode($d), LOCK_EX);
}

function mt_login_clear(): void
{
    @unlink(mt_throttle_file());
}

// ── File helpers ─────────────────────────────────────────────────────────────

/**
 * Absolute path of a stored (project-relative) path. Paths kept in the database and in
 * the page ("archives/…", "img/…", "logs/…") are mapped to the real folders:
 *   archives/ => storage/archives/   (private, never served)
 *   logs/     => storage/logs/       (private, never served)
 *   img/      => public/img/         (covers, served)
 * so the stored values never had to change when the project was restructured.
 */
function mt_abs(string $relative): string
{
    static $map = ['archives' => 'storage/archives', 'logs' => 'storage/logs', 'img' => 'public/img'];
    if (preg_match('#^(archives|logs|img)(/.*)?$#', $relative, $m)) {
        $relative = $map[$m[1]] . ($m[2] ?? '');
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

/** True only for files stored in archives/chapters/ (never trust a DB path blindly before unlink). */
function mt_is_chapter_path(string $p): bool
{
    return strpos($p, 'archives/chapters/') === 0 && strpos($p, '..') === false;
}
