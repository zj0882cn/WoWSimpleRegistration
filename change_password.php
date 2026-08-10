<?php
/**
 * Change Password Page — For logged-in users
 *
 * Allows a logged-in user to change their game account password.
 * The user enters a new password (typed twice for confirmation).
 * No phone verification needed — the user is already authenticated.
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

// Must be logged in
if (!MobileAuth::isLoggedIn()) {
    header('Location: ' . get_config('baseurl'));
    exit;
}

$mbUser    = MobileAuth::getCurrentUser();
$errorMsg  = '';
$successMsg = '';
$showResult = false;

// Handle change password request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirm_change'])) {
    $newPass     = trim($_POST['new_password'] ?? '');
    $confirmPass = trim($_POST['confirm_password'] ?? '');

    if (strlen($newPass) < 6 || strlen($newPass) > 32) {
        $errorMsg = '密码需6-32位字符';
    } elseif ($newPass !== $confirmPass) {
        $errorMsg = '两次输入的密码不一致';
    } else {
        $result = MobileAuth::changeMyPassword($newPass);
        if ($result['success']) {
            $successMsg = '密码修改成功！';
            $showResult = true;
        } else {
            $msg = $result['message'];
            if ($msg === 'account_not_found') {
                $errorMsg = '游戏账号不存在';
            } elseif ($msg === 'soap_error') {
                $errorMsg = '服务器连接失败，请稍后重试';
            } elseif ($msg === 'invalid_password') {
                $errorMsg = '密码需6-32位字符';
            } elseif ($msg === 'not_logged_in') {
                $errorMsg = '请先登录';
            } else {
                $errorMsg = '操作失败，请重试';
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
    <title><?= get_config('page_title') ?> — 修改密码</title>
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
        .change-wrapper {
            max-width: 460px;
            margin: 50px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .change-header {
            background: var(--brand-blue);
            color: #fff;
            padding: 24px 20px;
            text-align: center;
        }
        .change-header h2 { margin: 0; font-size: 20px; font-weight: 600; }
        .change-body { padding: 30px 24px; }
        .account-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 16px;
            margin: 16px 0;
            text-align: center;
        }
        .account-info .label { color: #888; font-size: 13px; }
        .account-info .value { font-weight: 700; color: #333; font-size: 16px; font-family: 'Courier New', monospace; }
        .form-group label { font-size: 14px; color: #555; font-weight: 600; }
        .form-control { border-radius: 8px; padding: 10px 14px; }
        .btn-change {
            background: var(--brand-blue);
            color: #fff;
            border: none;
            padding: 12px;
            font-size: 15px;
            border-radius: 8px;
        }
        .btn-change:hover { background: var(--brand-blue-hover); color: #fff; }
        .success-icon { font-size: 48px; color: var(--brand-blue); }
    </style>
</head>
<body>
<div class="change-wrapper">
    <div class="change-header">
        <h2><i class="fas fa-key"></i> 修改密码</h2>
    </div>
    <div class="change-body">

        <?php if ($showResult): ?>
            <div style="text-align: center;">
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h4 style="color: var(--brand-blue); margin: 15px 0;">
                    <?= htmlspecialchars($successMsg) ?>
                </h4>
                <p style="color: #888; font-size: 14px; margin-bottom: 20px;">
                    请使用新密码登录游戏客户端。
                </p>
                <a href="<?= get_config('baseurl') ?>" class="btn btn-change" style="display: inline-block; padding: 10px 40px;">
                    <i class="fas fa-home"></i> 返回首页
                </a>
            </div>
        <?php else: ?>
            <div class="account-info">
                <span class="label">游戏账号</span><br>
                <span class="value"><?= htmlspecialchars($mbUser['username'] ?? '') ?></span>
            </div>

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger" style="font-size: 14px;">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($errorMsg) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="confirm_change" value="1">
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
                <button type="submit" class="btn btn-change btn-block">
                    <i class="fas fa-check"></i> 确认修改
                </button>
            </form>

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
