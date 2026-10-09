<?php
/** Last entries of the security journal (logs/security.log). */

require_once __DIR__ . '/../../src/security.php';

mt_session_start();
header('Content-Type: application/json; charset=UTF-8');
mt_require_auth_json();

echo json_encode(['success' => true, 'entries' => mt_read_log(100)]);
