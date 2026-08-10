# 微信认证模块集成指南 (SOAP-Only Mode)

## 概述

本模块为 WoWSimpleRegistration 添加微信扫码登录功能。**完全使用 SOAP 模式**，无需任何数据库连接。所有游戏账号操作（创建、设置资料片、设置邮箱、设置密码）均通过 SOAP 命令发送到 worldserver，微信绑定关系存储在本地 JSON 文件中。

## 文件清单

```
WoWSimpleRegistration/
├── application/
│   ├── config/
│   │   └── wechat_config_snippet.php     # 微信 + SOAP 配置片段（追加到 config.php）
│   ├── data/                              # 绑定数据目录（自动创建）
│   │   └── wechat_bindings.json           # 微信绑定记录（JSON 文件，自动生成）
│   └── include/
│       └── wechat.php                     # 微信 OAuth + SOAP 核心类
├── template/
│   └── light/
│       ├── wechat_login_button.php        # 微信登录按钮（嵌入注册页）
│       └── wechat_bind.php                # 微信绑定/注册页面模板
├── wechat_callback.php                    # 微信 OAuth 回调处理
├── wechat_bind.php                        # 微信绑定/注册入口
└── WECHAT_INTEGRATION_GUIDE.md            # 本文档
```

## 与旧版本的区别

| 项目 | 旧版本（数据库模式） | 新版本（SOAP模式） |
|------|---------------------|-------------------|
| 账号创建 | 直接 INSERT 到 account 表 | SOAP `account create` 命令 |
| 密码加密 | PHP 端计算 SRP6 | 服务端通过 SOAP 自动处理 |
| 邮箱设置 | 直接 UPDATE account 表 | SOAP `account set email` 命令 |
| 资料片设置 | INSERT 时写入 expansion | SOAP `account set addon` 命令 |
| 绑定存储 | MySQL account_wechat_bindings 表 | JSON 文件 (wechat_bindings.json) |
| 账号存在检查 | SELECT 查询 account 表 | SOAP `account set addon` 探测 |
| 密码验证 | SRP6 verifySRP6() | SOAP `account set password` 命令 |
| 数据库连接 | 需要 | **不需要** |
| SQL 文件 | 需要 | **不需要** |

## SOAP 命令对照

| 操作 | SOAP 命令 | 说明 |
|------|----------|------|
| 创建账号 | `account create {USERNAME} {PASSWORD} [EMAIL]` | 服务端自动 SRP6 加密 |
| 设置资料片 | `account set addon {USERNAME} {EXPANSION}` | 0=经典, 1=TBC, 2=WotLK... |
| 设置邮箱 | `account set email {USERNAME} {EMAIL} {REG_EMAIL}` | 设置当前邮箱和注册邮箱 |
| 设置密码 | `account set password {USERNAME} {PASSWORD} {PASSWORD}` | 用于绑定验证 |
| 检查账号存在 | `account set addon {USERNAME} {EXPANSION}` | 成功=存在, 失败=不存在 |

## 集成步骤

### 1. 配置 — 添加微信设置

将 `application/config/wechat_config_snippet.php` 的内容追加到你的 `config.php` 文件末尾，然后修改：

```php
$config['wechat_enabled']   = true;
$config['wechat_appid']     = '你的微信AppID';
$config['wechat_appsecret'] = '你的微信AppSecret';

// 确保 SOAP 设置正确
$config['soap_host']     = '127.0.0.1';
$config['soap_port']     = '7878';
$config['soap_username'] = 'admin_soap';
$config['soap_password'] = 'admin_soap';
```

### 2. 创建数据目录

确保 `application/data/` 目录存在且可写：

```bash
mkdir -p application/data
chmod 755 application/data
# 如果 web server 以 www-data 运行：
chown www-data:www-data application/data
```

JSON 绑定文件 `wechat_bindings.json` 会在首次绑定时自动创建。

### 3. 确认 worldserver SOAP 已启用

在 `worldserver.conf` 中确认：

```
SOAP.Enabled = 1
SOAP.IP = "127.0.0.1"
SOAP.Port = 7878
```

### 4. 加载模块 — 修改 index.php

在 `index.php` 中添加 WeChatAuth 类的加载。找到其他 `require_once` 行，在其后添加：

```php
require_once __DIR__ . '/application/include/wechat.php';
```

### 5. 处理微信登出 — 修改 index.php

在 `index.php` 的顶部（session_start 之后）添加：

