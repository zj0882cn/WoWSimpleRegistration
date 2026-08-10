<?php
/**
 * Main Template — Mobile Login Mode (Light Theme)
 *
 * Homepage shows:
 *   1. Server status (online players, uptime, etc.) via SOAP
 *   2. Phone number + SMS code login form (or account info if logged in)
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

                    <?php if ($mbLoggedIn && $mbUser): ?>
                        <!-- ===== Logged In: Show Account Info ===== -->
                        <div class="mobile-login-section" style="padding: 20px;">
                            <h3>
                                <i class="fas fa-check-circle" style="color: var(--brand-blue);"></i>
                                <?= lang('welcome_back') ?: '欢迎回来' ?>
                            </h3>
                            <div class="account-info-card">
                                <div class="row">
                                    <div class="col-md-6">
                                        <span class="label d-block"><?= lang('account') ?: '游戏账号' ?></span>
                                        <span class="value"><?= htmlspecialchars($mbUser['username'] ?? '') ?></span>
                                    </div>
                                    <div class="col-md-6 text-md-right">
                                        <span class="label d-block"><?= lang('phone') ?: '手机号' ?></span>
                                        <span class="value" style="font-size: 14px;"><?= htmlspecialchars(MobileAuth::maskPhone($mbUser['phone'] ?? '')) ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="text-center" style="margin-top: 20px;">
                                <a href="<?= get_config('baseurl') ?>/reset_password.php"
                                   class="btn btn-warning" style="padding: 10px 30px;">
                                    <i class="fas fa-key"></i>
                                    <?= lang('reset_password') ?: '重置密码' ?>
                                </a>
                                <a href="<?= get_config('baseurl') ?>?mobile_logout=1"
                                   class="btn btn-outline-secondary" style="padding: 10px 30px; margin-top: 10px;">
                                    <i class="fas fa-sign-out-alt"></i>
                                    <?= lang('logout') ?: '退出登录' ?>
                                </a>
                            </div>

                            <div class="soap-notice">
                                <i class="fas fa-info-circle"></i>
                                <?= lang('reset_password_hint') ?: '重置密码将生成一个新的随机密码，原密码将失效。' ?>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- ===== Not Logged In: Show Phone Login Form ===== -->
                        <div class="mobile-login-section">
                            <h3>
                                <i class="fas fa-mobile-alt" style="color: var(--brand-blue);"></i>
                                <?= lang('mobile_login_title') ?: '手机号登录' ?>
                            </h3>
                            <p>
                                <?= lang('mobile_login_hint') ?: '输入手机号获取验证码，首次登录将自动创建游戏账号。' ?>
                            </p>

                            <form id="mobileLoginForm" action="<?= get_config('baseurl') ?>/sms_verify.php" method="POST" style="max-width: 360px; margin: 0 auto;">
                                <!-- Phone number input -->
                                <div class="input-group" style="margin-bottom: 15px;">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    </div>
                                    <input type="tel" id="phone" name="phone" class="form-control"
                                           placeholder="<?= lang('enter_phone') ?: '请输入手机号' ?>"
                                           maxlength="11" pattern="1[3-9]\d{9}" required
                                           autocomplete="tel">
                                </div>

                                <!-- Verification code input + send button -->
                                <div class="input-group" style="margin-bottom: 15px;">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fas fa-shield-alt"></i></span>
                                    </div>
                                    <input type="text" id="smsCode" name="code" class="form-control"
                                           placeholder="<?= lang('enter_code') ?: '验证码' ?>"
                                           maxlength="6" pattern="\d{6}" required
                                           autocomplete="one-time-code">
                                    <div class="input-group-append">
                                        <button type="button" id="sendCodeBtn" class="btn btn-outline-primary">
                                            <?= lang('send_code') ?: '获取验证码' ?>
                                        </button>
                                    </div>
                                </div>

                                <!-- Submit button -->
                                <button type="submit" class="btn btn-primary btn-block" style="padding: 12px; font-size: 16px;">
                                    <i class="fas fa-sign-in-alt"></i>
                                    <?= lang('login_register') ?: '登录 / 注册' ?>
                                </button>
                            </form>

                            <!-- Demo mode notice -->
                            <?php if (get_config('sms_provider') === 'demo'): ?>
                            <div class="demo-notice" style="margin-top: 15px;">
                                <i class="fas fa-info-circle"></i>
                                <span id="demoCodeDisplay"></span>
                            </div>
                            <?php endif; ?>

                            <!-- Error message display -->
                            <div id="smsError" class="alert alert-danger" style="display: none; margin-top: 15px; font-size: 14px;">
                            </div>

                            <div class="soap-notice" style="margin-top: 20px;">
                                <i class="fas fa-shield-alt"></i>
                                <?= lang('security_notice') ?: '本站仅支持手机验证码登录，不支持密码注册。账号通过 SOAP 安全创建。' ?>
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
                            <li><?= lang('howto_step2_mobile') ?: '输入手机号获取验证码，登录后将自动创建游戏账号。' ?></li>
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
$(function() {
    var sending = false;
    var countdown = 0;
    var countdownTimer = null;

    $('#sendCodeBtn').on('click', function() {
        var phone = $('#phone').val().trim();
        var btn = $(this);

        // Validate phone
        if (!/^1[3-9]\d{9}$/.test(phone)) {
            showSMSError('请输入正确的手机号');
            return;
        }

        if (sending || countdown > 0) return;

        sending = true;
        btn.prop('disabled', true);
        showSMSError('');

        $.ajax({
            url: '<?= get_config("baseurl") ?>/sms_send.php',
            type: 'POST',
            dataType: 'json',
            data: { phone: phone },
            success: function(resp) {
                sending = false;

                if (resp.success) {
                    // Start countdown
                    countdown = 60;
                    updateBtn();

                    <?php if (get_config('sms_provider') === 'demo'): ?>
                    // Demo mode: show the code
                    if (resp.code) {
                        $('#demoCodeDisplay').html(
                            '测试模式：验证码为 <strong style="font-size:18px;color:var(--brand-blue);">' +
                            resp.code + '</strong>（请勿在实际环境中使用）'
                        );
                        $('#smsCode').val(resp.code);
                    }
                    <?php endif; ?>
                } else {
                    btn.prop('disabled', false);

                    if (resp.message === 'rate_limited' && resp.wait) {
                        countdown = resp.wait;
                        updateBtn();
                    } else {
                        var errMsgs = {
                            'invalid_phone': '手机号格式不正确',
                            'rate_limited': '发送过于频繁，请稍后再试',
                            'mobile_auth_disabled': '手机登录功能未启用',
                            'aliyun_config_incomplete': '阿里云短信配置不完整',
                            'tencent_config_incomplete': '腾讯云短信配置不完整'
                        };
                        showSMSError(errMsgs[resp.message] || '验证码发送失败，请重试');
                    }
                }
            },
            error: function() {
                sending = false;
                btn.prop('disabled', false);
                showSMSError('网络错误，请重试');
            }
        });
    });

    function updateBtn() {
        var btn = $('#sendCodeBtn');
        if (countdown > 0) {
            btn.text(countdown + 's 后重发');
            btn.prop('disabled', true);
            countdown--;
            countdownTimer = setTimeout(updateBtn, 1000);
        } else {
            clearTimeout(countdownTimer);
            btn.text('获取验证码');
            btn.prop('disabled', false);
        }
    }

    function showSMSError(msg) {
        var el = $('#smsError');
        if (msg) {
            el.html('<i class="fas fa-exclamation-circle"></i> ' + msg).show();
        } else {
            el.hide();
        }
    }

    // Auto-clear error on input
    $('#phone, #smsCode').on('input', function() {
        showSMSError('');
    });
});
</script>

<?php require_once 'footer.php'; ?>
