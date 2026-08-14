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
        if (static::$dataFile !== null) {
            return true;
        }
        if (!get_config('mobile_enabled')) {
            return false;
        }
        static::$dataFile = __DIR__ . '/../data/mobile_bindings.json';
        static::$codeFile = __DIR__ . '/../data/sms_codes.json';
        return true;
    }

    /**
     * Ensure lazy initialization.
     */
    private static function ensureInit()
    {
        if (static::$dataFile === null) {
            static::init();
        }
    }

    /**
     * Get the domain for Web OTP API SMS format.
     *
     * Web OTP requires the SMS to end with:
     *   @your-domain.com #123456
     *
     * This extracts the domain from baseurl.
     *
     * @return string
     */
    public static function getOtpDomain()
    {
        $baseurl = get_config('baseurl') ?: '';
        $host = parse_url($baseurl, PHP_URL_HOST);
        return $host ?: 'localhost';
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
        static::ensureInit();
        if (static::$codeFile === null || !file_exists(static::$codeFile)) {
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
        static::ensureInit();
        if (static::$codeFile === null) {
            return false;
        }
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

        // In demo mode, return the code so the frontend can simulate
        // the Web OTP auto-fill (no real SMS is sent)
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
                    return ['success' => false, 'message' => 'max_attempts_exceeded', 'remaining' => 0];
                }

                // Increment attempts
                $codes[$key]['attempts']++;
                $currentAttempts = $codes[$key]['attempts'];
                static::saveCodes(array_values($codes));

                if ($entry['code'] === $code) {
                    // Mark as verified and remove
                    unset($codes[$key]);
                    static::saveCodes(array_values($codes));
                    return ['success' => true, 'message' => 'verified'];
                }

                $remaining = static::$maxAttempts - $currentAttempts;
                return [
                    'success'   => false,
                    'message'   => 'wrong_code',
                    'remaining' => max(0, $remaining),
                ];
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
            'TemplateParam'  => json_encode(['code' => $code, 'domain' => static::getOtpDomain()]),
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
            'TemplateParamSet' => [$code, static::getOtpDomain()],
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
     * Uses the SOAP Bridge (Python) for transport since PHP's native
     * network functions are sandboxed in this environment.
     *
     * @param string $command
     * @return array ['success' => bool, 'message' => string]
     */
    public static function soapCommand($command)
    {
        if (empty($command)) {
            return ['success' => false, 'message' => 'empty command'];
        }

        // Convert style string to constant value if needed
        $style = get_config('soap_style');
        if (is_string($style)) {
            $styleConstant = strtoupper($style);
            if (defined($styleConstant)) {
                $style = constant($styleConstant);
            } else {
                $style = SOAP_RPC; // default
            }
        }

        $soapOptions = [
            'location' => 'http://' . get_config('soap_host') . ':' . get_config('soap_port') . '/',
            'uri'      => get_config('soap_uri'),
            'style'    => $style,
            'login'    => get_config('soap_username'),
            'password' => get_config('soap_password'),
            'connection_timeout' => 5,
            'trace'    => true,
            'exceptions' => true,
        ];

        try {
            $conn = new SoapClient(NULL, $soapOptions);
            $result = $conn->executeCommand(new SoapParam($command, 'command'));
            unset($conn);

            $message = is_string($result) ? trim($result) : '';

            if (get_config('debug_mode')) {
                error_log('[Mobile SOAP] Command: ' . $command . ' -> OK: ' . substr($message, 0, 200));
            }

            return ['success' => true, 'message' => $message];
        } catch (Exception $e) {
            if (get_config('debug_mode')) {
                error_log('[Mobile SOAP] Error: ' . $command . ' -> ' . $e->getMessage());
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

    /**
     * Get account information via SOAP command "account info".
     *
     * Returns parsed data: username, expansion, GM level, last login, etc.
     *
     * @param string $username
     * @return array|false
     */
    public static function getAccountInfo($username)
    {
        $username = strtoupper($username);
        $result = static::soapCommand("account info {$username}");

        if (!$result['success']) {
            return false;
        }

        $raw = $result['message'];
        $info = [
            'username'    => $username,
            'expansion'   => '',
            'gm_level'    => '',
            'last_login'  => '',
            'last_ip'     => '',
            'online'      => false,
            'characters'  => 0,
            'raw'         => $raw,
        ];

        // Parse various output formats (EN / CN)
        if (preg_match('/Expansion:\s*(\S+)/i', $raw, $m)) {
            $expMap = ['0' => 'Classic', '1' => 'TBC', '2' => 'WotLK', '3' => 'Cata'];
            $info['expansion'] = $expMap[$m[1]] ?? $m[1];
        }
        if (preg_match('/Permission Level:\s*(\S+)/i', $raw, $m)) {
            $info['gm_level'] = $m[1];
        } elseif (preg_match('/Security Level:\s*(\S+)/i', $raw, $m)) {
            $info['gm_level'] = $m[1];
        }
        if (preg_match('/Last Login:\s*(.+)/i', $raw, $m)) {
            $info['last_login'] = trim($m[1]);
        } elseif (preg_match('/上次登录:\s*(.+)/i', $raw, $m)) {
            $info['last_login'] = trim($m[1]);
        }
        if (preg_match('/Last IP:\s*(\S+)/i', $raw, $m)) {
            $info['last_ip'] = $m[1];
        }
        if (preg_match('/Characters:\s*(\d+)/i', $raw, $m)) {
            $info['characters'] = (int)$m[1];
        }
        // Check if currently online
        if (stripos($raw, 'is online') !== false || stripos($raw, '在线') !== false) {
            $info['online'] = true;
        }

        return $info;
    }

    /**
     * Get character list for an account via SOAP "lookup player account".
     *
     * Output format from AzerothCore:
     *   "Characters at account NAME (Id: 12)"
     *   "Charname (GUID 2) - Race - Class - 80"
     *
     * @param string $username
     * @return array|false  ['account_id' => int, 'characters' => [...]]
     */
    public static function getAccountCharacters($username)
    {
        $username = strtoupper($username);
        $result = static::soapCommand("lookup player account {$username}");

        if (!$result['success']) {
            return false;
        }

        $raw = $result['message'];
        $data = [
            'account_id'  => 0,
            'characters'  => [],
            'raw'         => $raw,
        ];

        // Parse account ID from header line
        if (preg_match('/\(Id:\s*(\d+)\)/i', $raw, $m)) {
            $data['account_id'] = (int)$m[1];
        }

        // Race name mappings (EN → CN)
        $raceMap = [
            'Human' => '人类', 'Orc' => '兽人', 'Dwarf' => '矮人', 'Night Elf' => '暗夜精灵',
            'Undead' => '亡灵', 'Tauren' => '牛头人', 'Gnome' => '侏儒', 'Troll' => '巨魔',
            'Blood Elf' => '血精灵', 'Draenei' => '德莱尼',
        ];
        // Class name mappings (EN → CN)
        $classMap = [
            'Warrior' => '战士', 'Paladin' => '圣骑士', 'Hunter' => '猎人', 'Rogue' => '潜行者',
            'Priest' => '牧师', 'Death Knight' => '死亡骑士', 'Shaman' => '萨满祭司',
            'Mage' => '法师', 'Warlock' => '术士', 'Druid' => '德鲁伊',
        ];

        // Parse each character line: "Name (GUID x) - Race - Class - Level"
        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            // Match: CharName (GUID 2) - Tauren - Hunter - 80
            if (preg_match('/^(.+?)\s*\(GUID\s*(\d+)\)\s*-\s*(.+?)\s*-\s*(.+?)\s*-\s*(\d+)/i', trim($line), $m)) {
                $raceEn = trim($m[3]);
                $classEn = trim($m[4]);
                $data['characters'][] = [
                    'name'     => trim($m[1]),
                    'guid'     => (int)$m[2],
                    'race'     => $raceMap[$raceEn] ?? $raceEn,
                    'race_en'  => $raceEn,
                    'class'    => $classMap[$classEn] ?? $classEn,
                    'class_en' => $classEn,
                    'level'    => (int)$m[5],
                ];
            }
        }

        return $data;
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
        static::ensureInit();
        if (static::$dataFile === null || !file_exists(static::$dataFile)) {
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
        static::ensureInit();
        if (static::$dataFile === null) {
            return false;
        }
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
     * @param string $password  Plaintext password (will be hashed) — optional
     * @return bool
     */
    public static function saveBinding($phone, $username, $password = '')
    {
        $bindings = static::loadBindings();

        // Preserve existing password hash and phone if no new password/phone provided
        $existingHash = '';
        $existingPhone = $phone;
        $existingBotPassword = '';
        foreach ($bindings as $entry) {
            if ($entry['phone'] === $phone ||
                strtoupper($entry['username']) === strtoupper($username)) {
                $existingHash = $entry['password_hash'] ?? '';
                $existingBotPassword = $entry['bot_password'] ?? '';
                if (empty($phone) && !empty($entry['phone'])) {
                    $existingPhone = $entry['phone'];
                }
                break;
            }
        }

        // Remove any existing binding for this phone or username
        $bindings = array_filter($bindings, function ($entry) use ($phone, $username) {
            return $entry['phone'] !== $phone &&
                   strtoupper($entry['username']) !== strtoupper($username);
        });

        // Build new binding entry — preserve existing phone if new one is empty
        $newEntry = [
            'phone'     => $existingPhone ?: $phone,
            'username'  => strtoupper($username),
            'bind_time' => date('Y-m-d H:i:s'),
        ];

        // Store password hash + encrypted password for bot client
        if (!empty($password)) {
            $newEntry['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
            $newEntry['bot_password']  = static::encryptPassword($password);
        } elseif (!empty($existingHash)) {
            $newEntry['password_hash'] = $existingHash;
            if (!empty($existingBotPassword)) {
                $newEntry['bot_password'] = $existingBotPassword;
            }
        }

        $bindings[] = $newEntry;

        return static::saveBindings(array_values($bindings));
    }

    /**
     * Encrypt password for bot client storage.
     */
    private static function encryptPassword($password)
    {
        $key = static::getEncryptionKey();
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt password for bot client use.
     */
    public static function decryptPassword($encrypted)
    {
        if (empty($encrypted)) {
            return '';
        }
        $key = static::getEncryptionKey();
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 16);
        $encrypted_data = substr($data, 16);
        $decrypted = openssl_decrypt($encrypted_data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted ?: '';
    }

    /**
     * Get the encryption key (derived from config).
     */
    private static function getEncryptionKey()
    {
        $key = get_config('bot_encryption_key') ?: 'WoWSimpleRegistration2026BotKey!';
        return substr(hash('sha256', $key, true), 0, 32);
    }

    /**
     * Get bot password for a username (for client simulator).
     */
    public static function getBotPassword($username)
    {
        $username = strtoupper($username);
        $binding = static::getBindingByUsername($username);
        if (!$binding || empty($binding['bot_password'])) {
            return '';
        }
        return static::decryptPassword($binding['bot_password']);
    }

    /**
     * Update the stored password hash for a given username.
     *
     * Called after successful password change or reset.
     *
     * @param string $username
     * @param string $newPassword  Plaintext new password
     * @return bool
     */
    public static function updatePasswordHash($username, $newPassword)
    {
        $username  = strtoupper($username);
        $bindings  = static::loadBindings();
        $updated   = false;

        foreach ($bindings as &$entry) {
            if (strtoupper($entry['username']) === $username) {
                $entry['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
                $entry['bot_password']  = static::encryptPassword($newPassword);
                $entry['pw_updated_at'] = date('Y-m-d H:i:s');
                $updated = true;
                break;
            }
        }
        unset($entry);

        if ($updated) {
            static::saveBindings(array_values($bindings));
        }
        return $updated;
    }

    /**
     * Verify a plaintext password against the stored hash.
     *
     * @param string $username
     * @param string $password  Plaintext password to verify
     * @return array ['verified' => bool, 'has_hash' => bool]
     */
    public static function verifyPasswordHash($username, $password)
    {
        $username = strtoupper($username);
        $bindings = static::loadBindings();

        foreach ($bindings as $entry) {
            if (strtoupper($entry['username']) === $username) {
                if (!empty($entry['password_hash'])) {
                    return [
                        'verified' => password_verify($password, $entry['password_hash']),
                        'has_hash' => true,
                    ];
                }
                // No hash stored (old binding) — cannot verify
                return ['verified' => false, 'has_hash' => false];
            }
        }
        // No binding found
        return ['verified' => false, 'has_hash' => false];
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

    /**
     * Bind a phone number to an existing game account.
     *
     * Used when an account was created outside the web system (GM tool, DB direct, etc.)
     * and the user needs to attach their phone number for one-click login support.
     *
     * @param string $username  Game account username (already verified via login)
     * @param string $phone     Phone number to bind
     * @param string $code      SMS verification code
     * @return array ['success' => bool, 'message' => string]
     */
    public static function bindPhone($username, $phone, $code)
    {
        if (!static::init()) {
            return ['success' => false, 'message' => 'mobile_auth_disabled'];
        }

        $username = strtoupper($username);

        // Verify SMS code
        $verifyResult = static::verifyCode($phone, $code);
        if (!$verifyResult['success']) {
            return ['success' => false, 'message' => $verifyResult['message']];
        }

        // Check if this phone is already bound to another account
        $existingBinding = static::getBindingByPhone($phone);
        if ($existingBinding && strtoupper($existingBinding['username']) !== $username) {
            return ['success' => false, 'message' => 'phone_already_bound'];
        }

        // Verify account exists on game server
        if (!static::accountExists($username)) {
            return ['success' => false, 'message' => 'account_not_found'];
        }

        // Get existing binding for this username (may have empty phone)
        $binding = static::getBindingByUsername($username);
        $existingPasswordHash = '';
        if ($binding) {
            $existingPasswordHash = $binding['password_hash'] ?? '';
        }

        // Update or create binding with the phone
        if ($binding) {
            // Update existing binding — preserve password hash, update phone
            $bindings = static::loadBindings();
            foreach ($bindings as &$entry) {
                if (strtoupper($entry['username']) === $username) {
                    $entry['phone'] = $phone;
                    $entry['bind_time'] = date('Y-m-d H:i:s');
                    break;
                }
            }
            unset($entry);
            static::saveBindings(array_values($bindings));
        } else {
            // Create new binding with phone + empty password (user must set password via login first)
            static::saveBinding($phone, $username, '');
        }

        // Update session
        $_SESSION['mobile_phone'] = $phone;

        return [
            'success'  => true,
            'message'  => 'phone_bound',
            'username' => $username,
            'phone'    => $phone,
        ];
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

        // Save the binding (with password hash)
        static::saveBinding($phone, $username, $password);

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

    /**
     * Change the password for a game account using SOAP.
     *
     * After a successful SOAP password change, the local password hash
     * is also updated to stay in sync.
     *
     * @param string $username
     * @param string $newPassword  User-supplied new password (6-32 chars)
     * @return array
     */
    public static function changePassword($username, $newPassword)
    {
        $username = strtoupper($username);
        $newPassword = trim($newPassword);

        if (strlen($newPassword) < 6 || strlen($newPassword) > 16) {
            return ['success' => false, 'message' => 'invalid_password'];
        }

        if (!static::accountExists($username)) {
            return ['success' => false, 'message' => 'account_not_found'];
        }

        $pwCommand = "account set password {$username} {$newPassword} {$newPassword}";
        $pwResult = static::soapCommand($pwCommand);

        if (!$pwResult['success']) {
            return ['success' => false, 'message' => 'soap_error'];
        }

        $msg = strtolower($pwResult['message']);
        if (strpos($msg, 'not exist') !== false ||
            strpos($msg, 'not found') !== false ||
            strpos($msg, 'does not exist') !== false) {
            return ['success' => false, 'message' => 'account_not_found'];
        }

        // Update local password hash to stay in sync
        static::updatePasswordHash($username, $newPassword);

        return [
            'success'  => true,
            'username' => $username,
            'message'  => 'password_changed',
        ];
    }

    /**
     * Change password for the currently logged-in user.
     *
     * Verifies the old password against the stored hash before
     * allowing the change. If no hash is stored (legacy binding),
     * the change is allowed without verification and a hash is stored.
     *
     * @param string $oldPassword  User's current password
     * @param string $newPassword  Desired new password
     * @return array
     */
    public static function changeMyPassword($oldPassword, $newPassword)
    {
        if (!static::isLoggedIn()) {
            return ['success' => false, 'message' => 'not_logged_in'];
        }

        $username = $_SESSION['mobile_username'] ?? '';
        if (empty($username)) {
            return ['success' => false, 'message' => 'no_bound_account'];
        }

        // Verify old password against stored hash
        $verify = static::verifyPasswordHash($username, $oldPassword);
        if ($verify['has_hash'] && !$verify['verified']) {
            return ['success' => false, 'message' => 'wrong_old_password'];
        }

        return static::changePassword($username, $newPassword);
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

    /**
     * Perform an HTTP POST request using cURL.
     *
     * @param string $url
     * @param string|array $data  POST body (string for raw JSON, array for form-encoded)
     * @param array $headers  Optional HTTP headers
     * @return string|false
     */
    private static function httpPost($url, $data, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WoWSimpleRegistration/MobileAuth');

        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            if (get_config('debug_mode')) {
                error_log('[MobileAuth] HTTP POST error: ' . curl_error($ch));
            }
            curl_close($ch);
            return false;
        }
        curl_close($ch);
        return $response;
    }

    // -----------------------------------------------------------------------
    //  One-Click Login (号码认证 / Carrier Gateway Authentication)
    // -----------------------------------------------------------------------

    /**
     * Exchange an Aliyun NumberAuth token for the user's phone number.
     *
     * Uses Aliyun's GetMobile API (dypnsapi.aliyuncs.com).
     * The token is obtained on the frontend by the Aliyun H5 SDK after
     * the carrier gateway verifies the SIM card's phone number.
     *
     * Required config:
     *   numberauth_aliyun_access_key
     *   numberauth_aliyun_access_secret
     *
     * @param string $token  The access token from the frontend SDK
     * @return array ['success' => bool, 'phone' => string, 'message' => string]
     */
    public static function verifyAliyunToken($token)
    {
        $accessKey    = get_config('numberauth_aliyun_access_key');
        $accessSecret = get_config('numberauth_aliyun_access_secret');

        if (empty($accessKey) || empty($accessSecret)) {
            return ['success' => false, 'phone' => '', 'message' => 'numberauth_config_incomplete'];
        }

        if (empty($token)) {
            return ['success' => false, 'phone' => '', 'message' => 'token_required'];
        }

        // Aliyun dypnsapi GetMobile API
        $host = 'dypnsapi.aliyuncs.com';
        $params = [
            'AccessKeyId'      => $accessKey,
            'Format'           => 'JSON',
            'Version'          => '2017-05-25',
            'SignatureMethod'  => 'HMAC-SHA1',
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce'   => uniqid(),
            'Action'           => 'GetMobile',
            'RegionId'         => 'cn-hangzhou',
            'AccessToken'      => $token,
        ];

        // Compute signature (same method as SMS API)
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
            return ['success' => false, 'phone' => '', 'message' => 'numberauth_request_failed'];
        }

        $data = json_decode($response, true);
        if (isset($data['Code']) && $data['Code'] === 'OK' && !empty($data['GetMobileResultDTO']['Mobile'])) {
            $phone = $data['GetMobileResultDTO']['Mobile'];
            // Chinese numbers from Aliyun may or may not have the 86 prefix
            $phone = preg_replace('/^86/', '', $phone);
            return ['success' => true, 'phone' => $phone, 'message' => 'token_verified'];
        }

        $errMsg = $data['Message'] ?? $data['Code'] ?? 'numberauth_failed';
        return ['success' => false, 'phone' => '', 'message' => $errMsg];
    }

    /**
     * One-click login: register or log in using a phone number obtained
     * from carrier gateway authentication (no SMS verification needed).
     *
     * The carrier gateway already proved the user owns this phone number,
     * so we skip SMS verification and go straight to account creation/login.
     *
     * @param string $phone     Phone number from carrier gateway
     * @param string $password  User-supplied password (required for new accounts)
     * @param string $customUsername  User-supplied username (optional, auto-generated if empty)
     * @return array ['success' => bool, 'username' => ..., 'password' => ..., 'message' => ..., 'is_new' => bool]
     */
    public static function oneClickLogin($phone, $password = '', $customUsername = '')
    {
        if (!static::init()) {
            return ['success' => false, 'message' => 'mobile_auth_disabled'];
        }

        if (!static::isValidPhone($phone)) {
            return ['success' => false, 'message' => 'invalid_phone'];
        }

        // Check if this phone is already bound
        $binding = static::getBindingByPhone($phone);
        if ($binding) {
            // Already bound — check if account still exists via SOAP
            if (static::accountExists($binding['username'])) {
                // Log in
                $_SESSION['mobile_logged_in'] = true;
                $_SESSION['mobile_username']  = $binding['username'];
                $_SESSION['mobile_phone']     = $phone;

                // Update password hash if a new password is provided
                $password = trim($password);
                if (!empty($password) && strlen($password) >= 6 && strlen($password) <= 16) {
                    static::saveBinding($phone, $binding['username'], $password);
                }

                return [
                    'success'  => true,
                    'username' => $binding['username'],
                    'password' => '',
                    'message'  => 'login_success',
                    'is_new'   => false,
                ];
            }

            // Account was deleted — re-create with the same username
            $username = $binding['username'];
        }

        // For new accounts, password is required and must be 6-16 chars (AzerothCore SOAP limit)
        $password = trim($password);
        if (strlen($password) < 6 || strlen($password) > 16) {
            return ['success' => false, 'message' => 'invalid_password'];
        }

        // Use user-supplied username or auto-generate
        if (!isset($username) || empty($username)) {
            $customUsername = trim($customUsername);
            if (!empty($customUsername)) {
                // Validate custom username: 3-16 chars, alphanumeric
                if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $customUsername)) {
                    return ['success' => false, 'message' => 'invalid_username'];
                }
                $username = strtoupper($customUsername);
                // Check if username is taken
                if (static::accountExists($username)) {
                    // Account exists on server but phone not bound — bind phone to existing account
                    // This handles the case of GM-created accounts where user provides phone + password
                    $existingBinding = static::getBindingByUsername($username);
                    if ($existingBinding && !empty($existingBinding['phone'])) {
                        return ['success' => false, 'message' => 'username_taken'];
                    }
                    // Bind phone to existing account
                    static::saveBinding($phone, $username, $password);
                    $_SESSION['mobile_logged_in'] = true;
                    $_SESSION['mobile_username']  = $username;
                    $_SESSION['mobile_phone']     = $phone;

                    return [
                        'success'  => true,
                        'username' => $username,
                        'password' => $password,
                        'message'  => 'login_success',
                        'is_new'   => false,
                    ];
                }
            } else {
                $username = static::generateUsername($phone);
            }
        }
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
            $username = static::generateUsername($phone . time());
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

        // Save the binding (with password hash)
        static::saveBinding($phone, $username, $password);

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
}
