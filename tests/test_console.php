<?php
/**
 * Web Test Console — Interactive Testing Dashboard
 *
 * This page lets you test all features of the WeChat-Only registration
 * system from a browser, without needing a real WeChat account or
 * a real game server.
 *
 * Features:
 *   - View mock account database
 *   - Simulate WeChat login (bypass OAuth)
 *   - Test account creation via SOAP
 *   - Test password reset via SOAP
 *   - Test binding lookup
 *   - Clear all test data
 *
 * Access: http://localhost:8080/tests/test_console.php
 *
 * @author AzerothCore Community
 **/

session_start();
require_once __DIR__ . '/../application/config/config.php';
require_once __DIR__ . '/../application/include/core_handler.php';
require_once __DIR__ . '/../application/include/functions.php';
require_once __DIR__ . '/../application/include/wechat.php';

// Load language
$langFile = __DIR__ . '/../application/language/' . get_config('language') . '.php';
$language = file_exists($langFile) ? (include $langFile ?: []) : [];

// ---- Handle actions ----
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$messageType = ''; // 'success' or 'error'

// Override WeChatAuth to use test data file
$testDataFile = __DIR__ . '/test_wechat_bindings.json';
$ref = new ReflectionClass('WeChatAuth');
$prop = $ref->getProperty('dataFile');
$prop->setAccessible(true);
$prop->setValue(null, $testDataFile);

// Also set appId etc. so init() works
$prop2 = $ref->getProperty('appId'); $prop2->setAccessible(true); $prop2->setValue(null, 'test_appid');
$prop3 = $ref->getProperty('appSecret'); $prop3->setAccessible(true); $prop3->setValue(null, 'test_secret');
$prop4 = $ref->getProperty('redirectUri'); $prop4->setAccessible(true); $prop4->setValue(null, 'http://localhost:8080/wechat_callback.php');

if ($action) {
    switch ($action) {

        // ---- Simulate WeChat Login (skip OAuth, go straight to binding) ----
        case 'simulate_login':
            $testOpenid = 'test_openid_' . rand(1000, 9999);
            $_SESSION['wechat_user'] = [
                'openid'     => $testOpenid,
                'unionid'    => 'test_unionid_' . rand(1000, 9999),
                'nickname'   => '测试玩家' . rand(100, 999),
                'headimgurl' => 'https://placehold.co/72x72/07c160/fff?text=WX',
            ];
            $message = "已模拟微信登录，openid={$_SESSION['wechat_user']['openid']}，昵称={$_SESSION['wechat_user']['nickname']}。";
            $messageType = 'success';
            break;

        // ---- Simulate already-bound login ----
        case 'simulate_bound_login':
            // First create an account via SOAP
            $testUser = 'TESTUSER' . rand(100, 999);
            $testPass = 'TestPass' . rand(1000, 9999);
            $createCommand = str_replace(['{USERNAME}', '{PASSWORD}'], [$testUser, $testPass], get_config('soap_ca_command'));
            $r = WeChatAuth::soapCommand($createCommand);

            if ($r['success']) {
                $addonCmd = str_replace(['{USERNAME}', '{EXPANSION}'], [$testUser, get_config('expansion')], get_config('soap_asa_command'));
                WeChatAuth::soapCommand($addonCmd);

                // Save binding
                WeChatAuth::saveBinding('test_bound_openid', $testUser, 'test_unionid', '已绑定玩家', '');

                // Set session as logged in
                $_SESSION['wechat_logged_in'] = true;
                $_SESSION['wechat_username']  = $testUser;

                $message = "已模拟已绑定的微信登录。账号: {$testUser}，密码: {$testPass}";
                $messageType = 'success';
            } else {
                $message = "SOAP 连接失败，请确保模拟服务器正在运行。";
                $messageType = 'error';
            }
            break;

        // ---- Test Account Creation ----
        case 'test_create':
            $r = WeChatAuth::soapCommand('account list');
            if ($r['success']) {
                $message = "SOAP 连接成功！\n服务器响应:\n" . $r['message'];
                $messageType = 'success';
            } else {
                $message = "SOAP 连接失败！请确保 mock_soap_server.php 正在运行。\n错误: " . $r['message'];
                $messageType = 'error';
            }
            break;

        // ---- Test Password Reset ----
        case 'test_reset':
            if (!WeChatAuth::isLoggedIn()) {
                $message = "请先模拟已绑定的微信登录。";
                $messageType = 'error';
            } else {
                $r = WeChatAuth::resetMyPassword();
                if ($r['success']) {
                    $message = "密码重置成功！\n账号: {$r['username']}\n新密码: {$r['password']}";
                    $messageType = 'success';
                } else {
                    $message = "密码重置失败: " . $r['message'];
                    $messageType = 'error';
                }
            }
            break;

        // ---- Logout ----
        case 'logout':
            WeChatAuth::logout();
            unset($_SESSION['wechat_user']);
            $message = "已退出登录。";
            $messageType = 'success';
            break;

        // ---- Clear test data ----
        case 'clear_data':
            @unlink($testDataFile);
            @unlink(__DIR__ . '/mock_accounts.json');
            WeChatAuth::logout();
            unset($_SESSION['wechat_user']);
            $message = "所有测试数据已清除（绑定记录 + 模拟账号数据库）。";
            $messageType = 'success';
            break;

        // ---- Send custom SOAP command ----
        case 'custom_command':
            $cmd = trim($_POST['custom_cmd'] ?? '');
            if ($cmd) {
                $r = WeChatAuth::soapCommand($cmd);
                $message = "命令: {$cmd}\n结果: " . ($r['success'] ? '成功' : '失败') . "\n响应: " . $r['message'];
                $messageType = $r['success'] ? 'success' : 'error';
            }
            break;
    }
}

