# WoWSimpleRegistration — 手机号认证版 (Mobile SOAP Mode)

基于 [WoWSimpleRegistration](https://github.com/masterking32/WoWSimpleRegistration) 改造，采用**手机号 + SMS 验证码 + 一键登录**认证方式，无需微信公众号认证，无需数据库连接。所有账号操作通过 SOAP 命令完成。

## 分支说明

| 分支 | 说明 |
|------|------|
| `master` | 微信扫码登录版（需公众号认证） |
| `mobile-login` | **当前分支** — 手机号认证版（SMS + 一键登录） |

## 核心特性

- **手机一键登录** — 运营商网关自动识别本机号码，无需输入手机号和验证码
  - 移动端：自动识别手机号，一键注册/登录
  - PC 端：显示二维码引导手机扫码
  - 演示模式：手动输入手机号模拟识别
- **SMS 短信验证** — 支持阿里云短信、腾讯云短信
  - 注册时发送验证码验证手机号
  - 忘记密码时通过短信验证重置密码
- **用户自定义账号密码** — 注册时用户自行设置游戏账号和密码
- **密码登录** — 老用户可通过账号密码直接登录网站
- **修改密码** — 登录后可直接修改密码
- **忘记密码** — 3 步流程（手机号→短信验证→设置新密码），含安全 token 防护
- **SOAP-Only 模式** — 所有账号操作通过 SOAP 命令完成，无需 MySQL
- **服务器状态** — 首页实时显示在线人数、角色数、运行时间等
- **JSON 绑定存储** — 手机号与游戏账号的绑定关系存储在本地 JSON 文件
- **多语言** — 支持简体中文 / English
- **响应式设计** — 自适应 PC / 手机 / 平板

## 与原版的区别

| 功能 | 原版 | 本版本 |
|------|------|--------|
| 注册方式 | 密码注册 | **手机验证 + 自定义账号密码** |
| 登录方式 | 密码登录 | **一键登录 + 密码登录** |
| 修改密码 | 邮件验证 | **登录后直接修改** |
| 忘记密码 | 邮件验证 | **短信验证码重置** |
| 数据库连接 | 需要 | **不需要** |
| 账号操作 | SQL 直操作 | **SOAP 命令** |

## 环境要求

- PHP 8.0+（需 `php-cli`、`php-soap`、`php-curl`、`php-gd`、`php-gmp` 扩展）
- Composer
- AzerothCore worldserver（SOAP 已启用）

## 快速开始

### 1. 克隆仓库

```bash
git clone -b mobile-login https://github.com/zj0882cn/WoWSimpleRegistration.git
cd WoWSimpleRegistration
```

### 2. 安装依赖

```bash
cd application
composer install --no-dev
cd ..
```

### 3. 配置

复制示例配置并编辑：

```bash
cp application/config/config.php.bak application/config/config.php
# 或者从零创建
nano application/config/config.php
```

最小配置示例：

```php
<?php
// --- Basic ---
$config['baseurl'] = 'http://your-server-ip:8080';
$config['page_title'] = 'My WoW Server';
$config['language'] = 'chinese-simplified';

// --- SOAP ---
$config['soap_host']     = '127.0.0.1';  // worldserver 地址
$config['soap_port']     = '7878';
$config['soap_uri']      = 'urn:AC';
$config['soap_style']    = 'SOAP_RPC';
$config['soap_username'] = 'admin';
$config['soap_password'] = 'your-soap-password';

// --- Mobile Auth ---
$config['mobile_enabled'] = true;
$config['sms_provider']   = 'demo';  // 测试用；生产改为 'aliyun' 或 'tencent'
$config['numberauth_provider'] = 'demo';  // 测试用；生产改为 'aliyun'

// --- Game Info ---
$config['realmlist'] = 'logon.your-server.com';
$config['game_version'] = '3.3.5a (12340)';
$config['expansion'] = '2';
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

### 6. 启动

#### 方式 A：PHP 内置服务器（快速测试）

```bash
php -S 0.0.0.0:8080 -t .
```

访问 `http://your-server-ip:8080/`

#### 方式 B：一键部署脚本

```bash
chmod +x deploy_119.sh
./deploy_119.sh          # 完整部署
./deploy_119.sh update   # 仅更新代码（不覆盖 config.php）
```

#### 方式 C：Nginx + PHP-FPM（生产环境）

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/WoWSimpleRegistration;
    index index.php;

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

## 认证方式详解

### 1. 一键登录（号码认证）

通过运营商网关自动识别手机号，用户无需输入手机号和验证码。

| Provider | 说明 | 适用场景 |
|----------|------|----------|
| `demo` | 前端显示手机号输入框模拟识别 | 本地测试 |
| `aliyun` | 阿里云号码认证 H5 SDK | 生产环境 |

**生产环境配置（阿里云号码认证）：**

1. 申请阿里云号码认证服务：https://dypns.console.aliyun.com/
2. 配置 config.php：

```php
$config['numberauth_provider'] = 'aliyun';
$config['numberauth_aliyun_appkey'] = '你的H5 SDK AppKey';
$config['numberauth_aliyun_access_key'] = '你的AccessKey';
$config['numberauth_aliyun_access_secret'] = '你的AccessSecret';
```

3. 在阿里云控制台配置 H5 场景的域名白名单

### 2. SMS 短信验证

用于注册验证和忘记密码重置。

| Provider | 说明 | 适用场景 |
|----------|------|----------|
| `demo` | 验证码直接返回在接口响应中 | 本地测试 |
| `aliyun` | 阿里云短信 API | 生产环境 |
| `tencent` | 腾讯云短信 API | 生产环境 |

**阿里云短信配置：**

1. 申请阿里云短信服务：https://dysms.console.aliyun.com/
2. 创建短信签名和模板（模板内容示例：`您的验证码为${code}，5分钟内有效。`）
3. 配置 config.php：

```php
$config['sms_provider'] = 'aliyun';
$config['sms_aliyun_access_key'] = '你的AccessKey';
$config['sms_aliyun_access_secret'] = '你的AccessSecret';
$config['sms_aliyun_sign_name'] = '你的短信签名';
$config['sms_aliyun_template_code'] = 'SMS_123456789';  // 模板Code
```

**腾讯云短信配置：**

1. 申请腾讯云短信服务：https://console.cloud.tencent.com/smsv2
2. 配置 config.php：

```php
$config['sms_provider'] = 'tencent';
$config['sms_tencent_secret_id'] = '你的SecretId';
$config['sms_tencent_secret_key'] = '你的SecretKey';
$config['sms_tencent_sign_name'] = '你的短信签名';
$config['sms_tencent_template_id'] = '你的模板ID';
$config['sms_tencent_sdk_appid'] = '你的SDK AppID';
```

## 功能流程

### 注册 / 登录流程

```
用户访问首页
    │
    ▼
检测设备类型
    │
    ├── 移动端 ──→ 一键登录（运营商网关识别手机号）
    │               │
    │               ├── 演示模式: 手动输入手机号 + 账号 + 密码
    │               └── 阿里云模式: SDK 自动获取 token → 后端验证
    │
    └── PC 端 ──→ 显示二维码，引导手机扫码打开
                    │
                    ▼
              手机端完成一键登录
    │
    ▼
查询手机号绑定
    │
    ├── 已绑定 + 账号存在 ──→ 自动登录，跳转首页
    │
    └── 未绑定 ──→ 创建新账号
                    ├── SOAP: account create {USERNAME} {PASSWORD}
                    ├── SOAP: account set addon {USERNAME} {EXPANSION}
                    └── 保存 JSON 绑定记录
```

### 忘记密码流程

```
Step 1: 输入手机号
    │   └── 校验手机号已绑定且账号存在 → 发送 SMS 验证码
    ▼
Step 2: 输入验证码
    │   ├── 最多 5 次尝试
    │   ├── 验证通过 → 设置 session token（10 分钟有效）
    │   └── 验证失败 → 显示剩余尝试次数
    ▼
Step 3: 设置新密码
    │   ├── 校验 session token（防跳过验证）
    │   ├── 密码强度指示器
    │   └── SOAP: account set password {USERNAME} {PASSWORD} {PASSWORD}
    ▼
完成：显示成功页面
```

### 密码登录流程

```
用户输入账号 + 密码
    │
    ├── SOAP 检查账号存在
    │
    ├── 存在 → 设置 session，登录成功
    └── 不存在 → 提示账号不存在
```

## 项目结构

```
WoWSimpleRegistration/
├── index.php                      # 首页（服务器状态 + 登录入口）
├── oneclick_verify.php            # 一键登录验证接口
├── password_login.php             # 密码登录接口
├── reset_password.php             # 忘记密码页面（SMS 验证）
├── change_password.php            # 修改密码接口
├── sms_send.php                   # 发送短信验证码接口
├── sms_verify.php                 # 验证短信验证码接口
├── deploy_119.sh                  # 一键部署脚本
├── application/
│   ├── config/
│   │   └── config.php             # 主配置（.gitignore 排除）
│   ├── include/
│   │   ├── mobile.php             # 手机认证核心模块（SMS + 一键登录）
│   │   ├── user.php               # 用户类（精简版）
│   │   ├── core_handler.php       # SOAP 模式核心处理
│   │   └── functions.php          # 辅助函数
│   ├── language/
│   │   ├── chinese-simplified.php
│   │   └── english.php
│   ├── data/
│   │   ├── mobile_bindings.json   # 手机号绑定记录
│   │   └── sms_codes.json         # 短信验证码（自动生成）
│   ├── composer.json
│   └── loader.php
├── template/
│   └── light/
│       └── tpl/
│           ├── header.php         # 页面头部 + CSS
│           ├── footer.php
│           └── main.php           # 主页模板
├── scripts/
│   ├── deploy.sh                  # Nginx 部署脚本
│   ├── start_80.sh                # PHP 内置服务器启动
│   └── duckdns_update.sh          # DuckDNS 更新
├── tests/                         # 测试套件（微信版遗留）
├── .gitignore
└── README.md
```

## 配置项说明

### 基础配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `baseurl` | `http://127.0.0.1:8080` | 网站地址（支持环境变量 `WOW_BASEURL`） |
| `page_title` | `WoW Server` | 页面标题 |
| `language` | `chinese-simplified` | 默认语言 |
| `debug_mode` | `false` | 调试模式（开启后记录详细日志） |
| `template` | `light` | 模板名称 |

### 游戏服务器配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `realmlist` | — | 游戏 Realmlist 地址 |
| `game_version` | `3.3.5a (12340)` | 游戏版本 |
| `expansion` | `2` | 扩展包（0=经典, 1=TBC, 2=WLK, 3=Cata） |
| `server_core` | `1` | 服务端类型（1=AzerothCore） |
| `client_download_url` | ChromieCraft 下载页 | 客户端下载地址（显示在连接指南） |
| `client_download_baidu` | `true` | 是否显示百度网盘下载 |
| `client_download_baidu_url` | — | 百度网盘链接 |
| `client_download_baidu_code` | — | 百度网盘提取码 |

### SOAP 配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `soap_host` | `127.0.0.1` | worldserver SOAP 地址 |
| `soap_port` | `7878` | worldserver SOAP 端口 |
| `soap_uri` | `urn:AC` | SOAP URI（AzerothCore 用 `urn:AC`） |
| `soap_style` | `SOAP_RPC` | SOAP 调用风格 |
| `soap_username` | — | SOAP 管理员账号 |
| `soap_password` | — | SOAP 管理员密码 |
| `soap_ca_command` | `account create {USERNAME} {PASSWORD}` | 创建账号命令模板 |
| `soap_asa_command` | `account set addon {USERNAME} {EXPANSION}` | 设置扩展包命令模板 |

### 手机认证配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `mobile_enabled` | `true` | 启用手机认证 |
| `sms_provider` | `demo` | 短信服务商（`demo`/`aliyun`/`tencent`） |
| `numberauth_provider` | `demo` | 一键登录服务商（`demo`/`aliyun`） |

### 阿里云短信配置

| 配置项 | 说明 |
|--------|------|
| `sms_aliyun_access_key` | Access Key ID |
| `sms_aliyun_access_secret` | Access Key Secret |
| `sms_aliyun_sign_name` | 短信签名 |
| `sms_aliyun_template_code` | 模板 Code（如 `SMS_123456789`） |

### 腾讯云短信配置

| 配置项 | 说明 |
|--------|------|
| `sms_tencent_secret_id` | SecretId |
| `sms_tencent_secret_key` | SecretKey |
| `sms_tencent_sign_name` | 短信签名 |
| `sms_tencent_template_id` | 模板 ID |
| `sms_tencent_sdk_appid` | SDK AppID |

### 阿里云号码认证配置

| 配置项 | 说明 |
|--------|------|
| `numberauth_aliyun_appkey` | H5 SDK AppKey（前端使用） |
| `numberauth_aliyun_access_key` | Access Key ID（后端使用） |
| `numberauth_aliyun_access_secret` | Access Key Secret（后端使用） |

## SOAP 命令对照

| 操作 | SOAP 命令 |
|------|----------|
| 创建账号 | `account create {USERNAME} {PASSWORD}` |
| 设置扩展包 | `account set addon {USERNAME} {EXPANSION}` |
| 修改/重置密码 | `account set password {USERNAME} {PASSWORD} {PASSWORD}` |
| 检查账号存在 | `account set addon {USERNAME} {EXPANSION}`（成功=存在） |
| 获取服务器状态 | `server info` |

## 安全说明

- **Session Token 防护** — 忘记密码 Step 3 需验证 Step 2 设置的 session token，防止跳过短信验证
- **Token 过期** — 验证 token 10 分钟后自动失效
- **手机号一致性** — Step 3 提交的手机号必须与 Step 2 验证通过的一致
- **频率限制** — 短信发送间隔 60 秒，验证码 5 分钟有效
- **尝试次数限制** — 每个验证码最多 5 次验证尝试
- **密码安全** — 密码由 worldserver 端 SRP6 加密，PHP 端不接触哈希
- **绑定唯一性** — 一个手机号绑定一个游戏账号，反之亦然
- **配置隔离** — `config.php` 已在 `.gitignore` 中排除，不会泄露凭证

## 部署到生产服务器

### 快速部署

```bash
# 1. 在服务器上执行
git clone -b mobile-login https://github.com/zj0882cn/WoWSimpleRegistration.git
cd WoWSimpleRegistration

# 2. 运行部署脚本
chmod +x deploy_119.sh
./deploy_119.sh

# 3. 安装依赖
cd application
composer install --no-dev --ignore-platform-reqs
cd ..

# 4. 配置 config.php（填入实际 SOAP 和 SMS 凭证）
nano application/config/config.php

# 5. 启动 Web 服务（根据服务器环境选择）
# 宝塔面板: 在宝塔中添加站点，指向 WoWSimpleRegistration 目录
# 或 PHP 内置: php -S 0.0.0.0:9000 -t .
```

### 更新代码

```bash
./deploy_119.sh update
```

此命令仅更新代码，不会覆盖 `config.php`。

## 本地测试

### 演示模式测试

1. 确保 `config.php` 中 `sms_provider = 'demo'` 且 `numberauth_provider = 'demo'`
2. 启动服务：`php -S 0.0.0.0:8080 -t .`
3. 访问 `http://localhost:8080/`

在演示模式下：
- 一键登录：手动输入手机号、账号、密码，模拟运营商识别
- 短信验证码：验证码直接显示在页面上（不实际发送短信）
- 忘记密码：验证码同样显示在页面上

### worldserver SOAP 连接测试

确保 `config.php` 中 SOAP 配置正确，且 worldserver 已启用 SOAP：

```bash
# 测试 SOAP 连接
curl -s http://localhost:8080/ | grep -o '在线\|离线'
```

如果显示"在线"，说明 SOAP 连接正常。

## 常见问题

### Q: 提示 "SOAP 连接失败"

检查以下几点：
1. worldserver.conf 中 `SOAP.Enabled = 1`
2. `soap_host` 和 `soap_port` 配置正确
3. `soap_username` 和 `soap_password` 是 worldserver 的管理员凭证
4. 防火墙允许 SOAP 端口（默认 7878）的访问

### Q: 一键登录需要输入手机号

当前为演示模式（`numberauth_provider = 'demo'`）。生产环境需配置阿里云号码认证（改为 `aliyun`），即可通过运营商网关自动识别手机号。

### Q: 短信验证码收不到

当前为演示模式（`sms_provider = 'demo'`），验证码会直接显示在页面上。生产环境需配置阿里云或腾讯云短信服务。

### Q: 忘记密码提示"验证已过期"

短信验证通过后需在 10 分钟内完成密码设置。超时后需重新从 Step 1 开始。

### Q: Composer 安装报错

```bash
# 方法1: 更新 Composer
php composer.phar self-update

# 方法2: 忽略平台要求
composer install --no-dev --ignore-platform-reqs

# 方法3: 确保 PHP 扩展已安装
apt-get install php-soap php-curl php-gd php-gmp php-mbstring
```

### Q: 如何切换到微信登录版

```bash
git checkout master
```

微信版需要微信公众号认证和备案域名。

## 技术架构

```
┌──────────────────────────────────────────┐
│              用户浏览器                    │
│  ┌──────────┐  ┌──────────┐  ┌─────────┐ │
│  │ 一键登录  │  │ 密码登录  │  │ 忘记密码 │ │
│  └────┬─────┘  └────┬─────┘  └────┬────┘ │
└───────┼──────────────┼──────────────┼─────┘
        │              │              │
        ▼              ▼              ▼
┌──────────────────────────────────────────┐
│           PHP Web 应用                    │
│  ┌─────────────────────────────────────┐ │
│  │         MobileAuth 类               │ │
│  │  ┌─────────┐ ┌─────────┐ ┌────────┐ │ │
│  │  │SMS 验证 │ │一键登录 │ │SOAP通信│ │ │
│  │  └────┬────┘ └────┬────┘ └───┬────┘ │ │
│  │       │           │          │      │ │
│  │  ┌────▼───────────▼────┐     │      │ │
│  │  │  JSON 绑定存储       │     │      │ │
│  │  │  (mobile_bindings)  │     │      │ │
│  │  └─────────────────────┘     │      │ │
│  └──────────────────────────────┼──────┘ │
└─────────────────────────────────┼────────┘
                                  │
                    ┌─────────────▼──────────────┐
                    │   SMS Provider (阿里云/腾讯)  │
                    │   发送验证码到用户手机         │
                    └────────────────────────────┘
                                  │
                    ┌─────────────▼──────────────┐
                    │   AzerothCore Worldserver   │
                    │   SOAP 端口 7878            │
                    │   ┌──────────────────────┐  │
                    │   │ account create       │  │
                    │   │ account set addon    │  │
                    │   │ account set password │  │
                    │   │ server info          │  │
                    │   └──────────────────────┘  │
                    └────────────────────────────┘
```

## License

基于 WoWSimpleRegistration (MIT License) 改造。
