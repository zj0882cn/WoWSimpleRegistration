<?php
/**
 * Main Template — Email Login Mode (Light Theme)
 *
 * Homepage shows:
 *   1. Server status (online players, uptime, etc.) via SOAP
 *   2. Email + verification code login / registration
 *   3. Password login for returning users
 *   4. Account management (when logged in)
 *   5. Connection guide & contact tabs
 *
 * @author AzerothCore Community
 */
require_once 'header.php';

$mbLoggedIn = EmailAuth::isLoggedIn();
$mbUser     = EmailAuth::getCurrentUser();

// Check if this is a new registration (show password once)
$newAccount = !empty($_SESSION['user_new_account']) ? $_SESSION['user_new_account'] : false;
$newPassword = $_SESSION['user_new_password'] ?? '';
if ($newAccount) {
    unset($_SESSION['user_new_account'], $_SESSION['user_new_password']);
}

// Get server status via SOAP
$serverStatus = EmailAuth::getServerStatus();
$serverOnline = $serverStatus !== false;

// Detect mobile device (UI layout only, not used for auth flow)
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = (bool)preg_match('/Android|iPhone|iPad|iPod|Windows Phone|Mobile/i', $userAgent);

// Get config
$emailProvider = get_config('email_provider') ?: 'smtp';
$siteUrl       = get_config('baseurl') ?: '';
$emailEnabled  = EmailAuth::isEnabled();
$emailLoginEnabled      = EmailAuth::isFeatureEnabled('login');
$emailPasswordLoginOn   = EmailAuth::isFeatureEnabled('password_login');
$emailRegisterEnabled   = EmailAuth::isFeatureEnabled('register');
$emailResetEnabled      = EmailAuth::isFeatureEnabled('reset');
$emailBindEnabled       = EmailAuth::isFeatureEnabled('bind');

// Get email login result from session (set by email_verify.php redirect)
$emailLoginMsg = $loginMsg ?? '';
?>

