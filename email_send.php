<?php
/**
 * Email Send Endpoint — Email Login Mode
 *
 * AJAX endpoint to send a verification code to an email address.
 *
 * Request:  POST { email: "user@example.com" }
 * Response: JSON { success: bool, message: string, wait?: int }
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

$email = trim($_POST['email'] ?? '');

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'email_required']);
    exit;
}

$result = EmailAuth::sendCode($email);

$response = [
    'success' => $result['success'],
    'message' => $result['message'],
];

if (isset($result['wait'])) {
    $response['wait'] = $result['wait'];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
