<?php
/**
 * Forgot Password Page — Email Verification Required
 *
 * Flow:
 *   - User enters email → check binding → send code via email
 *   - User enters code → verify (server sets session token)
 *   - Set new password → SOAP reset (checks session token)
 *   - Done: Success page
 *
 * @author AzerothCore Community
 */

session_start();
require_once __DIR__ . '/application/config/config.php';
require_once __DIR__ . '/application/include/core_handler.php';
require_once __DIR__ . '/application/include/functions.php';
require_once __DIR__ . '/application/include/email.php';

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

if (!EmailAuth::isEnabled()) {
    header('Location: ' . get_config('baseurl'));
    exit;
}

if (!EmailAuth::isFeatureEnabled('reset')) {
    header('Location: ' . get_config('baseurl'));
    exit;
}

$resetTokenTTL = 600;

$isLoggedIn  = EmailAuth::isLoggedIn();
$sessionUser  = $_SESSION['user_username'] ?? '';
$sessionEmail = $_SESSION['user_email'] ?? '';

$email     = $sessionEmail;
$username  = $sessionUser;
$errorMsg  = '';
$remaining = null;
$autoEmail = $isLoggedIn && !empty($sessionEmail);
$step = intval($_POST['step'] ?? $_GET['step'] ?? 1);

if ($isLoggedIn && empty($sessionEmail) && $step <= 2) {
    $step     = 1;
    $errorMsg = '您尚未绑定邮箱，请先绑定后再重置密码';
}

// AJAX endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_POST['ajax_action'] === 'send_code') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) && $isLoggedIn && !empty($sessionEmail)) {
            $email = $sessionEmail;
        }
        if (!EmailAuth::isValidEmail($email)) {
            echo json_encode(['success' => false, 'message' => '请输入正确的邮箱']);
            exit;
        }
        $binding = EmailAuth::getBindingByEmail($email);
        if (!$binding) {
            echo json_encode(['success' => false, 'message' => '该邮箱未绑定游戏账号，请先绑定']);
            exit;
        }
        if (!EmailAuth::accountExists($binding['username'])) {
            echo json_encode(['success' => false, 'message' => '绑定的游戏账号不存在，请联系管理员']);
            exit;
        }
        $result = EmailAuth::sendCode($email);
        $response = ['success' => $result['success']];
        if ($result['success']) {
            $response['message'] = '验证码已发送';
            $response['email']   = $email;
            $response['username'] = $binding['username'];
            $response['masked_email'] = EmailAuth::maskEmail($email);
            if (!empty($result['code'])) {
                $response['code'] = $result['code'];
            }
        } else {
            $msg = $result['message'];
            if ($msg === 'rate_limited') {
                $wait = $result['wait'] ?? 60;
                $response['message'] = "发送太频繁，请 {$wait} 秒后再试";
                $response['wait'] = $wait;
            } else {
                $response['message'] = '验证码发送失败，请重试';
            }
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    elseif ($_POST['ajax_action'] === 'verify_code') {
        $email = trim($_POST['email'] ?? '');
        $code  = trim($_POST['code'] ?? '');
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'email_required']);
            exit;
        }
        if (empty($code)) {
            echo json_encode(['success' => false, 'message' => 'code_required']);
            exit;
        }
        $verifyResult = EmailAuth::verifyCode($email, $code);
        if (!$verifyResult['success']) {
            $msg = $verifyResult['message'];
            $remaining = $verifyResult['remaining'] ?? null;
            $errMsg = '验证码错误';
            if ($msg === 'wrong_code' || $msg === 'code_not_found') {
                if ($remaining !== null && $remaining > 0) {
                    $errMsg = "验证码错误，剩余 {$remaining} 次尝试机会";
                }
            } elseif ($msg === 'max_attempts_exceeded') {
                $errMsg = '尝试次数过多，请重新获取验证码';
            }
            echo json_encode(['success' => false, 'message' => $errMsg]);
            exit;
        }
        $binding = EmailAuth::getBindingByEmail($email);
        $username = $binding['username'] ?? '';
        if (empty($username)) {
            echo json_encode(['success' => false, 'message' => '账号信息异常，请重试']);
            exit;
        }
        $_SESSION['reset_verified_email']  = $email;
        $_SESSION['reset_verified_token']  = bin2hex(random_bytes(32));
        $_SESSION['reset_verified_time']   = time();
        $_SESSION['reset_verified_user']   = $username;
        echo json_encode([
            'success'  => true,
            'message'  => 'verified',
            'username' => $username,
            'email'    => $email,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    elseif ($_POST['ajax_action'] === 'set_password') {
        $email       = trim($_POST['email'] ?? '');
        $newPass     = trim($_POST['new_password'] ?? '');
        $confirmPass = trim($_POST['confirm_password'] ?? '');

        $sessionEmail  = $_SESSION['reset_verified_email'] ?? '';
        $sessionToken  = $_SESSION['reset_verified_token'] ?? '';
        $sessionTime   = $_SESSION['reset_verified_time'] ?? 0;
        $sessionUser   = $_SESSION['reset_verified_user'] ?? '';

        $tokenExpired = (time() - $sessionTime) > $resetTokenTTL;
        $tokenValid   = !empty($sessionToken) && !$tokenExpired
                        && $sessionEmail === $email
                        && !empty($sessionUser);

        if (!$tokenValid) {
            echo json_encode(['success' => false, 'message' => '验证已过期，请重新操作']);
            exit;
        }
        if (strlen($newPass) < 6 || strlen($newPass) > 32) {
            echo json_encode(['success' => false, 'message' => '密码需6-32位字符']);
            exit;
        }
        if ($newPass !== $confirmPass) {
            echo json_encode(['success' => false, 'message' => '两次输入的密码不一致']);
            exit;
        }
        $result = EmailAuth::setPassword($sessionUser, $newPass);
        if ($result['success']) {
            unset(
                $_SESSION['reset_verified_email'],
                $_SESSION['reset_verified_token'],
                $_SESSION['reset_verified_time'],
                $_SESSION['reset_verified_user']
            );
            echo json_encode(['success' => true, 'message' => '密码重置成功', 'username' => $sessionUser], JSON_UNESCAPED_UNICODE);
        } else {
            $msg = $result['message'];
            $errMsg = '重置失败，请重试';
            if ($msg === 'account_not_found') { $errMsg = '游戏账号不存在'; }
            elseif ($msg === 'soap_error') { $errMsg = '服务器连接失败，请稍后重试'; }
            elseif ($msg === 'soap_reset_not_supported') { $errMsg = '服务器不支持重置密码，请联系管理员'; }
            echo json_encode(['success' => false, 'message' => $errMsg]);
        }
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'unknown_action']);
    exit;
}

