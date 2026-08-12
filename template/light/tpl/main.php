<?php
/**
 * Main Template — One-Click Login Mode (Light Theme)
 *
 * Homepage shows:
 *   1. Server status (online players, uptime, etc.) via SOAP
 *   2. One-click login (mobile) or QR code scan (PC)
 *   3. Connection guide & contact tabs
 *
 * @author Amin Mahmoudi (MasterkinG)
 **/
require_once 'header.php';

$mbLoggedIn = MobileAuth::isLoggedIn();
$mbUser     = MobileAuth::getCurrentUser();

// Check if this is a new registration (show password once)
$newAccount = !empty($_SESSION['mobile_new_account']) ? $_SESSION['mobile_new_account'] : false;
$newPassword = $_SESSION['mobile_new_password'] ?? '';
if ($newAccount) {
    unset($_SESSION['mobile_new_account'], $_SESSION['mobile_new_password']);
}

// Get server status via SOAP
$serverStatus = MobileAuth::getServerStatus();
$serverOnline = $serverStatus !== false;

// Detect mobile device
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = (bool)preg_match('/Android|iPhone|iPad|iPod|Windows Phone|Mobile/i', $userAgent);

// Get one-click login provider config
$oneclickProvider = get_config('numberauth_provider') ?: 'demo';
$oneclickAppKey   = get_config('numberauth_aliyun_appkey') ?: '';
$siteUrl          = get_config('baseurl') ?: '';
?>

