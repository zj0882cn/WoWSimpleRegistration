<?php
// --- Basic Configuration ---
$config['baseurl'] = 'http://127.0.0.1:7880';
$config['page_title'] = 'WoW Server';
$config['language'] = 'chinese-simplified';
$config['supported_langs'] = [
    'chinese-simplified' => '简体中文',
    'english'            => 'English',
];

// --- Debug Mode ---
$config['debug_mode'] = true;

// --- Server Information ---
$config['realmlist'] = 'logon.testserver.com';
$config['patch_location'] = '';
$config['game_version'] = '3.3.5a (12340)';

// --- Client Download ---
$config['client_download_url'] = '';
$config['client_download_baidu'] = false;
$config['client_download_baidu_url'] = '';
$config['client_download_baidu_code'] = '';

$config['expansion'] = '2';

// --- Server Core Type ---
$config['server_core'] = 1;

// --- Feature Toggles ---
$config['disable_top_players'] = true;
$config['disable_online_players'] = true;
$config['disable_changepassword'] = false;

// --- Template ---
$config['template'] = 'light';

// --- SOAP Settings (AzerothCore server) ---
$config['soap_for_register']  = true;
$config['soap_host']     = '127.0.0.1';
$config['soap_port']     = '7878';
$config['soap_uri']      = 'urn:AC';
$config['soap_style']    = 'SOAP_RPC';
$config['soap_username'] = 'admin';
$config['soap_password'] = 'abcd12';

// SOAP command templates
$config['soap_ca_command']  = 'account create {USERNAME} {PASSWORD}';
$config['soap_asa_command'] = 'account set addon {USERNAME} {EXPANSION}';

// --- Email Auth Configuration ---
$config['email_enabled'] = true;           // 邮箱认证总开关
// Provider: 'smtp' (recommended) | 'mail' (PHP native mail())
$config['email_provider'] = 'smtp';

// --- Email Feature Toggles (细粒度开关) ---
$config['email_login_enabled']    = true;  // 邮箱验证码登录/注册
$config['email_password_login']   = true;  // 密码登录（已有账号）
$config['email_register_enabled'] = true;  // 新用户注册
$config['email_reset_enabled']    = true;  // 邮箱重置密码
$config['email_bind_enabled']     = true;  // 绑定邮箱到已有账号
$config['email_code_ttl'] = 600;            // 验证码有效期（秒）
$config['email_resend_interval'] = 60;      // 重发间隔（秒）
$config['email_from_name'] = 'WoW Server';  // 发件人名称

// --- SMTP Configuration ---
// Supported: QQ Mail, 163 Mail, Aliyun, Tencent Cloud SES, SendCloud, Gmail, etc.
// For Gmail: use smtp.gmail.com:587 with App Password (enable 2FA first)
// For QQ:    use smtp.qq.com:465 with Authorization Code (开启SMTP获取授权码)
// For 163:   use smtp.163.com:465 with Authorization Code
$config['smtp_host']   = '';    // e.g. 'smtp.qq.com'
$config['smtp_port']   = 587;   // 465 (SSL) or 587 (TLS) or 25 (plain)
$config['smtp_auth']   = true;  // true = 需要用户名密码认证
$config['smtp_user']   = '';    // SMTP 账号 (完整邮箱地址)
$config['smtp_pass']   = '';    // SMTP 授权码 (非登录密码)
$config['smtp_secure'] = 'tls'; // 'ssl' | 'tls' | '' (留空不加密)
$config['smtp_mail']   = '';    // 发件邮箱地址, e.g. 'noreply@yourdomain.com'

// --- Mobile Auth Configuration (legacy) ---
$config['mobile_enabled'] = false;
$config['sms_provider'] = 'demo';
$config['numberauth_provider'] = 'demo';

// --- WeChat (disabled for mobile mode) ---
$config['wechat_enabled'] = false;
$config['wechat_appid'] = '';
$config['wechat_appsecret'] = '';

// --- Captcha (disabled) ---
$config['captcha_type']   = 4;
$config['captcha_key']    = '';
$config['captcha_secret'] = '';
$config['captcha_language'] = 'en';

// --- Vote System (disabled) ---
$config['vote_system'] = false;
$config['vote_sites']  = [];

// --- 2FA (disabled) ---
$config['2fa_support'] = false;

// --- Script Version ---
$config['script_version'] = '2.1.0';