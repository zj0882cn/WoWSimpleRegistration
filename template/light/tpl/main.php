<?php
/**
 * Main Template — WeChat-Only Mode (Light Theme)
 *
 * Homepage shows:
 *   1. Server status (online players, uptime, etc.) via SOAP
 *   2. WeChat login button (or account info if logged in)
 *   3. Connection guide & contact tabs
 *
 * @author Amin Mahmoudi (MasterkinG)
 **/
require_once 'header.php';

$wxLoggedIn = WeChatAuth::isLoggedIn();
$wxUser     = WeChatAuth::getCurrentUser();

// Get server status via SOAP
$serverStatus = WeChatAuth::getServerStatus();
$serverOnline = $serverStatus !== false;
?>

<div class="row">
    <div class="main-box">
        <img src="<?= $antiXss->xss_clean(get_config('baseurl')) ?>/template/<?= $antiXss->xss_clean(get_config('template')) ?>/images/wow-logo.png"
             onerror="this.style.display='none'">

        <div class="col-xs-12" style="margin-top: 20px;">
            <!-- Status messages -->
            <?php if (!empty($wechatLoginMsg)): ?>
                <div class="alert-wechat">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($wechatLoginMsg) ?>
                </div>
            <?php endif; ?>

            <?php if ($resetResult && $resetResult['success']): ?>
                <div class="alert-wechat">
                    <i class="fas fa-check-circle"></i>
                    <?= lang('password_reset_success') ?: '密码重置成功！' ?>
                    <div class="account-info-card" style="margin-top: 15px;">
                        <span class="label"><?= lang('account') ?: '账号' ?>：</span>
                        <span class="value"><?= htmlspecialchars($resetResult['username']) ?></span><br>
                        <span class="label"><?= lang('new_password') ?: '新密码' ?>：</span>
                        <span class="value"><?= htmlspecialchars($resetResult['password']) ?></span>
                        <div class="password-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?= lang('save_password_warning') ?: '请妥善保存新密码！登录游戏时需要使用。' ?>
                        </div>
                    </div>
                </div>
            <?php elseif ($resetResult && !$resetResult['success']): ?>
                <div class="alert-wechat-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= lang('password_reset_failed') ?: '密码重置失败：' ?> <?= htmlspecialchars($resetResult['message']) ?>
                </div>
            <?php endif; ?>

            <!-- ===== Server Status Card ===== -->
            <div class="server-status-card">
                <div class="server-status-header">
                    <h4><i class="fas fa-server"></i> <?= lang('server_status') ?: '服务器状态' ?></h4>
                    <span class="status-badge <?= $serverOnline ? 'status-online' : 'status-offline' ?>">
                        <?= $serverOnline
                            ? '<i class="fas fa-circle"></i> ' . (lang('online') ?: '在线')
                            : '<i class="fas fa-circle"></i> ' . (lang('offline') ?: '离线') ?>
                    </span>
                </div>

                <?php if ($serverOnline): ?>
                <div class="server-status-grid">
                    <div class="status-item">
                        <div class="status-icon"><i class="fas fa-users"></i></div>
                        <div class="status-value"><?= $serverStatus['online_players'] ?></div>
                        <div class="status-label"><?= lang('online_players') ?: '在线玩家' ?></div>
                    </div>
                    <div class="status-item">
                        <div class="status-icon"><i class="fas fa-user-friends"></i></div>
                        <div class="status-value"><?= $serverStatus['characters'] ?></div>
                        <div class="status-label"><?= lang('characters_in_world') ?: '世界角色' ?></div>
                    </div>
                    <div class="status-item">
                        <div class="status-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="status-value"><?= $serverStatus['peak'] ?></div>
                        <div class="status-label"><?= lang('peak_players') ?: '最高在线' ?></div>
                    </div>
                    <div class="status-item">
                        <div class="status-icon"><i class="fas fa-clock"></i></div>
                        <div class="status-value" style="font-size: 14px; padding-top: 8px;"><?= htmlspecialchars($serverStatus['uptime']) ?></div>
                        <div class="status-label"><?= lang('uptime') ?: '运行时间' ?></div>
                    </div>
                </div>
                <?php else: ?>
                <div style="padding: 20px; text-align: center; color: #999;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 32px;"></i>
                    <p style="margin-top: 10px;"><?= lang('server_offline_msg') ?: '无法连接到游戏服务器' ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Navigation tabs -->
            <nav style="margin-top: 20px;">
                <div class="nav nav-tabs nav-fill" id="nav-tab" role="tablist">
                    <a class="nav-item nav-link active" id="nav-login-tab" data-toggle="tab"
                       href="#nav-login" role="tab" aria-selected="true">
                        <i class="fas fa-sign-in-alt"></i>
                        <?= lang('login') ?: '登录' ?>
                    </a>
                    <a class="nav-item nav-link" id="nav-howtoconnect-tab" data-toggle="tab"
                       href="#nav-howtoconnect" role="tab" aria-selected="false">
                        <i class="fas fa-plug"></i>
                        <?= lang('how_to_connect') ?: '连接指南' ?>
                    </a>
                    <a class="nav-item nav-link" id="nav-contact-tab" data-toggle="tab"
                       href="#nav-contact" role="tab" aria-selected="false">
                        <i class="fas fa-envelope"></i>
                        <?= lang('contact') ?: '联系我们' ?>
                    </a>
                    <?php if (!empty(get_config('supported_langs'))): ?>
                    <a class="nav-item nav-link" data-toggle="modal" data-target="#lang-modal"
                       role="tab" aria-selected="false">
                        <i class="fas fa-language"></i>
                        <?= lang('change_lang_head') ?: '语言' ?>
                    </a>
                    <?php endif; ?>
                </div>
            </nav>

            <div class="tab-content py-3 px-3 px-sm-0" id="nav-tabContent">

                <!-- ===== Login / Account Tab ===== -->
                <div class="tab-pane fade show active" id="nav-login" role="tabpanel"
                     aria-labelledby="nav-login-tab">

                    <?php if ($wxLoggedIn && $wxUser): ?>
                        <!-- ===== Logged In: Show Account Info ===== -->
                        <div class="wechat-login-section" style="padding: 20px;">
                            <h3>
                                <i class="fas fa-check-circle" style="color: var(--wechat-green);"></i>
                                <?= lang('welcome_back') ?: '欢迎回来' ?>
                            </h3>
                            <div class="account-info-card">
                                <div class="row">
                                    <div class="col-md-6">
                                        <span class="label d-block"><?= lang('account') ?: '游戏账号' ?></span>
                                        <span class="value"><?= htmlspecialchars($wxUser['username'] ?? '') ?></span>
                                    </div>
                                    <div class="col-md-6 text-md-right">
                                        <a href="<?= get_config('baseurl') ?>?wechat_logout=1"
                                           class="btn btn-outline-secondary btn-sm" style="margin-top: 15px;">
                                            <i class="fas fa-sign-out-alt"></i>
                                            <?= lang('logout') ?: '退出登录' ?>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <div class="text-center" style="margin-top: 20px;">
                                <a href="<?= get_config('baseurl') ?>/wechat_reset_password.php"
                                   class="btn btn-warning" style="padding: 10px 30px;">
                                    <i class="fas fa-key"></i>
                                    <?= lang('reset_password') ?: '重置密码' ?>
                                </a>
                            </div>

                            <div class="soap-notice">
                                <i class="fas fa-info-circle"></i>
                                <?= lang('reset_password_hint') ?: '重置密码将生成一个新的随机密码，原密码将失效。' ?>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- ===== Not Logged In: Show QR Code for WeChat Login ===== -->
                        <div class="wechat-login-section">
                            <?php
                            $loginMode = WeChatAuth::getLoginMode();
                            $authUrl = WeChatAuth::getAuthorizeUrl();
                            $baseUrl = get_config('baseurl');
                            // QR code API for the site URL
                            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($authUrl ?: $baseUrl);
                            ?>

                            <?php if ($loginMode === 'direct'): ?>
                                <!-- Inside WeChat browser: auto-redirect to OAuth -->
                                <script>
                                window.location.href = <?= json_encode($authUrl) ?>;
                                </script>
                                <p><?= lang('wechat_redirecting') ?: '正在跳转微信登录...' ?></p>

                            <?php elseif ($loginMode === 'open_in_wechat'): ?>
                                <!-- Mobile but not in WeChat browser: show QR code -->
                                <h3>
                                    <i class="fab fa-weixin" style="color: var(--wechat-green);"></i>
                                    <?= lang('wechat_login_title') ?: '请在微信中打开' ?>
                                </h3>
                                <p>
                                    <?= lang('wechat_open_in_wechat_hint') ?: '请使用微信扫描下方二维码，在微信中打开本页面完成登录。' ?>
                                </p>
                                <div style="text-align: center; padding: 20px 0;">
                                    <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Code"
                                         style="width: 200px; height: 200px; border-radius: 12px; border: 2px solid var(--wechat-green);"
                                         onerror="this.style.display='none'">
                                    <p style="font-size: 13px; color: #999; margin-top: 12px;">
                                        <?= lang('wechat_open_url') ?: '或在微信中访问：' ?><br>
                                        <code style="word-break: break-all; font-size: 11px;"><?= htmlspecialchars($baseUrl) ?></code>
                                    </p>
                                </div>

                            <?php else: ?>
                                <!-- PC browser: show QR code for WeChat scan -->
                                <h3>
                                    <i class="fab fa-weixin" style="color: var(--wechat-green);"></i>
                                    <?= lang('wechat_login_title') ?: '微信扫码登录' ?>
                                </h3>
                                <p>
                                    <?= lang('wechat_login_hint') ?: '请使用微信扫描下方二维码登录，注册后将自动创建游戏账号。' ?>
                                </p>

                                <?php if (get_config('wechat_enabled') && $authUrl): ?>
                                    <div style="text-align: center; padding: 20px 0;">
                                        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="微信登录二维码"
                                             style="width: 200px; height: 200px; border-radius: 12px; border: 2px solid var(--wechat-green); box-shadow: 0 2px 12px rgba(0,0,0,0.1);"
                                             onerror="this.style.display='none'">
                                        <p style="font-size: 13px; color: #999; margin-top: 12px;">
                                            <i class="fab fa-weixin"></i>
                                            <?= lang('wechat_scan_qr_hint') ?: '打开微信扫一扫，即可登录游戏' ?>
                                        </p>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning">
                                        <?= lang('wechat_not_enabled') ?: '微信登录功能未启用，请在配置文件中设置 wechat_enabled = true' ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <div class="soap-notice" style="margin-top: 20px;">
                                <i class="fas fa-shield-alt"></i>
                                <?= lang('security_notice') ?: '本站仅支持微信登录，不支持密码注册。账号通过 SOAP 安全创建。' ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ===== How to Connect Tab ===== -->
                <div class="tab-pane fade" id="nav-howtoconnect" role="tabpanel"
                     aria-labelledby="nav-howtoconnect-tab">
                    <div class="content_box1">
                        <h5><?= lang('how_to_connect') ?: '连接指南' ?></h5>
                        <hr>
                        <p><strong><?= lang('realmlist') ?: 'Realmlist' ?>:</strong>
                            <code><?= htmlspecialchars(get_config('realmlist')) ?></code></p>
                        <p><strong><?= lang('game_version') ?: '游戏版本' ?>:</strong>
                            <?= htmlspecialchars(get_config('game_version')) ?></p>
                        <?php if (!empty(get_config('patch_location'))): ?>
                        <p><strong><?= lang('patch') ?: '补丁' ?>:</strong>
                            <a href="<?= htmlspecialchars(get_config('patch_location')) ?>">Download</a></p>
                        <?php endif; ?>
                        <hr>
                        <ol>
                            <li><?= lang('howto_step1') ?: '修改 realmlist.wtf 文件，将内容设为：' ?>
                                <br><code>set realmlist <?= htmlspecialchars(get_config('realmlist')) ?></code></li>
                            <li><?= lang('howto_step2') ?: '使用微信扫码登录本站，系统将自动创建游戏账号。' ?></li>
                            <li><?= lang('howto_step3') ?: '使用生成的账号和密码登录游戏。' ?></li>
                        </ol>
                    </div>
                </div>

                <!-- ===== Contact Tab ===== -->
                <div class="tab-pane fade" id="nav-contact" role="tabpanel"
                     aria-labelledby="nav-contact-tab">
                    <div class="content_box1">
                        <h5><?= lang('contact') ?: '联系我们' ?></h5>
                        <hr>
                        <p><?= lang('contact_text') ?: '如有问题，请通过以下方式联系我们：' ?></p>
                        <p><i class="fab fa-weixin"></i> <?= lang('contact_wechat') ?: '微信公众号' ?>: WoWServer</p>
                        <p><i class="fab fa-qq"></i> QQ群: 123456789</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Language Modal -->
<?php if (!empty(get_config('supported_langs'))): ?>
<div class="modal" id="lang-modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title"><?= lang('change_lang_head') ?: '选择语言' ?></h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form action="" method="post">
                    <input type="hidden" name="langchangever" value="1">
                    <div class="form-group">
                        <select name="langchange" class="form-control">
                            <?php foreach (get_config('supported_langs') as $code => $name): ?>
                                <option value="<?= htmlspecialchars($code) ?>"
                                    <?= ($_COOKIE['website_lang'] ?? '') === $code ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-wechat btn-block">
                        <?= lang('save') ?: '保存' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
