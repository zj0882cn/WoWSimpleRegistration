<?php
/**
 * User Class — Mobile Login Mode (SOAP)
 *
 * This is a stripped-down version of the original user.php.
 * The following features have been REMOVED:
 *   - normal_register() / bnet_register()  — No password-based registration
 *   - normal_changepass() / bnet_changepass() — No change password feature
 *
 * The following features are KEPT:
 *   - lang_cookie_changer() — Language switching
 *   - post_handler() — Minimal POST handler (language change + captcha)
 *
 * Password reset is handled by MobileAuth::resetPassword() via SOAP,
 * not by this class.
 *
 * @author Amin Mahmoudi (MasterkinG)
 * @copyright Copyright (c) 2019 - 2024, MasterkinG32.
 **/

class user
{
    public static $captcha;

    /**
     * Handle POST requests.
     *
     * In WeChat-only mode, the only POST actions handled here are:
     *   - Language change
     *   - Captcha regeneration (if Gregwar\Captcha is available)
     *
     * Registration, change password, and restore password are NOT handled
     * here. Password reset is handled by reset_password.php via SOAP.
     */
    public static function post_handler()
    {
        // Handle language change
        if (!empty($_POST['langchangever'])) {
            self::lang_cookie_changer($_POST['langchange']);
        }

        // Build captcha if image captcha is enabled and the library is available
        if (empty(get_config('captcha_type')) && class_exists('Gregwar\Captcha\CaptchaBuilder')) {
            unset($_SESSION['captcha']);
            self::$captcha = new \Gregwar\Captcha\CaptchaBuilder;
            self::$captcha->build();
            $_SESSION['captcha'] = self::$captcha->getPhrase();
        }
    }

    /**
     * Language Changer — set a cookie with the selected language.
     *
     * @param string $getlang
     */
    public static function lang_cookie_changer($getlang)
    {
        $supported_langs = get_config('supported_langs');
        if (!empty($supported_langs) && !empty($supported_langs[$getlang])) {
            setcookie('website_lang', $getlang, time() + (86400 * 30), '/');
            header('location: ' . get_config('baseurl'));
            exit();
        }
    }
}