<div class="row">
    <div class="main-box">
        <img src="<?= $antiXss->xss_clean(get_config('baseurl')) ?>/template/<?= $antiXss->xss_clean(get_config('template')) ?>/images/wow-logo.png"
             onerror="this.style.display='none'">

        <div class="col-xs-12" style="margin-top: 20px;">
            <!-- Status messages -->
            <?php if (!empty($mobileLoginMsg)): ?>
                <div class="alert-wechat">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($mobileLoginMsg) ?>
                </div>
            <?php endif; ?>

            <!-- New account info (shown once after registration) -->
            <?php if ($newAccount && $mbLoggedIn && !empty($newPassword)): ?>
                <div class="alert-wechat" style="border-color: var(--brand-blue);">
                    <div style="text-align: center;">
                        <i class="fas fa-gift" style="font-size: 28px; color: var(--brand-blue);"></i>
                        <h5 style="margin-top: 10px; color: var(--brand-blue);">
                            <?= lang('account_created') ?: '账号创建成功！' ?>
                        </h5>
                        <p style="margin: 10px 0;">
                            <?= lang('account') ?: '游戏账号' ?>：
                            <code style="font-size: 16px; font-weight: 700;"><?= htmlspecialchars($mbUser['username']) ?></code>
                        </p>
                        <p style="margin: 5px 0;">
                            <?= lang('password') ?: '密码' ?>：
                            <code style="font-size: 16px; font-weight: 700; color: var(--brand-blue);"><?= htmlspecialchars($newPassword) ?></code>
                        </p>
                        <div class="password-warning" style="margin-top: 10px;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?= lang('save_password_warning') ?: '请立即妥善保存密码！此密码仅显示一次。' ?>
                        </div>
                    </div>
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
                        <?php if ($mbLoggedIn): ?>
                            <?= lang('account_management') ?: '账号管理' ?>
                        <?php else: ?>
                            <?= lang('login') ?: '登录' ?>
                        <?php endif; ?>
                    </a>
                    <?php if ($mbLoggedIn): ?>
                    <a class="nav-item nav-link" id="nav-accountinfo-tab" data-toggle="tab"
                       href="#nav-accountinfo" role="tab" aria-selected="false">
                        <i class="fas fa-user-circle"></i>
                        <?= lang('account_info_tab') ?: '账户' ?>
                    </a>
                    <?php endif; ?>
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

                    <?php if ($mbLoggedIn && $mbUser): ?>
                        <!-- ===== Logged In: Account Management ===== -->
                        <div class="mobile-login-section" style="padding: 20px;">
                            <h3>
                                <i class="fas fa-cog" style="color: var(--brand-blue);"></i>
                                <?= lang('account_management') ?: '账号管理' ?>
                            </h3>
                            <div class="account-info-card" style="text-align: center; padding: 20px;">
                                <span class="label d-block"><?= lang('account') ?: '游戏账号' ?></span>
                                <span class="value" style="font-size: 18px;"><?= htmlspecialchars($mbUser['username'] ?? '') ?></span>
                            </div>

                            <div class="text-center" style="margin-top: 20px;">
                                <a href="<?= get_config('baseurl') ?>/change_password.php"
                                   class="btn btn-primary" style="padding: 10px 24px;">
                                    <i class="fas fa-key"></i>
                                    修改密码
                                </a>
                                <a href="<?= get_config('baseurl') ?>/reset_password.php"
                                   class="btn btn-warning" style="padding: 10px 24px;">
                                    <i class="fas fa-redo"></i>
                                    忘记密码
                                </a>
                                <a href="<?= get_config('baseurl') ?>?mobile_logout=1"
                                   class="btn btn-outline-secondary" style="padding: 10px 24px; margin-top: 10px;">
                                    <i class="fas fa-sign-out-alt"></i>
                                    <?= lang('logout') ?: '退出登录' ?>
                                </a>
                            </div>

                            <div class="soap-notice">
                                <i class="fas fa-info-circle"></i>
                                修改密码：需输入旧密码验证后修改。忘记密码：需手机短信验证后重置。
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- ===== Not Logged In: One-Click Login ===== -->
                        <div class="mobile-login-section">

                        <?php if ($isMobile): ?>
                            <!-- ===== Mobile: One-Click Login ===== -->
                            <h3>
                                <i class="fas fa-bolt" style="color: var(--brand-blue);"></i>
                                <?= lang('oneclick_login_title') ?: '本机号码一键登录' ?>
                            </h3>
                            <p>
                                <?= lang('oneclick_login_hint') ?: '自动识别本机手机号，无需输入手机号和验证码，一键完成注册/登录。' ?>
                            </p>

                            <!-- One-click login button -->
                            <div class="oneclick-section">
                                <button type="button" id="oneclickBtn" class="oneclick-btn">
                                    <i class="fas fa-shield-alt"></i>
                                    <?= lang('oneclick_login_btn') ?: '一键登录' ?>
                                </button>
                            </div>

                            <?php if ($oneclickProvider === 'demo'): ?>
                            <!-- Demo mode: phone + username + password input -->
                            <div class="oneclick-demo-input" style="max-width: 360px; margin: 0 auto;">
                                <div class="input-group" style="margin-bottom: 10px;">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    </div>
                                    <input type="tel" id="demoPhone" class="form-control"
                                           placeholder="<?= lang('oneclick_demo_hint') ?: '手机号（自动验证）' ?>"
                                           maxlength="11" pattern="1[3-9]\d{9}">
                                </div>
                                <div class="input-group" style="margin-bottom: 10px;">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    </div>
                                    <input type="text" id="demoUsername" class="form-control"
                                           placeholder="自定义游戏账号（3-16位字母数字）"
                                           minlength="3" maxlength="16" pattern="[A-Za-z0-9]{3,16}">
                                </div>
                                <div class="input-group" style="margin-bottom: 10px;">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    </div>
                                    <input type="password" id="demoPassword" class="form-control"
                                           placeholder="<?= lang('password_hint') ?: '设置游戏密码（6-32位）' ?>"
                                           minlength="6" maxlength="32">
                                </div>
                                <button type="button" id="demoLoginBtn" class="btn btn-outline-primary btn-block">
                                    <i class="fas fa-sign-in-alt"></i>
                                    <?= lang('register_btn') ?: '注册 / 登录' ?>
                                </button>
                            </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <!-- ===== PC: Show QR code to scan with phone ===== -->
                            <h3>
                                <i class="fas fa-qrcode" style="color: var(--brand-blue);"></i>
                                <?= lang('oneclick_login_title') ?: '本机号码一键登录' ?>
                            </h3>
                            <p>
                                <?= lang('oneclick_login_hint') ?: '自动识别本机手机号，无需输入手机号和验证码，一键完成注册/登录。' ?>
                            </p>

                            <div class="oneclick-section" style="padding: 30px 0;">
                                <div id="qrcode" style="display: inline-block; padding: 16px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"></div>
                                <p style="margin-top: 16px; font-size: 14px; color: var(--text-muted);">
                                    <i class="fas fa-mobile-alt"></i>
                                    <?= lang('qr_scan_hint') ?: '请用手机扫描二维码，在手机上完成一键登录' ?>
                                </p>
                            </div>

                        <?php endif; ?>

                            <!-- Error message display -->
                            <div id="oneclickError" class="alert alert-danger" style="display: none; margin-top: 15px; font-size: 14px;">
                            </div>

                            <div class="soap-notice" style="margin-top: 20px;">
                                <i class="fas fa-shield-alt"></i>
                                <?= lang('security_notice') ?: '新用户注册需手机验证，老用户可使用账号密码登录。账号通过 SOAP 安全创建。' ?>
                            </div>

                            <!-- ===== Password Login (for returning users) ===== -->
                            <hr style="margin: 30px 0; border-color: var(--border);">
                            <h5 style="text-align: center; color: var(--text-muted);">
                                <?= lang('password_login_title') ?: '已有账号？密码登录' ?>
                            </h5>
                            <div class="oneclick-demo-input" style="max-width: 360px; margin: 15px auto;">
                                <div class="input-group" style="margin-bottom: 10px;">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    </div>
                                    <input type="text" id="pwdUsername" class="form-control"
                                           placeholder="<?= lang('account_placeholder') ?: '游戏账号' ?>"
                                           maxlength="16">
                                </div>
                                <div class="input-group" style="margin-bottom: 10px;">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-key"></i></span>
                                    </div>
                                    <input type="password" id="pwdPassword" class="form-control"
                                           placeholder="<?= lang('password_placeholder') ?: '密码' ?>"
                                           maxlength="32">
                                </div>
                                <button type="button" id="pwdLoginBtn" class="btn btn-primary btn-block">
                                    <i class="fas fa-sign-in-alt"></i>
                                    <?= lang('login_btn') ?: '登录' ?>
                                </button>
                                <div class="text-center" style="margin-top: 10px;">
                                    <a href="<?= get_config('baseurl') ?>/reset_password.php"
                                       style="font-size: 13px; color: var(--brand-blue);">
                                        <i class="fas fa-redo"></i>
                                        <?= lang('forgot_password') ?: '忘记密码？' ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($mbLoggedIn && $mbUser): ?>
                <!-- ===== Account Info Tab ===== -->
                <div class="tab-pane fade" id="nav-accountinfo" role="tabpanel"
                     aria-labelledby="nav-accountinfo-tab">
                    <div class="content_box1">
                        <h5><i class="fas fa-user-circle"></i> <?= lang('account_details') ?: '账户详情' ?></h5>
                        <hr>

                        <?php
                        // Get character list (includes account ID) from SOAP
                        $charList = MobileAuth::getAccountCharacters($mbUser['username']);
                        $soapOk = $charList !== false;
                        $charCount = ($charList && !empty($charList['characters'])) ? count($charList['characters']) : 0;
                        ?>

                        <!-- ===== Game Account Section ===== -->
                        <div class="account-info-card" style="padding: 20px; margin-bottom: 20px;">
                            <h6 style="color: var(--brand-blue); margin-bottom: 15px;">
                                <i class="fas fa-id-card"></i> <?= lang('account') ?: '游戏账号' ?>
                            </h6>

                            <div class="row" style="margin-bottom: 12px; align-items: center;">
                                <div class="col-5">
                                    <span class="label"><i class="fas fa-user"></i> <?= lang('account') ?: '账号' ?></span>
                                </div>
                                <div class="col-7">
                                    <span class="value" style="font-family: 'Courier New', monospace; font-weight: 700; font-size: 16px;">
                                        <?= htmlspecialchars($mbUser['username'] ?? '') ?>
                                    </span>
                                </div>
                            </div>

                            <?php if ($soapOk && !empty($charList['account_id'])): ?>
                            <div class="row" style="margin-bottom: 12px;">
                                <div class="col-5">
                                    <span class="label"><i class="fas fa-hashtag"></i> <?= lang('account_id') ?: '账号ID' ?></span>
                                </div>
                                <div class="col-7">
                                    <span class="value" style="font-size: 14px;">
                                        <?= (int)$charList['account_id'] ?>
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="row" style="margin-bottom: 12px;">
                                <div class="col-5">
                                    <span class="label"><i class="fas fa-phone"></i> <?= lang('bound_phone') ?: '绑定手机' ?></span>
                                </div>
                                <div class="col-7">
                                    <span class="value" style="font-size: 14px;">
                                        <?= htmlspecialchars(MobileAuth::maskPhone($mbUser['phone'] ?? '')) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="row" style="margin-bottom: 0;">
                                <div class="col-5">
                                    <span class="label"><i class="fas fa-users"></i> <?= lang('char_count') ?: '角色数量' ?></span>
                                </div>
                                <div class="col-7">
                                    <span class="value" style="font-size: 14px;">
                                        <?php if ($soapOk): ?>
                                            <?= $charCount ?>
                                        <?php else: ?>
                                            <span style="color: #999;">--</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- ===== Character List Section ===== -->
                        <h6 style="color: var(--brand-blue); margin-bottom: 15px;">
                            <i class="fas fa-users"></i> <?= lang('character_list') ?: '角色列表' ?>
                            <?php if ($soapOk && $charCount > 0): ?>
                                <span style="font-size: 13px; color: #999;">(<?= $charCount ?>)</span>
                            <?php endif; ?>
                        </h6>

                        <?php if ($soapOk && $charCount > 0): ?>
                            <div style="overflow-x: auto;">
                            <table class="table table-sm" style="font-size: 14px; margin-bottom: 0;">
                                <thead style="background: #f0f4f8;">
                                    <tr>
                                        <th><?= lang('char_name') ?: '角色名' ?></th>
                                        <th><?= lang('char_race') ?: '种族' ?></th>
                                        <th><?= lang('char_class') ?: '职业' ?></th>
                                        <th class="text-center"><?= lang('char_level') ?: '等级' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($charList['characters'] as $char): ?>
                                    <tr>
                                        <td style="font-weight: 600;"><?= htmlspecialchars($char['name']) ?></td>
                                        <td><?= htmlspecialchars($char['race']) ?></td>
                                        <td><?= htmlspecialchars($char['class']) ?></td>
                                        <td class="text-center">
                                            <span style="background: var(--brand-blue); color: #fff; padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 700;">
                                                <?= (int)$char['level'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php elseif ($soapOk): ?>
                            <div style="text-align: center; padding: 30px; color: #999;">
                                <i class="fas fa-user-plus" style="font-size: 32px;"></i>
                                <p style="margin-top: 10px; font-size: 13px;">
                                    <?= lang('no_characters') ?: '暂无角色，请先登录游戏创建角色' ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: #999; font-size: 13px;">
                                <i class="fas fa-exclamation-triangle"></i>
                                <?= lang('server_offline_msg') ?: '无法获取角色信息，服务器可能离线' ?>
                            </div>
                        <?php endif; ?>

                        <div class="soap-notice" style="margin-top: 15px;">
                            <i class="fas fa-shield-alt"></i>
                            <?= lang('security_notice') ?: '账号信息通过 SOAP 安全获取，请妥善保管你的账号密码。' ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ===== How to Connect Tab ===== -->
                <div class="tab-pane fade" id="nav-howtoconnect" role="tabpanel"
                     aria-labelledby="nav-howtoconnect-tab">
                    <div class="content_box1">
                        <h5><?= lang('how_to_connect') ?: '连接指南' ?></h5>
                        <hr>
                        <p><strong><?= lang('realmlist') ?: 'Realmlist' ?>:</strong>
                            <code><?= htmlspecialchars(get_config('realmlist')) ?></code></p>
                        <p><strong><?= lang('game_version') ?: '游戏版本' ?>:</strong>
                            <?= htmlspecialchars(get_config('game_version')) ?>
                            <?php if (!empty(get_config('client_download_url'))): ?>
                            &nbsp;&nbsp;<a href="<?= htmlspecialchars(get_config('client_download_url')) ?>" target="_blank"
                               style="font-size: 13px; color: var(--brand-blue);">
                                <i class="fas fa-download"></i>
                                <?= lang('client_download') ?: '客户端下载' ?>
                            </a>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty(get_config('client_download_baidu'))): ?>
                        <p style="font-size: 13px; color: #888; margin-left: 20px;">
                            <i class="fas fa-cloud-download-alt"></i>
                            <?= lang('baidu_pan') ?: '百度网盘' ?>:
                            <a href="<?= htmlspecialchars(get_config('client_download_baidu_url')) ?>" target="_blank"
                               style="color: var(--brand-blue);">
                                <?= lang('click_download') ?: '点击下载' ?>
                            </a>
                            <?php if (!empty(get_config('client_download_baidu_code'))): ?>
                            &nbsp;(<?= lang('extract_code') ?: '提取码' ?>:
                            <code><?= htmlspecialchars(get_config('client_download_baidu_code')) ?></code>)
                            <?php endif; ?>
                        </p>
                        <?php endif; ?>
                        <?php if (!empty(get_config('patch_location'))): ?>
                        <p><strong><?= lang('patch') ?: '补丁' ?>:</strong>
                            <a href="<?= htmlspecialchars(get_config('patch_location')) ?>">Download</a></p>
                        <?php endif; ?>
                        <hr>
                        <ol>
                            <li><?= lang('howto_step1') ?: '修改 realmlist.wtf 文件，将内容设为：' ?>
                                <br><code>set realmlist <?= htmlspecialchars(get_config('realmlist')) ?></code></li>
                            <li><?= lang('howto_step2_oneclick') ?: '在手机上打开本站，点击一键登录，系统将自动识别本机号码并创建游戏账号。' ?></li>
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
                    <button type="submit" class="btn btn-primary btn-block">
                        <?= lang('save') ?: '保存' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

