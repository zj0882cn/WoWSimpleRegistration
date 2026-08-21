<?php
session_start();

if (isset($_SESSION['account_id'])) {
    header('Location: profile.php');
    exit;
}

$dataFile = __DIR__ . '/application/data/users.json';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account  = trim($_POST['account'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if ($account === '' || $password === '') {
        $error = '请输入账号和密码';
    } elseif (strlen($account) < 3 || strlen($account) > 20) {
        $error = '账号长度需在3-20个字符之间';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $account)) {
        $error = '账号只能包含字母、数字和下划线';
    } elseif ($password !== $confirm) {
        $error = '两次输入的密码不一致';
    } elseif (strlen($password) < 6) {
        $error = '密码长度不能少于6个字符';
    } else {
        $content = file_get_contents($dataFile);
        $data = json_decode($content, true);

        if (!is_array($data)) {
            $error = '系统错误，请稍后再试';
        } else {
            $exists = false;
            foreach ($data['users'] as $user) {
                if ($user['account'] === $account) {
                    $exists = true;
                    break;
                }
            }

            if ($exists) {
                $error = '账号已存在';
            } else {
                $newId = count($data['users']) > 0 ? max(array_keys($data['users'])) + 1 : 1;
                $data['users'][(string)$newId] = [
                    'account' => $account,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'email' => '',
                    'created_at' => date('Y-m-d H:i:s')
                ];

                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                file_put_contents($dataFile, $json, LOCK_EX);

                $success = '注册成功，请登录';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注册</title>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .box { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 360px; }
        h2 { text-align: center; margin-bottom: 24px; }
        input { width: 100%; padding: 10px; margin-bottom: 16px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #4a90d9; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #357abd; }
        .error { color: #e74c3c; text-align: center; margin-bottom: 16px; }
        .success { color: #27ae60; text-align: center; margin-bottom: 16px; }
        .link { text-align: center; margin-top: 16px; }
        .link a { color: #4a90d9; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <h2>注册</h2>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="text" name="account" placeholder="账号（3-20位字母数字下划线）" value="<?php echo htmlspecialchars($_POST['account'] ?? ''); ?>" required>
            <input type="password" name="password" placeholder="密码（至少6位）" required>
            <input type="password" name="confirm" placeholder="确认密码" required>
            <button type="submit">注册</button>
        </form>

        <div class="link">
            已有账号？<a href="login.php">去登录</a>
        </div>
    </div>
</body>
</html>
