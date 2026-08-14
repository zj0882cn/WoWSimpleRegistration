<?php
/**
 * Bot Start API - 启动角色挂机模式
 *
 * 通过任务队列与 Bot Manager 通信，实现真正的后台挂机。
 * Bot Manager 会在后台运行 WoW 客户端模拟器，保持角色在线。
 */

require_once __DIR__ . '/application/include/loader.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Only handle AJAX actions
$ajaxAction = trim($_POST['ajax_action'] ?? '');
$account  = trim($_POST['account'] ?? '');
$character = trim($_POST['character'] ?? '');

// Data paths
$dataDir = __DIR__ . '/application/data';
$tasksFile = $dataDir . '/bot_tasks.json';
$statusFile = $dataDir . '/bot_status.json';

// Ensure data directory
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

// Helper: read JSON file
function readJsonFile($path, $default = []) {
    if (!file_exists($path)) return $default;
    $content = @file_get_contents($path);
    if ($content === false) return $default;
    $data = json_decode($content, true);
    return is_array($data) ? $data : $default;
}

// Helper: write JSON file
function writeJsonFile($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $result = file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $result !== false;
}

// Helper: send command to Bot Manager via Unix socket
function sendBotManagerCommand($cmd) {
    $sockFile = __DIR__ . '/application/data/bot_manager.sock';
    if (!file_exists($sockFile)) {
        return ['success' => false, 'message' => 'NOT_RUNNING'];
    }

    $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if (!$socket) {
        return ['success' => false, 'message' => 'SOCKET_CREATE_FAILED'];
    }

    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

    $result = @socket_connect($socket, $sockFile);
    if (!$result) {
        socket_close($socket);
        return ['success' => false, 'message' => 'CONNECT_FAILED'];
    }

    $payload = json_encode($cmd) . "\n";
    @socket_send($socket, $payload, strlen($payload), 0);

    $response = '';
    $totalRead = 0;
    $maxRead = 8192;
    while ($totalRead < $maxRead) {
        $chunk = @socket_read($socket, $maxRead - $totalRead);
        if ($chunk === false || $chunk === '') break;
        $response .= $chunk;
        $totalRead += strlen($chunk);
        // Try to parse early exit
        $decoded = @json_decode($response, true);
        if (is_array($decoded)) break;
    }

    socket_close($socket);

    if (empty($response)) {
        return ['success' => false, 'message' => 'NO_RESPONSE'];
    }

    $decoded = @json_decode($response, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'PARSE_ERROR'];
}

// Helper: ensure Bot Manager is running
function ensureBotManager() {
    $pidFile = __DIR__ . '/application/data/bot_manager.pid';
    if (file_exists($pidFile)) {
        $pid = (int)file_get_contents($pidFile);
        if ($pid > 0 && posix_kill($pid, 0)) {
            return true; // Already running
        }
    }

    // Try to start it
    $script = __DIR__ . '/scripts/bot_manager.py';
    if (!file_exists($script)) {
        return false;
    }

    $cmd = sprintf(
        'nohup python3 %s daemon > /dev/null 2>&1 &',
        escapeshellarg($script)
    );

    exec($cmd);
    sleep(2); // Give it time to start

    // Check if started
    if (file_exists($pidFile)) {
        $pid = (int)file_get_contents($pidFile);
        if ($pid > 0) {
            return true;
        }
    }

    return false;
}

// --- Route AJAX actions ---

if ($ajaxAction === 'status') {
    // Check bot manager status
    $response = sendBotManagerCommand(['action' => 'status']);

    if (!$response['success'] && in_array($response['message'] ?? '', ['NOT_RUNNING', 'CONNECT_FAILED', 'SOCKET_CREATE_FAILED', 'NO_RESPONSE'])) {
        // Try to start it
        ensureBotManager();
        // Re-check
        sleep(1);
        $response = sendBotManagerCommand(['action' => 'status']);
    }

    // Also include last saved status
    $savedStatus = readJsonFile($statusFile, []);
    echo json_encode([
        'online' => $response['success'] ?? false,
        'status' => $response,
        'saved_status' => $savedStatus,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajaxAction === 'stop' || $ajaxAction === 'stop_bot') {
    $response = sendBotManagerCommand([
        'action' => 'stop',
        'account' => $account,
        'character' => $character,
    ]);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajaxAction === 'ping') {
    $response = sendBotManagerCommand(['action' => 'ping']);
    echo json_encode([
        'manager_running' => $response['success'] ?? false,
        'response' => $response,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Default: start bot
// Require login
if (!MobileAuth::isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'not_logged_in']);
    exit;
}

if (empty($account) || empty($character)) {
    echo json_encode(['success' => false, 'message' => '参数不完整']);
    exit;
}

// Verify ownership
$currentUser = MobileAuth::getCurrentUser();
if (!$currentUser || strtoupper($currentUser['username']) !== strtoupper($account)) {
    echo json_encode(['success' => false, 'message' => 'not_authorized']);
    exit;
}

// Get bot password
$botPassword = MobileAuth::getBotPassword($account);
if (empty($botPassword)) {
    echo json_encode([
        'success' => false,
        'message' => 'no_password_set',
        'hint' => '该账号未设置密码。请先登录并设置密码后再启动挂机模式。'
    ]);
    exit;
}

// Ensure Bot Manager is running
if (!ensureBotManager()) {
    echo json_encode([
        'success' => false,
        'message' => 'manager_start_failed',
        'hint' => '挂机服务启动失败，请联系管理员检查 bot_manager.py 是否可执行。'
    ]);
    exit;
}

// Try direct socket command first
$response = sendBotManagerCommand([
    'action' => 'start_bot',
    'account' => $account,
    'character' => $character,
    'password' => $botPassword,
    'host' => get_config('soap_host') ?: '127.0.0.1',
]);

// If socket communication failed, use task queue as fallback
if (!$response['success'] && in_array($response['message'] ?? '', ['NOT_RUNNING', 'CONNECT_FAILED', 'SOCKET_CREATE_FAILED', 'NO_RESPONSE'])) {
    // Use task queue
    $tasks = readJsonFile($tasksFile, []);
    $tasks[] = [
        'action' => 'start',
        'account' => $account,
        'character' => $character,
        'password' => $botPassword,
        'host' => get_config('soap_host') ?: '127.0.0.1',
        'created_at' => time(),
    ];
    writeJsonFile($tasksFile, $tasks);

    // Force restart bot manager to pick up tasks
    $pidFile = $dataDir . '/bot_manager.pid';
    if (file_exists($pidFile)) {
        $pid = (int)file_get_contents($pidFile);
        if ($pid > 0) {
            posix_kill($pid, SIGUSR1);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => '任务已提交，正在启动挂机模式...',
        'mode' => 'task_queue',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Return the manager's response
echo json_encode($response, JSON_UNESCAPED_UNICODE);