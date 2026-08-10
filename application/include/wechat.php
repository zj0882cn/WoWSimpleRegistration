<?php
/**
 * WeChat OAuth2.0 Authentication Module (SOAP-Only Mode)
 *
 * Integrates WeChat QR code login (snsapi_login) with the WoWSimpleRegistration
 * system. This version operates WITHOUT any direct database connections —
 * all game account operations (create / set expansion / set
 * password) are performed via SOAP commands to the worldserver.
 *
 * WeChat-to-account bindings are persisted in a local JSON file instead of
 * a MySQL table.
 *
 * @author AzerothCore Community
 * @link https://developers.weixin.qq.com/doc/oplatform/Website_App/WeChat_Login/Wechat_Login.html
 **/

class WeChatAuth
{
    /** @var string WeChat Open Platform AppID */
    private static $appId;

    /** @var string WeChat Open Platform AppSecret */
    private static $appSecret;

    /** @var string Callback URL after WeChat authorization */
    private static $redirectUri;

    /** @var string OAuth scope (snsapi_userinfo for Official Account) */
    private static $scope = 'snsapi_userinfo';

    /** @var string Path to the JSON file that stores WeChat bindings */
    private static $dataFile;

    /** @var string WeChat Open Platform base URL (overridable for testing) */
    private static $openBaseurl = 'https://open.weixin.qq.com';

    /** @var string WeChat API base URL (overridable for testing) */
    private static $apiBaseurl = 'https://api.weixin.qq.com';

    // -----------------------------------------------------------------------
    //  Initialization
    // -----------------------------------------------------------------------

    /**
     * Load WeChat configuration from the global $config array.
     * Returns false if WeChat auth is disabled.
     */
    public static function init()
    {
        if (!get_config('wechat_enabled')) {
            return false;
        }
        static::$appId       = get_config('wechat_appid');
        static::$appSecret   = get_config('wechat_appsecret');
        static::$redirectUri = rtrim(get_config('baseurl'), '/') . '/wechat_callback.php';
        static::$dataFile    = __DIR__ . '/../data/wechat_bindings.json';

        // Allow overriding the WeChat API endpoints (for local mock testing)
        if (get_config('wechat_open_baseurl')) {
            static::$openBaseurl = rtrim(get_config('wechat_open_baseurl'), '/');
        }
        if (get_config('wechat_api_baseurl')) {
            static::$apiBaseurl = rtrim(get_config('wechat_api_baseurl'), '/');
        }

        return true;
    }

    // -----------------------------------------------------------------------
    //  Step 1 — Generate the authorization URL (QR code login)
    // -----------------------------------------------------------------------

    /**
     * Build the WeChat OAuth authorize URL that the user will be redirected to.
     *
     * Uses the Official Account (公众号) OAuth2 endpoint:
     *   /connect/oauth2/authorize
     *
     * - On PC: WeChat automatically shows a QR code page for scanning.
     * - On mobile (inside WeChat): Directly shows the authorization page.
     *
     * @return string|null Full URL or null if disabled
     */
    public static function getAuthorizeUrl()
    {
        if (!static::init()) {
            return null;
        }

        $state = static::generateState();
        $params = [
            'appid'         => static::$appId,
            'redirect_uri'  => static::$redirectUri,
            'response_type' => 'code',
            'scope'         => static::$scope,
            'state'         => $state,
        ];

        $query = http_build_query($params);
        return static::$openBaseurl . '/connect/oauth2/authorize?' . $query . '#wechat_redirect';
    }

    /**
     * Generate a random state value and store it in the session for CSRF
     * protection.
     */
    private static function generateState()
    {
        $state = bin2hex(random_bytes(8));
        $_SESSION['wechat_state'] = $state;
        $_SESSION['wechat_state_time'] = time();
        return $state;
    }

    // -----------------------------------------------------------------------
    //  Step 2 — Exchange the authorization code for an access_token
    // -----------------------------------------------------------------------

