<?php
/**
 * Mobile Phone SMS Authentication Module (SOAP-Only Mode)
 *
 * Replaces WeChatAuth with phone number + SMS verification code login.
 * All game account operations (create / set expansion / set password)
 * are performed via SOAP commands to the worldserver.
 *
 * Phone-to-account bindings are persisted in a local JSON file.
 *
 * SMS Provider Support:
 *   - "demo":    Code is returned in the API response (for testing)
 *   - "aliyun":  Aliyun SMS API (阿里云短信)
 *   - "tencent": Tencent Cloud SMS (腾讯云短信)
 *
 * @author AzerothCore Community
 **/

class MobileAuth
{
    /** @var string Path to the JSON file that stores phone bindings */
    private static $dataFile;

    /** @var string Path to the JSON file that stores SMS codes (with expiry) */
    private static $codeFile;

    /** @var int SMS code validity in seconds (5 minutes) */
    private static $codeTTL = 300;

    /** @var int Minimum interval between SMS sends (60 seconds) */
    private static $resendInterval = 60;

    /** @var int Maximum verification attempts per code */
    private static $maxAttempts = 5;

    // -----------------------------------------------------------------------
    //  Initialization
    // -----------------------------------------------------------------------

    /**
     * Initialize file paths.
     */
    public static function init()
    {
        if (!get_config('mobile_enabled')) {
            return false;
        }
        static::$dataFile = __DIR__ . '/../data/mobile_bindings.json';
        static::$codeFile = __DIR__ . '/../data/sms_codes.json';
        return true;
    }

    // -----------------------------------------------------------------------
    //  Phone number validation
    // -----------------------------------------------------------------------

    /**
     * Validate a Chinese mobile phone number.
     *
     * @param string $phone
     * @return bool
     */
    public static function isValidPhone($phone)
    {
        return preg_match('/^1[3-9]\d{9}$/', $phone) > 0;
    }

