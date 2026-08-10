<?php
/**
 * SMS Verify Endpoint — Mobile Login Mode
 *
 * Handles SMS code verification and login/registration.
 * On success, redirects back to index.php with a success message.
 *
 * Request:  POST { phone: "13812345678", code: "123456" }
 *
 * @author AzerothCore Community
 **/

session_start();
require_once __DIR__ . '/application/config/config.php';
require_once __DIR__ . '/application/include/core_handler.php';
require_once __DIR__ . '/application/include/functions.php';
require_once __DIR__ . '/application/include/mobile.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . get_config('baseurl'));
    exit;
}

// Check if mobile auth is enabled
if (!get_config('mobile_enabled')) {
    header('Location: ' . get_config('baseurl') . '?mobile_error=mobile_auth_disabled');
    exit;
}

$phone = trim($_POST['phone'] ?? '');
$code  = trim($_POST['code'] ?? '');

if (empty($phone) || empty($code)) {
    header('Location: ' . get_config('baseurl') . '?mobile_error=invalid_phone');
    exit;
}

// Attempt login or registration
$result = MobileAuth::loginOrRegister($phone, $code);

if ($result['success']) {
    // Store new account info in session for display
    if (!empty($result['is_new'])) {
        $_SESSION['mobile_new_account'] = true;
        $_SESSION['mobile_new_password'] = $result['password'];
    }
    header('Location: ' . get_config('baseurl') . '?mobile_login=success');
    exit;
}

// Error — redirect with error code
header('Location: ' . get_config('baseurl') . '?mobile_error=' . urlencode($result['message']));
exit;
