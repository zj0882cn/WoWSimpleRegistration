<?php
/**
 * WeChat OAuth Configuration Snippet (SOAP-Only Mode)
 *
 * Append these settings to your existing config.php (or config.php.sample)
 * to enable WeChat QR code login on your registration website.
 *
 * SOAP-ONLY MODE:
 *   - No MySQL database connection is needed for the WeChat module
 *   - All account operations (create, set expansion, set email, set password)
 *     are performed via SOAP commands to the worldserver
 *   - WeChat-to-account bindings are stored in a local JSON file
 *     (application/data/wechat_bindings.json)
 *
 * Prerequisites:
 *   1. Register at https://open.weixin.qq.com/ (WeChat Open Platform)
 *   2. Create a "Website Application" (网站应用) and pass review
 *   3. Obtain the AppID and AppSecret
 *   4. Set the authorization callback domain to your website domain
 *   5. Ensure the PHP cURL extension is enabled
 *   6. Ensure SOAP is enabled in worldserver.conf (SOAP.Enabled = 1)
 *   7. Ensure the application/data/ directory is writable by the web server
 **/

// --- WeChat Authentication ---
// Enable or disable WeChat login on the registration website.
$config['wechat_enabled'] = false;

// WeChat Open Platform AppID (from your approved Website Application).
$config['wechat_appid'] = 'YOUR_WECHAT_APPID';

// WeChat Open Platform AppSecret.
$config['wechat_appsecret'] = 'YOUR_WECHAT_APPSECRET';

// --- SOAP Settings (required for account operations) ---
// These mirror the existing soap_* settings in config.php.
// If you already have these set, you can remove the lines below.
$config['soap_for_register'] = true;   // Must be true for WeChat SOAP mode
$config['soap_host']    = '127.0.0.1';
$config['soap_port']    = '7878';
$config['soap_uri']     = 'urn:MaNGOS';
$config['soap_style']   = 'SOAP_RPC';
$config['soap_username'] = 'admin_soap';
$config['soap_password'] = 'admin_soap';

// SOAP command templates for account operations.
$config['soap_ca_command']  = 'account create {USERNAME} {PASSWORD}';  // Create account
$config['soap_asa_command'] = 'account set addon {USERNAME} {EXPANSION}'; // Set expansion

// --- Optional WeChat settings ---

// Allow users to auto-create game accounts via WeChat without entering
// a username/password. The system will generate random credentials and
// display them once on the binding success page.
$config['wechat_auto_register'] = true;

// Allow users to bind WeChat to an existing game account by providing
// their username and password.
$config['wechat_bind_existing'] = true;

// After a WeChat user is logged in, show their WeChat nickname and avatar
// in the website header.
$config['wechat_show_profile'] = true;

// The language for the WeChat QR code login page.
// Options: 'cn' (Simplified Chinese), 'en' (English). Default: 'cn'.
$config['wechat_lang'] = 'cn';

// Style for the embedded WeChat QR code.
// Options: 'black' (default, dark text on light bg), 'white' (light text on dark bg).
$config['wechat_qr_style'] = 'black';

// Enable the WeChat fast login feature (requires WeChat desktop client
// 3.9.11+ on Windows or 4.0.0+ on Mac). Set to 0 to disable.
$config['wechat_fast_login'] = 1;

// --- Mock / Testing Mode (DO NOT use in production) ---
// Override the WeChat API base URLs to point to a local mock server.
// This allows full end-to-end testing WITHOUT real WeChat credentials.
// Leave these commented out (or empty) for production use.
// $config['wechat_open_baseurl'] = 'http://127.0.0.1:9190';  // open.weixin.qq.com
// $config['wechat_api_baseurl']  = 'http://127.0.0.1:9190';  // api.weixin.qq.com
