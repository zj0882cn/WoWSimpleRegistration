<?php
session_start();

if (!isset($_SESSION['account_id'])) {
    header('Location: login.php');
    exit;
}

$dataFile = __DIR__ . '/application/data/users.json';

$accountId = $_SESSION['account_id'];
$account = $_SESSION['account'];
$email = '';
$createdAt = '';

$content = file_get_contents($dataFile);
$data = json_decode($content, true);

if (is_array($data) && isset($data['users'][(string)$accountId])) {
    $user = $data['users'][(string)$accountId];
    $email = $user['email'] ?? '';
    $createdAt = $user['created_at'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>个人中心</title>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .box { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 400px; }
        h2 { text-align: center; margin-bottom: 24px; }
        .info { margin-bottom: 12px; padding: 12px; background: #f9f9f9; border-radius: 4px; }
        .info .label { color: #888; font-size: 13px; }
        .info .value { font-size: 16px; margin-top: 4px; }
        .actions { margin-top: 24px; text-align: center; }
        .actions a { display: inline-block; padding: 10px 20px; background: #4a90d9; color: #fff; text-decoration: none; border-radius: 4px; margin: 4px; }
        .actions a:hover { background: #357abd; }
        .actions .logout { background: #e74c3c; }
        .actions .logout:hover { background: #c0392b; }
    </style>
</head>
<body>
    <div class="box">
        <h2>个人中心</h2>

        <div class="info">
            <div class="label">账号</div>
            <div class="value"><?php echo htmlspecialchars($account); ?></div>
        </div>

        <div class="info">
            <div class="label">邮箱</div>
            <div class="value"><?php echo $email ? htmlspecialchars($email) : '未绑定'; ?></div>
        </div>

        <div class="info">
            <div class="label">注册时间</div>
            <div class="value"><?php echo htmlspecialchars($createdAt); ?></div>
        </div>

        <div class="actions">
            <a href="bind_email.php"><?php echo $email ? '修改邮箱' : '绑定邮箱'; ?></a>
            <a href="logout.php" class="logout">退出登录</a>
        </div>
    </div>
</body>
</html>