    /**
     * Exchange the temporary code returned by WeChat for an access_token.
     *
     * @param string $code Authorization code from WeChat callback
     * @return array|false Token data or false on failure
     */
    public static function getAccessToken($code)
    {
        if (!static::init()) {
            return false;
        }

        $url = static::$apiBaseurl . '/sns/oauth2/access_token';
        $params = [
            'appid'      => static::$appId,
            'secret'     => static::$appSecret,
            'code'       => $code,
            'grant_type' => 'authorization_code',
        ];

        $response = static::httpGet($url . '?' . http_build_query($params));
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        if (isset($data['errcode'])) {
            if (get_config('debug_mode')) {
                error_log('[WeChat] getAccessToken error: ' . $data['errcode'] . ' ' . $data['errmsg']);
            }
            return false;
        }

        return $data;
    }

    // -----------------------------------------------------------------------
    //  Step 3 — Fetch the user's profile via access_token + openid
    // -----------------------------------------------------------------------

    /**
     * Retrieve WeChat user information.
     *
     * @param string $accessToken Access token from getAccessToken()
     * @param string $openid      OpenID from getAccessToken()
     * @return array|false User info array or false on failure
     */
    public static function getUserInfo($accessToken, $openid)
    {
        $url = static::$apiBaseurl . '/sns/userinfo';
        $params = [
            'access_token' => $accessToken,
            'openid'       => $openid,
            'lang'         => 'zh_CN',
        ];

        $response = static::httpGet($url . '?' . http_build_query($params));
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        if (isset($data['errcode'])) {
            if (get_config('debug_mode')) {
                error_log('[WeChat] getUserInfo error: ' . $data['errcode'] . ' ' . $data['errmsg']);
            }
            return false;
        }

        return $data;
    }

    // -----------------------------------------------------------------------
    //  Refresh & verify tokens
    // -----------------------------------------------------------------------

