<?php
/**
 * Password Login Endpoint
 *
 * Allows returning users to log in with username + password.
 * Registration still requires phone verification (one-click or SMS).
 *
 * Request:  POST { username: "xxx", password: "xxx" }
 * Response: JSON { success: bool, message: string, redirect?: string }
 *
 * @author AzerothCore Community
 **/

header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/application/config/config.php';
require_once __DIR__ . '/application/include/core_handler.php';
require_once __DIR__ . '/application/include/functions.php';
require_once __DIR__ . '/application/include/mobile.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'method_not_allowed']);
    exit;
}

// Check if mobile auth is enabled
if (!get_config('mobile_enabled')) {
    echo json_encode(['success' => false, 'message' => 'mobile_auth_disabled']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($username)) {
    echo json_encode(['success' => false, 'message' => 'username_required']);
    exit;
}

if (empty($password)) {
    echo json_encode(['success' => false, 'message' => 'password_required']);
    exit;
}

// Check if account exists via SOAP
if (!MobileAuth::accountExists(strtoupper($username))) {
    echo json_encode(['success' => false, 'message' => 'account_not_found']);
    exit;
}

// Verify password against stored hash
$pwVerify = MobileAuth::verifyPasswordHash($username, $password);
if (!$pwVerify['has_hash']) {
    echo json_encode(['success' => false, 'message' => 'no_password_hash']);
    exit;
}
if (!$pwVerify['verified']) {
    echo json_encode(['success' => false, 'message' => 'wrong_password']);
    exit;
}

// Set session — the user is now logged in on the website
$_SESSION['mobile_logged_in'] = true;
$_SESSION['mobile_username']  = strtoupper($username);
$_SESSION['mobile_phone']     = '';  // Password login doesn't have phone info

// Try to find phone from existing bindings
$binding = MobileAuth::getBindingByUsername($username);
if ($binding) {
    $_SESSION['mobile_phone'] = $binding['phone'];
}

echo json_encode([
    'success'  => true,
    'message'  => 'login_success',
    'username' => strtoupper($username),
    'redirect' => get_config('baseurl') . '?mobile_login=success',
]);