    /**
     * Mask a phone number for display (e.g. 138****1234).
     *
     * @param string $phone
     * @return string
     */
    public static function maskPhone($phone)
    {
        if (strlen($phone) < 7) {
            return $phone;
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    // -----------------------------------------------------------------------
    //  SMS code generation & storage
    // -----------------------------------------------------------------------

    /**
     * Generate a 6-digit verification code.
     *
     * @return string
     */
    private static function generateCode()
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Load all SMS codes from the JSON file.
     *
     * @return array
     */
    private static function loadCodes()
    {
        if (!file_exists(static::$codeFile)) {
            return [];
        }
        $content = file_get_contents(static::$codeFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save SMS codes to the JSON file.
     *
     * @param array $codes
     * @return bool
     */
    private static function saveCodes($codes)
    {
        $dir = dirname(static::$codeFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents(
            static::$codeFile,
            json_encode($codes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        ) !== false;
    }

    /**
     * Clean up expired codes from storage.
     *
     * @return array Cleaned codes array
     */
    private static function cleanExpiredCodes()
    {
        $codes = static::loadCodes();
        $now = time();
        $codes = array_filter($codes, function ($entry) use ($now) {
            return ($now - $entry['created_at']) < static::$codeTTL;
        });
        $codes = array_values($codes);
        static::saveCodes($codes);
        return $codes;
    }

    /**
     * Send an SMS verification code to a phone number.
     *
     * @param string $phone
     * @return array ['success' => bool, 'message' => string, 'code' => string (demo only)]
     */
    public static function sendCode($phone)
    {
        if (!static::init()) {
            return ['success' => false, 'message' => 'mobile_auth_disabled'];
        }

        if (!static::isValidPhone($phone)) {
            return ['success' => false, 'message' => 'invalid_phone'];
        }

        // Clean expired codes first
        $codes = static::cleanExpiredCodes();

        // Check resend interval (rate limiting)
        foreach ($codes as $entry) {
            if ($entry['phone'] === $phone) {
                $elapsed = time() - $entry['created_at'];
                if ($elapsed < static::$resendInterval) {
                    $wait = static::$resendInterval - $elapsed;
                    return ['success' => false, 'message' => 'rate_limited', 'wait' => $wait];
                }
                // Remove old code for this phone
                break;
            }
        }

        // Remove any existing code for this phone
        $codes = array_filter($codes, function ($entry) use ($phone) {
            return $entry['phone'] !== $phone;
        });

        // Generate new code
        $code = static::generateCode();

        // Store the code
        $codes[] = [
            'phone'       => $phone,
            'code'        => $code,
            'created_at'  => time(),
            'attempts'    => 0,
            'verified'    => false,
        ];
        static::saveCodes(array_values($codes));

        // Send via the configured provider
        $provider = get_config('sms_provider') ?: 'demo';
        $result = static::sendSMS($provider, $phone, $code);

        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'sms_send_failed'];
        }

        $response = ['success' => true, 'message' => 'code_sent'];

        // In demo mode, return the code in the response for testing
        if ($provider === 'demo') {
            $response['code'] = $code;
        }

        return $response;
    }

    /**
     * Verify an SMS code for a phone number.
     *
     * @param string $phone
     * @param string $code
     * @return array ['success' => bool, 'message' => string]
     */
    public static function verifyCode($phone, $code)
    {
        if (!static::init()) {
            return ['success' => false, 'message' => 'mobile_auth_disabled'];
        }

        $codes = static::cleanExpiredCodes();

        foreach ($codes as $key => $entry) {
            if ($entry['phone'] === $phone) {
                // Check attempt limit
                if ($entry['attempts'] >= static::$maxAttempts) {
                    // Remove the code
                    unset($codes[$key]);
                    static::saveCodes(array_values($codes));
                    return ['success' => false, 'message' => 'max_attempts_exceeded'];
                }

                // Increment attempts
                $codes[$key]['attempts']++;
                static::saveCodes(array_values($codes));

                if ($entry['code'] === $code) {
                    // Mark as verified and remove
                    unset($codes[$key]);
                    static::saveCodes(array_values($codes));
                    return ['success' => true, 'message' => 'verified'];
                }

                return ['success' => false, 'message' => 'wrong_code'];
            }
        }

        return ['success' => false, 'message' => 'code_not_found'];
    }

    // -----------------------------------------------------------------------
    //  SMS providers
    // -----------------------------------------------------------------------

    /**
     * Send an SMS via the configured provider.
     *
     * @param string $provider 'demo' | 'aliyun' | 'tencent'
     * @param string $phone
     * @param string $code
     * @return array ['success' => bool, 'message' => string]
     */
    private static function sendSMS($provider, $phone, $code)
    {
        switch ($provider) {
            case 'demo':
                // Demo mode: don't actually send, just log it
                if (get_config('debug_mode')) {
                    error_log("[MobileAuth] Demo SMS to {$phone}: code={$code}");
                }
                return ['success' => true, 'message' => 'demo_code_generated'];

            case 'aliyun':
                return static::sendAliyunSMS($phone, $code);

            case 'tencent':
                return static::sendTencentSMS($phone, $code);

            default:
                return ['success' => false, 'message' => 'unknown_sms_provider'];
        }
    }

    /**
     * Send SMS via Aliyun (阿里云短信).
     *
     * Requires config:
     *   sms_aliyun_access_key  — Access Key ID
     *   sms_aliyun_access_secret — Access Key Secret
     *   sms_aliyun_sign_name   — 短信签名
     *   sms_aliyun_template_code — 模板Code
     *
     * @param string $phone
     * @param string $code
     * @return array
     */
    private static function sendAliyunSMS($phone, $code)
    {
        $accessKey    = get_config('sms_aliyun_access_key');
        $accessSecret = get_config('sms_aliyun_access_secret');
        $signName     = get_config('sms_aliyun_sign_name');
        $templateCode = get_config('sms_aliyun_template_code');

        if (empty($accessKey) || empty($accessSecret) || empty($signName) || empty($templateCode)) {
            return ['success' => false, 'message' => 'aliyun_config_incomplete'];
        }

        // Aliyun SMS API endpoint
        $host = 'dysmsapi.aliyuncs.com';
        $params = [
            'PhoneNumbers'  => $phone,
            'SignName'       => $signName,
            'TemplateCode'   => $templateCode,
            'TemplateParam'  => json_encode(['code' => $code]),
            'AccessKeyId'    => $accessKey,
            'Format'         => 'JSON',
            'Version'        => '2017-05-25',
            'SignatureMethod'=> 'HMAC-SHA1',
            'Timestamp'      => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => uniqid(),
            'Action'         => 'SendSms',
            'RegionId'       => 'cn-hangzhou',
        ];

        // Compute signature
        ksort($params);
        $sortedQuery = '';
        foreach ($params as $k => $v) {
            $sortedQuery .= '&' . static::aliyunEncode($k) . '=' . static::aliyunEncode($v);
        }
        $sortedQuery = ltrim($sortedQuery, '&');
        $stringToSign = 'GET&' . static::aliyunEncode('/') . '&' . static::aliyunEncode($sortedQuery);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessSecret . '&', true));
        $params['Signature'] = $signature;

        $url = 'https://' . $host . '/?' . http_build_query($params);
        $response = static::httpGet($url);

        if ($response === false) {
            return ['success' => false, 'message' => 'aliyun_request_failed'];
        }

        $data = json_decode($response, true);
        if (isset($data['Code']) && $data['Code'] === 'OK') {
            return ['success' => true, 'message' => 'aliyun_sms_sent'];
        }

        return ['success' => false, 'message' => $data['Message'] ?? 'aliyun_sms_failed'];
    }

    /**
     * URL-encode a parameter for Aliyun signature (RFC 3986).
     *
     * @param string $str
     * @return string
     */
    private static function aliyunEncode($str)
    {
        $encoded = urlencode($str);
        $encoded = str_replace(['+', '*'], ['%20', '%2A'], $encoded);
        $encoded = str_replace('%7E', '~', $encoded);
        return $encoded;
    }

    /**
     * Send SMS via Tencent Cloud (腾讯云短信).
     *
     * Requires config:
     *   sms_tencent_secret_id  — SecretId
     *   sms_tencent_secret_key — SecretKey
     *   sms_tencent_sign_name  — 短信签名
     *   sms_tencent_template_id — 模板ID
     *   sms_tencent_sdk_appid  — SDK AppID
     *
     * @param string $phone
     * @param string $code
     * @return array
     */
    private static function sendTencentSMS($phone, $code)
    {
        $secretId  = get_config('sms_tencent_secret_id');
        $secretKey = get_config('sms_tencent_secret_key');
        $signName  = get_config('sms_tencent_sign_name');
        $templateId = get_config('sms_tencent_template_id');
        $sdkAppId  = get_config('sms_tencent_sdk_appid');

        if (empty($secretId) || empty($secretKey) || empty($signName) || empty($templateId) || empty($sdkAppId)) {
            return ['success' => false, 'message' => 'tencent_config_incomplete'];
        }

        // Use Tencent Cloud SMS HTTP API v3
        $host = 'sms.tencentcloudapi.com';
        $service = 'sms';
        $version = '2021-01-11';
        $action = 'SendSms';

        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);

        $payload = json_encode([
            'PhoneNumberSet'  => ['+86' . $phone],
            'SmsSdkAppId'     => $sdkAppId,
            'SignName'         => $signName,
            'TemplateId'       => $templateId,
            'TemplateParamSet' => [$code],
        ]);

        // Step 1: Build canonical request
        $hashedPayload = hash('SHA256', $payload);
        $canonicalRequest = "POST\n/\n\ncontent-type:application/json\nhost:{$host}\n\ncontent-type;host\n{$hashedPayload}";

        // Step 2: Build string to sign
        $credentialScope = "{$date}/{$service}/tc3_request";
        $hashedCanonical = hash('SHA256', $canonicalRequest);
        $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n{$hashedCanonical}";

        // Step 3: Compute signature
        $secretDate = hash_hmac('SHA256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('SHA256', $service, $secretDate, true);
        $secretSigning = hash_hmac('SHA256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('SHA256', $stringToSign, $secretSigning);

        // Step 4: Build authorization header
        $authorization = "TC3-HMAC-SHA256 Credential={$secretId}/{$credentialScope}, SignedHeaders=content-type;host, Signature={$signature}";

        $headers = [
            'Content-Type: application/json',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Version: ' . $version,
            'X-TC-Timestamp: ' . $timestamp,
            'Authorization: ' . $authorization,
        ];

        $url = 'https://' . $host . '/';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'tencent_request_failed'];
        }

        $data = json_decode($response, true);
        if (isset($data['Response']['SendStatusSet'][0]['Code']) &&
            $data['Response']['SendStatusSet'][0]['Code'] === 'Ok') {
            return ['success' => true, 'message' => 'tencent_sms_sent'];
        }

        $errMsg = $data['Response']['Error']['Message'] ?? 'tencent_sms_failed';
        return ['success' => false, 'message' => $errMsg];
    }

    // -----------------------------------------------------------------------
    //  SOAP command execution (reused from WeChatAuth pattern)
    // -----------------------------------------------------------------------

    /**
     * Execute a SOAP command on the worldserver and return the response text.
     *
     * @param string $command
     * @return array ['success' => bool, 'message' => string]
     */
    public static function soapCommand($command)
    {
        if (empty($command)) {
            return ['success' => false, 'message' => 'empty command'];
        }

        try {
            $conn = new SoapClient(NULL, [
                'location' => 'http://' . get_config('soap_host') . ':' . get_config('soap_port') . '/',
                'uri'      => get_config('soap_uri'),
                'style'    => get_config('soap_style'),
                'login'    => get_config('soap_username'),
                'password' => get_config('soap_password'),
            ]);

            $result = $conn->executeCommand(new SoapParam($command, 'command'));
            unset($conn);

            $message = is_string($result) ? trim($result) : '';
            return ['success' => true, 'message' => $message];
        } catch (Exception $e) {
            if (get_config('debug_mode')) {
                error_log('[MobileAuth SOAP] Error: ' . $e->getMessage());
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get server status info via SOAP command "server info".
     *
     * @return array|false Server status array or false on failure
     */
    public static function getServerStatus()
    {
        $result = static::soapCommand('server info');
        if (!$result['success']) {
            return false;
        }

        $raw = $result['message'];
        $status = [
            'revision'       => '',
            'online_players' => 0,
            'characters'     => 0,
            'peak'           => 0,
            'uptime'         => '',
            'update_time'    => '',
            'raw'            => $raw,
        ];

        if (preg_match('/Connected players:\s*(\d+)/i', $raw, $m)) {
            $status['online_players'] = (int)$m[1];
        }
        if (preg_match('/Characters in world:\s*(\d+)/i', $raw, $m)) {
            $status['characters'] = (int)$m[1];
        }
        if (preg_match('/Connection peak:\s*(\d+)/i', $raw, $m)) {
            $status['peak'] = (int)$m[1];
        }
        if (preg_match('/运行时间:\s*(.+)/i', $raw, $m)) {
            $status['uptime'] = trim($m[1]);
        } elseif (preg_match('/Uptime:\s*(.+)/i', $raw, $m)) {
            $status['uptime'] = trim($m[1]);
        }
        if (preg_match('/AzerothCore\s+(.+)/i', $raw, $m)) {
            $status['revision'] = trim($m[1]);
        }

        return $status;
    }

    /**
     * Check if a game account exists via SOAP.
     *
     * @param string $username
     * @return bool
     */
    public static function accountExists($username)
    {
        $username = strtoupper($username);
        $expansion = get_config('expansion');
        $command = "account set addon {$username} {$expansion}";
        $result = static::soapCommand($command);

        if (!$result['success']) {
            return false;
        }

        $msg = strtolower($result['message']);
        if (strpos($msg, 'not exist') !== false ||
            strpos($msg, 'not found') !== false ||
            strpos($msg, 'does not exist') !== false) {
            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------------
    //  Binding storage — JSON file (no database required)
    // -----------------------------------------------------------------------

    /**
     * Load all phone bindings from the JSON file.
     *
     * @return array
     */
    private static function loadBindings()
    {
        if (!file_exists(static::$dataFile)) {
            return [];
        }
        $content = file_get_contents(static::$dataFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save all phone bindings to the JSON file.
     *
     * @param array $bindings
     * @return bool
     */
    private static function saveBindings($bindings)
    {
        $dir = dirname(static::$dataFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents(
            static::$dataFile,
            json_encode($bindings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        ) !== false;
    }

    /**
     * Look up a binding by phone number.
     *
     * @param string $phone
     * @return array|false
     */
    public static function getBindingByPhone($phone)
    {
        $bindings = static::loadBindings();
        foreach ($bindings as $entry) {
            if ($entry['phone'] === $phone) {
                return $entry;
            }
        }
        return false;
    }

    /**
     * Look up a binding by game account username.
     *
     * @param string $username
     * @return array|false
     */
    public static function getBindingByUsername($username)
    {
        $username = strtoupper($username);
        $bindings = static::loadBindings();
        foreach ($bindings as $entry) {
            if (strtoupper($entry['username']) === $username) {
                return $entry;
            }
        }
        return false;
    }

    /**
     * Add or update a phone binding.
     *
     * @param string $phone
     * @param string $username  Game account username
     * @return bool
     */
    public static function saveBinding($phone, $username)
    {
        $bindings = static::loadBindings();

        // Remove any existing binding for this phone or username
        $bindings = array_filter($bindings, function ($entry) use ($phone, $username) {
            return $entry['phone'] !== $phone &&
                   strtoupper($entry['username']) !== strtoupper($username);
        });

        // Add the new binding
        $bindings[] = [
            'phone'     => $phone,
            'username'  => strtoupper($username),
            'bind_time' => date('Y-m-d H:i:s'),
        ];

        return static::saveBindings(array_values($bindings));
    }

    /**
     * Remove a phone binding.
     *
     * @param string $phone
     * @return bool
     */
    public static function removeBinding($phone)
    {
        $bindings = static::loadBindings();
        $bindings = array_filter($bindings, function ($entry) use ($phone) {
            return $entry['phone'] !== $phone;
        });
        return static::saveBindings(array_values($bindings));
    }

    // -----------------------------------------------------------------------
    //  High-level flow — login or register after SMS verification
    // -----------------------------------------------------------------------

    /**
     * Process login/registration after SMS code verification.
     *
     * Flow:
     *   1. Verify the SMS code
     *   2. Check if the phone is already bound to a game account
     *   3. If bound: log in (set session)
     *   4. If not bound: auto-create a game account via SOAP, then log in
     *
     * @param string $phone
     * @param string $code
     * @return array ['success' => bool, 'username' => ..., 'password' => ..., 'message' => ..., 'is_new' => bool]
     */
    public static function loginOrRegister($phone, $code)
    {
        if (!static::init()) {
            return ['success' => false, 'message' => 'mobile_auth_disabled'];
        }

        // Step 1: Verify the SMS code
        $verifyResult = static::verifyCode($phone, $code);
        if (!$verifyResult['success']) {
            return ['success' => false, 'message' => $verifyResult['message']];
        }

        // Step 2: Check if this phone is already bound
        $binding = static::getBindingByPhone($phone);
        if ($binding) {
            // Already bound — check if account still exists via SOAP
            if (static::accountExists($binding['username'])) {
                // Log in
                $_SESSION['mobile_logged_in'] = true;
                $_SESSION['mobile_username']  = $binding['username'];
                $_SESSION['mobile_phone']     = $phone;

                return [
                    'success'  => true,
                    'username' => $binding['username'],
                    'password' => '',
                    'message'  => 'login_success',
                    'is_new'   => false,
                ];
            }

            // Account was deleted — re-create
            $username = $binding['username'];
        }

        // Step 3: Auto-create a new game account
        if (!isset($username) || empty($username)) {
            $username = static::generateUsername($phone);
        }
        $password = static::generatePassword();
        $username = strtoupper($username);

        // Create account via SOAP
        $createCommand = get_config('soap_ca_command') ?: 'account create {USERNAME} {PASSWORD}';
        $createCommand = str_replace('{USERNAME}', $username, $createCommand);
        $createCommand = str_replace('{PASSWORD}', $password, $createCommand);
        $result = static::soapCommand($createCommand);

        if (!$result['success']) {
            return ['success' => false, 'message' => 'soap_create_failed'];
        }

        // Check if account already existed
        $msg = strtolower($result['message']);
        if (strpos($msg, 'already exist') !== false) {
            // Account exists, try with a different username
            $username = static::generateUsername($phone . time());
            $createCommand = str_replace('{USERNAME}', $username, $createCommand);
            $createCommand = str_replace($password, $password, $createCommand);
            // Actually regenerate password too
            $password = static::generatePassword();
            $createCommand = get_config('soap_ca_command') ?: 'account create {USERNAME} {PASSWORD}';
            $createCommand = str_replace('{USERNAME}', $username, $createCommand);
            $createCommand = str_replace('{PASSWORD}', $password, $createCommand);
            $result = static::soapCommand($createCommand);
            if (!$result['success']) {
                return ['success' => false, 'message' => 'soap_create_failed'];
            }
        }

        // Set expansion via SOAP
        $addonCommand = get_config('soap_asa_command');
        if (!empty($addonCommand)) {
            $addonCommand = str_replace('{USERNAME}', $username, $addonCommand);
            $addonCommand = str_replace('{EXPANSION}', get_config('expansion'), $addonCommand);
            static::soapCommand($addonCommand);
        } else {
            static::soapCommand("account set addon {$username} " . get_config('expansion'));
        }

        // Save the binding
        static::saveBinding($phone, $username);

        // Log in
        $_SESSION['mobile_logged_in'] = true;
        $_SESSION['mobile_username']  = $username;
        $_SESSION['mobile_phone']     = $phone;

        return [
            'success'  => true,
            'username' => $username,
            'password' => $password,
            'message'  => 'register_success',
            'is_new'   => true,
        ];
    }

    // -----------------------------------------------------------------------
    //  Registration helpers
    // -----------------------------------------------------------------------

    /**
     * Generate a unique username from a phone number.
     *
     * Format: T + last 7 digits of phone + optional suffix
     *
     * @param string $phone
     * @return string
     */
    public static function generateUsername($phone)
    {
        // Use last 7 digits of phone, prefix with T
        $base = 'T' . substr(preg_replace('/\D/', '', $phone), -7);
        $base = strtoupper(substr($base, 0, 14));

        $username = $base;
        $suffix = 1;
        while (static::accountExists($username)) {
            $username = $base . $suffix;
            $suffix++;
            if ($suffix > 99) {
                $username = $base . rand(100, 999);
                break;
            }
        }
        return $username;
    }

    /**
     * Generate a random password.
     *
     * @param int $length
     * @return string
     */
    public static function generatePassword($length = 12)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
    }

    // -----------------------------------------------------------------------
    //  Password reset (via SOAP, no database required)
    // -----------------------------------------------------------------------

    /**
     * Reset the password for a game account using SOAP.
     *
     * @param string $username
     * @return array
     */
    public static function resetPassword($username)
    {
        $username = strtoupper($username);

        if (!static::accountExists($username)) {
            return ['success' => false, 'password' => '', 'message' => 'account_not_found'];
        }

        $newPassword = static::generatePassword();
        $pwCommand = "account set password {$username} {$newPassword} {$newPassword}";
        $pwResult = static::soapCommand($pwCommand);

        if (!$pwResult['success']) {
            return ['success' => false, 'password' => '', 'message' => 'soap_error'];
        }

        $msg = strtolower($pwResult['message']);
        if (strpos($msg, 'not exist') !== false ||
            strpos($msg, 'not found') !== false ||
            strpos($msg, 'does not exist') !== false) {
            return ['success' => false, 'password' => '', 'message' => 'account_not_found'];
        }

        return [
            'success'  => true,
            'username' => $username,
            'password' => $newPassword,
            'message'  => '',
        ];
    }

    /**
     * Reset the password for the currently logged-in user's bound account.
     *
     * @return array
     */
    public static function resetMyPassword()
    {
        if (!static::isLoggedIn()) {
            return ['success' => false, 'password' => '', 'message' => 'not_logged_in'];
        }

        $username = $_SESSION['mobile_username'] ?? '';
        if (empty($username)) {
            return ['success' => false, 'password' => '', 'message' => 'no_bound_account'];
        }

        return static::resetPassword($username);
    }

    // -----------------------------------------------------------------------
    //  Session management
    // -----------------------------------------------------------------------

    /**
     * Check if a user is logged in via mobile.
     */
    public static function isLoggedIn()
    {
        return !empty($_SESSION['mobile_logged_in']);
    }

    /**
     * Get the currently logged-in user info.
     */
    public static function getCurrentUser()
    {
        if (!static::isLoggedIn()) {
            return null;
        }
        return [
            'username' => $_SESSION['mobile_username'] ?? null,
            'phone'    => $_SESSION['mobile_phone'] ?? null,
        ];
    }

    /**
     * Log out the mobile session.
     */
    public static function logout()
    {
        unset($_SESSION['mobile_logged_in'],
              $_SESSION['mobile_username'],
              $_SESSION['mobile_phone']);
    }

    // -----------------------------------------------------------------------
    //  HTTP helper
    // -----------------------------------------------------------------------

    /**
     * Perform an HTTP GET request using cURL.
     *
     * @param string $url
     * @return string|false
     */
    private static function httpGet($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WoWSimpleRegistration/MobileAuth');

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            if (get_config('debug_mode')) {
                error_log('[MobileAuth] HTTP error: ' . curl_error($ch));
            }
            curl_close($ch);
            return false;
        }
        curl_close($ch);
        return $response;
    }
}
