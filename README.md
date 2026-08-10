# WoWSimpleRegistration — 微信扫码登录版 (WeChat-Only SOAP Mode)

基于 [WoWSimpleRegistration](https://github.com/masterking32/WoWSimpleRegistration) 改造，**删除密码登录和修改密码功能**，仅保留微信扫码登录和密码重置。采用 SOAP-Only 模式，无需数据库连接。

## 核心特性

- **微信扫码登录** — 基于 OAuth2.0 (snsapi_login)，用户扫码即可登录
- **SOAP-Only 模式** — 所有账号操作通过 SOAP 命令发送到 worldserver，无需 MySQL
- **自动注册** — 微信用户首次登录自动创建游戏账号，无需手动填写
- **绑定已有账号** — 支持将微信绑定到已有的游戏账号
- **密码重置** — 通过 SOAP 重置密码，无需旧密码
- **JSON 绑定存储** — 微信与游戏账号的绑定关系存储在本地 JSON 文件中
- **完整测试套件** — 内置 Mock 服务器，无需真实微信凭证即可端到端测试

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

- PHP 7.4+（需 `php-cli`、`php-soap`、`php-curl`、`php-gd` 扩展）
- Composer
- AzerothCore worldserver（SOAP 已启用）
- 微信开放平台网站应用（已通过审核）

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

```bash
cp application/config/wechat_config_snippet.php application/config/config.php
```

编辑 `config.php`，填入你的微信凭证和 SOAP 设置：

```php
$config['wechat_enabled']   = true;
$config['wechat_appid']     = '你的微信AppID';
$config['wechat_appsecret'] = '你的微信AppSecret';

$config['soap_host']     = '127.0.0.1';
$config['soap_port']     = '7878';
$config['soap_username'] = 'admin_soap';
$config['soap_password'] = '你的SOAP密码';
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
SOAP.IP = "127.0.0.1"
SOAP.Port = 7878
```

### 6. 部署

使用 Nginx/Apache 或 PHP 内置服务器：

```bash
php -S 0.0.0.0:8080
```

访问 `http://localhost:8080` 即可看到微信扫码登录页面。

## 项目结构

```
WoWSimpleRegistration/
├── index.php                          # 微信扫码登录首页
├── wechat_callback.php                # OAuth 回调处理
├── wechat_bind.php                    # 账号绑定/注册页面
├── wechat_reset_password.php          # 密码重置页面
├── application/
│   ├── config/
│   │   ├── config.php                 # 主配置（需自行创建）
│   │   └── wechat_config_snippet.php  # 配置模板
│   ├── include/
│   │   ├── wechat.php                 # 微信认证核心模块
│   │   ├── user.php                   # 用户类（精简版）
│   │   ├── core_handler.php           # SOAP 模式核心处理
│   │   └── functions.php              # 辅助函数
│   ├── language/
│   │   ├── chinese-simplified.php     # 简体中文
│   │   └── english.php                # 英文
│   ├── data/                          # 绑定数据目录（自动创建）
│   │   └── wechat_bindings.json       # 微信绑定记录
│   ├── composer.json
│   └── loader.php
├── template/
│   └── light/
│       ├── tpl/
│       │   ├── header.php
│       │   ├── footer.php
│       │   └── main.php               # 微信登录页模板
│       ├── wechat_bind.php            # 绑定页模板
│       └── wechat_login_button.php    # 登录按钮组件
├── tests/                             # 测试套件
│   ├── start_test.sh                  # 一键启动测试环境
│   ├── test_config.php                # 测试配置
│   ├── mock_soap_handler.php          # Mock SOAP 服务器
│   ├── mock_wechat_oauth.php          # Mock 微信 OAuth 服务器
│   ├── test_console.php               # Web 测试控制台
│   └── test-guide/
│       └── test-guide.html            # 测试指南
├── WECHAT_INTEGRATION_GUIDE.md        # 集成指南
├── .gitignore
└── README.md
```

## 登录流程

```
用户访问首页
    │
    ▼
点击「微信扫码登录」
    │
    ▼  跳转微信开放平台
用户扫码确认
    │
    ▼  回调 code + state
wechat_callback.php 处理回调
    │
    ├── 验证 state（CSRF 防护）
    ├── 用 code 换取 access_token
    ├── 获取用户信息（昵称、头像）
    │
    ├── 查询 JSON 文件：openid 是否已绑定？
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

启动三个本地服务：

| 服务 | 端口 | 说明 |
|------|------|------|
| Mock SOAP Server | 7878 | 模拟 AzerothCore 世界服务器 |
| Mock WeChat OAuth | 9190 | 模拟微信开放平台 API |
| 注册网站 | 8080 | 主程序 |

### 测试页面

- 注册网站：http://localhost:8080
- 测试控制台：http://localhost:8080/tests/test_console.php
- Mock 扫码页：http://localhost:9190/connect/qrconnect
- 测试指南：http://localhost:8080/tests/test-guide/test-guide.html

### 停止测试服务

```bash
bash tests/start_test.sh --stop
```

详细的测试说明请查看 [WECHAT_INTEGRATION_GUIDE.md](WECHAT_INTEGRATION_GUIDE.md)。

## 配置项说明

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `wechat_enabled` | `false` | 启用/禁用微信登录 |
| `wechat_appid` | — | 微信开放平台 AppID |
| `wechat_appsecret` | — | 微信开放平台 AppSecret |
| `wechat_auto_register` | `true` | 允许自动注册新账号 |
| `wechat_bind_existing` | `true` | 允许绑定已有账号 |
| `wechat_show_profile` | `true` | 显示微信昵称和头像 |
| `soap_host` | `127.0.0.1` | worldserver SOAP 地址 |
| `soap_port` | `7878` | worldserver SOAP 端口 |
| `soap_username` | — | SOAP 用户名 |
| `soap_password` | — | SOAP 密码 |
| `soap_ca_command` | `account create {USERNAME} {PASSWORD}` | 创建账号命令模板 |
| `soap_asa_command` | `account set addon {USERNAME} {EXPANSION}` | 设置扩展包命令模板 |
| `wechat_open_baseurl` | — | Mock 测试用：覆盖 open.weixin.qq.com |
| `wechat_api_baseurl` | — | Mock 测试用：覆盖 api.weixin.qq.com |

## 获取微信 AppID / AppSecret

微信开放平台**不提供**网站扫码登录的测试 AppID。使用真实微信需要：

1. 注册 [微信开放平台](https://open.weixin.qq.com/) 账号
2. 创建「网站应用」并通过审核
3. 获取 AppID 和 AppSecret
4. 设置授权回调域名为你的网站域名

本地开发测试请使用内置的 Mock 微信 OAuth 服务器（见上方测试部分）。

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

## 技术栈

- PHP 7.4+ / SOAP / cURL
- 微信 OAuth2.0 (snsapi_login)
- AzerothCore SOAP API
- JSON 文件存储（无需数据库）
- Composer 依赖管理

## License

基于 WoWSimpleRegistration (MIT License) 改造。
