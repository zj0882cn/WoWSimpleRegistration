<?php
/**
 * Bind Phone Endpoint — For existing accounts without web binding
 *
 * Scenario: Account was created via GM tool / DB direct, user logs in via password,
 * then binds their phone number to enable one-click login for the future.
 *
 * Request:  POST { phone: "13812345678", code: "123456" }
 * Response: JSON { success: bool, message: string }
 *
 * @author AzerothCore Community
 */

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

// Must be logged in
if (!MobileAuth::isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'not_logged_in']);
    exit;
}

$username = $_SESSION['mobile_username'] ?? '';
$phone    = trim($_POST['phone'] ?? '');
$code     = trim($_POST['code'] ?? '');

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'phone_required']);
    exit;
}

if (empty($code)) {
    echo json_encode(['success' => false, 'message' => 'code_required']);
    exit;
}

// Bind the phone
$result = MobileAuth::bindPhone($username, $phone, $code);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
