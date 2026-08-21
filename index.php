<?php
/**
 * Main Entry Point — Email Login Mode
 *
 * In email mode:
 *   - If NOT logged in: show email + verification code login form
 *   - If logged in: show account info + email binding + password management
 *   - Accounts are auto-created on first email login
 *   - Password reset via email verification (standalone page: reset_password.php)
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

// Handle email logout
if (!empty($_GET['logout'])) {
    EmailAuth::logout();
    header('Location: ' . get_config('baseurl'));
    exit;
}

// Handle password reset result (from reset_password.php redirect)
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

// Handle email login result
$loginMsg = '';
if (!empty($_GET['login']) && $_GET['login'] === 'success') {
    $loginMsg = lang('login_success') ?: '登录成功！';
}
if (!empty($_GET['login_error'])) {
    $errMap = [
        'invalid_email'        => '邮箱格式不正确',
        'rate_limited'         => '验证码发送过于频繁，请稍后再试',
        'wrong_code'           => '验证码错误',
        'code_not_found'       => '验证码不存在或已过期，请重新获取',
        'max_attempts_exceeded'=> '尝试次数过多，请重新获取验证码',
        'soap_create_failed'   => '账号创建失败，请稍后再试',
        'email_auth_disabled'  => '邮箱登录功能未启用',
        'email_not_bound'      => '该邮箱未绑定游戏账号',
    ];
    $errKey = $_GET['login_error'];
    $loginMsg = $errMap[$errKey] ?? '登录失败，请重试';
}

// Load the main template
require_once base_path . 'template/' . get_config('template') . '/tpl/main.php';