// Form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1 && !empty($_POST['send_code'])) {
        if ($autoEmail) {
            $email = $sessionEmail;
        } else {
            $email = trim($_POST['email'] ?? '');
        }
        if (!EmailAuth::isValidEmail($email)) {
            $errorMsg = '请输入正确的邮箱';
        } else {
            $binding = EmailAuth::getBindingByEmail($email);
            if (!$binding) {
                $errorMsg = '该邮箱未绑定游戏账号，请先绑定';
            } elseif (!EmailAuth::accountExists($binding['username'])) {
                $errorMsg = '绑定的游戏账号不存在，请联系管理员';
            } else {
                $username = $binding['username'];
                $result = EmailAuth::sendCode($email);
                if ($result['success']) {
                    $step = 2;
                } else {
                    $msg = $result['message'];
                    if ($msg === 'rate_limited') {
                        $wait = $result['wait'] ?? 60;
                        $errorMsg = "发送太频繁，请 {$wait} 秒后再试";
                    } else {
                        $errorMsg = '验证码发送失败，请重试';
                    }
                }
            }
        }
    }
    elseif ($step === 2 && !empty($_POST['verify_code'])) {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) && $autoEmail) { $email = $sessionEmail; }
        $code  = trim($_POST['code'] ?? '');
        if (empty($email) || empty($code)) {
            $errorMsg = '请输入验证码';
            $step = 2;
        } else {
            $verifyResult = EmailAuth::verifyCode($email, $code);
            if ($verifyResult['success']) {
                $binding = EmailAuth::getBindingByEmail($email);
                $username = $binding['username'] ?? '';
                if (empty($username)) {
                    $errorMsg = '账号信息异常，请重试';
                    $step = 1;
                } else {
                    $_SESSION['reset_verified_email']  = $email;
                    $_SESSION['reset_verified_token']  = bin2hex(random_bytes(32));
                    $_SESSION['reset_verified_time']   = time();
                    $_SESSION['reset_verified_user']   = $username;
                    $step = 3;
                }
            } else {
                $msg = $verifyResult['message'];
                $remaining = $verifyResult['remaining'] ?? null;
                if ($msg === 'wrong_code' || $msg === 'code_not_found') {
                    if ($remaining !== null && $remaining > 0) {
                        $errorMsg = "验证码错误，剩余 {$remaining} 次尝试机会";
                    } else {
                        $errorMsg = '验证码错误';
                    }
                } elseif ($msg === 'max_attempts_exceeded') {
                    $errorMsg = '尝试次数过多，请重新获取验证码';
                    $step = 1;
                } else {
                    $errorMsg = '验证失败，请重试';
                }
                if ($step !== 1) { $step = 2; }
            }
        }
    }
    elseif ($step === 3 && !empty($_POST['set_password'])) {
        $email       = trim($_POST['email'] ?? '');
        $newPass     = trim($_POST['new_password'] ?? '');
        $confirmPass = trim($_POST['confirm_password'] ?? '');
        $sessionEmail  = $_SESSION['reset_verified_email'] ?? '';
        $sessionToken  = $_SESSION['reset_verified_token'] ?? '';
        $sessionTime   = $_SESSION['reset_verified_time'] ?? 0;
        $sessionUser   = $_SESSION['reset_verified_user'] ?? '';
        $tokenExpired = (time() - $sessionTime) > $resetTokenTTL;
        $tokenValid   = !empty($sessionToken) && !$tokenExpired
                        && $sessionEmail === $email
                        && !empty($sessionUser);
        if (!$tokenValid) {
            $errorMsg = '验证已过期，请重新操作';
            $step = 1;
            unset($_SESSION['reset_verified_email'], $_SESSION['reset_verified_token'], $_SESSION['reset_verified_time'], $_SESSION['reset_verified_user']);
        } else {
            $username = $sessionUser;
            if (strlen($newPass) < 6 || strlen($newPass) > 32) {
                $errorMsg = '密码需6-32位字符';
                $step = 3;
            } elseif ($newPass !== $confirmPass) {
                $errorMsg = '两次输入的密码不一致';
                $step = 3;
            } else {
                $result = EmailAuth::setPassword($username, $newPass);
                if ($result['success']) {
                    $step = 4;
                    unset($_SESSION['reset_verified_email'], $_SESSION['reset_verified_token'], $_SESSION['reset_verified_time'], $_SESSION['reset_verified_user']);
                } else {
                    $msg = $result['message'];
                    $errorMsg = '重置失败，请重试';
                    if ($msg === 'account_not_found') { $errorMsg = '游戏账号不存在'; }
                    elseif ($msg === 'soap_error') { $errorMsg = '服务器连接失败，请稍后重试'; }
                    elseif ($msg === 'soap_reset_not_supported') { $errorMsg = '服务器不支持重置密码，请联系管理员'; }
                    $step = 3;
                }
            }
        }
    }
}

