<?php
/**
 * CivicFix — Admin Logout Endpoint (php/admin_logout.php)
 * Destroys the server-side admin session safely.
 */

session_start();
require_once __DIR__ . '/db.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

send_json([
    'ok'      => true,
    'message' => 'Admin logged out successfully.'
]);
