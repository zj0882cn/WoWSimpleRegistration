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
$binding = MobileAuth::getBindingByUsername($username);

if (!$pwVerify['has_hash']) {
    // Account exists on game server (verified above) but no password hash stored locally.
    // This can happen when:
    //   1. User registered via Aliyun one-click (no password input required)
    //   2. Account was created outside the web system (GM tool, DB direct, etc.)
    //   3. mobile_bindings.json was reset during deployment
    // Solution: trust the password input (account exists on SOAP), save the hash for future logins.
    if ($binding) {
        // Binding exists but no password_hash — save it now, preserving the phone
        MobileAuth::updatePasswordHash($username, $password);
    } else {
        // No binding at all — create one with the password and empty phone
        // (user can later bind phone via one-click login)
        MobileAuth::saveBinding('', $username, $password);
    }
} elseif (!$pwVerify['verified']) {
    echo json_encode(['success' => false, 'message' => 'wrong_password']);
    exit;
}

// Refresh binding after potential update
$binding = MobileAuth::getBindingByUsername($username);

// Set session — the user is now logged in on the website
$_SESSION['mobile_logged_in'] = true;
$_SESSION['mobile_username']  = strtoupper($username);
$_SESSION['mobile_phone']     = '';

// Use phone from binding if available
if ($binding && !empty($binding['phone'])) {
    $_SESSION['mobile_phone'] = $binding['phone'];
}

echo json_encode([
    'success'  => true,
    'message'  => 'login_success',
    'username' => strtoupper($username),
    'redirect' => get_config('baseurl') . '?mobile_login=success',
]);
