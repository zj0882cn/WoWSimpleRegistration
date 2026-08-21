<?php
/**
 * Password Login Endpoint
 *
 * Allows returning users to log in with username + password.
 * Password is verified via SOAP (account set password trick — no local storage).
 * Registration requires email verification.
 *
 * Request:  POST { username: "xxx", password: "xxx" }
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

if (!EmailAuth::isFeatureEnabled('password_login')) {
    echo json_encode(['success' => false, 'message' => 'password_login_disabled']);
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

$username = strtoupper($username);

// Step 1: Verify account exists on the game server
if (!EmailAuth::accountExists($username)) {
    echo json_encode(['success' => false, 'message' => 'account_not_found']);
    exit;
}

// Step 2: Verify password via SOAP (no local password storage)
// Trick: account set password USER PASSWORD PASSWORD succeeds only if PASSWORD matches
$pwCommand = "account set password {$username} {$password} {$password}";
$pwResult = EmailAuth::soapCommand($pwCommand);

if (!$pwResult['success']) {
    $msg = strtolower($pwResult['message'] ?? '');
    // Check for wrong password errors
    if (strpos($msg, 'password') !== false &&
        (strpos($msg, 'wrong') !== false || strpos($msg, 'incorrect') !== false || strpos($msg, 'invalid') !== false)) {
        echo json_encode(['success' => false, 'message' => 'wrong_password']);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'server_connection_error']);
    exit;
}

// Password verified successfully via SOAP
// Step 3: Set session
$_SESSION['user_logged_in'] = true;
$_SESSION['user_username']  = $username;

// Get email binding if available (for account management features)
$binding = EmailAuth::getBindingByUsername($username);
if ($binding && !empty($binding['email'])) {
    $_SESSION['user_email'] = $binding['email'];
}

echo json_encode([
    'success'  => true,
    'message'  => 'login_success',
    'username' => $username,
    'redirect' => get_config('baseurl') . '?login=success',
]);