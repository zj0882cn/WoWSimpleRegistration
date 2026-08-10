<?php
/**
 * Header Template — Light Theme
 *
 * @author Amin Mahmoudi (MasterkinG)
 **/
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(get_config('page_title')) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <style>
        :root {
            --wechat-green: #07c160;
            --wechat-green-hover: #06ad56;
            --bg: #f5f7fa;
            --card-bg: #fff;
            --text: #333;
            --text-muted: #999;
            --border: #e8e8e8;
        }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Noto Sans CJK SC", "Microsoft YaHei", sans-serif;
        }
        .main-box {
            max-width: 900px;
            margin: 30px auto;
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            padding: 30px;
        }
        .main-box img {
            display: block;
            margin: 0 auto 10px;
            max-width: 200px;
        }
        .nav-tabs .nav-link {
            color: #666;
            border: none;
            border-bottom: 2px solid transparent;
            border-radius: 0;
            font-size: 14px;
        }
        .nav-tabs .nav-link.active {
            color: var(--wechat-green);
            border-bottom-color: var(--wechat-green);
            background: none;
        }
        .btn-wechat {
            background: var(--wechat-green);
            color: #fff;
            border: none;
            padding: 12px 40px;
            font-size: 16px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .btn-wechat:hover {
            background: var(--wechat-green-hover);
            color: #fff;
            text-decoration: none;
        }
        .btn-wechat i { margin-right: 8px; }
        .wechat-login-section {
            text-align: center;
            padding: 40px 20px;
        }
        .wechat-login-section h3 {
            margin-bottom: 10px;
            font-size: 22px;
            color: var(--text);
        }
        .wechat-login-section p {
            color: var(--text-muted);
            margin-bottom: 30px;
            font-size: 14px;
        }
        .account-info-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 24px;
            margin: 20px 0;
        }
        .account-info-card .label {
            color: #888;
            font-size: 13px;
        }
        .account-info-card .value {
            font-weight: 700;
            color: #333;
            font-size: 16px;
            font-family: 'Courier New', monospace;
        }
        .alert-wechat {
            background: #e8f7ef;
            border: 1px solid #07c160;
            color: #07c160;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        .alert-wechat-error {
            background: #fff3f3;
            border: 1px solid #dc3545;
            color: #dc3545;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
        }
        .password-warning {
            color: #e6a23c;
            font-size: 13px;
            margin-top: 8px;
        }
        .content_box1 {
            padding: 15px;
        }
        .soap-notice {
            font-size: 12px;
            color: #6c757d;
            margin-top: 15px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 4px;
            text-align: center;
        }
        /* Server Status Card */
        .server-status-card {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            border-radius: 12px;
            padding: 24px;
            color: #fff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        .server-status-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .server-status-header h4 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #fff;
        }
        .server-status-header h4 i {
            margin-right: 8px;
            color: #4facfe;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        .status-badge i {
            font-size: 8px;
        }
        .status-online {
            background: rgba(7, 193, 96, 0.2);
            color: #07c160;
            border: 1px solid rgba(7, 193, 96, 0.3);
        }
        .status-offline {
            background: rgba(220, 53, 69, 0.2);
            color: #ff6b6b;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        .server-status-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        @media (max-width: 576px) {
            .server-status-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
        }
        .status-item {
            text-align: center;
            padding: 16px 8px;
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            transition: background 0.2s;
        }
        .status-item:hover {
            background: rgba(255,255,255,0.1);
        }
        .status-icon {
            font-size: 20px;
            color: #4facfe;
            margin-bottom: 8px;
        }
        .status-value {
            font-size: 24px;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }
        .status-label {
            font-size: 12px;
            color: rgba(255,255,255,0.6);
            margin-top: 4px;
        }
    </style>
</head>
<body>
