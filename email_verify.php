<?php
/**
 * Email Verify Endpoint — Email Login Mode
 *
 * Handles email code verification and login/registration.
 * On success, redirects back to index.php with a success message.
 *
 * Request:  POST { email: "user@example.com", code: "123456", username?: "...", password?: "..." }
 *
 * @author AzerothCore Community
 */

session_start();
require_once __DIR__ . '/application/config/config.php';
require_once __DIR__ . '/application/include/core_handler.php';
require_once __DIR__ . '/application/include/functions.php';
require_once __DIR__ . '/application/include/email.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . get_config('baseurl'));
    exit;
}

if (!EmailAuth::isEnabled()) {
    header('Location: ' . get_config('baseurl') . '?login_error=email_auth_disabled');
    exit;
}

if (!EmailAuth::isFeatureEnabled('login')) {
    header('Location: ' . get_config('baseurl') . '?login_error=email_login_disabled');
    exit;
}

$email    = trim($_POST['email'] ?? '');
$code     = trim($_POST['code'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($email) || empty($code)) {
    header('Location: ' . get_config('baseurl') . '?login_error=invalid_email');
    exit;
}

$result = EmailAuth::loginOrRegister($email, $code, $username, $password);

if ($result['success']) {
    if (!empty($result['is_new'])) {
        $_SESSION['user_new_account'] = true;
        $_SESSION['user_new_password'] = $result['password'];
    }
    header('Location: ' . get_config('baseurl') . '?login=success');
    exit;
}

header('Location: ' . get_config('baseurl') . '?login_error=' . urlencode($result['message']));
exit;
