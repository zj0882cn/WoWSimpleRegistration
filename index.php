<?php
/**
 * Main Entry Point — Mobile Login Mode
 *
 * In mobile-only mode:
 *   - If the user is NOT logged in: show phone number + SMS code login form
 *   - If the user IS logged in: show their account info + password reset button
 *   - No registration form (accounts are auto-created on first login)
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

// Handle mobile logout
if (!empty($_GET['mobile_logout'])) {
    MobileAuth::logout();
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

// Handle mobile login result (from sms_verify.php redirect)
$mobileLoginMsg = '';
if (!empty($_GET['mobile_login']) && $_GET['mobile_login'] === 'success') {
    $mobileLoginMsg = lang('mobile_login_success') ?: '登录成功！';
}
if (!empty($_GET['mobile_error'])) {
    $errMap = [
        'invalid_phone'       => '手机号格式不正确',
        'rate_limited'        => '验证码发送过于频繁，请稍后再试',
        'wrong_code'          => '验证码错误',
        'code_not_found'      => '验证码不存在或已过期，请重新获取',
        'max_attempts_exceeded' => '尝试次数过多，请重新获取验证码',
        'soap_create_failed'  => '账号创建失败，请稍后再试',
        'mobile_auth_disabled'=> '手机登录功能未启用',
    ];
    $errKey = $_GET['mobile_error'];
    $mobileLoginMsg = $errMap[$errKey] ?? '登录失败，请重试';
}

// Load the main template
require_once base_path . 'template/' . get_config('template') . '/tpl/main.php';
