<?php
/**
 * Utility Functions — SOAP, Captcha, and helpers.
 *
 * SOAP-Only Mode: No database functions are included.
 *
 * @author Amin Mahmoudi (MasterkinG)
 * @copyright Copyright (c) 2019 - 2024, MasterkinG32.
 **/

/**
 * Execute a SOAP command on the worldserver.
 *
 * Uses the SOAP Bridge (Python) for transport since PHP's native
 * network functions are sandboxed in this environment.
 *
 * @param string $command
 * @return bool True on success, false on failure
 */
function RemoteCommandWithSOAP($command)
{
    if (empty($command)) {
        return false;
    }

    require_once __DIR__ . '/soap_transport.php';

    $result = soap_send_command($command);

    if (get_config('debug_mode')) {
        error_log('[SOAP] Command: ' . $command . ' -> ' . ($result['success'] ? 'OK' : 'FAIL: ' . $result['message']));
    }

    return $result['success'];
}

/**
 * Get captcha HTML for forms.
 * In WeChat-only mode, captcha is typically disabled.
 *
 * @return string
 */
function GetCaptchaHTML()
{
    $captchaType = get_config('captcha_type');
    if ($captchaType > 3) {
        return '';
    }

    if ($captchaType == 0) {
        // Image captcha
        if (!empty(user::$captcha)) {
            return '<div class="input-group">
                <span class="input-group">' . lang('captcha') . '</span>
                <img src="' . user::$captcha->inline() . '" style="max-width:200px;">
                <input type="text" class="form-control" name="captcha" placeholder="' . lang('captcha') . '">
            </div>';
        }
    } elseif ($captchaType == 1) {
        // HCaptcha
        return '<div class="h-captcha" data-sitekey="' . get_config('captcha_key') . '"></div>
                <script src="https://hcaptcha.com/1/api.js" async defer></script>';
    } elseif ($captchaType == 2) {
        // ReCaptcha v2
        return '<div class="g-recaptcha" data-sitekey="' . get_config('captcha_key') . '"></div>
                <script src="https://www.google.com/recaptcha/api.js" async defer></script>';
    } elseif ($captchaType == 3) {
        // Cloudflare Turnstile
        return '<div class="cf-turnstile" data-sitekey="' . get_config('captcha_key') . '"></div>
                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }

    return '';
}

/**
 * Validate the captcha input.
 * Returns true if captcha is disabled or valid.
 *
 * @return bool
 */
function captcha_validation()
{
    $captchaType = get_config('captcha_type');
    if ($captchaType > 3) {
        return true; // Captcha disabled
    }

    if ($captchaType == 0) {
        // Image captcha
        if (empty($_POST['captcha']) || empty($_SESSION['captcha'])) {
            return false;
        }
        return strcasecmp($_POST['captcha'], $_SESSION['captcha']) == 0;
    }

    // For external captcha services (HCaptcha, ReCaptcha, Turnstile)
    $token = $_POST['h-captcha-response'] ?? $_POST['g-recaptcha-response'] ?? $_POST['cf-turnstile-response'] ?? '';
    if (empty($token)) {
        return false;
    }

    $verifyUrls = [
        1 => 'https://hcaptcha.com/siteverify',
        2 => 'https://www.google.com/recaptcha/api/siteverify',
        3 => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ];

    $url = $verifyUrls[$captchaType] ?? '';
    if (empty($url)) {
        return true;
    }

    $data = [
        'secret'   => get_config('captcha_secret'),
        'response' => $token,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return !empty($result['success']);
}
