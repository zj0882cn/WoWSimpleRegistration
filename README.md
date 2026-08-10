# WoWSimpleRegistration — 微信扫码登录版 (WeChat-Only SOAP Mode)

基于 [WoWSimpleRegistration](https://github.com/masterking32/WoWSimpleRegistration) 改造，**删除密码登录和修改密码功能**，仅保留微信登录和密码重置。采用 SOAP-Only 模式，无需数据库连接。

## 核心特性

- **微信公众号 OAuth 登录** — 基于 OAuth2.0 (snsapi_userinfo)
  - PC 端：显示二维码扫码登录
  - 手机微信内：自动授权直接登录
  - 手机非微信：提示在微信中打开
- **SOAP-Only 模式** — 所有账号操作通过 SOAP 命令发送到 worldserver，无需 MySQL
- **自动注册** — 微信用户首次登录自动创建游戏账号
- **绑定已有账号** — 支持将微信绑定到已有的游戏账号
- **密码重置** — 通过 SOAP 重置密码，无需旧密码
- **JSON 绑定存储** — 微信与游戏账号的绑定关系存储在本地 JSON 文件中
- **设备自适应** — 自动识别 PC / 手机 / 微信浏览器，显示不同登录方式

## 与原版的区别

| 功能 | 原版 | 本版本 |
|------|------|--------|
| 密码注册 | 支持 | **已删除** |
| 密码登录 | 支持 | **已删除** |
| 修改密码 | 支持 | **已删除** |
| 密码重置 | 邮件验证 | **微信登录 + SOAP** |
| 微信登录 | 无 | **核心登录方式** |
| 数据库连接 | 需要 | **不需要** |
| 账号操作 | SQL 直操作 | **SOAP 命令** |

## 环境要求

- PHP 8.0+（需 `php-cli`、`php-soap`、`php-curl`、`php-gd`、`php-gmp` 扩展）
- Composer
- AzerothCore worldserver（SOAP 已启用）
- 微信公众号（已认证，用于网页授权）
- **域名** — 微信 OAuth 要求域名（不支持 IP / localhost / 自定义端口）

## 快速开始

### 1. 克隆仓库

```bash
git clone https://github.com/zj0882cn/WoWSimpleRegistration.git
cd WoWSimpleRegistration
```

### 2. 安装依赖

```bash
cd application
composer install
cd ..
```

### 3. 配置

创建 `application/config/config.php`，填入你的配置：

```php
<?php
// --- Basic ---
$config['baseurl'] = 'https://your-domain.com';  // 你的域名

// --- SOAP ---
$config['soap_host']     = '127.0.0.1';
$config['soap_port']     = '7878';
$config['soap_uri']      = 'urn:AC';
$config['soap_style']    = 'SOAP_RPC';
$config['soap_username'] = 'admin';
$config['soap_password'] = 'your-password';

// --- WeChat (公众号 OAuth) ---
$config['wechat_enabled']       = true;
$config['wechat_appid']         = '你的公众号AppID';
$config['wechat_appsecret']     = '你的公众号AppSecret';
```

### 4. 创建数据目录

```bash
mkdir -p application/data
chmod 755 application/data
```

### 5. 确认 worldserver SOAP 已启用

在 `worldserver.conf` 中确认：

```ini
SOAP.Enabled = 1
SOAP.IP = "0.0.0.0"    # 或 127.0.0.1（仅本机）
SOAP.Port = 7878
```

### 6. 部署

#### 方式 A：一键部署脚本（推荐，含 Nginx + SSL）

```bash
sudo bash scripts/deploy.sh
```

脚本会自动：
- 安装 Nginx + PHP-FPM
- 配置 Nginx 站点（端口 80）
- 可选配置 Let's Encrypt SSL（端口 443）
- 可选配置 DuckDNS 自动更新
- 更新 config.php 中的 baseurl

#### 方式 B：PHP 内置服务器（快速测试）

```bash
sudo bash scripts/start_80.sh
# 或指定端口
sudo bash scripts/start_80.sh 8080
```

#### 方式 C：手动配置 Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/WoWSimpleRegistration;
    index index.php;

    # 微信验证文件
    location ~ ^/MP_verify_[A-Za-z0-9]+\.txt$ {
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 保护敏感目录
    location ~ /(application/config|application/data|tests) {
        deny all;
    }
}
```

## 微信公众号配置

### 1. 配置网页授权域名

在微信公众平台 → 设置与开发 → 公众号设置 → 功能设置：

| 配置项 | 填写内容 |
|--------|----------|
| 网页授权域名 | `your-domain.com`（不带 http:// 和端口） |

### 2. 放置验证文件

微信会要求下载验证文件 `MP_verify_xxxx.txt`，将其放到网站根目录：

```bash
cp MP_verify_xxxx.txt /path/to/WoWSimpleRegistration/
```

### 3. 配置 IP 白名单

在微信公众平台 → 设置与开发 → 基本配置 → 公众号开发信息：

| 配置项 | 填写内容 |
|--------|----------|
| IP 白名单 | 你的服务器 IP，如 `YOUR_SERVER_IP` |

## 使用 DuckDNS（免费域名测试）

如果没有备案域名，可使用 DuckDNS 进行测试：

```bash
# 1. 注册 https://www.duckdns.org 获取域名和 token
# 2. 编辑脚本填入你的信息
nano scripts/duckdns_update.sh
# 3. 运行更新
bash scripts/duckdns_update.sh
# 4. 加入 crontab 自动更新
crontab -e
# 添加: */5 * * * * /path/to/duckdns_update.sh
```

> **注意**：微信可能不接受 `.duckdns.org` 域名作为网页授权域名。如果被拒绝，需要使用已备案的域名。

## 登录流程

```
用户访问首页
    │
    ▼
检测设备类型
    │
    ├── PC 浏览器 ──→ 显示「微信扫码登录」按钮
    │                   │
    │                   ▼ 跳转微信 OAuth
    │                 扫码确认
    │
    ├── 手机 + 微信内 ──→ 自动授权，无需扫码
    │
    └── 手机 + 非微信 ──→ 提示「请在微信中打开」
                            │
                            ▼
                      显示二维码引导用户扫码打开
    │
    ▼  微信回调 code + state
wechat_callback.php 处理回调
    │
    ├── 验证 state（CSRF 防护）
    ├── 用 code 换取 access_token
    ├── 获取用户信息
    │
    ├── 查询 JSON 绑定文件
    │
    ├─ 已绑定 ──→ 自动登录，跳转首页
    │
    └─ 未绑定 ──→ 跳转绑定页面
                    │
                    ├── 自动注册新账号
                    │   ├── SOAP: account create
                    │   ├── SOAP: account set addon
                    │   └── 保存 JSON 绑定
                    │
                    └── 绑定已有账号
                        ├── SOAP: 检查账号存在
                        ├── SOAP: account set password
                        └── 保存 JSON 绑定
```

## 测试

本项目内置完整的 Mock 测试环境，**无需真实微信凭证或游戏服务器**即可测试全部功能。

### 一键启动测试

```bash
cd tests
bash start_test.sh
```

| 服务 | 端口 | 说明 |
|------|------|------|
| Mock SOAP Server | 7878 | 模拟 AzerothCore 世界服务器 |
| Mock WeChat OAuth | 9190 | 模拟微信 API |
| 注册网站 | 8080 | 主程序 |

### 停止测试服务

```bash
bash tests/start_test.sh --stop
```

## 项目结构

```
WoWSimpleRegistration/
├── index.php                      # 微信登录首页
├── wechat_callback.php            # OAuth 回调处理
├── wechat_bind.php                # 账号绑定/注册页面
├── wechat_reset_password.php      # 密码重置页面
├── application/
│   ├── config/
│   │   └── config.php             # 主配置（.gitignore 排除）
│   ├── include/
│   │   ├── wechat.php             # 微信认证核心模块
│   │   ├── user.php               # 用户类（精简版）
│   │   ├── core_handler.php       # SOAP 模式核心处理
│   │   └── functions.php          # 辅助函数
│   ├── language/
│   │   ├── chinese-simplified.php
│   │   └── english.php
│   ├── data/
│   │   └── wechat_bindings.json   # 微信绑定记录
│   └── loader.php
├── template/
│   └── light/
│       ├── tpl/
│       │   ├── header.php
│       │   ├── footer.php
│       │   └── main.php           # 微信登录页模板
│       └── wechat_bind.php        # 绑定页模板
├── scripts/
│   ├── deploy.sh                  # 一键部署脚本（Nginx + SSL）
│   ├── start_80.sh                # PHP 内置服务器快速启动
│   └── duckdns_update.sh          # DuckDNS 自动更新
├── tests/                         # 测试套件
│   ├── start_test.sh
│   ├── mock_soap_handler.php
│   ├── mock_wechat_oauth.php
│   └── test_console.php
├── .gitignore
└── README.md
```

## 配置项说明

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `baseurl` | `http://localhost:8080` | 网站地址（支持环境变量 `WOW_BASEURL`） |
| `wechat_enabled` | `false` | 启用/禁用微信登录 |
| `wechat_appid` | — | 微信公众号 AppID |
| `wechat_appsecret` | — | 微信公众号 AppSecret |
| `wechat_auto_register` | `true` | 允许自动注册新账号 |
| `wechat_bind_existing` | `true` | 允许绑定已有账号 |
| `soap_host` | `127.0.0.1` | worldserver SOAP 地址 |
| `soap_port` | `7878` | worldserver SOAP 端口 |
| `soap_username` | — | SOAP 用户名 |
| `soap_password` | — | SOAP 密码 |
| `soap_ca_command` | `account create {USERNAME} {PASSWORD}` | 创建账号命令 |
| `soap_asa_command` | `account set addon {USERNAME} {EXPANSION}` | 设置扩展包命令 |
| `wechat_open_baseurl` | — | Mock 测试：覆盖 open.weixin.qq.com |
| `wechat_api_baseurl` | — | Mock 测试：覆盖 api.weixin.qq.com |

## SOAP 命令对照

| 操作 | SOAP 命令 |
|------|----------|
| 创建账号 | `account create {USERNAME} {PASSWORD}` |
| 设置扩展包 | `account set addon {USERNAME} {EXPANSION}` |
| 重置密码 | `account set password {USERNAME} {PASSWORD} {PASSWORD}` |
| 检查账号存在 | `account set addon {USERNAME} {EXPANSION}`（成功=存在） |

## 安全说明

- **CSRF 防护** — OAuth state 参数，10 分钟过期
- **绑定唯一性** — 一个 openid 绑定一个游戏账号，反之亦然
- **密码安全** — 密码由 worldserver 端 SRP6 加密，PHP 端不接触哈希
- **配置隔离** — `config.php` 已在 `.gitignore` 中排除，不会泄露凭证

## License

基于 WoWSimpleRegistration (MIT License) 改造。