```php
// Handle WeChat logout
if (!empty($_GET['wechat_logout'])) {
    WeChatAuth::logout();
    header('Location: ' . get_config('baseurl'));
    exit;
}
```

### 6. 嵌入微信登录按钮 — 修改模板

在注册页面模板（如 `template/light/main.php`）中，在注册表单下方添加：

```php
<?php include __DIR__ . '/template/' . get_config('template') . '/wechat_login_button.php'; ?>
```

### 7. 微信开放平台配置

1. 登录 [微信开放平台](https://open.weixin.qq.com/)
2. 创建「网站应用」并通过审核
3. 在应用设置中，将**授权回调域名**设为你的网站域名
4. 获取 AppID 和 AppSecret，填入 config.php

## 数据流

```
┌─────────────────────────────────────────────────────────────────┐
│                  SOAP-Only 模式数据流                             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  [注册页面]                                                      │
│  用户点击"微信登录"                                               │
│       │                                                         │
│       ▼                                                         │
│  [WeChatAuth::getAuthorizeUrl()]                                │
│  生成授权URL + CSRF state                                        │
│       │                                                         │
│       ▼  302重定向到微信                                          │
│  [微信开放平台] 用户扫码确认                                      │
│       │                                                         │
│       ▼  回调 code + state                                       │
│  [wechat_callback.php]                                          │
│       │                                                         │
│       ├─ 验证 state（CSRF防护）                                   │
│       ├─ getAccessToken(code) → access_token + openid           │
│       ├─ getUserInfo(token, openid) → nickname + avatar         │
│       │                                                         │
│       ├─ 查询 JSON 文件：openid 是否已绑定？                       │
│       │                                                         │
│       ├──────────┬──────────────┐                               │
│       ▼ 已绑定    ▼ 未绑定        │                               │
│  自动登录         跳转绑定页       │                               │
│       │          wechat_bind.php │                               │
│       │                │        │                               │
│       │     ┌──────────┤        │                               │
│       │     ▼          ▼        │                               │
│       │  自动注册    绑定已有     │                               │
│       │     │          │        │                               │
│       │     ▼          ▼        │                               │
│       │  [SOAP]     [SOAP]      │                               │
│       │  account    account     │                               │
│       │  create     set addon   │                               │
│       │  + set      (检查存在)   │                               │
│       │  addon    + account     │                               │
│       │  + set     set password │                               │
│       │  email    (验证密码)     │                               │
│       │     │          │        │                               │
│       │     ▼          ▼        │                               │
│       │  [JSON文件]  [JSON文件]  │                               │
│       │  保存绑定    保存绑定     │                               │
│       │     │          │        │                               │
│       └─────┴──────────┘        │                               │
│              ▼                  │                               │
│       显示成功页面                │                               │
│       (账号+密码)                │                               │
│                                                                 │
│  关键区别：                                                      │
│  ✗ 无 database::$auth 连接                                       │
│  ✗ 无 SQL INSERT/UPDATE                                          │
│  ✗ 无 SRP6 PHP 计算                                              │
│  ✓ 全部通过 SOAP 命令                                            │
│  ✓ 绑定数据存 JSON 文件                                          │
│  ✓ 服务端自动处理 SRP6 加密                                      │
└─────────────────────────────────────────────────────────────────┘
```

## JSON 绑定文件格式

`application/data/wechat_bindings.json`:

```json
[
    {
        "openid": "o6_bmasdasdsad6_2sgVt7hMZOPfL",
        "username": "WXUSER123",
        "unionid": "o6_bmasdasdsad6_2sgVt7hMZOPfL",
        "nickname": "张三",
        "headimgurl": "https://thirdwx.qlogo.cn/mmopen/...",
        "bind_time": "2026-08-10 15:30:00"
    }
]
```

## 安全说明

- **CSRF 防护**：使用 state 参数防止跨站请求伪造，10 分钟过期
- **SOAP 认证**：SOAP 连接使用独立的用户名/密码认证
- **绑定唯一性**：一个 openid 只能绑定一个游戏账号，一个游戏账号只能绑定一个微信
- **密码处理**：所有密码由服务端 SRP6 自动加密，PHP 端不接触密码哈希
- **绑定验证**：绑定已有账号时，通过 SOAP `account set password` 验证账号存在性并确认密码

## 环境要求

- PHP 8.0+
- cURL 扩展（调用微信 API）
- SOAP 扩展（连接 worldserver）
- `application/data/` 目录可写（存储 JSON 绑定文件）
- worldserver SOAP 已启用
- 已通过审核的微信开放平台网站应用
