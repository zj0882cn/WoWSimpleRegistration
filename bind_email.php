<?php
session_start();

if (!isset($_SESSION['account_id'])) {
    header('Location: login.php');
    exit;
}

$dataFile = __DIR__ . '/application/data/users.json';

$accountId = $_SESSION['account_id'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $error = '请输入邮箱';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } else {
        $fp = fopen($dataFile, 'c+');

        if (!$fp) {
            $error = '系统错误，请稍后再试';
        } else {
            flock($fp, LOCK_EX);

            $content = stream_get_contents($fp);
            $data = json_decode($content, true);

            if (is_array($data) && isset($data['users'][(string)$accountId])) {
                $data['users'][(string)$accountId]['email'] = $email;

                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, $json);

                $success = '邮箱绑定成功';
            } else {
                $error = '账号不存在';
            }

            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}

$currentEmail = '';
$content = file_get_contents($dataFile);
$data = json_decode($content, true);

if (is_array($data) && isset($data['users'][(string)$accountId])) {
    $currentEmail = $data['users'][(string)$accountId]['email'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>绑定邮箱</title>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .box { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 360px; }
        h2 { text-align: center; margin-bottom: 24px; }
        input { width: 100%; padding: 10px; margin-bottom: 16px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #4a90d9; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #357abd; }
        .error { color: #e74c3c; text-align: center; margin-bottom: 16px; }
        .success { color: #27ae60; text-align: center; margin-bottom: 16px; }
        .current { color: #888; text-align: center; margin-bottom: 16px; font-size: 14px; }
        .link { text-align: center; margin-top: 16px; }
        .link a { color: #4a90d9; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <h2>绑定邮箱</h2>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($currentEmail): ?>
            <div class="current">当前绑定：<?php echo htmlspecialchars($currentEmail); ?></div>
        <?php else: ?>
            <div class="current">尚未绑定邮箱</div>
        <?php endif; ?>

        <form method="post">
            <input type="email" name="email" placeholder="输入邮箱" value="<?php echo htmlspecialchars($_POST['email'] ?? $currentEmail); ?>" required>
            <button type="submit">保存</button>
        </form>

        <div class="link">
            <a href="profile.php">返回个人中心</a>
        </div>
    </div>
</body>
</html>
