<?php
/**
 * WeChat Bind Template — Light Theme
 *
 * This template is loaded by wechat_bind.php. It renders the page where
 * users choose between auto-creating a game account or binding WeChat
 * to an existing one.
 *
 * Variables expected from the parent script:
 *   $wxUser       — array with openid, nickname, headimgurl
 *   $errorMsg     — string error message (if any)
 *   $successMsg   — string success message (if any)
 *   $showResult   — bool whether to show the success card
 *   $createdAccount — string auto-generated username (if auto-register)
 *   $result       — array from registerAndBind (may contain 'password')
 **/
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= get_config('page_title') ?> — 微信绑定</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        :root {
            --wechat-green: #07c160;
            --wechat-green-hover: #06ad56;
            --bg: #f5f7fa;
        }
        body { background: var(--bg); }
        .bind-wrapper {
            max-width: 460px;
            margin: 50px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .bind-header {
            background: var(--wechat-green);
            color: #fff;
            padding: 24px 20px;
            text-align: center;
        }
        .bind-header h2 { margin: 0; font-size: 20px; font-weight: 600; }
        .bind-body { padding: 30px 24px; }
        .wx-avatar {
            width: 72px; height: 72px;
            border-radius: 50%;
            display: block;
            margin: 0 auto 10px;
            border: 3px solid #e8e8e8;
        }
        .wx-nickname {
            text-align: center;
            font-size: 17px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .wx-hint {
            text-align: center;
            color: #999;
            font-size: 13px;
            margin-bottom: 20px;
        }
        .nav-tabs .nav-link {
            color: #555;
            border: none;
            border-bottom: 2px solid transparent;
            border-radius: 0;
        }
        .nav-tabs .nav-link.active {
            color: var(--wechat-green);
            border-bottom-color: var(--wechat-green);
            background: none;
        }
        .btn-wechat {
            background: var(--wechat-green);
            color: #fff;
            border: none;
            padding: 10px;
            font-size: 15px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .btn-wechat:hover { background: var(--wechat-green-hover); color: #fff; }
        .account-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 18px;
            margin: 16px 0;
            font-family: 'Courier New', monospace;
            font-size: 15px;
            line-height: 1.8;
        }
        .account-card .label { color: #666; }
        .account-card .value { font-weight: 700; color: #333; }
        .password-warning {
            color: #e6a23c;
            font-size: 13px;
            margin-top: 8px;
        }
    </style>
</head>
<body>
<div class="bind-wrapper">
    <div class="bind-header">
        <h2>微信账号绑定</h2>
    </div>
    <div class="bind-body">

        <?php if ($showResult): ?>
            <!-- Success result -->
            <div style="text-align: center;">
                <div style="font-size: 48px; color: #07c160; margin-bottom: 10px;">&#10003;</div>
                <h4 style="color: #07c160; margin-bottom: 20px;"><?= htmlspecialchars($successMsg) ?></h4>
                <?php if ($createdAccount): ?>
                    <div class="account-card">
                        <span class="label">游戏账号：</span>
                        <span class="value"><?= htmlspecialchars($createdAccount) ?></span><br>
                        <?php if (isset($result['password'])): ?>
                        <span class="label">登录密码：</span>
                        <span class="value"><?= htmlspecialchars($result['password']) ?></span>
                        <div class="password-warning">
                            &#9888; 请妥善保存密码！登录游戏时需要使用。
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <a href="<?= get_config('baseurl') ?>" class="btn btn-wechat" style="display: inline-block; padding: 10px 40px;">
                    返回首页
                </a>
            </div>
        <?php else: ?>
            <!-- WeChat user info -->
            <?php if (!empty($wxUser['headimgurl'])): ?>
                <img src="<?= htmlspecialchars($wxUser['headimgurl']) ?>" class="wx-avatar" alt="avatar">
            <?php endif; ?>
            <div class="wx-nickname"><?= htmlspecialchars($wxUser['nickname'] ?? '微信用户') ?></div>
            <div class="wx-hint">您的微信尚未绑定游戏账号</div>

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger" style="font-size: 14px;"><?= htmlspecialchars($errorMsg) ?></div>
            <?php endif; ?>

            <ul class="nav nav-tabs nav-fill mb-3">
                <li class="nav-item">
                    <a class="nav-link active" data-toggle="tab" href="#tab-register">创建新账号</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#tab-bind">绑定已有账号</a>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Auto register -->
                <div class="tab-pane fade show active" id="tab-register">
                    <form method="POST">
                        <input type="hidden" name="action" value="auto_register">
                        <p style="color: #999; font-size: 13px; margin-bottom: 15px;">
                            系统将自动创建游戏账号并绑定到您的微信，无需填写邮箱。
                        </p>
                        <button type="submit" class="btn btn-wechat btn-block">创建并绑定</button>
                    </form>
                </div>

                <!-- Bind existing -->
                <div class="tab-pane fade" id="tab-bind">
                    <form method="POST">
                        <input type="hidden" name="action" value="bind_existing">
                        <p style="color: #999; font-size: 13px; margin-bottom: 15px;">
                            输入您已有的游戏账号密码，将微信绑定到该账号。
                        </p>
                        <div class="form-group">
                            <input type="text" name="bind_username" class="form-control"
                                   placeholder="游戏账号" required>
                        </div>
                        <div class="form-group">
                            <input type="password" name="bind_password" class="form-control"
                                   placeholder="密码" required>
                        </div>
                        <button type="submit" class="btn btn-wechat btn-block">绑定账号</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
