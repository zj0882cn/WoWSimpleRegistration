<?php
/**
 * Forgot Password Page — SMS Verification Required
 *
 * Flow:
 *   - Logged in + phone bound → auto-use session phone, send code directly (no input)
 *   - Logged in + no phone   → prompt to bind phone first, redirect to main page
 *   - Not logged in          → user enters phone → check binding → send code
 *   Step 2: User enters SMS code → verify (server sets session token)
 *   Step 3: Set new password → SOAP reset (checks session token)
 *   Done:   Success page
 *
 * @author AzerothCore Community
 */

session_start();
require_once __DIR__ . '/application/config/config.php';
require_once __DIR__ . '/application/include/core_handler.php';
require_once __DIR__ . '/application/include/functions.php';
require_once __DIR__ . '/application/include/mobile.php';

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

if (!get_config('mobile_enabled')) {
    header('Location: ' . get_config('baseurl'));
    exit;
}

/** Verification token TTL in seconds (10 minutes) */
$resetTokenTTL = 600;

// Determine login state
$isLoggedIn  = MobileAuth::isLoggedIn();
$sessionUser  = $_SESSION['mobile_username'] ?? '';
$sessionPhone = $_SESSION['mobile_phone'] ?? '';

// Pre-fill phone from session if available
$phone     = $sessionPhone;
$username  = $sessionUser;
$errorMsg  = '';
$demoCode  = '';
$remaining = null;

// Auto-send flag: whether phone was auto-filled from session (skip phone input UI)
$autoPhone = $isLoggedIn && !empty($sessionPhone);

// State: which step are we on?
//   1 = send code (auto or manual phone input)
//   2 = enter SMS code
//   3 = set new password
//   4 = done (success)
$step = intval($_POST['step'] ?? $_GET['step'] ?? 1);

// If user is logged in but has no bound phone → force to bind first
if ($isLoggedIn && empty($sessionPhone) && $step <= 2) {
    $step     = 1;
    $errorMsg = '您尚未绑定手机号，请先绑定后再重置密码';
}

