<?php
/**
 * Bind Email Endpoint — For existing accounts without email binding
 *
 * Scenario: Account was created via GM tool / DB direct, user logs in via password,
 * then binds their email address to enable email-based login for the future.
 *
 * Request:  POST { email: "user@example.com", code: "123456" }
 * Response: JSON { success: bool, message: string }
 *
 * @author AzerothCore Community
 */

header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/application/config/config.php';
require_once __DIR__ . '/application/include/core_handler.php';
require_once __DIR__ . '/application/include/functions.php';
require_once __DIR__ . '/application/include/email.php';

if (!EmailAuth::isEnabled()) {
    echo json_encode(['success' => false, 'message' => 'email_auth_disabled']);
    exit;
}

if (!EmailAuth::isFeatureEnabled('bind')) {
    echo json_encode(['success' => false, 'message' => 'email_bind_disabled']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'method_not_allowed']);
    exit;
}

// Must be logged in
if (!EmailAuth::isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'not_logged_in']);
    exit;
}

$username = $_SESSION['user_username'] ?? '';
$email    = trim($_POST['email'] ?? '');
$code     = trim($_POST['code'] ?? '');

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'email_required']);
    exit;
}

if (!EmailAuth::isValidEmail($email)) {
    echo json_encode(['success' => false, 'message' => 'invalid_email']);
    exit;
}

if (empty($code)) {
    echo json_encode(['success' => false, 'message' => 'code_required']);
    exit;
}

// Bind the email
$result = EmailAuth::bindEmail($username, $email, $code);

echo json_encode($result, JSON_UNESCAPED_UNICODE);