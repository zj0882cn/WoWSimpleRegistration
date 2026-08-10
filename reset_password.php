<?php
/**
 * Forgot Password Page — SMS Verification Required
 *
 * Flow:
 *   Step 1: User enters phone number → send SMS code
 *   Step 2: User enters SMS code → verify
 *   Step 3: After verification, user sets new password → SOAP reset
 *
 * @author AzerothCore Community
 **/

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

// State: which step are we on?
//   1 = enter phone (default)
//   2 = enter SMS code
//   3 = set new password
//   done = success
$step    = intval($_POST['step'] ?? $_GET['step'] ?? 1);
$errorMsg = '';
$demoCode = ''; // demo mode shows the code
$phone    = '';
$username = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Step 1: Send SMS code ---
    if ($step === 1 && !empty($_POST['send_code'])) {
        $phone = trim($_POST['phone'] ?? '');

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            $errorMsg = '请输入正确的手机号';
        } else {
            // Check if this phone has a bound account
            $binding = MobileAuth::getBindingByPhone($phone);
            if (!$binding) {
                $errorMsg = '该手机号未注册游戏账号';
            } elseif (!MobileAuth::accountExists($binding['username'])) {
                $errorMsg = '绑定的游戏账号不存在，请联系管理员';
            } else {
                $username = $binding['username'];
                $result = MobileAuth::sendCode($phone);
                if ($result['success']) {
                    $step = 2;
                    // In demo mode, the code is returned for display
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
        $phone = trim($_POST['phone'] ?? '');
        $code  = trim($_POST['code'] ?? '');

        if (empty($phone) || empty($code)) {
            $errorMsg = '请输入验证码';
            $step = 2;
        } else {
            $verifyResult = MobileAuth::verifyCode($phone, $code);
            if ($verifyResult['success']) {
                // Get the bound account
                $binding = MobileAuth::getBindingByPhone($phone);
                $username = $binding['username'] ?? '';
                $step = 3; // Move to set new password
            } else {
                $msg = $verifyResult['message'];
                if ($msg === 'invalid_code' || $msg === 'code_not_found') {
                    $errorMsg = '验证码错误';
                } elseif ($msg === 'max_attempts_exceeded') {
                    $errorMsg = '尝试次数过多，请重新获取验证码';
                    $step = 1;
                } elseif ($msg === 'code_expired') {
                    $errorMsg = '验证码已过期，请重新获取';
                    $step = 1;
                } else {
                    $errorMsg = '验证失败，请重试';
                }
                $step = 2;
            }
        }
    }

    // --- Step 3: Set new password ---
    elseif ($step === 3 && !empty($_POST['set_password'])) {
        $phone       = trim($_POST['phone'] ?? '');
        $newPass     = trim($_POST['new_password'] ?? '');
        $confirmPass = trim($_POST['confirm_password'] ?? '');

        if (strlen($newPass) < 6 || strlen($newPass) > 32) {
            $errorMsg = '密码需6-32位字符';
            $step = 3;
            // Re-fetch username for display
            $binding = MobileAuth::getBindingByPhone($phone);
            $username = $binding['username'] ?? '';
        } elseif ($newPass !== $confirmPass) {
            $errorMsg = '两次输入的密码不一致';
            $step = 3;
            $binding = MobileAuth::getBindingByPhone($phone);
            $username = $binding['username'] ?? '';
        } else {
            $binding = MobileAuth::getBindingByPhone($phone);
            $username = $binding['username'] ?? '';

            if (empty($username)) {
                $errorMsg = '账号信息异常，请重试';
                $step = 1;
            } else {
                $result = MobileAuth::changePassword($username, $newPass);
                if ($result['success']) {
                    $step = 4; // Done
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
            background: #22c55e;
            color: #fff;
        }
        .step-line {
            width: 30px; height: 2px;
            background: #e0e0e0;
            margin-top: 15px;
        }
        .step-line.done { background: #22c55e; }
        .form-group label { font-size: 14px; color: #555; font-weight: 600; }
        .form-control { border-radius: 8px; padding: 10px 14px; }
        .btn-brand {
            background: var(--brand-blue);
            color: #fff;
            border: none;
            padding: 12px;
            font-size: 15px;
            border-radius: 8px;
        }
        .btn-brand:hover { background: var(--brand-blue-hover); color: #fff; }
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
        .success-icon { font-size: 48px; color: #22c55e; }
    </style>
</head>
<body>
<div class="reset-wrapper">
    <div class="reset-header">
        <h2><i class="fas fa-redo"></i> 忘记密码</h2>
    </div>
    <div class="reset-body">

        <?php if ($step < 4): ?>
        <!-- Step indicator -->
        <div class="step-indicator">
            <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">1</div>
            <div class="step-line <?= $step > 1 ? 'done' : '' ?>"></div>
            <div class="step-dot <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>">2</div>
            <div class="step-line <?= $step > 2 ? 'done' : '' ?>"></div>
            <div class="step-dot <?= $step >= 3 ? 'active' : '' ?>">3</div>
        </div>
        <?php endif; ?>

        <?php if ($errorMsg): ?>
            <div class="alert alert-danger" style="font-size: 14px;">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <!-- ===== Step 1: Enter phone number ===== -->
            <p style="text-align: center; color: #888; margin-bottom: 20px;">
                输入注册时绑定的手机号，我们将发送验证码
            </p>
            <form method="POST" action="">
                <input type="hidden" name="step" value="1">
                <input type="hidden" name="send_code" value="1">
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> 手机号</label>
                    <input type="tel" name="phone" class="form-control"
                           placeholder="请输入手机号" maxlength="11"
                           pattern="1[3-9]\d{9}" required
                           value="<?= htmlspecialchars($phone) ?>">
                </div>
                <button type="submit" class="btn btn-brand btn-block">
                    <i class="fas fa-paper-plane"></i> 发送验证码
                </button>
            </form>

        <?php elseif ($step === 2): ?>
            <!-- ===== Step 2: Enter SMS code ===== -->
            <p style="text-align: center; color: #888; margin-bottom: 10px;">
                验证码已发送至 <strong><?= htmlspecialchars($phone) ?></strong>
            </p>

            <?php if ($demoCode): ?>
                <div class="demo-code-box">
                    <i class="fas fa-info-circle"></i> 演示模式验证码：<br>
                    <code><?= htmlspecialchars($demoCode) ?></code>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="verify_code" value="1">
                <input type="hidden" name="phone" value="<?= htmlspecialchars($phone) ?>">
                <div class="form-group">
                    <label><i class="fas fa-shield-alt"></i> 短信验证码</label>
                    <input type="text" name="code" class="form-control"
                           placeholder="请输入6位验证码" maxlength="6"
                           pattern="\d{6}" required
                           style="text-align: center; font-size: 20px; letter-spacing: 5px;">
                </div>
                <button type="submit" class="btn btn-brand btn-block">
                    <i class="fas fa-check"></i> 验证
                </button>
            </form>
            <form method="POST" action="" style="margin-top: 8px;">
                <input type="hidden" name="step" value="1">
                <input type="hidden" name="send_code" value="1">
                <input type="hidden" name="phone" value="<?= htmlspecialchars($phone) ?>">
                <button type="submit" class="btn btn-link btn-block" style="font-size: 13px; color: #666;">
                    没收到？重新发送
                </button>
            </form>

        <?php elseif ($step === 3): ?>
            <!-- ===== Step 3: Set new password ===== -->
            <div class="account-tag">
                <span class="label">游戏账号</span><br>
                <span class="value"><?= htmlspecialchars($username) ?></span>
            </div>
            <p style="text-align: center; color: #888; margin-bottom: 20px;">
                手机验证通过，请设置新密码
            </p>
            <form method="POST" action="">
                <input type="hidden" name="step" value="3">
                <input type="hidden" name="set_password" value="1">
                <input type="hidden" name="phone" value="<?= htmlspecialchars($phone) ?>">
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> 新密码（6-32位）</label>
                    <input type="password" name="new_password" class="form-control"
                           placeholder="请输入新密码" minlength="6" maxlength="32" required
                           autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> 确认新密码</label>
                    <input type="password" name="confirm_password" class="form-control"
                           placeholder="请再次输入新密码" minlength="6" maxlength="32" required
                           autocomplete="new-password">
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
                <h4 style="color: #22c55e; margin: 15px 0;">
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

        <?php if ($step < 4): ?>
            <a href="<?= get_config('baseurl') ?>" class="btn btn-outline-secondary btn-block" style="margin-top: 10px;">
                <i class="fas fa-times"></i> 取消
            </a>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