// ---- Gather status info ----
$wxLoggedIn = WeChatAuth::isLoggedIn();
$wxUser     = WeChatAuth::getCurrentUser();
$wxSession  = $_SESSION['wechat_user'] ?? null;

// Load bindings
$allBindings = [];
$refMethod = $ref->getMethod('loadBindings');
$refMethod->setAccessible(true);
$allBindings = $refMethod->invoke(null);

// Check SOAP server status
$soapOnline = false;
$soapCheck = WeChatAuth::soapCommand('server info');
if ($soapCheck['success']) {
    $soapOnline = true;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>测试控制台 — <?= htmlspecialchars(get_config('page_title')) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <style>
        body {
            background: #f5f7fa;
            font-family: -apple-system, "PingFang SC", "Noto Sans CJK SC", "Microsoft YaHei", sans-serif;
        }
        .console-wrapper {
            max-width: 900px;
            margin: 30px auto;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .card-header {
            background: #07c160;
            color: #fff;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
            padding: 15px 20px;
        }
        .card-header i { margin-right: 8px; }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        .status-online { background: #e8f7ef; color: #07c160; }
        .status-offline { background: #fff3f3; color: #dc3545; }
        .btn-test {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            margin: 5px;
        }
        .console-output {
            background: #1e1e2e;
            color: #a6e3a1;
            border-radius: 8px;
            padding: 16px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 300px;
            overflow-y: auto;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #888; font-size: 13px; }
        .info-value { font-weight: 600; font-size: 14px; }
        table { font-size: 13px; }
        table code { font-size: 12px; }
    </style>
</head>
<body>
<div class="console-wrapper">

    <!-- ===== Header ===== -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-flask"></i> 测试控制台 — 微信注册系统
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="info-row">
                        <span class="info-label">SOAP 服务器</span>
                        <span class="status-badge <?= $soapOnline ? 'status-online' : 'status-offline' ?>">
                            <i class="fas fa-<?= $soapOnline ? 'check-circle' : 'times-circle' ?>"></i>
                            <?= $soapOnline ? '在线' : '离线' ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">SOAP 地址</span>
                        <span class="info-value"><?= get_config('soap_host') ?>:<?= get_config('soap_port') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">微信登录状态</span>
                        <span class="status-badge <?= $wxLoggedIn ? 'status-online' : 'status-offline' ?>">
                            <?= $wxLoggedIn ? '已登录' : '未登录' ?>
                        </span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-row">
                        <span class="info-label">微信会话 (wechat_user)</span>
                        <span class="info-value"><?= $wxSession ? '存在' : '无' ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">当前账号</span>
                        <span class="info-value"><?= $wxUser['username'] ?? '—' ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">绑定记录数</span>
                        <span class="info-value"><?= count($allBindings) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Message ===== -->
    <?php if ($message): ?>
    <div class="card">
        <div class="card-body">
            <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>" style="margin: 0;">
                <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <pre style="margin: 5px 0 0; white-space: pre-wrap; font-family: inherit;"><?= htmlspecialchars($message) ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== Test Actions ===== -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-cogs"></i> 测试操作
        </div>
        <div class="card-body">
            <div class="text-center">
                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="action" value="simulate_login">
                    <button type="submit" class="btn btn-info btn-test">
                        <i class="fab fa-weixin"></i> 模拟微信扫码（未绑定）
                    </button>
                </form>

                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="action" value="simulate_bound_login">
                    <button type="submit" class="btn btn-success btn-test">
                        <i class="fas fa-user-check"></i> 模拟微信扫码（已绑定）
                    </button>
                </form>

                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="action" value="test_create">
                    <button type="submit" class="btn btn-primary btn-test">
                        <i class="fas fa-server"></i> 测试 SOAP 连接
                    </button>
                </form>

                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="action" value="test_reset">
                    <button type="submit" class="btn btn-warning btn-test" <?= !$wxLoggedIn ? 'disabled' : '' ?>>
                        <i class="fas fa-key"></i> 测试密码重置
                    </button>
                </form>

                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="btn btn-secondary btn-test">
                        <i class="fas fa-sign-out-alt"></i> 退出登录
                    </button>
                </form>

                <form method="POST" action="" style="display: inline;">
                    <input type="hidden" name="action" value="clear_data">
                    <button type="submit" class="btn btn-danger btn-test"
                            onclick="return confirm('确定清除所有测试数据？')">
                        <i class="fas fa-trash"></i> 清除测试数据
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== Custom SOAP Command ===== -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-terminal"></i> 自定义 SOAP 命令
        </div>
        <div class="card-body">
            <form method="POST" action="" class="form-inline">
                <input type="hidden" name="action" value="custom_command">
                <input type="text" name="custom_cmd" class="form-control flex-grow-1 mr-2"
                       placeholder="例如: account create TESTUSER pass123"
                       value="<?= htmlspecialchars($_POST['custom_cmd'] ?? '') ?>">
                <button type="submit" class="btn btn-dark">
                    <i class="fas fa-paper-plane"></i> 发送
                </button>
            </form>
            <small class="text-muted mt-2 d-block">
                可用命令: <code>account create {user} {pass}</code> |
                <code>account set addon {user} {exp}</code> |
                <code>account set password {user} {pass} {pass}</code> |
                <code>account delete {user}</code> |
                <code>account list</code> |
                <code>server info</code> |
                <code>help</code>
            </small>
        </div>
    </div>

    <!-- ===== Bindings Table ===== -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-link"></i> 微信绑定记录 (<?= count($allBindings) ?>)
        </div>
        <div class="card-body">
            <?php if (empty($allBindings)): ?>
                <p class="text-muted text-center">暂无绑定记录</p>
            <?php else: ?>
                <table class="table table-sm table-striped">
                    <thead>
                        <tr>
                            <th>OpenID</th>
                            <th>游戏账号</th>
                            <th>昵称</th>
                            <th>绑定时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allBindings as $b): ?>
                        <tr>
                            <td><code><?= htmlspecialchars(substr($b['openid'], 0, 20)) ?>...</code></td>
                            <td><strong><?= htmlspecialchars($b['username']) ?></strong></td>
                            <td><?= htmlspecialchars($b['nickname'] ?? '') ?></td>
                            <td><?= htmlspecialchars($b['bind_time'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== Page Links ===== -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-external-link-alt"></i> 页面导航
        </div>
        <div class="card-body">
            <a href="<?= get_config('baseurl') ?>/" class="btn btn-outline-primary btn-test">
                <i class="fas fa-home"></i> 首页
            </a>
            <a href="<?= get_config('baseurl') ?>/wechat_bind.php" class="btn btn-outline-primary btn-test">
                <i class="fas fa-link"></i> 绑定页面
            </a>
            <a href="<?= get_config('baseurl') ?>/wechat_reset_password.php" class="btn btn-outline-warning btn-test">
                <i class="fas fa-key"></i> 密码重置页
            </a>
            <a href="<?= get_config('baseurl') ?>/wechat_callback.php" class="btn btn-outline-info btn-test">
                <i class="fas fa-reply"></i> 微信回调页
            </a>
        </div>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
