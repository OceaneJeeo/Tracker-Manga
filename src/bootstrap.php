<?php
/**
 * Shared start-up for the API endpoints (public/api/*.php).
 *
 *   require_once __DIR__ . '/../../src/bootstrap.php';
 *   mt_boot_json();        // GET endpoint
 *   mt_boot_json(true);    // POST endpoint (+ CSRF check)
 *
 * Provides: $pdo (database), mt_ensure_schema(), everything from security.php.
 */

require_once __DIR__ . '/security.php';
require_once dirname(__DIR__) . '/config/mysql.php';   // defines $pdo
require_once __DIR__ . '/schema.php';

/** Session (read-only) + JSON header + authentication (+ CSRF for POST). */
function mt_boot_json(bool $post = false): void
{
    mt_session_start();
    header('Content-Type: application/json; charset=UTF-8');
    mt_require_auth_json();
    if ($post) {
        mt_require_csrf();
    }
}
