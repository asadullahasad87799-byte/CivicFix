<?php
/**
 * CivicFix — Admin Authentication Endpoint (php/admin_login.php)
 * Supports:
 * - GET ?check=1 : Returns current admin session status
 * - POST         : Authenticates admin username & password using bcrypt password_verify()
 */

session_start();
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $loggedIn = !empty($_SESSION['admin_logged_in']);
    send_json([
        'ok'        => true,
        'logged_in' => $loggedIn,
        'admin'     => $loggedIn ? [
            'username'   => $_SESSION['admin_username'] ?? 'admin',
            'full_name'  => $_SESSION['admin_full_name'] ?? 'Nodal Civic Officer',
            'department' => $_SESSION['admin_department'] ?? 'Municipal Corporation',
            'role'       => $_SESSION['admin_role'] ?? 'super_admin',
        ] : null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

try {
    $pdo = get_db_connection();

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = $_POST;
    if (stripos($contentType, 'application/json') !== false) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }

    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $password === '') {
        send_json(['ok' => false, 'error' => 'Please enter both username and password.'], 400);
    }

    $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        send_json(['ok' => false, 'error' => 'Invalid admin username or password.'], 401);
    }

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
    $_SESSION['admin_logged_in']  = true;
    $_SESSION['admin_id']         = (int)$admin['id'];
    $_SESSION['admin_username']   = $admin['username'];
    $_SESSION['admin_full_name']  = $admin['full_name'];
    $_SESSION['admin_department'] = $admin['department'];
    $_SESSION['admin_role']       = $admin['role'];

    send_json([
        'ok'      => true,
        'message' => 'Admin login successful.',
        'admin'   => [
            'username'   => $admin['username'],
            'full_name'  => $admin['full_name'],
            'department' => $admin['department'],
            'role'       => $admin['role'],
        ]
    ]);

} catch (Throwable $e) {
    send_json([
        'ok'    => false,
        'error' => 'Login service error.',
        'debug' => $e->getMessage()
    ], 500);
}
