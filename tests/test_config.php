<?php
/**
 * Test Configuration — For Local Testing Only
 *
 * Copy this file to application/config/config.php when testing.
 * It points to the mock SOAP server and uses test WeChat credentials.
 *
 * IMPORTANT: Do NOT use this in production!
 *
 * @author AzerothCore Community
 **/

// --- Basic Configuration ---
$config['baseurl'] = 'http://localhost:8080';
$config['page_title'] = 'WoW Server (测试模式)';
$config['language'] = 'chinese-simplified';
$config['supported_langs'] = [
    'chinese-simplified' => '简体中文',
    'english'            => 'English',
];

// --- Debug Mode (enable for testing) ---
$config['debug_mode'] = true;

// --- Server Information ---
$config['realmlist'] = 'logon.testserver.com';
$config['patch_location'] = '';
$config['game_version'] = '3.3.5a (12340)';

// --- Client Download ---
$config['client_download_url'] = 'https://www.chromiecraft.com/fr/downloads/';
$config['client_download_baidu'] = true;
$config['client_download_baidu_url'] = 'https://pan.baidu.com/s/1xr-u8T3Qh909AUOxzij-tA';
$config['client_download_baidu_code'] = 'd7ai';

$config['expansion'] = '2';

// --- Server Core Type ---
$config['server_core'] = 1; // AzerothCore

// --- Battle.net / SRP6 ---
$config['battlenet_support'] = false;
$config['srp6_support'] = false;
$config['srp6_version'] = 0;

// --- Feature Toggles ---
$config['disable_top_players'] = true;
$config['disable_online_players'] = true;
$config['disable_changepassword'] = true;  // Change password permanently disabled

// --- Template ---
$config['template'] = 'light';

// --- SOAP Settings (pointing to mock server) ---
$config['soap_for_register']  = true;
$config['soap_host']     = '127.0.0.1';
$config['soap_port']     = '7878';        // Mock server port
$config['soap_uri']      = 'urn:MaNGOS';
$config['soap_style']    = 'SOAP_RPC';
$config['soap_username'] = 'admin';       // Mock server doesn't check credentials
$config['soap_password'] = 'admin';

// SOAP command templates
$config['soap_ca_command']  = 'account create {USERNAME} {PASSWORD}';
$config['soap_asa_command'] = 'account set addon {USERNAME} {EXPANSION}';

// --- WeChat Authentication (Mock Mode) ---
// WeChat does NOT provide test AppID/AppSecret for website scan login.
// Instead, we use a local mock OAuth server that simulates the entire flow.
//
// The mock server runs on port 9090 and simulates:
//   - /connect/qrconnect          (QR code login page)
//   - /sns/oauth2/access_token     (code -> access_token exchange)
//   - /sns/userinfo                (user profile)
//
// To use REAL WeChat:
//   1. Register at https://open.weixin.qq.com/
//   2. Create a Website Application and pass review
//   3. Remove the two wechat_*_baseurl lines below
//   4. Replace wechat_appid / wechat_appsecret with your real credentials
$config['wechat_enabled']       = true;
$config['wechat_appid']         = 'mock_appid';      // Any value works with mock server
$config['wechat_appsecret']     = 'mock_appsecret';   // Any value works with mock server

// Point WeChat APIs to the local mock server (port 9190)
$config['wechat_open_baseurl']  = 'http://127.0.0.1:9190';  // open.weixin.qq.com
$config['wechat_api_baseurl']   = 'http://127.0.0.1:9190';  // api.weixin.qq.com

$config['wechat_auto_register'] = true;
$config['wechat_bind_existing'] = true;
$config['wechat_show_profile']  = true;
$config['wechat_lang']          = 'cn';
$config['wechat_qr_style']      = 'black';
$config['wechat_fast_login']    = 1;

// --- Captcha (disabled for easier testing) ---
$config['captcha_type']   = 4;  // >3 = disabled
$config['captcha_key']    = '';
$config['captcha_secret'] = '';
$config['captcha_language'] = 'en';

// --- SMTP (not used in SOAP-only mode) ---
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
$config['script_version'] = '2.0.2';
