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

// --- Mobile Auth Configuration ---
$config['mobile_enabled'] = true;
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

// --- SMTP (not used) ---
$config['smtp_host']   = '';
$config['smtp_port']   = 587;
$config['smtp_auth']   = true;
$config['smtp_user']   = '';
$config['smtp_pass']   = '';
$config['smtp_secure'] = 'tls';
$config['smtp_mail']   = '';

// --- Vote System (disabled) ---
$config['vote_system'] = false;
$config['vote_sites']  = [];

// --- 2FA (disabled) ---
$config['2fa_support'] = false;

// --- Script Version ---
$config['script_version'] = '2.1.0';