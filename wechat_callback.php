<?php
/**
 * WeChat OAuth Callback Handler (SOAP-Only Mode)
 *
 * WeChat redirects users back to this file after they scan the QR code.
 * The callback contains a `code` and `state` parameter that we exchange
 * for an access_token, then look up / create the game account binding.
 *
 * No database connection is needed — all account operations go through
 * SOAP, and bindings are stored in a JSON file.
 *
 * @author AzerothCore Community
 **/

session_start();
require_once __DIR__ . '/application/config/config.php';
require_once __DIR__ . '/application/include/core_handler.php';
require_once __DIR__ . '/application/include/functions.php';
require_once __DIR__ . '/application/include/wechat.php';

// Load language file
if (!empty($_COOKIE['website_lang'])) {
    $langFile = __DIR__ . '/application/language/' . $_COOKIE['website_lang'] . '.php';
} else {
    $langFile = __DIR__ . '/application/language/' . get_config('language') . '.php';
}
if (file_exists($langFile)) {
    $language = include $langFile;
    if (!is_array($language)) {
        $language = [];
    }
} else {
    $language = [];
}

// NOTE: No database connection — SOAP-only mode

// Check if WeChat auth is enabled
if (!get_config('wechat_enabled')) {
    die('WeChat authentication is not enabled.');
}

// Get parameters from WeChat callback
$code  = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

if (empty($code)) {
    // User denied authorization or error
    header('Location: ' . get_config('baseurl') . '?wechat_error=denied');
    exit;
}

// Process the callback
$result = WeChatAuth::handleCallback($code, $state);

if ($result['status'] === 'error') {
    header('Location: ' . get_config('baseurl') . '?wechat_error=' . $result['message']);
    exit;
}

if ($result['status'] === 'bound') {
    // Already bound — redirect to success page
    header('Location: ' . get_config('baseurl') . '?wechat_login=success&username=' . urlencode($result['username']));
    exit;
}

// Status is 'unbound' — redirect to the binding / registration page
// WeChat user info is stored in session by handleCallback()
header('Location: ' . get_config('baseurl') . '/wechat_bind.php');
exit;
