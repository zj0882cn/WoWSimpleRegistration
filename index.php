<?php
/**
 * Main Entry Point — WeChat-Only Mode
 *
 * This page replaces the original index.php which had a username/password
 * registration form. In WeChat-only mode:
 *
 *   - If the user is NOT logged in: show a "Login with WeChat" button
 *   - If the user IS logged in: show their account info + password reset button
 *   - No registration form (accounts are auto-created via WeChat)
 *   - No change password feature (only password reset)
 *   - Password reset generates a new random password via SOAP
 *
 * @author Amin Mahmoudi (MasterkinG)
 * @copyright Copyright (c) 2019 - 2024, MasterkinG32.
 **/

$osType = PHP_OS;

if (version_compare(PHP_VERSION, '8.0', '<')) {
    echo '<p>Your server needs to run PHP version 8.0.0 or higher.</p>';
    exit();
}

require_once './application/loader.php';

// Handle POST requests (language change, captcha)
user::post_handler();

// Handle WeChat logout
if (!empty($_GET['wechat_logout'])) {
    WeChatAuth::logout();
    header('Location: ' . get_config('baseurl'));
    exit;
}

// Handle password reset result (from wechat_reset_password.php redirect)
$resetResult = null;
if (!empty($_GET['reset_success'])) {
    $resetResult = [
        'success'  => true,
        'username' => $_GET['username'] ?? '',
        'password' => $_GET['password'] ?? '',
    ];
} elseif (!empty($_GET['reset_error'])) {
    $resetResult = [
        'success' => false,
        'message' => $_GET['reset_error'],
    ];
}

// Handle WeChat login result
$wechatLoginMsg = '';
if (!empty($_GET['wechat_login']) && $_GET['wechat_login'] === 'success') {
    $wechatLoginMsg = lang('wechat_login_success') ?: 'WeChat login successful!';
}
if (!empty($_GET['wechat_error'])) {
    $wechatLoginMsg = lang('wechat_login_failed') ?: 'WeChat login failed. Please try again.';
}

// Load the main template
require_once base_path . 'template/' . get_config('template') . '/tpl/main.php';