<?php if (!$isMobile): ?>
<!-- QR Code library for PC -->
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<?php endif; ?>

<?php if ($isMobile && $oneclickProvider === 'aliyun' && !empty($oneclickAppKey)): ?>
<!-- Aliyun NumberAuth H5 SDK -->
<script src="https://g.alicdn.com/AliyunNumberAuthSDK/aliyun-numberauth-sdk.min.js"></script>
<?php endif; ?>

<script>
// 全局错误捕获，防止任何 JS 错误影响登录功能
window.onerror = function(msg, url, line, col, error) {
    console.error('Global JS Error:', msg, 'at line', line);
    return true; // 阻止默认错误处理
};

$(function() {
    var siteUrl = window.location.origin;
    var provider = '<?= addslashes($oneclickProvider) ?>';
    var isMobile = <?= $isMobile ? 'true' : 'false' ?>;

    // --- PC: Generate QR Code ---
    var qrcodeEl = document.getElementById('qrcode');
    if (!isMobile && typeof QRCode !== 'undefined' && qrcodeEl) {
        try {
            new QRCode(qrcodeEl, {
                text: siteUrl,
                width: 200,
                height: 200,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        } catch (e) {
            console.warn('QR code generation failed:', e);
        }
    }

    // --- Error display helper ---
    function showError(msg) {
        var el = $('#oneclickError');
        // 如果 msg 为空、null 或 undefined，隐藏错误
        if (!msg) {
            el.hide();
            return;
        }
        var friendlyMsgs = {
            'soap_create_failed': '游戏服务器连接失败，请稍后重试',
            'soap_error': '游戏服务器连接失败，请稍后重试',
            'mobile_auth_disabled': '登录功能未启用',
            'invalid_phone': '手机号格式不正确',
            'invalid_password': '密码需6-32位字符',
            'invalid_username': '账号需3-16位字母或数字',
            'username_taken': '该账号已被使用，请换一个',
            'phone_required': '请输入手机号',
            'password_required': '请输入密码',
            'username_required': '请输入游戏账号',
            'account_not_found': '账号不存在，请检查或先注册',
            'wrong_password': '密码错误，请重新输入',
            'token_required': '认证令牌缺失，请重试',
            'numberauth_config_incomplete': '号码认证配置不完整，请联系管理员',
            'numberauth_request_failed': '号码认证请求失败，请重试',
            'numberauth_failed': '号码认证失败，请重试'
        };
        var displayMsg = friendlyMsgs[msg] || msg;
        el.html('<i class="fas fa-exclamation-circle"></i> ' + displayMsg).show();
    }

    // --- Redirect after successful login ---
    function handleLoginSuccess(resp) {
        console.log('Login response:', resp);
        if (resp && resp.success) {
            showError(''); // 清除任何可能残留的错误
            if (resp.redirect) {
                window.location.href = resp.redirect;
            } else {
                window.location.reload();
            }
        } else {
            var errMsg = (resp && resp.message) ? resp.message : '<?= lang("oneclick_failed") ?: "一键登录失败，请重试" ?>';
            showError(errMsg);
        }
    }

    // --- Send token/phone/username/password to backend ---
    function submitOneClick(token, phone, username, password) {
        var data = {};
        if (token) data.token = token;
        if (phone) data.phone = phone;
        if (username) data.username = username;
        if (password) data.password = password;

        $.ajax({
            url: siteUrl + '/oneclick_verify.php',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: handleLoginSuccess,
            error: function(xhr, status, err) {
                console.error('AJAX error:', {status: status, error: err, responseText: xhr.responseText});
                showError('网络错误: ' + (err || '未知错误'));
            }
        });
    }

    // --- Password login ---
    function submitPasswordLogin(username, password, btn) {
        $.ajax({
            url: siteUrl + '/password_login.php',
            type: 'POST',
            dataType: 'json',
            data: { username: username, password: password },
            success: function(resp) {
                if (resp && resp.success) {
                    // 成功：不恢复按钮，直接跳转
                    handleLoginSuccess(resp);
                } else {
                    // 失败：恢复按钮并显示错误
                    btn.prop('disabled', false);
                    btn.html('<i class="fas fa-sign-in-alt"></i> 登录');
                    handleLoginSuccess(resp);
                }
            },
            error: function(xhr, status, err) {
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-sign-in-alt"></i> 登录');
                console.error('AJAX error:', {status: status, error: err, responseText: xhr.responseText});
                showError('网络错误: ' + (err || '未知错误'));
            }
        });
    }

    <?php if ($isMobile): ?>
    // --- Mobile: One-Click Login ---

    <?php if ($oneclickProvider === 'aliyun' && !empty($oneclickAppKey)): ?>
    // Production mode: Aliyun NumberAuth H5 SDK
    var authSDK = null;
    var oneClickBusy = false;

    function initAliyunAuth() {
        if (typeof AliyunNumberAuth === 'undefined') {
            showError('号码认证SDK加载失败，请刷新重试');
            return false;
        }
        authSDK = new AliyunNumberAuth({
            appKey: '<?= addslashes($oneclickAppKey) ?>',
            timeout: 8000
        });
        return true;
    }

    $('#oneclickBtn').on('click', function() {
        if (oneClickBusy) return;
        oneClickBusy = true;

        var btn = $(this);
        btn.prop('disabled', true);
        btn.html('<span class="spinner"></span> <?= lang("oneclick_verifying") ?: "正在验证本机号码..." ?>');
        showError('');

        if (!initAliyunAuth()) {
            oneClickBusy = false;
            btn.prop('disabled', false);
            btn.html('<i class="fas fa-shield-alt"></i> <?= lang("oneclick_login_btn") ?: "一键登录" ?>');
            return;
        }

        authSDK.getToken().then(function(token) {
            submitOneClick(token, null);
        }).catch(function(err) {
            oneClickBusy = false;
            btn.prop('disabled', false);
            btn.html('<i class="fas fa-shield-alt"></i> <?= lang("oneclick_login_btn") ?: "一键登录" ?>');

            var errMsg = '<?= lang("oneclick_failed") ?: "一键登录失败" ?>';
            if (err && err.code === 'NO_CELLULAR') {
                errMsg = '<?= lang("oneclick_need_data") ?: "一键登录需要使用移动数据网络，请切换到手机流量后重试" ?>';
            }
            showError(errMsg);
        });
    });

    <?php else: ?>
    // Demo mode: use phone + username + password input
    $('#demoLoginBtn').on('click', function() {
        var phone = $('#demoPhone').val().trim();
        var username = $('#demoUsername').val().trim();
        var password = $('#demoPassword').val();

        if (!/^1[3-9]\d{9}$/.test(phone)) {
            showError('请输入正确的手机号');
            return;
        }
        if (username && !/^[A-Za-z0-9]{3,16}$/.test(username)) {
            showError('账号需3-16位字母或数字');
            return;
        }
        if (password.length < 6 || password.length > 32) {
            showError('密码需6-32位字符');
            return;
        }

        var btn = $(this);
        btn.prop('disabled', true);
        btn.html('<span class="spinner"></span> <?= lang("oneclick_verifying") ?: "正在验证..." ?>');
        showError('');

        submitOneClick(null, phone, username, password);
    });

    // Also allow Enter key on the inputs
    $('#demoPhone, #demoUsername, #demoPassword').on('keypress', function(e) {
        if (e.which === 13) {
            $('#demoLoginBtn').click();
        }
    });
    <?php endif; ?>

    <?php endif; ?>

    // --- Password Login (for returning users, works on both PC and Mobile) ---
    $('#pwdLoginBtn').on('click', function() {
        var username = $('#pwdUsername').val().trim();
        var password = $('#pwdPassword').val();

        if (!username) {
            showError('请输入游戏账号');
            return;
        }
        if (!password) {
            showError('请输入密码');
            return;
        }

        var btn = $(this);
        btn.prop('disabled', true);
        btn.html('<span class="spinner"></span> 登录中...');
        showError('');

        submitPasswordLogin(username, password, btn);
    });

    $('#pwdUsername, #pwdPassword').on('keypress', function(e) {
        if (e.which === 13) {
            $('#pwdLoginBtn').click();
        }
    });
});
</script>

<?php require_once 'footer.php'; ?>
