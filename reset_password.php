<?php
/**
 * Password Reset Page — Mobile Login Mode (SOAP)
 *
 * This page allows a mobile-authenticated user to reset their game account
 * password. A new random password is generated and set via SOAP.
 *
 * Flow:
 *   1. User must be logged in via mobile (session check)
 *   2. User clicks "Reset Password" to confirm
 *   3. System generates a new random password
 *   4. System sets the new password via SOAP command
 *   5. New password is displayed once for the user to save
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

// Check if mobile auth is enabled
if (!get_config('mobile_enabled')) {
    header('Location: ' . get_config('baseurl'));
    exit;
}

// User must be logged in via mobile
if (!MobileAuth::isLoggedIn()) {
    header('Location: ' . get_config('baseurl'));
    exit;
}

$mbUser     = MobileAuth::getCurrentUser();
$errorMsg   = '';
$successMsg = '';
$newPassword = '';
$showResult  = false;

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirm_reset'])) {
    $result = MobileAuth::resetMyPassword();

    if ($result['success']) {
        $successMsg  = lang('password_reset_success') ?: '密码重置成功！';
        $newPassword = $result['password'];
        $showResult  = true;
    } else {
        $msg = $result['message'];
        if ($msg === 'account_not_found') {
            $errorMsg = lang('account_not_found') ?: '游戏账号不存在，请联系管理员。';
        } elseif ($msg === 'soap_error') {
            $errorMsg = lang('soap_error') ?: 'SOAP 连接失败，请检查服务器状态后重试。';
        } elseif ($msg === 'not_logged_in') {
            $errorMsg = lang('not_logged_in') ?: '请先登录。';
        } elseif ($msg === 'no_bound_account') {
            $errorMsg = lang('no_bound_account') ?: '未找到绑定的游戏账号。';
        } else {
            $errorMsg = lang('error_try_again') ?: '发生错误，请重试。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= get_config('page_title') ?> — <?= lang('reset_password') ?: '重置密码' ?></title>
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
        .account-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 16px;
            margin: 16px 0;
            text-align: center;
        }
        .account-info .label { color: #888; font-size: 13px; }
        .account-info .value { font-weight: 700; color: #333; font-size: 16px; font-family: 'Courier New', monospace; }
        .password-card {
            background: #f8f9fa;
            border: 2px dashed var(--brand-blue);
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        .password-card .new-password {
            font-size: 22px;
            font-weight: 700;
            color: var(--brand-blue);
            font-family: 'Courier New', monospace;
            letter-spacing: 2px;
            padding: 10px;
            background: #fff;
            border-radius: 6px;
            display: inline-block;
            margin: 10px 0;
        }
        .password-warning {
            color: var(--warning);
            font-size: 13px;
            margin-top: 12px;
            text-align: center;
        }
        .btn-reset {
            background: var(--brand-blue);
            color: #fff;
            border: none;
            padding: 12px;
            font-size: 15px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .btn-reset:hover { background: var(--brand-blue-hover); color: #fff; }
        .btn-danger-reset {
            background: #e6a23c;
            color: #fff;
            border: none;
            padding: 12px;
            font-size: 15px;
            border-radius: 8px;
        }
        .btn-danger-reset:hover { background: #d4880c; color: #fff; }
        .warning-box {
            background: #fff7e6;
            border: 1px solid #ffd591;
            border-radius: 8px;
            padding: 12px 16px;
            margin: 15px 0;
            color: #d4880c;
            font-size: 13px;
        }
    </style>
</head>
<body>
<div class="reset-wrapper">
    <div class="reset-header">
        <h2><i class="fas fa-key"></i> <?= lang('reset_password') ?: '重置密码' ?></h2>
    </div>
    <div class="reset-body">

        <?php if ($showResult): ?>
            <!-- ===== Success: Show new password ===== -->
            <div style="text-align: center;">
                <div style="font-size: 48px; color: var(--brand-blue); margin-bottom: 10px;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h4 style="color: var(--brand-blue); margin-bottom: 20px;">
                    <?= htmlspecialchars($successMsg) ?>
                </h4>

                <div class="account-info">
                    <span class="label"><?= lang('account') ?: '游戏账号' ?></span><br>
                    <span class="value"><?= htmlspecialchars($mbUser['username'] ?? '') ?></span>
                </div>

                <div class="password-card">
                    <span class="label" style="color: #666; font-size: 13px;">
                        <?= lang('new_password') ?: '新密码' ?>
                    </span>
                    <div class="new-password"><?= htmlspecialchars($newPassword) ?></div>
                    <div class="password-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= lang('save_password_warning') ?: '请立即妥善保存新密码！此密码仅显示一次。' ?>
                    </div>
                </div>

                <a href="<?= get_config('baseurl') ?>" class="btn btn-reset" style="display: inline-block; padding: 10px 40px;">
                    <i class="fas fa-home"></i> <?= lang('back_to_home') ?: '返回首页' ?>
                </a>
            </div>

        <?php else: ?>
            <!-- ===== Confirmation form ===== -->
            <div class="account-info">
                <span class="label"><?= lang('account') ?: '游戏账号' ?></span><br>
                <span class="value"><?= htmlspecialchars($mbUser['username'] ?? '') ?></span>
            </div>

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger" style="font-size: 14px;">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($errorMsg) ?>
                </div>
            <?php endif; ?>

            <div class="warning-box">
                <i class="fas fa-info-circle"></i>
                <?= lang('reset_password_warning') ?: '重置密码将生成一个新的随机密码，原密码将立即失效。此操作不可撤销。' ?>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="confirm_reset" value="1">
                <button type="submit" class="btn btn-danger-reset btn-block">
                    <i class="fas fa-redo"></i>
                    <?= lang('confirm_reset_password') ?: '确认重置密码' ?>
                </button>
            </form>

            <a href="<?= get_config('baseurl') ?>" class="btn btn-outline-secondary btn-block" style="margin-top: 10px;">
                <i class="fas fa-times"></i> <?= lang('cancel') ?: '取消' ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
