<?php
/**
 * WeChat Account Binding / Registration Page (SOAP-Only Mode)
 *
 * Shown after a user scans the WeChat QR code but their WeChat identity
 * is not yet linked to a game account. The user can:
 *   1. Auto-create a new game account linked to their WeChat (via SOAP)
 *   2. Bind their WeChat to an existing game account (via SOAP)
 *
 * No database connection — all operations go through SOAP.
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
    header('Location: ' . get_config('baseurl'));
    exit;
}

// The WeChat user info must be in the session (set by the callback handler)
$wxUser = $_SESSION['wechat_user'] ?? null;
if (!$wxUser) {
    // No WeChat session — redirect to home to initiate login
    header('Location: ' . get_config('baseurl'));
    exit;
}

$errorMsg   = '';
$successMsg = '';
$showResult = false;
$createdAccount = '';
$resultData = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'auto_register') {
        // ---- Auto-create a new game account via SOAP (no email) ----
        $result = WeChatAuth::registerAndBind(
            $wxUser['openid'],
            $wxUser['unionid'] ?? '',
            $wxUser['nickname'] ?? '',
            $wxUser['headimgurl'] ?? ''
        );

        if ($result['success']) {
            $successMsg     = (lang('account_created') ?: 'Account created successfully!');
            $createdAccount = $result['username'];
            $resultData     = $result;
            $showResult     = true;

            // Set login session
            $_SESSION['wechat_logged_in'] = true;
            $_SESSION['wechat_username']  = $result['username'];

            // Clear the temp WeChat user session
            unset($_SESSION['wechat_user']);
        } else {
            if ($result['message'] === 'username_exists') {
                $errorMsg = (lang('username_exists') ?: 'Username already exists. Please try again.');
            } elseif ($result['message'] === 'soap_create_failed') {
                $errorMsg = lang('soap_error') ?: 'SOAP connection failed. Please check server status and try again.';
            } else {
                $errorMsg = (lang('error_try_again') ?: 'An error occurred. Please try again.');
            }
        }
    } elseif ($action === 'bind_existing') {
        // ---- Bind to an existing game account via SOAP ----
        $username = trim($_POST['bind_username'] ?? '');
        $password = trim($_POST['bind_password'] ?? '');

        if (empty($username) || empty($password)) {
            $errorMsg = lang('error_try_again') ?: 'Please enter your username and password.';
        } else {
            $result = WeChatAuth::verifyAndBind(
                $username,
                $password,
                $wxUser['openid'],
                $wxUser['unionid'] ?? '',
                $wxUser['nickname'] ?? '',
                $wxUser['headimgurl'] ?? ''
            );

            if ($result['success']) {
                $successMsg = lang('wechat_bind_success') ?: 'WeChat bound successfully! You are now logged in.';
                $showResult = true;

                $_SESSION['wechat_logged_in'] = true;
                $_SESSION['wechat_username']  = $result['username'];

                unset($_SESSION['wechat_user']);
            } else {
                if ($result['message'] === 'account_not_found') {
                    $errorMsg = (lang('username_not_exist') ?: 'Account not found.');
                } elseif ($result['message'] === 'soap_error') {
                    $errorMsg = lang('soap_error') ?: 'SOAP connection failed. Please check server status.';
                } else {
                    $errorMsg = (lang('error_try_again') ?: 'An error occurred. Please try again.');
                }
            }
        }
    }
}

// Load template
$templateName = get_config('template') ?: 'light';
$templatePath = __DIR__ . '/template/' . $templateName . '/wechat_bind.php';
if (!file_exists($templatePath)) {
    $templatePath = __DIR__ . '/template/light/wechat_bind.php';
}

// Make $result available to the template (for password display)
if (!empty($resultData)) {
    $result = $resultData;
}

include $templatePath;
