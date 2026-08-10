<?php
/**
 * One-Click Login Endpoint — Carrier Gateway Authentication
 *
 * Receives a token from the Aliyun NumberAuth H5 SDK (or a phone number
 * in demo mode), verifies it server-side, and registers/logs in the user
 * without any SMS verification.
 *
 * Request (production):  POST { token: "xxx" }
 * Request (demo):        POST { phone: "13812345678" }
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

$provider = get_config('numberauth_provider') ?: 'demo';
$token    = trim($_POST['token'] ?? '');
$phone    = trim($_POST['phone'] ?? '');

// --- Production mode: verify Aliyun token ---
if ($provider === 'aliyun' && !empty($token)) {
    $verifyResult = MobileAuth::verifyAliyunToken($token);

    if (!$verifyResult['success']) {
        echo json_encode([
            'success' => false,
            'message' => $verifyResult['message'],
            'fallback' => true,
        ]);
        exit;
    }

    $phone = $verifyResult['phone'];
} elseif ($provider === 'aliyun' && empty($token) && empty($phone)) {
    // No token and no phone in aliyun mode
    echo json_encode([
        'success'  => false,
        'message'  => 'token_required',
        'fallback' => true,
    ]);
    exit;
}

// --- Demo mode: use the phone number directly ---
// In demo mode, the frontend sends the phone number (simulating auto-detection)

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'phone_required']);
    exit;
}

// Perform one-click login (register or log in)
$result = MobileAuth::oneClickLogin($phone);

if ($result['success']) {
    // Store new account info in session for display
    if (!empty($result['is_new'])) {
        $_SESSION['mobile_new_account']  = true;
        $_SESSION['mobile_new_password'] = $result['password'];
    }

    echo json_encode([
        'success'  => true,
        'message'  => $result['message'],
        'is_new'   => $result['is_new'] ?? false,
        'username' => $result['username'] ?? '',
        'redirect' => get_config('baseurl') . '?mobile_login=success',
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result['message'],
    ]);
}
