<?php
/**
 * Mock WeChat OAuth Server — Simulates WeChat Open Platform APIs
 *
 * This file is used with PHP's built-in web server:
 *   php -S 0.0.0.0:9090 mock_wechat_oauth.php
 *
 * It simulates the following WeChat API endpoints:
 *   1. GET  /connect/qrconnect          — QR code authorization page
 *   2. GET  /sns/oauth2/access_token     — Exchange code for access_token
 *   3. GET  /sns/userinfo                — Get WeChat user info
 *   4. GET  /sns/oauth2/refresh_token    — Refresh access_token
 *   5. GET  /sns/auth                    — Verify access_token
 *
 * This allows full end-to-end testing of the WeChat login flow
 * WITHOUT real WeChat AppID/AppSecret or a registered Website Application.
 *
 * @author AzerothCore Community
 **/

session_start();

// ---- File-based storage for codes/tokens (works across requests without cookies) ----
$codesFile  = __DIR__ . '/mock_oauth_codes.json';
$tokensFile = __DIR__ . '/mock_oauth_tokens.json';

function loadJson($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveJson($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// ---- Load mock user profiles ----
$usersFile = __DIR__ . '/mock_wechat_users.json';
$mockUsers = [];
if (file_exists($usersFile)) {
    $mockUsers = json_decode(file_get_contents($usersFile), true) ?: [];
}

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$query  = $_GET;

$timestamp = date('H:i:s');
error_log("[{$timestamp}] WeChat Mock: {$method} {$uri}");

// =========================================================================
//  1. QR Code Authorization Page (Website Application)
//    Real URL: https://open.weixin.qq.com/connect/qrconnect?appid=...&redirect_uri=...&state=...
//  Also handles Official Account OAuth:
//    Real URL: https://open.weixin.qq.com/connect/oauth2/authorize?appid=...&redirect_uri=...&state=...
//
//  For Official Account:
//    - PC browser: Shows QR code page (same as qrconnect)
//    - Mobile + WeChat browser: Auto-redirects with code (seamless)
// =========================================================================
if ($uri === '/connect/qrconnect' || $uri === '/connect/oauth2/authorize') {
    $redirectUri = $query['redirect_uri'] ?? '';
    $state       = $query['state'] ?? '';
    $appid       = $query['appid'] ?? 'mock_appid';
    $scope       = $query['scope'] ?? 'snsapi_userinfo';

    // redirect_uri is already decoded by PHP's $_GET
    // but may be double-encoded if old code used urlencode()
    if (strpos($redirectUri, '%3A') !== false) {
        $redirectUri = urldecode($redirectUri);
    }

    // Store in session so the "scan" handler knows where to redirect
    $_SESSION['mock_redirect_uri'] = $redirectUri;
    $_SESSION['mock_state']        = $state;
    $_SESSION['mock_appid']        = $appid;

    // Detect device
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isWeChat = stripos($ua, 'MicroMessenger') !== false;
    $isMobile = preg_match('/Android|iPhone|iPad|iPod|Windows Phone|Mobile/i', $ua) > 0;

    // Official Account + WeChat browser: simulate seamless authorization
    if ($uri === '/connect/oauth2/authorize' && $isWeChat) {
        // Auto-generate code and redirect (no QR code needed)
        $code = 'mock_code_' . bin2hex(random_bytes(8));
        $allCodes = loadJson($codesFile);
        $allCodes[$code] = [
            'user_key'   => 'default',
            'created_at' => time(),
        ];
        if (count($allCodes) > 50) {
            $allCodes = array_slice($allCodes, -50, null, true);
        }
        saveJson($codesFile, $allCodes);

        $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
        $callbackUrl = $redirectUri . $separator . 'code=' . $code . '&state=' . urlencode($state);
        error_log("[{$timestamp}] WeChat browser auto-auth redirect: {$callbackUrl}");
        header('Location: ' . $callbackUrl);
        exit;
    }

    // Show a fake QR code page with a "Simulate Scan" button
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>微信扫码登录 (模拟)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <style>
        body {
            background: #f5f7fa;
            font-family: -apple-system, "PingFang SC", "Noto Sans CJK SC", "Microsoft YaHei", sans-serif;
        }
        .qr-container {
            max-width: 400px;
            margin: 60px auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            padding: 40px 30px;
            text-align: center;
        }
        .mock-qr {
            width: 200px;
            height: 200px;
            margin: 20px auto;
            background: #fff;
            border: 2px dashed #07c160;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .mock-qr-pattern {
            font-size: 120px;
            color: #07c160;
            line-height: 1;
        }
        .mock-badge {
            position: absolute;
            top: -12px;
            right: -12px;
            background: #ff9800;
            color: #fff;
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        .btn-wechat {
            background: #07c160;
            color: #fff;
            border: none;
            padding: 12px 40px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-wechat:hover { background: #06ad56; color: #fff; }
        .btn-secondary-mock {
            background: #e0e0e0;
            color: #666;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 14px;
            margin-top: 10px;
        }
        .user-selector {
            margin: 20px 0;
            text-align: left;
        }
        .user-selector select {
            width: 100%;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #ddd;
            font-size: 14px;
        }
        .info-text {
            color: #999;
            font-size: 13px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="qr-container">
        <h4 style="color: #333; margin-bottom: 5px;">
            <i class="fab fa-weixin text-success"></i> 微信扫码登录
        </h4>
        <p class="text-muted" style="font-size: 13px;">使用微信扫描二维码登录</p>

        <div class="mock-qr">
            <span class="mock-qr-pattern">Ⴅ</span>
            <span class="mock-badge">模拟</span>
        </div>

        <div class="user-selector">
            <label class="text-muted" style="font-size: 13px;">选择模拟微信用户:</label>
            <select id="mockUser" class="form-control">
                <option value="default">默认用户 (微信测试玩家)</option>
                <option value="gamer">游戏达人 (TestGamer)</option>
                <option value="newbie">萌新玩家 (TestNewbie)</option>
                <option value="vip">VIP玩家 (TestVIP)</option>
                <option value="random">随机用户</option>
            </select>
        </div>

        <div class="mt-3">
            <form method="POST" action="/mock_scan" id="scanForm">
                <input type="hidden" name="mock_user" id="mockUserInput" value="default">
                <button type="submit" class="btn btn-wechat">
                    <i class="fas fa-qrcode"></i> 模拟扫码确认登录
                </button>
            </form>
            <button class="btn btn-secondary-mock" onclick="window.close()">
                取消
            </button>
        </div>

        <p class="info-text">
            <i class="fas fa-info-circle"></i> 这是模拟微信登录页面，点击上方按钮即可模拟扫码登录。<br>
            无需真实的微信 AppID / AppSecret。
        </p>
    </div>

    <script>
        document.getElementById('scanForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var user = document.getElementById('mockUser').value;
            document.getElementById('mockUserInput').value = user;
            this.submit();
        });
    </script>
</body>
</html>
    <?php
    exit;
}

// =========================================================================
//  1b. Handle the "simulated scan" — redirect back to the app's callback
// =========================================================================
if ($uri === '/mock_scan' && $method === 'POST') {
    $redirectUri = $_SESSION['mock_redirect_uri'] ?? '';
    $state       = $_SESSION['mock_state'] ?? '';
    $mockUserKey = $_POST['mock_user'] ?? 'default';

    if (empty($redirectUri) || empty($state)) {
        http_response_code(400);
        echo 'Missing redirect URI or state. Please start from the login page.';
        exit;
    }

    // Generate a mock authorization code
    $code = 'mock_code_' . bin2hex(random_bytes(8));

    // Store the mock user selection with the code (file-based, not session)
    $allCodes = loadJson($codesFile);
    $allCodes[$code] = [
        'user_key'   => $mockUserKey,
        'created_at' => time(),
    ];
    // Keep only last 50 codes
    if (count($allCodes) > 50) {
        $allCodes = array_slice($allCodes, -50, null, true);
    }
    saveJson($codesFile, $allCodes);

    // Build the redirect URL (just like WeChat does)
    $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
    $callbackUrl = $redirectUri . $separator . 'code=' . $code . '&state=' . urlencode($state);

    error_log("[{$timestamp}] Mock redirect to: {$callbackUrl}");

    // Redirect back to the application's callback handler
    header('Location: ' . $callbackUrl);
    exit;
}

// =========================================================================
//  2. Exchange authorization code for access_token
//    Real URL: https://api.weixin.qq.com/sns/oauth2/access_token?appid=...&code=...&grant_type=authorization_code
// =========================================================================
if ($uri === '/sns/oauth2/access_token') {
    $code = $query['code'] ?? '';

    // Look up the mock user for this code (file-based)
    $allCodes = loadJson($codesFile);
    $codeData = $allCodes[$code] ?? null;
    if (!$codeData) {
        echo json_encode(['errcode' => 40029, 'errmsg' => 'invalid code']);
        exit;
    }

    // Clean up old codes
    unset($allCodes[$code]);
    saveJson($codesFile, $allCodes);

    $userKey = $codeData['user_key'];
    $userInfo = getMockUser($userKey);

    // Generate mock tokens
    $openid       = $userInfo['openid'];
    $accessToken  = 'mock_access_' . bin2hex(random_bytes(8));
    $refreshToken = 'mock_refresh_' . bin2hex(random_bytes(8));

    // Store tokens in file (not session)
    $allTokens = loadJson($tokensFile);
    $allTokens[$accessToken] = [
        'openid'        => $openid,
        'user_info'     => $userInfo,
        'expires_at'    => time() + 7200,
        'refresh_token' => $refreshToken,
    ];
    saveJson($tokensFile, $allTokens);

    echo json_encode([
        'access_token'  => $accessToken,
        'expires_in'    => 7200,
        'refresh_token' => $refreshToken,
        'openid'        => $openid,
        'scope'         => 'snsapi_login',
        'unionid'       => $userInfo['unionid'] ?? '',
    ]);
    exit;
}

// =========================================================================
//  3. Get WeChat user info
//    Real URL: https://api.weixin.qq.com/sns/userinfo?access_token=...&openid=...
// =========================================================================
if ($uri === '/sns/userinfo') {
    $accessToken = $query['access_token'] ?? '';
    $openid      = $query['openid'] ?? '';

    // Look up token data from file
    $allTokens = loadJson($tokensFile);
    $tokenData = $allTokens[$accessToken] ?? null;
    if (!$tokenData) {
        echo json_encode(['errcode' => 40001, 'errmsg' => 'invalid credential']);
        exit;
    }

    $userInfo = $tokenData['user_info'];

    echo json_encode([
        'openid'     => $userInfo['openid'],
        'nickname'   => $userInfo['nickname'],
        'sex'        => $userInfo['sex'] ?? 0,
        'language'   => 'zh_CN',
        'city'       => $userInfo['city'] ?? '',
        'province'   => $userInfo['province'] ?? '',
        'country'    => $userInfo['country'] ?? 'CN',
        'headimgurl' => $userInfo['headimgurl'] ?? '',
        'privilege'  => [],
        'unionid'    => $userInfo['unionid'] ?? '',
    ]);
    exit;
}

// =========================================================================
//  4. Refresh access_token
//    Real URL: https://api.weixin.qq.com/sns/oauth2/refresh_token?appid=...&grant_type=refresh_token&refresh_token=...
// =========================================================================
if ($uri === '/sns/oauth2/refresh_token') {
    $refreshToken = $query['refresh_token'] ?? '';

    // Token data was already stored during access_token exchange
    $allTokens = loadJson($tokensFile);
    // Find the token data by searching for a matching refresh_token entry
    // Since we don't store refresh tokens separately anymore, we look for any token
    // with the same openid. For mock purposes, this is fine.
    $tokenData = null;
    foreach ($allTokens as $t) {
        if (isset($t['refresh_token']) && $t['refresh_token'] === $refreshToken) {
            $tokenData = $t;
            break;
        }
    }
    // Fallback: if not found, check if it's a known refresh token pattern
    if (!$tokenData) {
        echo json_encode(['errcode' => 40030, 'errmsg' => 'invalid refresh_token']);
        exit;
    }

    $newAccessToken = 'mock_access_' . bin2hex(random_bytes(8));
    $allTokens[$newAccessToken] = [
        'openid'     => $tokenData['openid'],
        'user_info'  => $tokenData['user_info'],
        'expires_at' => time() + 7200,
    ];
    saveJson($tokensFile, $allTokens);

    echo json_encode([
        'access_token'  => $newAccessToken,
        'expires_in'    => 7200,
        'refresh_token' => $refreshToken,
        'openid'        => $tokenData['openid'],
        'scope'         => 'snsapi_login',
        'unionid'       => $tokenData['user_info']['unionid'] ?? '',
    ]);
    exit;
}

// =========================================================================
//  5. Verify access_token
//    Real URL: https://api.weixin.qq.com/sns/auth?access_token=...&openid=...
// =========================================================================
if ($uri === '/sns/auth') {
    $accessToken = $query['access_token'] ?? '';

    if (isset($_SESSION['mock_tokens'][$accessToken])) {
        echo json_encode(['errcode' => 0, 'errmsg' => 'ok']);
    } else {
        $allTokens = loadJson($tokensFile);
        if (isset($allTokens[$accessToken])) {
            echo json_encode(['errcode' => 0, 'errmsg' => 'ok']);
        } else {
            echo json_encode(['errcode' => 40001, 'errmsg' => 'invalid credential']);
        }
    }
    exit;
}

// =========================================================================
//  6. Mock user management API (for test console)
// =========================================================================
if ($uri === '/mock_api/users' && $method === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(array_values($mockUsers));
    exit;
}

if ($uri === '/mock_api/status' && $method === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'status'    => 'running',
        'endpoints' => [
            '/connect/qrconnect',
            '/sns/oauth2/access_token',
            '/sns/userinfo',
            '/sns/oauth2/refresh_token',
            '/sns/auth',
        ],
        'active_tokens'   => count(loadJson($tokensFile)),
        'active_codes'    => count(loadJson($codesFile)),
    ]);
    exit;
}

// =========================================================================
//  Helper: Get a mock WeChat user profile
// =========================================================================
function getMockUser($key)
{
    $users = [
        'default' => [
            'openid'     => 'mock_openid_default',
            'unionid'    => 'mock_unionid_default',
            'nickname'   => '微信测试玩家',
            'sex'        => 1,
            'city'       => '北京',
            'province'   => '北京',
            'country'    => 'CN',
            'headimgurl' => 'https://placehold.co/96x96/07c160/fff?text=WX',
        ],
        'gamer' => [
            'openid'     => 'mock_openid_gamer',
            'unionid'    => 'mock_unionid_gamer',
            'nickname'   => 'TestGamer',
            'sex'        => 1,
            'city'       => '上海',
            'province'   => '上海',
            'country'    => 'CN',
            'headimgurl' => 'https://placehold.co/96x96/e74c3c/fff?text=Gamer',
        ],
        'newbie' => [
            'openid'     => 'mock_openid_newbie',
            'unionid'    => 'mock_unionid_newbie',
            'nickname'   => 'TestNewbie',
            'sex'        => 2,
            'city'       => '广州',
            'province'   => '广东',
            'country'    => 'CN',
            'headimgurl' => 'https://placehold.co/96x96/3498db/fff?text=New',
        ],
        'vip' => [
            'openid'     => 'mock_openid_vip',
            'unionid'    => 'mock_unionid_vip',
            'nickname'   => 'TestVIP',
            'sex'        => 1,
            'city'       => '深圳',
            'province'   => '广东',
            'country'    => 'CN',
            'headimgurl' => 'https://placehold.co/96x96/f39c12/fff?text=VIP',
        ],
        'random' => [
            'openid'     => 'mock_openid_' . bin2hex(random_bytes(4)),
            'unionid'    => 'mock_unionid_' . bin2hex(random_bytes(4)),
            'nickname'   => 'RandomUser_' . rand(1000, 9999),
            'sex'        => rand(1, 2),
            'city'       => '杭州',
            'province'   => '浙江',
            'country'    => 'CN',
            'headimgurl' => 'https://placehold.co/96x96/9b59b6/fff?text=RND',
        ],
    ];

    return $users[$key] ?? $users['default'];
}

// =========================================================================
//  404 for anything else
// =========================================================================
http_response_code(404);
echo json_encode(['errcode' => -1, 'errmsg' => 'Mock endpoint not found: ' . $uri]);