// AJAX endpoint for sending SMS code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_POST['ajax_action'] === 'send_code') {
        $phone = trim($_POST['phone'] ?? '');

        // For logged-in users, phone comes from session
        if (empty($phone) && $isLoggedIn && !empty($sessionPhone)) {
            $phone = $sessionPhone;
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            echo json_encode(['success' => false, 'message' => '请输入正确的手机号']);
            exit;
        }

        // Check if this phone has a bound account
        $binding = MobileAuth::getBindingByPhone($phone);
        if (!$binding) {
            echo json_encode(['success' => false, 'message' => '该手机号未绑定游戏账号，请先绑定']);
            exit;
        }
        if (!MobileAuth::accountExists($binding['username'])) {
            echo json_encode(['success' => false, 'message' => '绑定的游戏账号不存在，请联系管理员']);
            exit;
        }

        $result = MobileAuth::sendCode($phone);
        $response = ['success' => $result['success']];

        if ($result['success']) {
            $response['message'] = '验证码已发送';
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

    echo json_encode(['success' => false, 'message' => 'unknown_action']);
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Step 1: Send SMS code ---
    if ($step === 1 && !empty($_POST['send_code'])) {
        // Use auto-filled phone from session if available
        if ($autoPhone) {
            $phone = $sessionPhone;
        } else {
            $phone = trim($_POST['phone'] ?? '');
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            $errorMsg = '请输入正确的手机号';
        } else {
            $binding = MobileAuth::getBindingByPhone($phone);
            if (!$binding) {
                $errorMsg = '该手机号未绑定游戏账号，请先绑定';
            } elseif (!MobileAuth::accountExists($binding['username'])) {
                $errorMsg = '绑定的游戏账号不存在，请联系管理员';
            } else {
                $username = $binding['username'];
                $result = MobileAuth::sendCode($phone);
                if ($result['success']) {
                    $step = 2;
                    if (!empty($result['code'])) {
                        $demoCode = $result['code'];
                    }
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

    // --- Step 2: Verify SMS code ---
    elseif ($step === 2 && !empty($_POST['verify_code'])) {
        // Phone comes from POST or session
        $phone = trim($_POST['phone'] ?? '');
        if (empty($phone) && $autoPhone) {
            $phone = $sessionPhone;
        }
        $code  = trim($_POST['code'] ?? '');

        if (empty($phone) || empty($code)) {
            $errorMsg = '请输入验证码';
            $step = 2;
        } else {
            $verifyResult = MobileAuth::verifyCode($phone, $code);
            if ($verifyResult['success']) {
                $binding = MobileAuth::getBindingByPhone($phone);
                $username = $binding['username'] ?? '';

                if (empty($username)) {
                    $errorMsg = '账号信息异常，请重试';
                    $step = 1;
                } else {
                    $_SESSION['reset_verified_phone']  = $phone;
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
                if ($step !== 1) {
                    $step = 2;
                }
            }
        }
    }

    // --- Step 3: Set new password ---
    elseif ($step === 3 && !empty($_POST['set_password'])) {
        $phone       = trim($_POST['phone'] ?? '');
        $newPass     = trim($_POST['new_password'] ?? '');
        $confirmPass = trim($_POST['confirm_password'] ?? '');

        $sessionPhone  = $_SESSION['reset_verified_phone'] ?? '';
        $sessionToken  = $_SESSION['reset_verified_token'] ?? '';
        $sessionTime   = $_SESSION['reset_verified_time'] ?? 0;
        $sessionUser   = $_SESSION['reset_verified_user'] ?? '';

        $tokenExpired = (time() - $sessionTime) > $resetTokenTTL;
        $tokenValid   = !empty($sessionToken) && !$tokenExpired
                        && $sessionPhone === $phone
                        && !empty($sessionUser);

        if (!$tokenValid) {
            $errorMsg = '验证已过期，请重新操作';
            $step = 1;
            unset(
                $_SESSION['reset_verified_phone'],
                $_SESSION['reset_verified_token'],
                $_SESSION['reset_verified_time'],
                $_SESSION['reset_verified_user']
            );
        } else {
            $username = $sessionUser;

            if (strlen($newPass) < 6 || strlen($newPass) > 32) {
                $errorMsg = '密码需6-32位字符';
                $step = 3;
            } elseif ($newPass !== $confirmPass) {
                $errorMsg = '两次输入的密码不一致';
                $step = 3;
            } else {
                $result = MobileAuth::changePassword($username, $newPass);
                if ($result['success']) {
                    $step = 4;
                    unset(
                        $_SESSION['reset_verified_phone'],
                        $_SESSION['reset_verified_token'],
                        $_SESSION['reset_verified_time'],
                        $_SESSION['reset_verified_user']
                    );
                } else {
                    $msg = $result['message'];
                    if ($msg === 'account_not_found') {
                        $errorMsg = '游戏账号不存在';
                    } elseif ($msg === 'soap_error') {
                        $errorMsg = '服务器连接失败，请稍后重试';
                    } else {
                        $errorMsg = '重置失败，请重试';
                    }
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
        if ($autoPhone) {
            $phone = $sessionPhone;
        }
    } else {
        $phone    = $_SESSION['reset_verified_phone'] ?? '';
        $username = $_SESSION['reset_verified_user'] ?? '';
    }
}

// Guard: logged in without phone binding → show bind-first page
$noPhoneBound = $isLoggedIn && empty($sessionPhone) && $step <= 2;

// Compute masked phone for display
$maskedPhone = $phone ? MobileAuth::maskPhone($phone) : '';

$smsProvider = get_config('sms_provider') ?: 'demo';
$siteUrl     = get_config('baseurl') ?: '';
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
        body {
            background: var(--bg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Noto Sans CJK SC", "Microsoft YaHei", sans-serif;
        }
        .reset-wrapper {
            max-width: 460px;
            margin: 50px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .reset-header {
            background: var(--brand-blue);
            color: #fff;
            padding: 24px 20px;
            text-align: center;
        }
        .reset-header h2 { margin: 0; font-size: 20px; font-weight: 600; }
        .reset-body { padding: 30px 24px; }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 25px;
        }
        .step-dot {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #999;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 700;
            margin: 0 8px;
            transition: all 0.3s;
        }
        .step-dot.active {
            background: var(--brand-blue);
            color: #fff;
        }
        .step-dot.done {
            background: var(--success);
            color: #fff;
        }
        .step-line {
            width: 30px; height: 2px;
            background: #e0e0e0;
            margin-top: 15px;
        }
        .step-line.done { background: var(--success); }
        .form-group label { font-size: 14px; color: #555; font-weight: 600; }
        .form-control { border-radius: 8px; padding: 10px 14px; }
        .form-control:focus {
            border-color: var(--brand-blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .btn-brand {
            background: var(--brand-blue);
            color: #fff;
            border: none;
            padding: 12px;
            font-size: 15px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .btn-brand:hover { background: var(--brand-blue-hover); color: #fff; }
        .btn-brand:disabled {
            background: #a0b0d0;
            cursor: not-allowed;
        }
        .demo-code-box {
            background: #fff7e6;
            border: 1px solid #ffd591;
            border-radius: 8px;
            padding: 12px;
            margin: 12px 0;
            text-align: center;
            font-size: 14px;
            color: #d4880c;
        }
        .demo-code-box code {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 3px;
            color: var(--brand-blue);
        }
        .account-tag {
            background: #f0f5ff;
            border: 1px solid #adc6ff;
            border-radius: 8px;
            padding: 10px 16px;
            margin: 12px 0;
            text-align: center;
        }
        .account-tag .label { color: #888; font-size: 13px; }
        .account-tag .value { font-weight: 700; color: #333; font-size: 16px; font-family: 'Courier New', monospace; }
        .success-icon { font-size: 48px; color: var(--success); }
        .input-group-text {
            border-radius: 8px 0 0 8px;
            background: #f0f0f0;
        }
        .input-group .form-control:last-child {
            border-radius: 0 8px 8px 0;
        }
        .password-toggle {
            cursor: pointer;
            border: none;
            background: transparent;
            color: #999;
            padding: 0 10px;
        }
        .password-toggle:hover { color: var(--brand-blue); }
        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 6px;
            transition: all 0.3s;
        }
        .strength-weak { background: var(--danger); }
        .strength-medium { background: var(--warning); }
        .strength-strong { background: var(--success); }
        .strength-text { font-size: 12px; margin-top: 2px; }
        .resend-timer {
            text-align: center;
            font-size: 13px;
            color: #999;
            margin-top: 8px;
        }
        .resend-timer .countdown { color: var(--brand-blue); font-weight: 600; }
        .remaining-hint {
            font-size: 12px;
            color: #999;
            text-align: center;
            margin-top: 8px;
        }
        .ajax-alert {
            display: none;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 12px;
        }
        .ajax-alert.show { display: block; }
        .ajax-alert.success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #15803d;
        }
        .ajax-alert.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }
        .info-box {
            background: #f0f5ff;
            border: 1px solid #adc6ff;
            border-radius: 12px;
            padding: 24px 20px;
            margin-bottom: 20px;
            text-align: center;
        }
        .info-box .phone-display {
            font-size: 22px;
            font-weight: 700;
            color: var(--brand-blue);
            letter-spacing: 2px;
            margin: 10px 0;
        }
        .info-box .hint {
            font-size: 13px;
            color: #888;
        }
        .bind-prompt {
            background: #fff7e6;
            border: 1px solid #ffd591;
            border-radius: 12px;
            padding: 24px 20px;
            text-align: center;
        }
        .bind-prompt .icon {
            font-size: 48px;
            color: var(--warning);
            margin-bottom: 10px;
        }
        .bind-prompt h4 {
            color: #d4880c;
            margin-bottom: 10px;
        }
        .bind-prompt p {
            color: #666;
            font-size: 14px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="reset-wrapper">
    <div class="reset-header">
        <h2><i class="fas fa-redo"></i> 忘记密码</h2>
    </div>
    <div class="reset-body">

        <?php if ($noPhoneBound): ?>
            <!-- ===== Logged in but no phone bound → prompt to bind ===== -->
            <div class="bind-prompt">
                <div class="icon">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <h4>尚未绑定手机号</h4>
                <p>
                    您的账号 <strong><?= htmlspecialchars($sessionUser) ?></strong> 尚未绑定手机号。<br>
                    请先返回主页绑定手机后，再使用验证码重置密码。
                </p>
                <a href="<?= get_config('baseurl') ?>?tab=accountinfo" class="btn btn-brand">
                    <i class="fas fa-link"></i> 去绑定手机
                </a>
            </div>

        <?php elseif ($step === 1): ?>
            <!-- ===== Step 1: Send code ===== -->

            <?php if ($autoPhone): ?>
                <!-- Auto mode: phone from session, show masked phone directly -->
                <p style="text-align: center; color: #888; margin-bottom: 15px;">
                    验证码将发送至您绑定的手机号
                </p>
                <div class="info-box">
                    <div class="phone-display"><?= htmlspecialchars($maskedPhone) ?></div>
                    <div class="hint">
                        <i class="fas fa-shield-alt" style="color: var(--brand-blue);"></i>
                        已验证账号：<strong><?= htmlspecialchars($username) ?></strong>
                    </div>
                </div>
                <!-- AJAX status message -->
                <div id="ajaxAlert" class="ajax-alert"></div>
                <!-- Demo code display -->
                <div id="demoCodeBox" class="demo-code-box" style="display: none;">
                    <i class="fas fa-info-circle"></i> 演示模式验证码：<br>
                    <code id="demoCodeValue"></code>
                </div>
                <form method="POST" action="" id="step1Form">
                    <input type="hidden" name="step" value="1">
                    <input type="hidden" name="send_code" value="1">
                    <input type="hidden" name="phone" value="<?= htmlspecialchars($phone) ?>">
                    <button type="submit" id="sendCodeBtn" class="btn btn-brand btn-block">
                        <i class="fas fa-paper-plane"></i> 发送验证码
                    </button>
                </form>

            <?php else: ?>
                <!-- Manual mode: user enters phone number -->
                <p style="text-align: center; color: #888; margin-bottom: 20px;">
                    请输入账号绑定的手机号，我们将发送验证码
                </p>
                <!-- AJAX status message -->
                <div id="ajaxAlert" class="ajax-alert"></div>
                <!-- Demo code display -->
                <div id="demoCodeBox" class="demo-code-box" style="display: none;">
                    <i class="fas fa-info-circle"></i> 演示模式验证码：<br>
                    <code id="demoCodeValue"></code>
                </div>
                <form method="POST" action="" id="step1Form">
                    <input type="hidden" name="step" value="1">
                    <input type="hidden" name="send_code" value="1">
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> 手机号</label>
                        <input type="tel" name="phone" id="phoneInput" class="form-control"
                               placeholder="请输入手机号" maxlength="11"
                               pattern="1[3-9]\d{9}" required
                               value="<?= htmlspecialchars($phone) ?>">
                    </div>
                    <button type="submit" id="sendCodeBtn" class="btn btn-brand btn-block">
                        <i class="fas fa-paper-plane"></i> 发送验证码
                    </button>
                </form>
            <?php endif; ?>

        <?php elseif ($step === 2): ?>
            <!-- ===== Step 2: Enter SMS code ===== -->
            <p style="text-align: center; color: #888; margin-bottom: 10px;">
                验证码已发送至
                <strong><?= $autoPhone ? htmlspecialchars($maskedPhone) : htmlspecialchars($phone) ?></strong>
            </p>

            <?php if ($demoCode): ?>
                <div class="demo-code-box">
                    <i class="fas fa-info-circle"></i> 演示模式验证码：<br>
                    <code><?= htmlspecialchars($demoCode) ?></code>
                </div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="off">
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="verify_code" value="1">
                <input type="hidden" name="phone" value="<?= htmlspecialchars($phone) ?>">
                <div class="form-group">
                    <label><i class="fas fa-shield-alt"></i> 短信验证码</label>
                    <input type="text" name="code" id="codeInput" class="form-control"
                           placeholder="请输入6位验证码" maxlength="6"
                           pattern="\d{6}" required
                           autocomplete="one-time-code"
                           style="text-align: center; font-size: 20px; letter-spacing: 5px;"
                           value="">
                </div>
                <button type="submit" class="btn btn-brand btn-block">
                    <i class="fas fa-check"></i> 验证
                </button>
            </form>

            <!-- Resend button with countdown -->
            <div class="resend-timer" id="resendArea">
                <button type="button" id="resendBtn" class="btn btn-link" style="font-size: 13px; color: #666; padding: 4px;">
                    没收到？重新发送
                </button>
            </div>

            <?php if ($remaining !== null): ?>
                <div class="remaining-hint">
                    剩余 <?= (int)$remaining ?> 次验证机会
                </div>
            <?php endif; ?>

        <?php elseif ($step === 3): ?>
            <!-- ===== Step 3: Set new password ===== -->
            <div class="account-tag">
                <span class="label">游戏账号</span><br>
                <span class="value"><?= htmlspecialchars($username) ?></span>
            </div>
            <p style="text-align: center; color: #888; margin-bottom: 20px;">
                手机验证通过，请设置新密码
            </p>
            <form method="POST" action="" id="step3Form">
                <input type="hidden" name="step" value="3">
                <input type="hidden" name="set_password" value="1">
                <input type="hidden" name="phone" value="<?= htmlspecialchars($phone) ?>">
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> 新密码（6-32位）</label>
                    <div class="input-group">
                        <input type="password" name="new_password" id="newPassword" class="form-control"
                               placeholder="请输入新密码" minlength="6" maxlength="32" required
                               autocomplete="new-password">
                        <div class="input-group-append">
                            <button type="button" class="password-toggle" onclick="togglePassword('newPassword', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="password-strength" id="strengthBar" style="background: #e0e0e0;"></div>
                    <div class="strength-text" id="strengthText" style="color: #999;"></div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> 确认新密码</label>
                    <div class="input-group">
                        <input type="password" name="confirm_password" id="confirmPassword" class="form-control"
                               placeholder="请再次输入新密码" minlength="6" maxlength="32" required
                               autocomplete="new-password">
                        <div class="input-group-append">
                            <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-brand btn-block">
                    <i class="fas fa-check"></i> 确认重置密码
                </button>
            </form>

        <?php elseif ($step === 4): ?>
            <!-- ===== Done: Success ===== -->
            <div style="text-align: center;">
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h4 style="color: var(--success); margin: 15px 0;">
                    密码重置成功！
                </h4>
                <div class="account-tag">
                    <span class="label">游戏账号</span><br>
                    <span class="value"><?= htmlspecialchars($username) ?></span>
                </div>
                <p style="color: #888; font-size: 14px; margin-bottom: 20px;">
                    请使用新密码登录游戏客户端。
                </p>
                <a href="<?= get_config('baseurl') ?>" class="btn btn-brand" style="display: inline-block; padding: 10px 40px;">
                    <i class="fas fa-home"></i> 返回首页
                </a>
            </div>
        <?php endif; ?>

        <?php if ($step < 4 && !$noPhoneBound): ?>
            <a href="<?= get_config('baseurl') ?>" class="btn btn-outline-secondary btn-block" style="margin-top: 10px;">
                <i class="fas fa-times"></i> 取消
            </a>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
(function() {
    'use strict';

    var siteUrl = '<?= addslashes($siteUrl) ?>';
    var isDemo  = <?= ($smsProvider === 'demo') ? 'true' : 'false' ?>;
    var autoPhone = <?= $autoPhone ? 'true' : 'false' ?>;

    // ===== Password show/hide toggle =====
    window.togglePassword = function(inputId, btn) {
        var input = document.getElementById(inputId);
        var icon  = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    };

    // ===== Password strength meter =====
    var newPwdInput = document.getElementById('newPassword');
    if (newPwdInput) {
        newPwdInput.addEventListener('input', function() {
            var val = this.value;
            var bar  = document.getElementById('strengthBar');
            var text = document.getElementById('strengthText');
            var score = 0;

            if (val.length >= 6) score++;
            if (val.length >= 10) score++;
            if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score++;
            if (/\d/.test(val)) score++;
            if (/[^a-zA-Z0-9]/.test(val)) score++;

            bar.className = 'password-strength';
            if (val.length === 0) {
                bar.style.background = '#e0e0e0';
                text.textContent = '';
            } else if (score <= 2) {
                bar.classList.add('strength-weak');
                text.textContent = '弱';
                text.style.color = '#ef4444';
            } else if (score <= 3) {
                bar.classList.add('strength-medium');
                text.textContent = '中';
                text.style.color = '#e6a23c';
            } else {
                bar.classList.add('strength-strong');
                text.textContent = '强';
                text.style.color = '#22c55e';
            }
        });
    }

    // ===== Step 1: AJAX SMS sending =====
    var step1Form = document.getElementById('step1Form');
    if (step1Form) {
        step1Form.addEventListener('submit', function(e) {
            e.preventDefault();

            var phone, btn, alert;
            if (autoPhone) {
                phone = document.querySelector('#step1Form input[name="phone"]').value;
            } else {
                phone = document.getElementById('phoneInput').value.trim();
                if (!/^1[3-9]\d{9}$/.test(phone)) {
                    showAlert(document.getElementById('ajaxAlert'), 'error', '请输入正确的手机号');
                    return;
                }
            }

            btn = document.getElementById('sendCodeBtn');
            alert = document.getElementById('ajaxAlert');

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 发送中...';

            var formData = new FormData();
            formData.append('ajax_action', 'send_code');
            formData.append('phone', phone);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                if (data.success) {
                    showAlert(alert, 'success', '验证码已发送');

                    if (data.code) {
                        var box = document.getElementById('demoCodeBox');
                        document.getElementById('demoCodeValue').textContent = data.code;
                        box.style.display = 'block';
                    }

                    setTimeout(function() {
                        step1Form.submit();
                    }, 800);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> 发送验证码';
                    showAlert(alert, 'error', data.message || '发送失败');
                }
            })
            .catch(function() {
                step1Form.submit();
            });
        });
    }

    // ===== Step 2: Resend code with countdown =====
    var resendBtn = document.getElementById('resendBtn');
    if (resendBtn) {
        var countdown = 60;
        var phone = '<?= addslashes($phone) ?>';

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
            formData.append('phone', phone);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                if (data.success) {
                    if (data.code) {
                        var existingBox = document.querySelector('.demo-code-box code');
                        if (existingBox) {
                            existingBox.textContent = data.code;
                        }
                    }
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

        // Web OTP API: auto-receive SMS code
        if ('otpCredentials' in navigator) {
            navigator.credentials.get({
                otp: { transport: ['sms'] }
            }).then(function(otp) {
                var codeInput = document.getElementById('codeInput');
                if (otp && codeInput) {
                    codeInput.value = otp.code;
                    setTimeout(function() {
                        codeInput.form.submit();
                    }, 300);
                }
            }).catch(function() {});
        }
    }

    function showAlert(element, type, message) {
        if (!element) return;
        element.className = 'ajax-alert show ' + type;
        element.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;
    }
})();
</script>
</body>
</html>
