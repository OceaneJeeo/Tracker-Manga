<?php
/**
 * Change the password from the interface.
 *
 * POST: current, new, confirm
 * - needs the current password (and counts wrong ones in the login lock)
 * - new password: 10 characters minimum
 * - rewrites config/auth.php (new hash + new MT_AUTH_EPOCH) => every OTHER open
 *   session is signed out, this one stays logged in
 */

require_once __DIR__ . '/../../src/security.php';

mt_session_start(false);   // writable: this session gets the new epoch
header('Content-Type: application/json; charset=UTF-8');
mt_require_auth_json();
mt_require_csrf();

try {
    $locked = mt_login_locked_seconds();
    if ($locked > 0) {
        throw new Exception('Trop de tentatives. Réessaie dans ' . ceil($locked / 60) . ' min.');
    }

    $current = (string)($_POST['current'] ?? '');
    $new     = (string)($_POST['new'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    if (!password_verify($current, MT_PASSWORD_HASH)) {
        mt_login_record_failure();
        mt_log('password_change_failed', 'wrong current password');
        throw new Exception('Mot de passe actuel incorrect');
    }
    if (strlen($new) < 10)            throw new Exception('Le nouveau mot de passe doit faire au moins 10 caractères');
    if (strlen($new) > 200)           throw new Exception('Mot de passe trop long (max 200 caractères)');
    if ($new !== $confirm)            throw new Exception('La confirmation ne correspond pas');
    if (hash_equals($current, $new))  throw new Exception('Le nouveau mot de passe doit être différent de l\'ancien');

    $file = __DIR__ . '/config/auth.php';
    if (!is_writable($file) || !is_writable(dirname($file))) {
        throw new Exception('config/auth.php n\'est pas modifiable (droits d\'écriture)');
    }

    $hash  = password_hash($new, PASSWORD_DEFAULT);
    $epoch = time();
    $code  = "<?php\n"
           . "/**\n * Authentication config (rewritten automatically by \"Changer le mot de passe\").\n *\n"
           . " * MT_PASSWORD_HASH : hash of the password\n"
           . " *   manual change:  echo password_hash('nouveau_mot_de_passe', PASSWORD_DEFAULT);\n"
           . " * MT_AUTH_EPOCH    : changes with every password change => every other open session is signed out\n */\n"
           . "const MT_PASSWORD_HASH = " . var_export($hash, true) . ";\n"
           . "const MT_AUTH_EPOCH = $epoch;\n";

    // Write next to the target, then swap: a crash can never leave a half-written auth.php
    $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $code, LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        throw new Exception('Impossible d\'écrire config/auth.php');
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }

    $_SESSION['epoch'] = $epoch;   // keep THIS session signed in
    mt_login_clear();
    mt_log('password_changed', 'other sessions signed out');

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    mt_json_fail($e);
}
