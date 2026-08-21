<?php
session_start();

if (isset($_SESSION['account_id'])) {
    header('Location: profile.php');
    exit;
}

$dataFile = __DIR__ . '/application/data/users.json';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account  = trim($_POST['account'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($account === '' || $password === '') {
        $error = '请输入账号和密码';
    } else {
        $content = file_get_contents($dataFile);
        $data = json_decode($content, true);

        if (!is_array($data) || empty($data['users'])) {
            $error = '账号或密码错误';
        } else {
            $found = null;
            $foundId = null;

            foreach ($data['users'] as $id => $user) {
                if ($user['account'] === $account) {
                    $found = $user;
                    $foundId = $id;
                    break;
                }
            }

            if ($found && password_verify($password, $found['password_hash'])) {
                $_SESSION['account_id'] = (int)$foundId;
                $_SESSION['account'] = $account;
                header('Location: profile.php');
                exit;
            } else {
                $error = '账号或密码错误';
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
    <title>登录</title>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .box { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 360px; }
        h2 { text-align: center; margin-bottom: 24px; }
        input { width: 100%; padding: 10px; margin-bottom: 16px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #4a90d9; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #357abd; }
        .error { color: #e74c3c; text-align: center; margin-bottom: 16px; }
        .link { text-align: center; margin-top: 16px; }
        .link a { color: #4a90d9; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <h2>登录</h2>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="text" name="account" placeholder="账号" value="<?php echo htmlspecialchars($_POST['account'] ?? ''); ?>" required>
            <input type="password" name="password" placeholder="密码" required>
            <button type="submit">登录</button>
        </form>

        <div class="link">
            没有账号？<a href="register.php">去注册</a>
        </div>
    </div>
</body>
</html>