<div class="row">
    <div class="main-box">
        <div class="col-xs-12">
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

            <!-- Login success message -->
            <?php if (!empty($emailLoginMsg)): ?>
                <div class="alert alert-info" style="border-radius: 8px; margin-bottom: 15px;">
                    <i class="fas fa-info-circle"></i>
                    <?= htmlspecialchars($emailLoginMsg) ?>
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
                    <a class="nav-item nav-link<?= $mbLoggedIn ? '' : ' active' ?>" id="nav-login-tab" data-toggle="tab"
                       href="#nav-login" role="tab" aria-selected="<?= $mbLoggedIn ? 'false' : 'true' ?>">
                        <i class="fas fa-sign-in-alt"></i>
                        <?php if ($mbLoggedIn): ?>
                            <?= lang('account_management') ?: '账号管理' ?>
                        <?php else: ?>
                            <?= lang('login') ?: '登录' ?>
                        <?php endif; ?>
                    </a>
                    <?php if ($mbLoggedIn): ?>
                    <a class="nav-item nav-link active" id="nav-accountinfo-tab" data-toggle="tab"
                       href="#nav-accountinfo" role="tab" aria-selected="true">
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
                <div class="tab-pane fade<?= $mbLoggedIn ? '' : ' show active' ?>" id="nav-login" role="tabpanel"
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
                                    <?= lang('change_password') ?: '修改密码' ?>
                                </a>
                                <?php if ($emailResetEnabled): ?>
                                <a href="javascript:void(0)"
                                   onclick="openResetPasswordModal()"
                                   class="btn btn-warning" style="padding: 10px 24px;">
                                    <i class="fas fa-redo"></i>
                                    <?= lang('forgot_password') ?: '忘记密码' ?>
                                </a>
                                <?php endif; ?>
                                <a href="<?= get_config('baseurl') ?>?logout=1"
                                   class="btn btn-outline-secondary" style="padding: 10px 24px; margin-top: 10px;">
                                    <i class="fas fa-sign-out-alt"></i>
                                    <?= lang('logout') ?: '退出登录' ?>
                                </a>
                            </div>

                            <div class="soap-notice">
                                <i class="fas fa-info-circle"></i>
                                <?= lang('email_manage_notice') ?: '修改密码：需输入旧密码验证后修改。忘记密码：需邮箱验证码验证后重置。' ?>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- ===== Not Logged In: Email Login / Registration ===== -->
                        <div class="mobile-login-section login-area">

                        <!-- Success modal displayed within login area -->
                        <?php if (!empty($emailLoginMsg)): ?>
                            <div class="modal-overlay" id="loginSuccessModal">
                                <div class="modal-content">
                                    <div class="modal-icon success">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <div class="modal-message"><?= htmlspecialchars($emailLoginMsg) ?></div>
                                    <button class="modal-close" onclick="document.getElementById('loginSuccessModal').style.display='none'">
                                        <?= lang('ok') ?: '好的' ?>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!$emailEnabled): ?>
                            <!-- Email auth disabled notice -->
                            <div class="demo-notice" style="max-width: 400px; margin: 20px auto;">
                                <i class="fas fa-exclamation-triangle"></i>
                                <?= lang('email_auth_disabled') ?: '邮箱登录功能未启用，请联系管理员。' ?>
                            </div>
                        <?php else: ?>
                            <!-- ===== Email + Verification Code Login ===== -->
                            <h3>
                                <i class="fas fa-envelope" style="color: var(--brand-blue);"></i>
                                <?= lang('email_login_title') ?: '邮箱登录 / 注册' ?>
                            </h3>
                            <p>
                                <?= lang('email_login_hint') ?: '输入邮箱获取验证码，首次登录将自动创建游戏账号。' ?>
                            </p>

                            <!-- Email login form -->
                            <div class="email-login-form" style="max-width: 400px; margin: 0 auto;">
                                <div class="input-group" style="margin-bottom: 10px;">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    </div>
                                    <input type="email" id="loginEmail" class="form-control"
                                           placeholder="<?= lang('enter_email') ?: '请输入邮箱' ?>"
                                           autocomplete="email"
                                           style="border-radius: 8px;">
                                </div>

                                <!-- Step 1: Send code button -->
                                <button type="button" id="sendEmailCodeBtn" class="btn btn-outline-primary btn-block" style="border-radius: 8px; margin-bottom: 10px;">
                                    <i class="fas fa-paper-plane"></i>
                                    <?= lang('send_code') ?: '获取验证码' ?>
                                </button>

                                <!-- Verification code + username + password (hidden until code sent) -->
                                <div id="emailCodeSection" style="display: none;">
                                    <div class="input-group" style="margin-bottom: 10px;">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-shield-alt"></i></span>
                                        </div>
                                        <input type="text" id="loginCode" class="form-control"
                                               placeholder="<?= lang('enter_code') ?: '验证码' ?>"
                                               maxlength="6" pattern="\d{6}"
                                               style="border-radius: 8px; text-align: center; font-size: 20px; letter-spacing: 5px;">
                                        <button type="button" id="resendCodeBtn" class="btn btn-outline-secondary" style="border-radius: 0 8px 8px 0;">
                                            <?= lang('resend_code') ?: '重新发送' ?>
                                        </button>
                                    </div>

                                    <?php if ($emailRegisterEnabled): ?>
                                    <div class="input-group" style="margin-bottom: 10px;">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                        </div>
                                        <input type="text" id="loginUsername" class="form-control"
                                               placeholder="<?= lang('username_hint') ?: '自定义游戏账号（3-16位字母数字）' ?>"
                                               minlength="3" maxlength="16" pattern="[A-Za-z0-9]{3,16}"
                                               style="border-radius: 8px;">
                                    </div>

                                    <div class="input-group" style="margin-bottom: 10px;">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                        </div>
                                        <input type="password" id="loginPassword" class="form-control"
                                               placeholder="<?= lang('password_hint') ?: '设置游戏密码（6-32位）' ?>"
                                               minlength="6" maxlength="32"
                                               style="border-radius: 8px;">
                                    </div>
                                    <?php else: ?>
                                    <input type="hidden" id="loginUsername" value="">
                                    <input type="hidden" id="loginPassword" value="">
                                    <?php endif; ?>

                                    <button type="button" id="loginSubmitBtn" class="btn btn-brand btn-block" style="border-radius: 8px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #fff; border: none; padding: 12px; font-size: 15px; font-weight: 600; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);">
                                        <i class="fas fa-sign-in-alt"></i>
                                        <?= lang('login_register') ?: '登录 / 注册' ?>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

                            <!-- Error message display -->
                            <div id="loginError" class="alert alert-danger" style="display: none; margin-top: 5px; font-size: 14px;"></div>

                            <!-- ===== Password Login (for returning users) ===== -->
                            <?php if ($emailPasswordLoginOn): ?>
                            <div class="email-login-form" style="max-width: 360px; margin: 20px auto 0;">
                                <h6 style="color: var(--text-muted); margin-bottom: 10px;">
                                    <i class="fas fa-key"></i> <?= lang('password_login_title') ?: '已有账号？使用密码登录' ?>
                                </h6>
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
                                <?php if ($emailResetEnabled): ?>
                                <div class="text-center" style="margin-top: 10px;">
                                    <a href="javascript:void(0)"
                                       onclick="openResetPasswordModal()"
                                       style="font-size: 13px; color: var(--brand-blue);">
                                        <i class="fas fa-redo"></i>
                                        <?= lang('forgot_password') ?: '忘记密码？' ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($mbLoggedIn && $mbUser): ?>
                <!-- ===== Account Info Tab ===== -->
                <div class="tab-pane fade show active" id="nav-accountinfo" role="tabpanel"
                     aria-labelledby="nav-accountinfo-tab">
                    <div class="content_box1">
                        <h5><i class="fas fa-user-circle"></i> <?= lang('account_details') ?: '账户详情' ?></h5>
                        <hr>

                        <?php
                        // Get character list (includes account ID) from SOAP
                        $charList = EmailAuth::getAccountCharacters($mbUser['username']);
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

                            <div class="row" style="margin-bottom: 12px; align-items: center;">
                                <div class="col-5">
                                    <span class="label"><i class="fas fa-envelope"></i> <?= lang('bound_email') ?: '绑定邮箱' ?></span>
                                </div>
                                <div class="col-7">
                                    <?php if (!empty($mbUser['email'])): ?>
                                        <span class="value" style="font-size: 14px; word-break: break-all;">
                                            <?= htmlspecialchars(EmailAuth::maskEmail($mbUser['email'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="value" style="font-size: 14px; color: #e6a23c;">
                                            <i class="fas fa-exclamation-triangle"></i> <?= lang('not_bound') ?: '未绑定' ?>
                                        </span>
                                        <?php if ($emailBindEnabled): ?>
                                        <button type="button" id="bindEmailBtn" class="btn btn-outline-primary btn-sm" style="margin-left: 10px; padding: 2px 12px; font-size: 12px;">
                                            <i class="fas fa-link"></i> <?= lang('bind') ?: '绑定' ?>
                                        </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Bind email form (hidden by default) -->
                            <div id="bindEmailForm" style="display: none; margin-bottom: 12px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                                <div style="font-size: 13px; color: #666; margin-bottom: 10px;">
                                    <i class="fas fa-info-circle" style="color: var(--brand-blue);"></i>
                                    <?= lang('bind_email_hint') ?: '绑定邮箱后可使用邮箱验证码登录，无需每次输入密码' ?>
                                </div>
                                <div class="input-group" style="margin-bottom: 8px;">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    </div>
                                    <input type="email" id="bindEmailInput" class="form-control" placeholder="<?= lang('enter_email') ?: '请输入邮箱' ?>" style="border-radius: 8px;">
                                </div>
                                <div class="input-group" style="margin-bottom: 8px;">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-key"></i></span>
                                    </div>
                                    <input type="text" id="bindEmailCodeInput" class="form-control" placeholder="<?= lang('enter_code') ?: '验证码' ?>" maxlength="6" style="border-radius: 8px;">
                                    <button type="button" id="bindSendCodeBtn" class="btn btn-outline-primary"><?= lang('get_code') ?: '获取验证码' ?></button>
                                </div>
                                <button type="button" id="bindEmailSubmitBtn" class="btn btn-primary btn-sm" style="width: 100%;">
                                    <i class="fas fa-check"></i> <?= lang('confirm_bind') ?: '确认绑定' ?>
                                </button>
                                <div id="bindEmailError" style="display: none; margin-top: 8px; font-size: 13px; color: #dc3545;"></div>
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
                                        <th class="text-center"><?= lang('char_action') ?: '操作' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($charList['characters'] as $char): ?>
                                    <tr id="char-row-<?= htmlspecialchars($char['name']) ?>">
                                        <td style="font-weight: 600;"><?= htmlspecialchars($char['name']) ?></td>
                                        <td><?= htmlspecialchars($char['race']) ?></td>
                                        <td><?= htmlspecialchars($char['class']) ?></td>
                                        <td class="text-center">
                                            <span style="background: var(--brand-blue); color: #fff; padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 700;">
                                                <?= (int)$char['level'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <button type="button"
                                                class="btn btn-sm btn-bot-action"
                                                data-character="<?= htmlspecialchars($char['name']) ?>"
                                                data-account="<?= htmlspecialchars($mbUser['username']) ?>"
                                                style="padding: 4px 10px; font-size: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; border: none; border-radius: 6px; cursor: pointer; transition: all 0.2s;">
                                                <i class="fas fa-robot"></i> <?= lang('go_idle') ?: '挂机' ?>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>

                            <!-- Bot Status Panel -->
                            <div id="botStatusPanel" style="display: none; margin-top: 15px; padding: 15px; background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%); border: 1px solid #667eea; border-radius: 10px;">
                                <div style="display: flex; align-items: center; margin-bottom: 10px;">
                                    <div id="botStatusIcon" style="width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 10px; background: #f39c12;">
                                        <i class="fas fa-robot" style="color: #fff;"></i>
                                    </div>
                                    <div>
                                        <strong id="botStatusTitle"><?= lang('bot_idle_status') ?: '挂机状态' ?></strong>
                                        <div id="botStatusMessage" style="font-size: 13px; color: #666;"><?= lang('bot_preparing') ?: '准备中...' ?></div>
                                    </div>
                                </div>
                                <div id="botLog" style="max-height: 120px; overflow-y: auto; font-size: 12px; font-family: monospace; background: #1a1a2e; color: #0f0; padding: 10px; border-radius: 6px; display: none;"></div>
                                <button type="button" id="stopBotBtn" style="margin-top: 10px; padding: 5px 12px; background: #e74c3c; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; display: none;">
                                    <i class="fas fa-stop"></i> <?= lang('stop_bot') ?: '停止挂机' ?>
                                </button>
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
                            <li><?= lang('howto_step2_email') ?: '在本站使用邮箱验证码登录，系统将自动创建游戏账号。' ?></li>
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

<script>
// 版本号 - 用于调试缓存问题
console.log('[WoWSimpleRegistration] 版本: 20260813-email-auth');
console.log('[WoWSimpleRegistration] Email auth UI 已启用');

// 全局错误捕获，防止任何 JS 错误影响登录功能
window.onerror = function(msg, url, line, col, error) {
    console.error('Global JS Error:', msg, 'at line', line);
    return true; // 阻止默认错误处理
};

$(function() {
    var siteUrl = window.location.origin;
    var provider = '<?= addslashes($emailProvider) ?>';
    var isMobile = <?= $isMobile ? 'true' : 'false' ?>;

    // --- Error display helper ---
    function showError(msg) {
        var el = $('#loginError');
        if (!msg) {
            el.hide();
            return;
        }
        var friendlyMsgs = {
            'soap_create_failed': '<?= lang('soap_create_failed') ?: '游戏服务器连接失败，请稍后重试' ?>',
            'soap_error': '<?= lang('soap_error') ?: '游戏服务器连接失败，请稍后重试' ?>',
            'email_auth_disabled': '<?= lang('email_auth_disabled') ?: '邮箱登录功能未启用' ?>',
            'invalid_email': '<?= lang('invalid_email') ?: '邮箱格式不正确' ?>',
            'invalid_password': '<?= lang('invalid_password') ?: '密码需6-32位字符' ?>',
            'invalid_username': '<?= lang('invalid_username') ?: '账号需3-16位字母或数字' ?>',
            'username_taken': '<?= lang('username_taken') ?: '该账号已被使用，请换一个' ?>',
            'email_required': '<?= lang('email_required') ?: '请输入邮箱' ?>',
            'password_required': '<?= lang('password_required') ?: '请输入密码' ?>',
            'username_required': '<?= lang('username_required') ?: '请输入游戏账号' ?>',
            'account_not_found': '<?= lang('account_not_found') ?: '账号不存在，请检查或先注册' ?>',
            'no_password_hash': '<?= lang('no_password_hash') ?: '该账号未设置密码，请先通过邮箱验证登录' ?>',
            'wrong_password': '<?= lang('wrong_password') ?: '密码错误，请重新输入' ?>',
            'code_required': '<?= lang('code_required') ?: '请输入验证码' ?>',
            'wrong_code': '<?= lang('wrong_code') ?: '验证码错误' ?>',
            'code_not_found': '<?= lang('code_not_found') ?: '验证码不存在或已过期' ?>',
            'rate_limited': '<?= lang('rate_limited') ?: '验证码发送过于频繁，请稍后再试' ?>',
            'max_attempts_exceeded': '<?= lang('max_attempts_exceeded') ?: '尝试次数过多，请重新获取验证码' ?>',
            'email_already_bound': '<?= lang('email_already_bound') ?: '该邮箱已绑定其他账号' ?>',
        };
        var displayMsg = friendlyMsgs[msg] || msg;
        el.html('<i class="fas fa-exclamation-circle"></i> ' + displayMsg).show();
    }

    // --- Email login flow ---
    var loginState = {
        email: '',
        code: '',
        username: '',
        password: '',
        cooldown: 0,
        cooldownTimer: null
    };

    function startCooldown(seconds) {
        loginState.cooldown = seconds;
        var btn = $('#sendEmailCodeBtn');
        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin"></i> ' + seconds + 's ' + '<?= lang('resend_code') ?: '重新发送' ?>');
        loginState.cooldownTimer = setInterval(function() {
            loginState.cooldown--;
            if (loginState.cooldown <= 0) {
                clearInterval(loginState.cooldownTimer);
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-paper-plane"></i> <?= lang('send_code') ?: '获取验证码' ?>');
            } else {
                btn.html('<i class="fas fa-spinner fa-spin"></i> ' + loginState.cooldown + 's ' + '<?= lang('resend_code') ?: '重新发送' ?>');
            }
        }, 1000);
    }

    // Send email verification code
    $('#sendEmailCodeBtn').on('click', function() {
        var email = $('#loginEmail').val().trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showError('<?= lang('invalid_email') ?: '请输入正确的邮箱地址' ?>');
            return;
        }
        var btn = $(this);
        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin"></i> <?= lang('sending') ?: '发送中...' ?>');
        showError('');

        $.ajax({
            url: siteUrl + '/email_send.php',
            type: 'POST',
            dataType: 'json',
            data: { email: email },
            success: function(resp) {
                if (resp && resp.success) {
                    loginState.email = email;
                    $('#emailCodeSection').slideDown(300);
                    showError('');
                    startCooldown(60);
                } else {
                    btn.prop('disabled', false);
                    btn.html('<i class="fas fa-paper-plane"></i> <?= lang('send_code') ?: '获取验证码' ?>');
                    showError((resp && resp.message) || '<?= lang('send_failed') ?: '发送失败，请重试' ?>');
                }
            },
            error: function(xhr, status, err) {
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-paper-plane"></i> <?= lang('send_code') ?: '获取验证码' ?>');
                showError('<?= lang('network_error') ?: '网络错误' ?>: ' + (err || '<?= lang('unknown_error') ?: '未知错误' ?>'));
            }
        });
    });

    // Resend code button
    $('#resendCodeBtn').on('click', function() {
        if (loginState.cooldown > 0) return;
        var btn = $(this);
        btn.prop('disabled', true);
        btn.text('<?= lang('sending') ?: '发送中...' ?>');
        $.ajax({
            url: siteUrl + '/email_send.php',
            type: 'POST',
            dataType: 'json',
            data: { email: loginState.email },
            success: function(resp) {
                if (resp && resp.success) {
                    btn.prop('disabled', true);
                    startCooldown(60);
                    showError('');
                } else {
                    btn.prop('disabled', false);
                    btn.text('<?= lang('resend_code') ?: '重新发送' ?>');
                    showError((resp && resp.message) || '<?= lang('send_failed') ?: '发送失败' ?>');
                }
            },
            error: function() {
                btn.prop('disabled', false);
                btn.text('<?= lang('resend_code') ?: '重新发送' ?>');
                showError('<?= lang('network_error') ?: '网络错误，请重试' ?>');
            }
        });
    });

    // Submit email login/registration
    $('#loginSubmitBtn').on('click', function() {
        var email = loginState.email;
        var code = $('#loginCode').val().trim();
        var username = $('#loginUsername').val().trim();
        var password = $('#loginPassword').val();

        if (!email) { showError('<?= lang('email_first') ?: '请先获取验证码' ?>'); return; }
        if (!code || code.length < 4) { showError('<?= lang('enter_code') ?: '请输入验证码' ?>'); return; }
        if (username && !/^[A-Za-z0-9]{3,16}$/.test(username)) { showError('<?= lang('invalid_username') ?: '账号需3-16位字母或数字' ?>'); return; }
        if (password && (password.length < 6 || password.length > 32)) { showError('<?= lang('invalid_password') ?: '密码需6-32位字符' ?>'); return; }

        var btn = $(this);
        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin"></i> <?= lang('logging_in') ?: '登录中...' ?>');
        showError('');

        $.ajax({
            url: siteUrl + '/oneclick_verify.php',
            type: 'POST',
            dataType: 'json',
            data: { email: email, code: code, username: username, password: password },
            success: function(resp) {
                if (resp && resp.success) {
                    showError('');
                    if (resp.redirect) {
                        window.location.href = resp.redirect;
                    } else {
                        window.location.reload();
                    }
                } else {
                    btn.prop('disabled', false);
                    btn.html('<i class="fas fa-sign-in-alt"></i> <?= lang('login_register') ?: '登录 / 注册' ?>');
                    showError((resp && resp.message) || '<?= lang('login_failed') ?: '登录失败，请重试' ?>');
                }
            },
            error: function(xhr, status, err) {
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-sign-in-alt"></i> <?= lang('login_register') ?: '登录 / 注册' ?>');
                showError('<?= lang('network_error') ?: '网络错误' ?>: ' + (err || '<?= lang('unknown_error') ?: '未知错误' ?>'));
            }
        });
    });

    // Enter key handlers for login form
    $('#loginEmail').on('keypress', function(e) {
        if (e.which === 13) { e.preventDefault(); $('#sendEmailCodeBtn').click(); }
    });
    $('#loginCode').on('keypress', function(e) {
        if (e.which === 13) { e.preventDefault(); $('#loginSubmitBtn').click(); }
    });
    $('#loginUsername, #loginPassword').on('keypress', function(e) {
        if (e.which === 13) { e.preventDefault(); $('#loginSubmitBtn').click(); }
    });

    // --- Password login (for returning users) ---
    function submitPasswordLogin(username, password, btn) {
        $.ajax({
            url: siteUrl + '/password_login.php',
            type: 'POST',
            dataType: 'json',
            data: { username: username, password: password },
            success: function(resp) {
                if (resp && resp.success) {
                    showError('');
                    if (resp.redirect) {
                        window.location.href = resp.redirect;
                    } else {
                        window.location.reload();
                    }
                } else {
                    btn.prop('disabled', false);
                    btn.html('<i class="fas fa-sign-in-alt"></i> <?= lang('login_btn') ?: '登录' ?>');
                    showError((resp && resp.message) || '<?= lang('login_failed') ?: '登录失败' ?>');
                }
            },
            error: function(xhr, status, err) {
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-sign-in-alt"></i> <?= lang('login_btn') ?: '登录' ?>');
                showError('<?= lang('network_error') ?: '网络错误' ?>: ' + (err || '<?= lang('unknown_error') ?: '未知错误' ?>'));
            }
        });
    }

    $('#pwdLoginBtn').on('click', function() {
        var username = $('#pwdUsername').val().trim();
        var password = $('#pwdPassword').val();
        if (!username) { showError('<?= lang('username_required') ?: '请输入游戏账号' ?>'); return; }
        if (!password) { showError('<?= lang('password_required') ?: '请输入密码' ?>'); return; }
        var btn = $(this);
        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin"></i> <?= lang('logging_in') ?: '登录中...' ?>');
        showError('');
        submitPasswordLogin(username, password, btn);
    });

    $('#pwdUsername, #pwdPassword').on('keypress', function(e) {
        if (e.which === 13) { e.preventDefault(); $('#pwdLoginBtn').click(); }
    });

    // --- Bind email for accounts ---
    var bindCodeCooldown = 0;

    $('#bindEmailBtn').on('click', function() {
        $('#bindEmailForm').slideToggle(200);
    });

    $('#bindSendCodeBtn').on('click', function() {
        var email = $('#bindEmailInput').val().trim();
        var errEl = $('#bindEmailError');
        errEl.hide();

        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errEl.text('<?= lang('invalid_email') ?: '请输入正确的邮箱地址' ?>').show();
            return;
        }

        var btn = $(this);
        btn.prop('disabled', true);
        btn.text('<?= lang('sending') ?: '发送中...' ?>');

        $.ajax({
            url: siteUrl + '/email_send.php',
            type: 'POST',
            dataType: 'json',
            data: { email: email },
            success: function(resp) {
                if (resp && resp.success) {
                    btn.prop('disabled', true);
                    bindCodeCooldown = 60;
                    var timer = setInterval(function() {
                        btn.text(bindCodeCooldown + 's <?= lang('retry') ?: '后重试' ?>');
                        bindCodeCooldown--;
                        if (bindCodeCooldown < 0) {
                            clearInterval(timer);
                            btn.prop('disabled', false);
                            btn.text('<?= lang('get_code') ?: '获取验证码' ?>');
                        }
                    }, 1000);
                } else {
                    btn.prop('disabled', false);
                    btn.text('<?= lang('get_code') ?: '获取验证码' ?>');
                    errEl.text(resp.message || '<?= lang('send_failed') ?: '验证码发送失败' ?>').show();
                }
            },
            error: function() {
                btn.prop('disabled', false);
                btn.text('<?= lang('get_code') ?: '获取验证码' ?>');
                errEl.text('<?= lang('network_error') ?: '网络错误，请重试' ?>').show();
            }
        });
    });

    $('#bindEmailSubmitBtn').on('click', function() {
        var email = $('#bindEmailInput').val().trim();
        var code = $('#bindEmailCodeInput').val().trim();
        var errEl = $('#bindEmailError');
        errEl.hide();

        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errEl.text('<?= lang('invalid_email') ?: '请输入正确的邮箱地址' ?>').show();
            return;
        }
        if (!code || code.length < 4) {
            errEl.text('<?= lang('enter_code') ?: '请输入验证码' ?>').show();
            return;
        }

        var btn = $(this);
        btn.prop('disabled', true);
        btn.html('<span class="spinner"></span> <?= lang('binding') ?: '绑定中...' ?>');

        $.ajax({
            url: siteUrl + '/bind_email.php',
            type: 'POST',
            dataType: 'json',
            data: { email: email, code: code },
            success: function(resp) {
                if (resp && resp.success) {
                    errEl.css('color', '#28a745').text('<?= lang('bind_success') ?: '邮箱绑定成功！' ?>').show();
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    btn.prop('disabled', false);
                    btn.html('<i class="fas fa-check"></i> <?= lang('confirm_bind') ?: '确认绑定' ?>');
                    var msgMap = {
                        'wrong_code': '<?= lang('wrong_code') ?: '验证码错误' ?>',
                        'code_not_found': '<?= lang('code_not_found') ?: '验证码不存在或已过期' ?>',
                        'max_attempts_exceeded': '<?= lang('max_attempts_exceeded') ?: '尝试次数过多，请重新获取验证码' ?>',
                        'email_already_bound': '<?= lang('email_already_bound') ?: '该邮箱已绑定其他账号' ?>',
                        'account_not_found': '<?= lang('account_not_found') ?: '账号不存在' ?>',
                        'not_logged_in': '<?= lang('not_logged_in') ?: '请先登录' ?>'
                    };
                    var msg = msgMap[resp.message] || resp.message || '<?= lang('bind_failed') ?: '绑定失败' ?>';
                    errEl.css('color', '#dc3545').text(msg).show();
                }
            },
            error: function() {
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-check"></i> <?= lang('confirm_bind') ?: '确认绑定' ?>');
                errEl.css('color', '#dc3545').text('<?= lang('network_error') ?: '网络错误，请重试' ?>').show();
            }
        });
    });

    // ===== Password Reset Modal =====
    var resetState = {
        email: '',
        username: '',
        countdownTimer: null,
        countdownValue: 0
    };

    window.openResetPasswordModal = function() {
        var modal = document.getElementById('resetPasswordModal');
        if (!modal) return;

        resetState = { email: '', username: '', countdownTimer: null, countdownValue: 0 };
        document.getElementById('resetModalMsg').style.display = 'none';

        var isLoggedIn = <?= $mbLoggedIn ? 'true' : 'false' ?>;
        var sessionEmail = '<?= addslashes($_SESSION['user_email'] ?? '') ?>';
        var autoMode = isLoggedIn && sessionEmail;

        if (autoMode) {
            resetState.email = sessionEmail;
            document.getElementById('resetAutoEmailBox').style.display = 'block';
            document.getElementById('resetManualBox').style.display = 'none';

            $.ajax({
                url: siteUrl + '/reset_password.php',
                type: 'POST',
                dataType: 'json',
                data: { ajax_action: 'send_code', email: sessionEmail },
                success: function(resp) {
                    if (resp && resp.success) {
                        document.getElementById('resetMaskedEmail').textContent = resp.masked_email || EmailAuthMaskClient(sessionEmail);
                        document.getElementById('resetAccountName').textContent = resp.username || '';
                        resetState.username = resp.username || '';
                    } else {
                        document.getElementById('resetMaskedEmail').textContent = EmailAuthMaskClient(sessionEmail);
                    }
                },
                error: function() {
                    document.getElementById('resetMaskedEmail').textContent = EmailAuthMaskClient(sessionEmail);
                }
            });
        } else {
            document.getElementById('resetAutoEmailBox').style.display = 'none';
            document.getElementById('resetManualBox').style.display = 'block';
        }

        showResetStep(1);
        modal.style.display = 'flex';
    };

    window.closeResetPasswordModal = function() {
        var modal = document.getElementById('resetPasswordModal');
        if (modal) modal.style.display = 'none';
        if (resetState.countdownTimer) {
            clearInterval(resetState.countdownTimer);
            resetState.countdownTimer = null;
        }
    };

    function EmailAuthMaskClient(email) {
        if (!email || email.indexOf('@') === -1) return email;
        var parts = email.split('@');
        var local = parts[0];
        if (local.length <= 2) {
            parts[0] = local.charAt(0) + new Array(local.length).join('*');
        } else if (local.length <= 4) {
            parts[0] = local.charAt(0) + new Array(local.length).join('*');
        } else {
            parts[0] = local.substring(0, 2) + new Array(local.length - 1).join('*');
        }
        return parts.join('@');
    }

    function showResetStep(step) {
        ['resetStep1', 'resetStep2', 'resetStep3', 'resetStep4'].forEach(function(id) {
            document.getElementById(id).style.display = 'none';
        });
        var target = document.getElementById('resetStep' + step);
        if (target) target.style.display = 'block';
        document.getElementById('resetModalMsg').style.display = 'none';
        if (step === 3) {
            document.getElementById('resetAccountDisplay').textContent = resetState.username || '';
        }
    }

    function showResetMsg(type, msg) {
        var el = document.getElementById('resetModalMsg');
        el.style.display = 'block';
        if (type === 'error') {
            el.style.background = '#fef2f2';
            el.style.border = '1px solid #fecaca';
            el.style.color = '#dc2626';
            el.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + msg;
        } else {
            el.style.background = '#f0fdf4';
            el.style.border = '1px solid #bbf7d0';
            el.style.color = '#15803d';
            el.innerHTML = '<i class="fas fa-check-circle"></i> ' + msg;
        }
    }

    $('#resetSendCodeBtn').on('click', function() {
        var btn = $(this);
        var email = resetState.email;
        if (!email) email = $('#resetEmailInput').val().trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showResetMsg('error', '<?= lang('invalid_email') ?: '请输入正确的邮箱' ?>');
            return;
        }
        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin"></i> <?= lang('sending') ?: '发送中...' ?>');
        $.ajax({
            url: siteUrl + '/reset_password.php',
            type: 'POST', dataType: 'json',
            data: { ajax_action: 'send_code', email: email },
            success: function(resp) {
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-paper-plane"></i> <?= lang('send_code') ?: '发送验证码' ?>');
                if (resp && resp.success) {
                    resetState.email = resp.email || email;
                    resetState.username = resp.username || resetState.username;
                    $('#resetEmailDisplay').text(resp.masked_email || EmailAuthMaskClient(email));
                    showResetStep(2);
                    showResetMsg('success', '<?= lang('code_sent') ?: '验证码已发送' ?>');
                    startResetCountdown(60);
                } else {
                    showResetMsg('error', (resp && resp.message) || '<?= lang('send_failed') ?: '发送失败' ?>');
                }
            },
            error: function() {
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-paper-plane"></i> <?= lang('send_code') ?: '发送验证码' ?>');
                showResetMsg('error', '<?= lang('network_error') ?: '网络错误，请重试' ?>');
            }
        });
    });

    $('#resetVerifyBtn').on('click', function() {
        var code = $('#resetCodeInput').val().trim();
        if (!code) { showResetMsg('error', '<?= lang('enter_code') ?: '请输入验证码' ?>'); return; }
        var btn = $(this);
        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin"></i> <?= lang('verifying') ?: '验证中...' ?>');
        $.ajax({
            url: siteUrl + '/reset_password.php',
            type: 'POST', dataType: 'json',
            data: { ajax_action: 'verify_code', email: resetState.email, code: code },
            success: function(resp) {
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-check"></i> <?= lang('verify') ?: '验证' ?>');
                if (resp && resp.success) {
                    resetState.username = resp.username || resetState.username;
                    showResetStep(3);
                } else {
                    showResetMsg('error', (resp && resp.message) || '<?= lang('verify_failed') ?: '验证失败' ?>');
                }
            },
            error: function() {
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-check"></i> <?= lang('verify') ?: '验证' ?>');
                showResetMsg('error', '<?= lang('network_error') ?: '网络错误，请重试' ?>');
            }
        });
    });

    $('#resetResendBtn').on('click', function() {
        if (resetState.countdownValue > 0) return;
        var btn = $(this);
        btn.prop('disabled', true);
        btn.text('<?= lang('sending') ?: '发送中...' ?>');
        $.ajax({
            url: siteUrl + '/reset_password.php',
            type: 'POST', dataType: 'json',
            data: { ajax_action: 'send_code', email: resetState.email },
            success: function(resp) {
                if (resp && resp.success) {
                    btn.prop('disabled', false);
                    btn.text('<?= lang('resend_code') ?: '没收到？重新发送' ?>');
                    startResetCountdown(60);
                    showResetMsg('success', '<?= lang('code_resent') ?: '验证码已重新发送' ?>');
                } else {
                    btn.prop('disabled', false);
                    btn.text('<?= lang('resend_code') ?: '没收到？重新发送' ?>');
                    showResetMsg('error', (resp && resp.message) || '<?= lang('send_failed') ?: '发送失败' ?>');
                }
            },
            error: function() {
                btn.prop('disabled', false);
                btn.text('<?= lang('resend_code') ?: '没收到？重新发送' ?>');
                showResetMsg('error', '<?= lang('network_error') ?: '网络错误，请重试' ?>');
            }
        });
    });

    function startResetCountdown(seconds) {
        resetState.countdownValue = seconds;
        var btn = document.getElementById('resetResendBtn');
        if (!btn) return;
        btn.disabled = true;
        resetState.countdownTimer = setInterval(function() {
            if (resetState.countdownValue <= 0) {
                clearInterval(resetState.countdownTimer);
                resetState.countdownTimer = null;
                resetState.countdownValue = 0;
                btn.disabled = false;
                btn.textContent = '<?= lang('resend_code') ?: '没收到？重新发送' ?>';
                return;
            }
            btn.textContent = '<?= lang('resend_code') ?: '重新发送' ?>（' + resetState.countdownValue + 's）';
            resetState.countdownValue--;
        }, 1000);
    }

    $('#resetSetPwdBtn').on('click', function() {
        var newPwd = $('#resetNewPwd').val();
        var confirmPwd = $('#resetConfirmPwd').val();
        if (newPwd.length < 6 || newPwd.length > 32) {
            showResetMsg('error', '<?= lang('invalid_password') ?: '密码需6-32位字符' ?>');
            return;
        }
        if (newPwd !== confirmPwd) {
            showResetMsg('error', '<?= lang('password_mismatch') ?: '两次输入的密码不一致' ?>');
            return;
        }
        var btn = $(this);
        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin"></i> <?= lang('resetting') ?: '重置中...' ?>');
        $.ajax({
            url: siteUrl + '/reset_password.php',
            type: 'POST', dataType: 'json',
            data: {
                ajax_action: 'set_password',
                email: resetState.email,
                new_password: newPwd,
                confirm_password: confirmPwd
            },
            success: function(resp) {
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-check"></i> <?= lang('confirm_reset_password') ?: '确认重置密码' ?>');
                if (resp && resp.success) {
                    resetState.username = resp.username || resetState.username;
                    $('#resetSuccessAccount').text(resetState.username);
                    showResetStep(4);
                } else {
                    showResetMsg('error', (resp && resp.message) || '<?= lang('reset_failed') ?: '重置失败' ?>');
                }
            },
            error: function() {
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-check"></i> <?= lang('confirm_reset_password') ?: '确认重置密码' ?>');
                showResetMsg('error', '<?= lang('network_error') ?: '网络错误，请重试' ?>');
            }
        });
    });

    $('#resetNewPwd').on('input', function() {
        var val = $(this).val();
        var bar = $('#resetStrengthBar');
        var text = $('#resetStrengthText');
        var score = 0;
        if (val.length >= 6) score++;
        if (val.length >= 10) score++;
        if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score++;
        if (/\d/.test(val)) score++;
        if (/[^a-zA-Z0-9]/.test(val)) score++;
        bar.removeClass('strength-weak strength-medium strength-strong');
        if (!val) { bar.css('background', '#e0e0e0'); text.text('').css('color', '#999'); }
        else if (score <= 2) { bar.addClass('strength-weak').css('background', ''); text.text('弱').css('color', '#ef4444'); }
        else if (score <= 3) { bar.addClass('strength-medium').css('background', ''); text.text('中').css('color', '#e6a23c'); }
        else { bar.addClass('strength-strong').css('background', ''); text.text('强').css('color', '#22c55e'); }
    });

    window.toggleResetPwd = function(inputId, btn) {
        var input = document.getElementById(inputId);
        var icon = btn.querySelector('i');
        if (input.type === 'password') { input.type = 'text'; icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
        else { input.type = 'password'; icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
    };

    $(document).on('keydown', '#resetCodeInput', function(e) {
        if (e.which === 13) { e.preventDefault(); $('#resetVerifyBtn').click(); }
    });
    $(document).on('keydown', '#resetEmailInput', function(e) {
        if (e.which === 13) { e.preventDefault(); $('#resetSendCodeBtn').click(); }
    });

    // ===== Bot / Idle Feature =====
    $('.btn-bot-action').on('click', function() {
        var btn = $(this);
        var character = btn.data('character');
        var account = btn.data('account');
        startBotMode(account, character, btn);
    });

    function startBotMode(account, character, btn) {
        var panel = $('#botStatusPanel');
        var statusIcon = $('#botStatusIcon');
        var statusTitle = $('#botStatusTitle');
        var statusMsg = $('#botStatusMessage');
        var botLog = $('#botLog');
        var stopBtn = $('#stopBotBtn');

        panel.show();
        botLog.show().html('');
        stopBtn.show();

        function logMsg(msg, color) {
            var cls = color ? ('color:' + color + ';') : '';
            botLog.append('<div style="' + cls + '">' + msg + '</div>');
            botLog.scrollTop(botLog[0].scrollHeight);
        }

        logMsg('[' + new Date().toLocaleTimeString() + '] <?= lang('bot_starting') ?: '正在启动挂机模式' ?>...');
        statusIcon.css('background', '#f39c12');
        statusTitle.text('<?= lang('bot_idle_status') ?: '挂机中' ?>');
        statusMsg.text('<?= lang('bot_connecting') ?: '正在连接游戏服务器' ?>...');

        btn.prop('disabled', true);
        btn.html('<i class="fas fa-spinner fa-spin"></i> <?= lang('bot_idle') ?: '挂机中' ?>');

        $.ajax({
            url: siteUrl + '/bot_start.php',
            type: 'POST',
            dataType: 'json',
            data: { account: account, character: character },
            timeout: 30000,
            success: function(resp) {
                if (resp && resp.success) {
                    logMsg('[' + new Date().toLocaleTimeString() + '] ✓ ' + (resp.message || '<?= lang('bot_started') ?: '角色已登录，Bot模式已启动' ?>'));
                    statusIcon.css('background', '#28a745');
                    statusTitle.text('<?= lang('bot_running') ?: '挂机运行中' ?>');
                    statusMsg.text('<?= lang('bot_character_idle') ?: '角色' ?>"' + character + '" <?= lang('bot_is_idle') ?: '正在挂机中' ?>');

                    if (resp.logs && Array.isArray(resp.logs)) {
                        resp.logs.forEach(function(l) { logMsg(l); });
                    }
                } else {
                    var errMsg = (resp && resp.message) || '<?= lang('bot_start_failed') ?: '启动失败，请重试' ?>';
                    logMsg('[' + new Date().toLocaleTimeString() + '] ✗ ' + errMsg, '#ff6b6b');
                    statusIcon.css('background', '#e74c3c');
                    statusTitle.text('<?= lang('bot_failed') ?: '挂机失败' ?>');
                    statusMsg.text(errMsg);
                    btn.prop('disabled', false);
                    btn.html('<i class="fas fa-robot"></i> <?= lang('go_idle') ?: '挂机' ?>');
                }
            },
            error: function(xhr, status, err) {
                logMsg('[' + new Date().toLocaleTimeString() + '] ✗ <?= lang('network_error') ?: '网络错误' ?>: ' + (err || '<?= lang('unknown_error') ?: '未知错误' ?>'), '#ff6b6b');
                statusIcon.css('background', '#e74c3c');
                statusTitle.text('<?= lang('bot_failed') ?: '挂机失败' ?>');
                statusMsg.text('<?= lang('server_connect_failed') ?: '连接服务器失败，请重试' ?>');
                btn.prop('disabled', false);
                btn.html('<i class="fas fa-robot"></i> <?= lang('go_idle') ?: '挂机' ?>');
            }
        });
    }

    $('#stopBotBtn').on('click', function() {
        $('#botStatusPanel').hide();
        $('#botLog').hide();
        $(this).hide();
        $('.btn-bot-action').each(function() {
            $(this).prop('disabled', false);
            $(this).html('<i class="fas fa-robot"></i> <?= lang('go_idle') ?: '挂机' ?>');
        });
    });

});
</script>

<!-- ===== Password Reset Modal (Email-based) ===== -->
<div class="modal-overlay" id="resetPasswordModal" style="z-index: 10000;">
    <div class="modal-content" style="max-width: 440px; width: 92%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
            <h4 style="margin: 0; font-weight: 600;">
                <i class="fas fa-key" style="color: var(--brand-blue);"></i>
                <?= lang('forgot_password') ?: '忘记密码' ?>
            </h4>
            <button type="button" onclick="closeResetPasswordModal()"
                    style="border: none; background: transparent; font-size: 22px; color: #999; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Step 1: Send code to email -->
        <div id="resetStep1">
            <div id="resetAutoEmailBox" style="display: none; background: #f0f5ff; border: 1px solid #adc6ff; border-radius: 10px; padding: 16px; margin-bottom: 16px; text-align: center;">
                <div style="font-size: 18px; font-weight: 700; color: var(--brand-blue); word-break: break-all;" id="resetMaskedEmail"></div>
                <div style="font-size: 13px; color: #888; margin-top: 6px;"><?= lang('code_sent_to_email') ?: '验证码将发送至以上邮箱' ?></div>
                <div style="font-size: 13px; color: #666; margin-top: 4px;"><?= lang('account') ?: '账号' ?>：<strong id="resetAccountName"></strong></div>
            </div>

            <div id="resetManualBox">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; font-size: 14px;"><i class="fas fa-envelope"></i> <?= lang('email') ?: '邮箱' ?></label>
                    <input type="email" id="resetEmailInput" class="form-control"
                           placeholder="<?= lang('enter_bound_email') ?: '请输入账号绑定的邮箱' ?>"
                           autocomplete="email"
                           style="border-radius: 8px;">
                </div>
                <div style="font-size: 12px; color: #888; margin-bottom: 14px;">
                    <i class="fas fa-info-circle"></i> <?= lang('email_must_bound') ?: '该邮箱必须已绑定游戏账号' ?>
                </div>
            </div>

            <button type="button" id="resetSendCodeBtn" class="btn btn-brand btn-block" style="border-radius: 8px; padding: 12px; font-size: 15px; background: var(--brand-blue); color: #fff; border: none;">
                <i class="fas fa-paper-plane"></i> <?= lang('send_code') ?: '发送验证码' ?>
            </button>
        </div>

        <!-- Step 2: Verify code -->
        <div id="resetStep2" style="display: none;">
            <div style="text-align: center; color: #888; margin-bottom: 12px; font-size: 14px;">
                <?= lang('code_sent_to') ?: '验证码已发送至' ?>
                <strong id="resetEmailDisplay" style="color: var(--brand-blue);"></strong>
            </div>
            <div class="form-group" style="margin-bottom: 14px;">
                <label style="font-weight: 600; font-size: 14px;"><i class="fas fa-shield-alt"></i> <?= lang('email_code') ?: '邮箱验证码' ?></label>
                <input type="text" id="resetCodeInput" class="form-control"
                       placeholder="<?= lang('enter_6digit_code') ?: '请输入6位验证码' ?>" maxlength="6" pattern="\d{6}"
                       autocomplete="one-time-code"
                       style="text-align: center; font-size: 20px; letter-spacing: 5px; border-radius: 8px;">
            </div>
            <button type="button" id="resetVerifyBtn" class="btn btn-brand btn-block" style="border-radius: 8px; padding: 12px; font-size: 15px; background: var(--brand-blue); color: #fff; border: none;">
                <i class="fas fa-check"></i> <?= lang('verify') ?: '验证' ?>
            </button>
            <div style="text-align: center; margin-top: 10px;">
                <button type="button" id="resetResendBtn" style="border: none; background: transparent; color: #666; font-size: 13px; cursor: pointer; text-decoration: underline;">
                    <?= lang('resend_code') ?: '没收到？重新发送' ?>
                </button>
            </div>
        </div>

        <!-- Step 3: Set new password -->
        <div id="resetStep3" style="display: none;">
            <div style="background: #f0f5ff; border: 1px solid #adc6ff; border-radius: 10px; padding: 12px; margin-bottom: 16px; text-align: center;">
                <div style="font-size: 13px; color: #888;"><?= lang('account') ?: '游戏账号' ?></div>
                <div style="font-weight: 700; color: #333; font-size: 18px;" id="resetAccountDisplay"></div>
            </div>
            <p style="text-align: center; color: #888; font-size: 14px; margin-bottom: 16px;"><?= lang('email_verified_set_password') ?: '邮箱验证通过，请设置新密码' ?></p>
            <div class="form-group" style="margin-bottom: 12px;">
                <label style="font-weight: 600; font-size: 14px;"><i class="fas fa-lock"></i> <?= lang('new_password') ?: '新密码' ?>（6-32位）</label>
                <div style="position: relative;">
                    <input type="password" id="resetNewPwd" class="form-control"
                           placeholder="<?= lang('enter_new_password') ?: '请输入新密码' ?>" minlength="6" maxlength="32" required
                           autocomplete="new-password"
                           style="border-radius: 8px; padding-right: 40px;">
                    <button type="button" onclick="toggleResetPwd('resetNewPwd', this)"
                            style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: none; background: transparent; color: #999; cursor: pointer;">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="password-strength" id="resetStrengthBar" style="height: 4px; border-radius: 2px; margin-top: 6px; background: #e0e0e0;"></div>
                <div class="strength-text" id="resetStrengthText" style="font-size: 12px; color: #999;"></div>
            </div>
            <div class="form-group" style="margin-bottom: 16px;">
                <label style="font-weight: 600; font-size: 14px;"><i class="fas fa-lock"></i> <?= lang('confirm_password') ?: '确认新密码' ?></label>
                <div style="position: relative;">
                    <input type="password" id="resetConfirmPwd" class="form-control"
                           placeholder="<?= lang('reenter_password') ?: '请再次输入新密码' ?>" minlength="6" maxlength="32" required
                           autocomplete="new-password"
                           style="border-radius: 8px; padding-right: 40px;">
                    <button type="button" onclick="toggleResetPwd('resetConfirmPwd', this)"
                            style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: none; background: transparent; color: #999; cursor: pointer;">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            <button type="button" id="resetSetPwdBtn" class="btn btn-brand btn-block" style="border-radius: 8px; padding: 12px; font-size: 15px; background: var(--brand-blue); color: #fff; border: none;">
                <i class="fas fa-check"></i> <?= lang('confirm_reset_password') ?: '确认重置密码' ?>
            </button>
        </div>

        <!-- Step 4: Success -->
        <div id="resetStep4" style="display: none; text-align: center; padding: 20px 0;">
            <div style="font-size: 56px; color: #22c55e; margin-bottom: 16px;">
                <i class="fas fa-check-circle"></i>
            </div>
            <h4 style="color: #22c55e; margin-bottom: 12px; font-weight: 600;"><?= lang('password_reset_success') ?: '密码重置成功！' ?></h4>
            <p style="color: #888; margin-bottom: 20px; font-size: 14px;">
                <?= lang('account') ?: '账号' ?> <strong id="resetSuccessAccount"></strong> <?= lang('password_reset_done') ?: '的密码已重置<br>请使用新密码登录游戏客户端' ?>
            </p>
            <button type="button" onclick="closeResetPasswordModal()" class="btn btn-brand" style="border-radius: 8px; padding: 10px 40px; background: var(--brand-blue); color: #fff; border: none;">
                <?= lang('ok') ?: '好的' ?>
            </button>
        </div>

        <!-- Error/info message area -->
        <div id="resetModalMsg" style="display: none; padding: 10px 14px; border-radius: 8px; font-size: 14px; margin-top: 12px;"></div>
    </div>
</div>

<?php require_once 'footer.php'; ?>