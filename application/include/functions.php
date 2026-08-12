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
 * Tries native SoapClient first, falls back to HTTP bridge if connection fails.
 *
 * @param string $command
 * @return bool True on success, false on failure
 */
function RemoteCommandWithSOAP($command)
{
    if (empty($command)) {
        return false;
    }

    // Try native SOAP first
    $result = tryNativeSOAP($command);
    if ($result !== null) {
        return $result;
    }

    // Fallback to HTTP bridge (for sandboxed environments)
    return tryBridgeSOAP($command);
}

/**
 * Try native SoapClient connection.
 *
 * @param string $command
 * @return bool|null True/false on result, null if connection failed
 */
function tryNativeSOAP($command)
{
    try {
        $conn = new SoapClient(null, [
            'location' => 'http://' . get_config('soap_host') . ':' . get_config('soap_port') . '/',
            'uri'      => get_config('soap_uri'),
            'style'    => constant(get_config('soap_style')),
            'login'    => get_config('soap_username'),
            'password' => get_config('soap_password'),
            'connection_timeout' => 5,
        ]);

        $result = $conn->executeCommand(new SoapParam($command, 'command'));
        unset($conn);

        if (get_config('debug_mode')) {
            error_log('[SOAP Native] Command: ' . $command . ' -> ' . ($result ? 'OK' : 'FAIL'));
        }

        return (bool)$result;
    } catch (SoapFault $e) {
        // Connection failed - will try bridge
        if (get_config('debug_mode')) {
            error_log('[SOAP Native] Failed: ' . $e->getMessage() . ' (trying bridge)');
        }
        return null;
    } catch (Exception $e) {
        if (get_config('debug_mode')) {
            error_log('[SOAP Native] Error: ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * Fallback to HTTP bridge for SOAP commands.
 *
 * @param string $command
 * @return bool True on success, false on failure
 */
function tryBridgeSOAP($command)
{
    $bridgeUrl = 'http://127.0.0.1:7999/';

    $data = json_encode(['command' => $command]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $bridgeUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode == 0 || !empty($error)) {
        if (get_config('debug_mode')) {
            error_log('[SOAP Bridge] Failed: ' . $error);
        }
        return false;
    }

    $result = json_decode($response, true);

    if (get_config('debug_mode')) {
        error_log('[SOAP Bridge] Command: ' . $command . ' -> ' . ($result['success'] ? 'OK' : 'FAIL: ' . ($result['message'] ?? 'unknown')));
    }

    return !empty($result['success']);
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
