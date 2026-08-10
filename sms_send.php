<?php
/**
 * SMS Send Endpoint — Mobile Login Mode
 *
 * AJAX endpoint to send a verification code to a phone number.
 *
 * Request:  POST { phone: "13812345678" }
 * Response: JSON { success: bool, message: string, code?: string (demo only), wait?: int }
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

// Get phone number
$phone = trim($_POST['phone'] ?? '');

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'phone_required']);
    exit;
}

// Send the code
$result = MobileAuth::sendCode($phone);

// Build response
$response = [
    'success' => $result['success'],
    'message' => $result['message'],
];

// In demo mode, include the code for testing
if (isset($result['code'])) {
    $response['code'] = $result['code'];
}

// Include wait time if rate limited
if (isset($result['wait'])) {
    $response['wait'] = $result['wait'];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