// Guard: if user lands on step 3 via GET without a valid token, redirect
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $step === 3) {
    $sessionToken = $_SESSION['reset_verified_token'] ?? '';
    $sessionTime  = $_SESSION['reset_verified_time'] ?? 0;
    if (empty($sessionToken) || (time() - $sessionTime) > $resetTokenTTL) {
        $step = 1;
        if ($autoEmail) { $email = $sessionEmail; }
    } else {
        $email    = $_SESSION['reset_verified_email'] ?? '';
        $username = $_SESSION['reset_verified_user'] ?? '';
    }
}

$noEmailBound = $isLoggedIn && empty($sessionEmail) && $step <= 2;
$maskedEmail = $email ? EmailAuth::maskEmail($email) : '';
$emailProvider = get_config('email_provider') ?: 'smtp';
$siteUrl = get_config('baseurl') ?: '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= get_config('page_title') ?> — 忘记密码</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <style>
        :root {
            --brand-blue: #2563eb;
            --brand-blue-hover: #1d4ed8;
            --bg: #f5f7fa;
            --warning: #e6a23c;
            --success: #22c55e;
            --danger: #ef4444;
        }
        body { background: var(--bg); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; }
        .reset-wrapper { max-width: 460px; margin: 50px auto; background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); overflow: hidden; }
        .reset-header { background: var(--brand-blue); color: #fff; padding: 24px 20px; text-align: center; }
        .reset-header h2 { margin: 0; font-size: 20px; font-weight: 600; }
        .reset-body { padding: 30px 24px; }
        .form-group label { font-size: 14px; color: #555; font-weight: 600; }
        .form-control { border-radius: 8px; padding: 10px 14px; }
        .form-control:focus { border-color: var(--brand-blue); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .btn-brand { background: var(--brand-blue); color: #fff; border: none; padding: 12px; font-size: 15px; border-radius: 8px; }
        .btn-brand:hover { background: var(--brand-blue-hover); color: #fff; }
        .btn-brand:disabled { background: #a0b0d0; cursor: not-allowed; }
        .demo-code-box { background: #fff7e6; border: 1px solid #ffd591; border-radius: 8px; padding: 12px; margin: 12px 0; text-align: center; font-size: 14px; color: #d4880c; }
        .demo-code-box code { font-size: 20px; font-weight: 700; letter-spacing: 3px; color: var(--brand-blue); }
        .account-tag { background: #f0f5ff; border: 1px solid #adc6ff; border-radius: 8px; padding: 10px 16px; margin: 12px 0; text-align: center; }
        .account-tag .label { color: #888; font-size: 13px; }
        .account-tag .value { font-weight: 700; color: #333; font-size: 16px; font-family: 'Courier New', monospace; }
        .success-icon { font-size: 48px; color: var(--success); }
        .input-group-text { border-radius: 8px 0 0 8px; background: #f0f0f0; }
        .input-group .form-control:last-child { border-radius: 0 8px 8px 0; }
        .password-toggle { cursor: pointer; border: none; background: transparent; color: #999; padding: 0 10px; }
        .password-toggle:hover { color: var(--brand-blue); }
        .password-strength { height: 4px; border-radius: 2px; margin-top: 6px; }
        .strength-weak { background: var(--danger); }
        .strength-medium { background: var(--warning); }
        .strength-strong { background: var(--success); }
        .strength-text { font-size: 12px; margin-top: 2px; }
        .resend-timer { text-align: center; font-size: 13px; color: #999; margin-top: 8px; }
        .resend-timer .countdown { color: var(--brand-blue); font-weight: 600; }
        .ajax-alert { display: none; padding: 10px 14px; border-radius: 8px; font-size: 14px; margin-bottom: 12px; }
        .ajax-alert.show { display: block; }
        .ajax-alert.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .ajax-alert.error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
        .info-box { background: #f0f5ff; border: 1px solid #adc6ff; border-radius: 12px; padding: 24px 20px; margin-bottom: 20px; text-align: center; }
        .info-box .email-display { font-size: 18px; font-weight: 700; color: var(--brand-blue); margin: 10px 0; word-break: break-all; }
        .info-box .hint { font-size: 13px; color: #888; }
        .bind-prompt { background: #fff7e6; border: 1px solid #ffd591; border-radius: 12px; padding: 24px 20px; text-align: center; }
        .bind-prompt .icon { font-size: 48px; color: var(--warning); margin-bottom: 10px; }
        .bind-prompt h4 { color: #d4880c; margin-bottom: 10px; }
        .bind-prompt p { color: #666; font-size: 14px; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="reset-wrapper">
    <div class="reset-header">
        <h2><i class="fas fa-redo"></i> 忘记密码</h2>
    </div>
    <div class="reset-body">
        <?php if ($noEmailBound): ?>
            <div class="bind-prompt">
                <div class="icon"><i class="fas fa-envelope"></i></div>
                <h4>尚未绑定邮箱</h4>
                <p>您的账号 <strong><?= htmlspecialchars($sessionUser) ?></strong> 尚未绑定邮箱。<br>请先返回主页绑定邮箱后，再使用验证码重置密码。</p>
                <a href="<?= get_config('baseurl') ?>?tab=accountinfo" class="btn btn-brand"><i class="fas fa-link"></i> 去绑定邮箱</a>
            </div>
        <?php elseif ($step === 1): ?>
            <?php if ($autoEmail): ?>
                <p style="text-align: center; color: #888; margin-bottom: 15px;">验证码将发送至您绑定的邮箱</p>
                <div class="info-box">
                    <div class="email-display"><?= htmlspecialchars($maskedEmail) ?></div>
                    <div class="hint"><i class="fas fa-shield-alt" style="color: var(--brand-blue);"></i> 已验证账号：<strong><?= htmlspecialchars($username) ?></strong></div>
                </div>
                <div id="ajaxAlert" class="ajax-alert"></div>
                <form method="POST" action="" id="step1Form">
                    <input type="hidden" name="step" value="1">
                    <input type="hidden" name="send_code" value="1">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                    <button type="submit" id="sendCodeBtn" class="btn btn-brand btn-block"><i class="fas fa-paper-plane"></i> 发送验证码</button>
                </form>
            <?php else: ?>
                <p style="text-align: center; color: #888; margin-bottom: 20px;">请输入账号绑定的邮箱，我们将发送验证码</p>
                <div id="ajaxAlert" class="ajax-alert"></div>
                <form method="POST" action="" id="step1Form">
                    <input type="hidden" name="step" value="1">
                    <input type="hidden" name="send_code" value="1">
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> 邮箱</label>
                        <input type="email" name="email" id="emailInput" class="form-control" placeholder="请输入邮箱" required value="<?= htmlspecialchars($email) ?>">
                    </div>
                    <button type="submit" id="sendCodeBtn" class="btn btn-brand btn-block"><i class="fas fa-paper-plane"></i> 发送验证码</button>
                </form>
            <?php endif; ?>
        <?php elseif ($step === 2): ?>
            <p style="text-align: center; color: #888; margin-bottom: 10px;">验证码已发送至 <strong><?= $autoEmail ? htmlspecialchars($maskedEmail) : htmlspecialchars($email) ?></strong></p>
            <form method="POST" action="" autocomplete="off">
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="verify_code" value="1">
                <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                <div class="form-group">
                    <label><i class="fas fa-shield-alt"></i> 邮箱验证码</label>
                    <input type="text" name="code" id="codeInput" class="form-control" placeholder="请输入6位验证码" maxlength="6" pattern="\d{6}" required style="text-align: center; font-size: 20px; letter-spacing: 5px;" value="">
                </div>
                <button type="submit" class="btn btn-brand btn-block"><i class="fas fa-check"></i> 验证</button>
            </form>
            <div class="resend-timer" id="resendArea">
                <button type="button" id="resendBtn" class="btn btn-link" style="font-size: 13px; color: #666; padding: 4px;">没收到？重新发送</button>
            </div>
            <?php if ($remaining !== null): ?>
                <div class="remaining-hint" style="font-size: 12px; color: #999; text-align: center; margin-top: 8px;">剩余 <?= (int)$remaining ?> 次验证机会</div>
            <?php endif; ?>
        <?php elseif ($step === 3): ?>
            <div class="account-tag">
                <span class="label">游戏账号</span><br>
                <span class="value"><?= htmlspecialchars($username) ?></span>
            </div>
            <p style="text-align: center; color: #888; margin-bottom: 20px;">邮箱验证通过，请设置新密码</p>
            <form method="POST" action="" id="step3Form">
                <input type="hidden" name="step" value="3">
                <input type="hidden" name="set_password" value="1">
                <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> 新密码（6-32位）</label>
                    <div class="input-group">
                        <input type="password" name="new_password" id="newPassword" class="form-control" placeholder="请输入新密码" minlength="6" maxlength="32" required autocomplete="new-password">
                    </div>
                    <div class="password-strength" id="strengthBar" style="background: #e0e0e0;"></div>
                    <div class="strength-text" id="strengthText" style="color: #999;"></div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> 确认新密码</label>
                    <input type="password" name="confirm_password" id="confirmPassword" class="form-control" placeholder="请再次输入新密码" minlength="6" maxlength="32" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-brand btn-block"><i class="fas fa-check"></i> 确认重置密码</button>
            </form>
        <?php elseif ($step === 4): ?>
            <div style="text-align: center;">
                <div class="success-icon"><i class="fas fa-check-circle"></i></div>
                <h4 style="color: var(--success); margin: 15px 0;">密码重置成功！</h4>
                <div class="account-tag"><span class="label">游戏账号</span><br><span class="value"><?= htmlspecialchars($username) ?></span></div>
                <p style="color: #888; font-size: 14px; margin-bottom: 20px;">请使用新密码登录游戏客户端。</p>
                <a href="<?= get_config('baseurl') ?>" class="btn btn-brand" style="display: inline-block; padding: 10px 40px;"><i class="fas fa-home"></i> 返回首页</a>
            </div>
        <?php endif; ?>
        <?php if ($step < 4 && !$noEmailBound): ?>
            <a href="<?= get_config('baseurl') ?>" class="btn btn-outline-secondary btn-block" style="margin-top: 10px;"><i class="fas fa-times"></i> 取消</a>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
(function() {
    'use strict';
    var siteUrl = '<?= addslashes($siteUrl) ?>';
    var autoEmail = <?= $autoEmail ? 'true' : 'false' ?>;

    // ===== Password strength meter =====
    var newPwdInput = document.getElementById('newPassword');
    if (newPwdInput) {
        newPwdInput.addEventListener('input', function() {
            var val = this.value;
            var bar = document.getElementById('strengthBar');
            var text = document.getElementById('strengthText');
            var score = 0;
            if (val.length >= 6) score++;
            if (val.length >= 10) score++;
            if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score++;
            if (/\d/.test(val)) score++;
            if (/[^a-zA-Z0-9]/.test(val)) score++;
            bar.className = 'password-strength';
            if (val.length === 0) { bar.style.background = '#e0e0e0'; text.textContent = ''; }
            else if (score <= 2) { bar.classList.add('strength-weak'); text.textContent = '弱'; text.style.color = '#ef4444'; }
            else if (score <= 3) { bar.classList.add('strength-medium'); text.textContent = '中'; text.style.color = '#e6a23c'; }
            else { bar.classList.add('strength-strong'); text.textContent = '强'; text.style.color = '#22c55e'; }
        });
    }

    function showAlert(element, type, message) {
        if (!element) return;
        element.className = 'ajax-alert show ' + type;
        element.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;
    }

    // ===== Step 1: AJAX email sending =====
    var step1Form = document.getElementById('step1Form');
    if (step1Form) {
        step1Form.addEventListener('submit', function(e) {
            e.preventDefault();
            var email, btn, alert;
            if (autoEmail) {
                email = document.querySelector('#step1Form input[name="email"]').value;
            } else {
                email = document.getElementById('emailInput').value.trim();
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    showAlert(document.getElementById('ajaxAlert'), 'error', '请输入正确的邮箱');
                    return;
                }
            }
            btn = document.getElementById('sendCodeBtn');
            alert = document.getElementById('ajaxAlert');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 发送中...';
            var formData = new FormData();
            formData.append('ajax_action', 'send_code');
            formData.append('email', email);
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(function(resp) { return resp.json(); })
                .then(function(data) {
                    if (data.success) {
                        showAlert(alert, 'success', '验证码已发送');
                        setTimeout(function() { step1Form.submit(); }, 800);
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-paper-plane"></i> 发送验证码';
                        showAlert(alert, 'error', data.message || '发送失败');
                    }
                })
                .catch(function() { step1Form.submit(); });
        });
    }

    // ===== Step 2: Resend with countdown =====
    var resendBtn = document.getElementById('resendBtn');
    if (resendBtn) {
        var countdown = 60;
        var email = '<?= addslashes($email) ?>';
        function startCountdown() {
            resendBtn.disabled = true;
            resendBtn.style.color = '#ccc';
            resendBtn.textContent = '';
            var timer = setInterval(function() {
                if (countdown <= 0) {
                    clearInterval(timer);
                    resendBtn.disabled = false;
                    resendBtn.style.color = '#666';
                    resendBtn.innerHTML = '没收到？重新发送';
                    countdown = 60;
                    return;
                }
                resendBtn.innerHTML = '重新发送（<span class="countdown">' + countdown + '</span>s）';
                countdown--;
            }, 1000);
        }
        startCountdown();
        resendBtn.addEventListener('click', function() {
            if (resendBtn.disabled) return;
            resendBtn.disabled = true;
            resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 发送中...';
            var formData = new FormData();
            formData.append('ajax_action', 'send_code');
            formData.append('email', email);
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(function(resp) { return resp.json(); })
                .then(function(data) {
                    if (data.success) {
                        countdown = 60;
                        startCountdown();
                    } else {
                        alert(data.message || '发送失败');
                        resendBtn.disabled = false;
                        resendBtn.innerHTML = '没收到？重新发送';
                    }
                })
                .catch(function() {
                    resendBtn.disabled = false;
                    resendBtn.innerHTML = '没收到？重新发送';
                });
        });
    }
})();
</script>
</body>
</html>
