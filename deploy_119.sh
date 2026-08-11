#!/bin/bash
# =====================================================================
# WoWSimpleRegistration 部署脚本 — 通用生产环境
# 分支: mobile-login (手机一键登录)
# =====================================================================
# 用法:
#   chmod +x deploy_119.sh
#   ./deploy_119.sh          # 完整部署
#   ./deploy_119.sh update   # 仅更新代码（不覆盖 config.php）
# =====================================================================

set -e

# ===== 配置项（按需修改）=====
DEPLOY_DIR="/www/wwwroot/WoWSimpleRegistration"
GIT_REPO="https://github.com/zj0882cn/WoWSimpleRegistration.git"
GIT_BRANCH="mobile-login"
WEB_PORT="9000"

# SOAP 配置（worldserver 在本机，用 127.0.0.1）
SOAP_HOST="127.0.0.1"
SOAP_PORT="7878"
SOAP_USER="admin"
SOAP_PASS="YOUR_SOAP_PASSWORD"

# 站点配置
SITE_URL="http://YOUR_SERVER_IP:${WEB_PORT}"
PAGE_TITLE="WoW Server"
# =====================================================================

# 颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

info()  { echo -e "${GREEN}[INFO]${NC}  $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }
step()  { echo -e "\n${BLUE}===== $1 =====${NC}"; }

MODE="${1:-full}"

# ===== 步骤 1: 检查环境 =====
step "1/7 检查运行环境"

if ! command -v php &> /dev/null; then
    error "PHP 未安装！请先安装 PHP 8.0+"
    echo "  Ubuntu/Debian: apt install -y php-cli php-soap php-curl php-gd php-gmp php-mbstring php-xml"
    echo "  CentOS/RHEL:   yum install -y php-cli php-soap php-curl php-gd php-gmp php-mbstring php-xml"
    exit 1
fi

PHP_VER=$(php -v 2>/dev/null | head -1)
info "PHP: ${PHP_VER}"

# 检查必要扩展
MISSING_EXT=()
for ext in soap curl gd gmp mbstring xml; do
    if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
        MISSING_EXT+=("$ext")
    fi
done

if [ ${#MISSING_EXT[@]} -gt 0 ]; then
    warn "缺少 PHP 扩展: ${MISSING_EXT[*]}"
    echo "  请安装后重新运行此脚本"
    exit 1
fi
info "PHP 扩展检查通过"

# 检查 git
if ! command -v git &> /dev/null; then
    error "git 未安装！请先安装: apt install -y git"
    exit 1
fi
info "git 已安装"

# ===== 步骤 2: 拉取/更新代码 =====
step "2/7 拉取代码"

if [ -d "${DEPLOY_DIR}/.git" ]; then
    info "目录已存在，更新代码..."
    cd "${DEPLOY_DIR}"
    git fetch origin
    git checkout "${GIT_BRANCH}"
    git pull origin "${GIT_BRANCH}"
else
    info "克隆代码到 ${DEPLOY_DIR} ..."
    mkdir -p "$(dirname "${DEPLOY_DIR}")"
    git clone -b "${GIT_BRANCH}" "${GIT_REPO}" "${DEPLOY_DIR}"
    cd "${DEPLOY_DIR}"
fi
info "代码就绪，当前分支: $(git branch --show-current)"

# ===== 步骤 3: 安装 Composer 依赖 =====
step "3/7 安装 Composer 依赖"

cd "${DEPLOY_DIR}/application"

if [ ! -f "composer.phar" ]; then
    info "下载 Composer ..."
    curl -sS https://getcomposer.org/installer | php
fi

info "安装依赖（--no-dev）..."
php composer.phar install --no-dev --ignore-platform-req=ext-fileinfo 2>/dev/null || {
    warn "composer install 遇到警告，尝试 --ignore-platform-reqs ..."
    php composer.phar install --no-dev --ignore-platform-reqs
}
info "依赖安装完成"

cd "${DEPLOY_DIR}"

# ===== 步骤 4: 创建数据目录 =====
step "4/7 创建数据目录"

mkdir -p application/data
info "数据目录: application/data/"

# ===== 步骤 5: 生成配置文件 =====
step "5/7 生成配置文件"

CONFIG_FILE="application/config/config.php"

if [ "${MODE}" = "update" ] && [ -f "${CONFIG_FILE}" ]; then
    info "更新模式：保留现有 config.php"
else
    info "生成 config.php ..."
    cat > "${CONFIG_FILE}" << 'PHPEOF'
<?php
/**
 * WoWSimpleRegistration — 生产环境配置
 *
 * 自动生成 by deploy_119.sh
 * 分支: mobile-login (手机一键登录)
 **/

// --- Basic Configuration ---
$config['baseurl'] = '__SITE_URL__';
$config['page_title'] = '__PAGE_TITLE__';
$config['language'] = 'chinese-simplified';
$config['supported_langs'] = [
    'chinese-simplified' => '简体中文',
    'english'            => 'English',
];

// --- Debug Mode ---
$config['debug_mode'] = false;

// --- Server Information ---
$config['realmlist'] = 'YOUR_SERVER_IP';
$config['patch_location'] = '';
$config['game_version'] = '3.3.5a (12340)';
$config['expansion'] = '2';

// --- Server Core Type ---
$config['server_core'] = 1; // AzerothCore

// --- Battle.net / SRP6 ---
$config['battlenet_support'] = false;
$config['srp6_support'] = false;
$config['srp6_version'] = 0;

// --- Feature Toggles ---
$config['disable_top_players'] = true;
$config['disable_online_players'] = true;
$config['disable_changepassword'] = true;

// --- Template ---
$config['template'] = 'light';

// --- SOAP Settings (本机 worldserver) ---
$config['soap_for_register']  = true;
$config['soap_host']     = '__SOAP_HOST__';
$config['soap_port']     = '__SOAP_PORT__';
$config['soap_uri']      = 'urn:AC';
$config['soap_style']    = 'SOAP_RPC';
$config['soap_username'] = '__SOAP_USER__';
$config['soap_password'] = '__SOAP_PASS__';

// SOAP command templates
$config['soap_ca_command']  = 'account create {USERNAME} {PASSWORD}';
$config['soap_asa_command'] = 'account set addon {USERNAME} {EXPANSION}';

// --- Mobile Authentication ---
$config['mobile_enabled'] = true;
$config['sms_provider']   = 'demo';

// Aliyun SMS (上线时配置)
$config['sms_aliyun_access_key']    = '';
$config['sms_aliyun_access_secret'] = '';
$config['sms_aliyun_sign_name']     = '';
$config['sms_aliyun_template_code'] = '';

// Tencent Cloud SMS (上线时配置)
$config['sms_tencent_secret_id']   = '';
$config['sms_tencent_secret_key']  = '';
$config['sms_tencent_sign_name']   = '';
$config['sms_tencent_template_id'] = '';
$config['sms_tencent_sdk_appid']   = '';

// --- One-Click Login (号码认证) ---
// "demo" = 演示模式 | "aliyun" = 阿里云号码认证
$config['numberauth_provider'] = 'demo';
$config['numberauth_aliyun_appkey'] = '';
$config['numberauth_aliyun_access_key']    = '';
$config['numberauth_aliyun_access_secret'] = '';

// --- Captcha (disabled) ---
$config['captcha_type']   = 4;
$config['captcha_key']    = '';
$config['captcha_secret'] = '';
$config['captcha_language'] = 'en';

// --- SMTP (not used) ---
$config['smtp_host']   = '';
$config['smtp_port']   = 587;
$config['smtp_auth']   = true;
$config['smtp_user']   = '';
$config['smtp_pass']   = '';
$config['smtp_secure'] = 'tls';
$config['smtp_mail']   = '';

// --- Vote System (disabled) ---
$config['vote_system'] = false;
$config['vote_sites']  = [];

// --- 2FA (disabled) ---
$config['2fa_support'] = false;

// --- Script Version ---
$config['script_version'] = '2.1.0';
PHPEOF

    # 替换占位符
    sed -i "s|__SITE_URL__|${SITE_URL}|g" "${CONFIG_FILE}"
    sed -i "s|__PAGE_TITLE__|${PAGE_TITLE}|g" "${CONFIG_FILE}"
    sed -i "s|__SOAP_HOST__|${SOAP_HOST}|g" "${CONFIG_FILE}"
    sed -i "s|__SOAP_PORT__|${SOAP_PORT}|g" "${CONFIG_FILE}"
    sed -i "s|__SOAP_USER__|${SOAP_USER}|g" "${CONFIG_FILE}"
    sed -i "s|__SOAP_PASS__|${SOAP_PASS}|g" "${CONFIG_FILE}"

    info "config.php 已生成"
fi

# ===== 步骤 6: 设置权限 =====
step "6/7 设置权限"

# 尝试检测 web 用户
WEB_USER="www-data"
if id -u "nginx" &>/dev/null; then
    WEB_USER="nginx"
elif id -u "www" &>/dev/null; then
    WEB_USER="www"
fi

chown -R "${WEB_USER}:${WEB_USER}" "${DEPLOY_DIR}" 2>/dev/null || warn "chown 失败（可能需要 root 权限），请手动执行"
chmod -R 755 "${DEPLOY_DIR}"
chmod -R 775 "${DEPLOY_DIR}/application/data"
info "权限设置完成 (web 用户: ${WEB_USER})"

# ===== 步骤 7: 验证 =====
step "7/7 验证部署"

# 检查 config.php
if [ ! -f "application/config/config.php" ]; then
    error "config.php 不存在！"
    exit 1
fi
info "config.php 存在"

# 检查 vendor
if [ ! -d "application/vendor" ]; then
    error "vendor 目录不存在！Composer 依赖可能未安装成功"
    exit 1
fi
info "vendor 目录存在"

# 检查 SOAP 连接
info "测试 SOAP 连接 (${SOAP_HOST}:${SOAP_PORT})..."
SOAP_TEST=$(php -r "
try {
    \$c = new SoapClient(null, [
        'location' => 'http://${SOAP_HOST}:${SOAP_PORT}/',
        'uri' => 'urn:AC',
        'style' => SOAP_RPC,
        'login' => '${SOAP_USER}',
        'password' => '${SOAP_PASS}',
        'connection_timeout' => 5
    ]);
    \$r = \$c->executeCommand(new SoapParam('server info', 'command'));
    echo 'OK:' . substr(\$r, 0, 80);
} catch(Exception \$e) {
    echo 'FAIL:' . \$e->getMessage();
}
" 2>/dev/null)

if [[ "${SOAP_TEST}" == "OK:"* ]]; then
    info "SOAP 连接成功"
    echo "  ${SOAP_TEST:3}"
else
    warn "SOAP 连接失败: ${SOAP_TEST:5}"
    echo "  请确认 worldserver 正在运行，且 SOAP 端口 ${SOAP_PORT} 已开放"
fi

# 启动方式提示
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  部署完成！${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "访问地址: ${SITE_URL}"
echo "部署目录: ${DEPLOY_DIR}"
echo ""
echo "启动方式（选一种）:"
echo ""
echo "  [1] PHP 内置服务器（临时测试）:"
echo "      cd ${DEPLOY_DIR}"
echo "      php -S 0.0.0.0:${WEB_PORT} -t ."
echo ""
echo "  [2] Nginx + PHP-FPM（推荐生产）:"
echo "      配置站点根目录: ${DEPLOY_DIR}"
echo "      参考下方 Nginx 配置"
echo ""
echo "Nginx 配置参考:"
echo "  server {"
echo "      listen ${WEB_PORT};"
echo "      server_name YOUR_SERVER_IP;"
echo "      root ${DEPLOY_DIR};"
echo "      index index.php;"
echo ""
echo "      location / {"
echo "          try_files \$uri \$uri/ /index.php?\$query_string;"
echo "      }"
echo ""
echo "      location ~ \.php\$ {"
echo "          fastcgi_pass unix:/run/php/php-fpm.sock;"
echo "          fastcgi_index index.php;"
echo "          fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;"
echo "          include fastcgi_params;"
echo "      }"
echo ""
echo "      # 保护敏感目录"
echo "      location ~ /(application/config|application/data|tests|\.git) {"
echo "          deny all;"
echo "      }"
echo "  }"
echo ""
echo "验证 SOAP 连接:"
echo "  curl -s -X POST -H 'Content-Type: text/xml' -u '${SOAP_USER}:${SOAP_PASS}' \\"
echo "    -d '<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:ns1=\"urn:AC\"><soapenv:Body><ns1:executeCommand><command>server info</command></ns1:executeCommand></soapenv:Body></soapenv:Envelope>' \\"
echo "    http://${SOAP_HOST}:${SOAP_PORT}/"
echo ""