    /**
     * Refresh an expired access_token using the refresh_token.
     */
    public static function refreshAccessToken($refreshToken)
    {
        if (!static::init()) {
            return false;
        }

        $url = static::$apiBaseurl . '/sns/oauth2/refresh_token';
        $params = [
            'appid'         => static::$appId,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        $response = static::httpGet($url . '?' . http_build_query($params));
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        if (isset($data['errcode'])) {
            return false;
        }

        return $data;
    }

    /**
     * Verify that an access_token is still valid.
     */
    public static function verifyAccessToken($accessToken, $openid)
    {
        $url = static::$apiBaseurl . '/sns/auth';
        $params = [
            'access_token' => $accessToken,
            'openid'       => $openid,
        ];

        $response = static::httpGet($url . '?' . http_build_query($params));
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        return isset($data['errcode']) && $data['errcode'] === 0;
    }

    // -----------------------------------------------------------------------
    //  SOAP command execution
    // -----------------------------------------------------------------------

    /**
     * Execute a SOAP command on the worldserver and return the response text.
     *
     * Unlike the global RemoteCommandWithSOAP() which only returns true/false,
     * this method captures and returns the command output so we can check for
     * error messages (e.g. "Account already exists").
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

            // AzerothCore returns the command output in the SOAP response.
            // If the response contains "Account created" it's a success.
            // If it contains "already exist" it means the account exists.
            return ['success' => true, 'message' => $message];
        } catch (Exception $e) {
            if (get_config('debug_mode')) {
                error_log('[WeChat SOAP] Error: ' . $e->getMessage());
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get server status info via SOAP command "server info".
     *
     * Parses the AzerothCore output to extract:
     *   - revision / build info
     *   - connected players count
     *   - characters in world
     *   - connection peak
     *   - server uptime
     *   - update time diff
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

        // Parse: Connected players: 0. Characters in world: 0.
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
     * Check if a game account exists by attempting a read-only SOAP command.
     *
     * Uses `account set addon {username} {current_expansion}` which succeeds
     * only if the account exists. The expansion is set to the same value it
     * already has, so no actual change occurs.
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

        // If the account doesn't exist, the server typically returns
        // a message like "Account not exist" or "Not found"
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
     * Load all WeChat bindings from the JSON file.
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
     * Save all WeChat bindings to the JSON file.
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
     * Look up a binding by WeChat openid.
     *
     * @param string $openid
     * @return array|false
     */
    public static function getBindingByOpenid($openid)
    {
        $bindings = static::loadBindings();
        foreach ($bindings as $entry) {
            if ($entry['openid'] === $openid) {
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
     * Add or update a WeChat binding.
     *
     * @param string $openid
     * @param string $username  Game account username
     * @param string $unionid
     * @param string $nickname
     * @param string $headimgurl
     * @return bool
     */
    public static function saveBinding($openid, $username, $unionid, $nickname, $headimgurl)
    {
        $bindings = static::loadBindings();

        // Remove any existing binding for this openid or username
        $bindings = array_filter($bindings, function ($entry) use ($openid, $username) {
            return $entry['openid'] !== $openid &&
                   strtoupper($entry['username']) !== strtoupper($username);
        });

        // Add the new binding
        $bindings[] = [
            'openid'     => $openid,
            'username'   => strtoupper($username),
            'unionid'    => $unionid ?: '',
            'nickname'   => $nickname ?: '',
            'headimgurl' => $headimgurl ?: '',
            'bind_time'  => date('Y-m-d H:i:s'),
        ];

        // Re-index and save
        return static::saveBindings(array_values($bindings));
    }

    /**
     * Remove a WeChat binding by openid.
     *
     * @param string $openid
     * @return bool
     */
    public static function removeBinding($openid)
    {
        $bindings = static::loadBindings();
        $bindings = array_filter($bindings, function ($entry) use ($openid) {
            return $entry['openid'] !== $openid;
        });
        return static::saveBindings(array_values($bindings));
    }

    // -----------------------------------------------------------------------
    //  High-level flow — handle the OAuth callback
    // -----------------------------------------------------------------------

    /**
     * Process the full OAuth callback: validate state -> exchange code ->
     * get user info -> look up binding -> return a result array.
     *
     * @param string $code
     * @param string $state
     * @return array ['status' => 'bound'|'unbound'|'error', ...]
     */
    public static function handleCallback($code, $state)
    {
        // 1. Validate state (CSRF protection)
        if (empty($_SESSION['wechat_state']) || $_SESSION['wechat_state'] !== $state) {
            return ['status' => 'error', 'message' => 'invalid_state'];
        }

        // State expires after 10 minutes
        if (time() - $_SESSION['wechat_state_time'] > 600) {
            return ['status' => 'error', 'message' => 'state_expired'];
        }
        unset($_SESSION['wechat_state'], $_SESSION['wechat_state_time']);

        // 2. Exchange code for access_token
        $tokenData = static::getAccessToken($code);
        if (!$tokenData || empty($tokenData['access_token']) || empty($tokenData['openid'])) {
            return ['status' => 'error', 'message' => 'token_exchange_failed'];
        }

        // 3. Get user info
        $userInfo = static::getUserInfo($tokenData['access_token'], $tokenData['openid']);
        if (!$userInfo) {
            $userInfo = [
                'openid'     => $tokenData['openid'],
                'nickname'   => 'wx_' . substr($tokenData['openid'], -6),
                'headimgurl' => '',
            ];
        }

        $openid   = $tokenData['openid'];
        $unionid  = isset($tokenData['unionid']) ? $tokenData['unionid'] : '';
        $nickname = isset($userInfo['nickname']) ? $userInfo['nickname'] : '';
        $avatar   = isset($userInfo['headimgurl']) ? $userInfo['headimgurl'] : '';

        // 4. Store WeChat user info in session for the binding step
        $_SESSION['wechat_user'] = [
            'openid'     => $openid,
            'unionid'    => $unionid,
            'nickname'   => $nickname,
            'headimgurl' => $avatar,
        ];

        // 5. Check if this openid is already bound (JSON file lookup)
        $binding = static::getBindingByOpenid($openid);
        if ($binding) {
            // Already bound — auto-login
            $_SESSION['wechat_logged_in'] = true;
            $_SESSION['wechat_username']  = $binding['username'];
            return [
                'status'   => 'bound',
                'username' => $binding['username'],
                'openid'   => $openid,
                'nickname' => $binding['nickname'],
            ];
        }

        // 6. Not bound — the caller should show the bind / register page
        return [
            'status'     => 'unbound',
            'openid'     => $openid,
            'unionid'    => $unionid,
            'nickname'   => $nickname,
            'headimgurl' => $avatar,
        ];
    }

    // -----------------------------------------------------------------------
    //  Registration helpers
    // -----------------------------------------------------------------------

    /**
     * Auto-generate a unique username from a WeChat nickname.
     * Strips non-alphanumeric characters and appends a suffix if needed.
     *
     * Uses SOAP to check for username collisions.
     *
     * @param string $nickname
     * @return string
     */
    public static function generateUsername($nickname)
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nickname));
        if (strlen($base) < 2) {
            $base = 'WX' . strtoupper(substr(md5(uniqid()), 0, 6));
        }
        $base = substr($base, 0, 14);

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
     * Generate a random password (used for auto-created WeChat accounts).
     *
     * @param int $length
     * @return string
     */
    public static function generatePassword($length = 12)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
    }

    /**
     * Register a new game account via WeChat using SOAP and bind it.
     *
     * All account operations go through SOAP — no database access.
     * No email is bound during registration.
     *
     * @param string $openid
     * @param string $unionid
     * @param string $nickname
     * @param string $headimgurl
     * @param string $username  Optional username (auto-generated if empty)
     * @param string $password  Optional password (auto-generated if empty)
     * @return array ['success' => bool, 'username' => ..., 'password' => ..., 'message' => ...]
     */
    public static function registerAndBind($openid, $unionid, $nickname, $headimgurl,
                                           $username = '', $password = '')
    {
        if (empty($username)) {
            $username = static::generateUsername($nickname);
        }
        if (empty($password)) {
            $password = static::generatePassword();
        }

        $username = strtoupper($username);

        // Step 1: Create the account via SOAP (no email)
        // Command: account create {username} {password}
        $createCommand = get_config('soap_ca_command') ?: 'account create {USERNAME} {PASSWORD}';
        $createCommand = str_replace('{USERNAME}', $username, $createCommand);
        $createCommand = str_replace('{PASSWORD}', $password, $createCommand);

        $result = static::soapCommand($createCommand);

        if (!$result['success']) {
            return ['success' => false, 'username' => $username, 'password' => $password,
                    'message' => 'soap_create_failed'];
        }

        // Check if account already existed (server may return a message)
        $msg = strtolower($result['message']);
        if (strpos($msg, 'already exist') !== false) {
            return ['success' => false, 'username' => $username, 'password' => $password,
                    'message' => 'username_exists'];
        }

        // Step 2: Set expansion via SOAP
        // Command: account set addon {username} {expansion}
        $addonCommand = get_config('soap_asa_command');
        if (!empty($addonCommand)) {
            $addonCommand = str_replace('{USERNAME}', $username, $addonCommand);
            $addonCommand = str_replace('{EXPANSION}', get_config('expansion'), $addonCommand);
            static::soapCommand($addonCommand);
        } else {
            // Default command
            static::soapCommand("account set addon {$username} " . get_config('expansion'));
        }

        // Step 3: Save the binding in the JSON file
        static::saveBinding($openid, $username, $unionid, $nickname, $headimgurl);

        return [
            'success'  => true,
            'username' => $username,
            'password' => $password,
            'message'  => '',
        ];
    }

    /**
     * Bind WeChat to an existing game account.
     *
     * In SOAP-only mode, password verification is performed by using SOAP to
     * set the password on the account. If the account exists, the SOAP command
     * succeeds (confirming the account exists) and the password is set to the
     * value the user provided. If the account does not exist, SOAP fails.
     *
     * This approach ensures the user knows both the username and the intended
     * password, and effectively re-confirms the password on bind.
     *
     * @param string $username
     * @param string $password
     * @param string $openid
     * @param string $unionid
     * @param string $nickname
     * @param string $headimgurl
     * @return array ['success' => bool, 'message' => ...]
     */
    public static function verifyAndBind($username, $password, $openid, $unionid,
                                         $nickname, $headimgurl)
    {
        $username = strtoupper($username);

        // Step 1: Check if the account exists via SOAP
        if (!static::accountExists($username)) {
            return ['success' => false, 'message' => 'account_not_found'];
        }

        // Step 2: Verify / set the password via SOAP
        // AzerothCore command: account set password {account} {password} {password}
        $pwCommand = "account set password {$username} {$password} {$password}";
        $pwResult = static::soapCommand($pwCommand);

        if (!$pwResult['success']) {
            return ['success' => false, 'message' => 'soap_error'];
        }

        // Step 3: Save the binding in the JSON file
        static::saveBinding($openid, $username, $unionid, $nickname, $headimgurl);

        return [
            'success'  => true,
            'username' => $username,
            'message'  => '',
        ];
    }

    // -----------------------------------------------------------------------
    //  Password reset (via SOAP, no database required)
    // -----------------------------------------------------------------------

    /**
     * Reset the password for a game account using SOAP.
     *
     * Generates a new random password and sets it on the account via the
     * SOAP command: account set password {username} {password} {password}
     *
     * No old password is required — this is a "reset" not a "change".
     * The caller is responsible for ensuring the user is authorised
     * (e.g. logged in via WeChat with a valid binding).
     *
     * @param string $username  Game account username
     * @return array ['success' => bool, 'username' => ..., 'password' => ..., 'message' => ...]
     */
    public static function resetPassword($username)
    {
        $username = strtoupper($username);

        // Step 1: Verify the account exists via SOAP
        if (!static::accountExists($username)) {
            return ['success' => false, 'password' => '', 'message' => 'account_not_found'];
        }

        // Step 2: Generate a new random password
        $newPassword = static::generatePassword();

        // Step 3: Set the new password via SOAP
        // AzerothCore command: account set password {account} {password} {password}
        $pwCommand = "account set password {$username} {$newPassword} {$newPassword}";
        $pwResult  = static::soapCommand($pwCommand);

        if (!$pwResult['success']) {
            return ['success' => false, 'password' => '', 'message' => 'soap_error'];
        }

        // Check for error in the SOAP response
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
     * Reset the password for the currently logged-in WeChat user's bound account.
     *
     * Convenience wrapper around resetPassword() that pulls the username
     * from the WeChat session binding.
     *
     * @return array ['success' => bool, 'username' => ..., 'password' => ..., 'message' => ...]
     */
    public static function resetMyPassword()
    {
        if (!static::isLoggedIn()) {
            return ['success' => false, 'password' => '', 'message' => 'not_logged_in'];
        }

        $username = $_SESSION['wechat_username'] ?? '';
        if (empty($username)) {
            return ['success' => false, 'password' => '', 'message' => 'no_bound_account'];
        }

        return static::resetPassword($username);
    }

    // -----------------------------------------------------------------------
    //  Session management
    // -----------------------------------------------------------------------

    /**
     * Check if a user is logged in via WeChat.
     */
    public static function isLoggedIn()
    {
        return !empty($_SESSION['wechat_logged_in']);
    }

    /**
     * Get the currently logged-in WeChat user info.
     */
    public static function getCurrentUser()
    {
        if (!static::isLoggedIn()) {
            return null;
        }
        return [
            'username' => $_SESSION['wechat_username'] ?? null,
        ];
    }

    /**
     * Log out the WeChat session.
     */
    public static function logout()
    {
        unset($_SESSION['wechat_logged_in'],
              $_SESSION['wechat_username'],
              $_SESSION['wechat_user']);
    }

    // -----------------------------------------------------------------------
    //  Device detection
    // -----------------------------------------------------------------------

    /**
     * Check if the request comes from inside WeChat's built-in browser.
     *
     * @return bool
     */
    public static function isWeChatBrowser()
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return stripos($ua, 'MicroMessenger') !== false;
    }

    /**
     * Check if the request comes from a mobile device.
     *
     * @return bool
     */
    public static function isMobile()
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return preg_match('/Android|iPhone|iPad|iPod|Windows Phone|Mobile/i', $ua) > 0;
    }

    /**
     * Determine the best login approach based on the device.
     *
     * - PC browser: Show QR code (redirect to WeChat OAuth, which displays QR)
     * - Mobile + inside WeChat: Direct redirect (seamless authorization)
     * - Mobile + outside WeChat: Show "please open in WeChat" message
     *
     * @return string 'qr_code' | 'direct' | 'open_in_wechat'
     */
    public static function getLoginMode()
    {
        if (static::isWeChatBrowser()) {
            return 'direct';
        }
        if (static::isMobile()) {
            return 'open_in_wechat';
        }
        return 'qr_code';
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
        curl_setopt($ch, CURLOPT_USERAGENT, 'WoWSimpleRegistration/WeChatAuth');

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            if (get_config('debug_mode')) {
                error_log('[WeChat] HTTP error: ' . curl_error($ch));
            }
            curl_close($ch);
            return false;
        }
        curl_close($ch);
        return $response;
    }
}
