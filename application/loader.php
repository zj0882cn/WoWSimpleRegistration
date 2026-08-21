<?php
/**
 * Application Loader — SOAP-Only Mode (No Database)
 *
 * Loads configuration, helper functions, and the WeChat auth module.
 * Database connection is intentionally skipped — all account operations
 * go through SOAP commands to the worldserver.
 *
 * @author Amin Mahmoudi (MasterkinG)
 * @copyright Copyright (c) 2019 - 2024, MasterkinG32.
 **/

use voku\helper\AntiXSS;

header('X-Powered-Framework: MasterkinG-Framework');
header('X-Powered-CMS: MasterkinG-CMS');

ob_start();
session_start();

define('base_path', str_replace('application/loader.php', '', str_replace('\\', '/', __FILE__)));
define('app_path', str_replace('application/loader.php', '', str_replace('\\', '/', __FILE__)) . 'application/');

// Load Composer autoloader if available
if (file_exists(app_path . 'vendor/autoload.php')) {
    require app_path . 'vendor/autoload.php';
}

// Load configuration
require_once app_path . 'config/config.php';

// Load core helpers
require_once app_path . 'include/core_handler.php';
require_once app_path . 'include/functions.php';

// Load Email authentication module (primary auth)
require_once app_path . 'include/email.php';

// Load Mobile (SMS) authentication module (legacy, kept for compatibility)
require_once app_path . 'include/mobile.php';

// Load user class (password reset only — no register, no changepass)
require_once app_path . 'include/user.php';

// Load language file
$languageName = strtolower(get_config('language'));
$languageFile = app_path . 'language/' . $languageName . '.php';
$language = [];

if (!preg_match('/^([a-z-]+)$/i', $languageName) || !file_exists($languageFile)) {
    // Fall back to English if the configured language file doesn't exist
    $languageFile = app_path . 'language/english.php';
    if (!file_exists($languageFile)) {
        $language = [];
    }
}

if (!empty($_COOKIE['website_lang']) && file_exists(app_path . 'language/' . strtolower($_COOKIE['website_lang']) . '.php')) {
    $langFile = app_path . 'language/' . strtolower($_COOKIE['website_lang']) . '.php';
    $loaded = include $langFile;
    $language = is_array($loaded) ? $loaded : [];
} elseif (file_exists($languageFile)) {
    $loaded = include $languageFile;
    $language = is_array($loaded) ? $loaded : [];
}

// Set up AntiXSS
$antiXss = new AntiXSS();

// Error reporting
if (get_config('debug_mode')) {
    error_reporting(-1);
    ini_set('display_errors', 1);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_DEPRECATED);
}

// Validate config version
if (empty(get_config('script_version')) || version_compare(get_config('script_version'), '2.1.0', '<')) {
    exit('Use the latest version of config.php file.');
}

// Check PHP extensions
if (get_config('soap_for_register') && !extension_loaded('soap')) {
    exit('Please enable SOAP in your php.ini');
}

if (get_config('captcha_type') == 0 && !extension_loaded('gd')) {
    // Captcha is optional in WeChat-only mode — don't block if disabled
    if (get_config('captcha_type') <= 3) {
        // Captcha enabled but GD missing — disable silently
        $config['captcha_type'] = 4;
    }
}

// NOTE: No database connection — SOAP-only mode
// The original database::db_connect() call has been removed.
