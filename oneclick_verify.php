<?php
/**
 * Register/Login Endpoint — Email Verification + User-Defined Account
 *
 * New user: email verification + user-defined username + user-defined password
 * Returning user: email recognized → direct login
 *
 * Request:  POST { email: "user@example.com", code: "123456", username: "MyAccount", password: "mypass" }
 * Response: JSON { success: bool, message: string, redirect?: string }
 *
 * @author AzerothCore Community
 */

header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/application/config/config.php';
require_once __DIR__ . '/application/include/core_handler.php';
require_once __DIR__ . '/application/include/functions.php';
require_once __DIR__ . '/application/include/email.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'method_not_allowed']);
    exit;
}

if (!EmailAuth::isEnabled()) {
    echo json_encode(['success' => false, 'message' => 'email_auth_disabled']);
    exit;
}

if (!EmailAuth::isFeatureEnabled('login')) {
    echo json_encode(['success' => false, 'message' => 'email_login_disabled']);
    exit;
}

$email    = trim($_POST['email'] ?? '');
$code     = trim($_POST['code'] ?? '');
$password = trim($_POST['password'] ?? '');
$username = trim($_POST['username'] ?? '');

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'email_required']);
    exit;
}

if (empty($code)) {
    echo json_encode(['success' => false, 'message' => 'code_required']);
    exit;
}

// Perform register or login (includes internal code verification)
$result = EmailAuth::loginOrRegister($email, $code, $username, $password);

if ($result['success']) {
    if (!empty($result['is_new'])) {
        $_SESSION['user_new_account']  = true;
        $_SESSION['user_new_password'] = $result['password'];
    }

    echo json_encode([
        'success'  => true,
        'message'  => $result['message'],
        'is_new'   => $result['is_new'] ?? false,
        'username' => $result['username'] ?? '',
        'redirect' => get_config('baseurl') . '?login=success',
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result['message'],
    ]);
}
