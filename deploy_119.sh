#!/bin/bash
# =====================================================================
# WoWSimpleRegistration 部署脚本 — 通用生产环境
# 分支: mobile-login (手机一键登录)
# =====================================================================
# 用法:
#   chmod +x deploy_119.sh
#   ./deploy_119.sh              # 完整部署
#   ./deploy_119.sh update       # 仅更新代码（不覆盖 config.php）
#
# 已知问题处理:
#   1. Composer 旧版 putenv() 不兼容 PHP 8.x → 自动下载最新 Composer
#   2. ext-fileinfo 缺失 → 使用 --ignore-platform-reqs
#   3. 国内 packagist 镜像失效 → 自动切换官方源
# =====================================================================

set -e

# ===== 配置项（按需修改）=====
DEPLOY_DIR="/www/wwwroot/WoWSimpleRegistration"
GIT_REPO="https://gitee.com/zj555sadfs/WoWSimpleRegistration.git"
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

# =====================================================================
# 步骤 1: 检查环境
# =====================================================================
step "1/8 检查运行环境"

if ! command -v php &> /dev/null; then
    error "PHP 未安装！"
    echo ""
    echo "  安装方法："
    echo "  Ubuntu/Debian: apt install -y php-cli php-soap php-curl php-gd php-gmp php-mbstring php-xml php-fileinfo"
    echo "  CentOS/RHEL:   yum install -y php-cli php-soap php-curl php-gd php-gmp php-mbstring php-xml php-fileinfo"
    echo ""
    echo "  宝塔面板: 软件商店 → 安装 PHP 8.0+ → 安装扩展 soap/curl/gd/gmp/mbstring/fileinfo"
    exit 1
fi

PHP_VER=$(php -v 2>/dev/null | head -1)
info "PHP: ${PHP_VER}"

# 检查 PHP 版本 >= 7.4
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')
if [ "${PHP_MAJOR}" -lt 7 ] || ([ "${PHP_MAJOR}" -eq 7 ] && [ "${PHP_MINOR}" -lt 4 ]); then
    error "PHP 版本过低 (需要 7.4+)，当前: ${PHP_MAJOR}.${PHP_MINOR}"
    exit 1
fi
info "PHP 版本检查通过 (${PHP_MAJOR}.${PHP_MINOR})"

# 检查必要扩展（fileinfo 不强制，可用 --ignore-platform-reqs 跳过）
MISSING_EXT=()
REQUIRED_EXT=("soap" "curl" "gd" "gmp" "mbstring" "xml")
for ext in "${REQUIRED_EXT[@]}"; do
    if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
        MISSING_EXT+=("$ext")
    fi
done

if [ ${#MISSING_EXT[@]} -gt 0 ]; then
    error "缺少 PHP 扩展: ${MISSING_EXT[*]}"
    echo ""
    echo "  安装方法："
    echo "  Ubuntu/Debian: apt install -y php-{soap,curl,gd,gmp,mbstring,xml}"
    echo "  宝塔面板: PHP 设置 → 安装扩展 → soap, curl, gd, gmp, mbstring"
    exit 1
fi
info "PHP 扩展检查通过"

# 检查 fileinfo（非必需，但有更好）
if php -m 2>/dev/null | grep -qi "^fileinfo$"; then
    info "fileinfo 扩展已安装"
else
    warn "fileinfo 扩展未安装 (不影响运行，Composer 将跳过此检查)"
fi

# 检查 git
if ! command -v git &> /dev/null; then
    error "git 未安装！请先安装: apt install -y git 或 yum install -y git"
    exit 1
fi
info "git 已安装"

# =====================================================================
# 步骤 2: 拉取/更新代码
# =====================================================================
step "2/8 拉取代码"

if [ -d "${DEPLOY_DIR}/.git" ]; then
    info "目录已存在，更新代码..."
    cd "${DEPLOY_DIR}"
    git fetch --all
    git checkout "${GIT_BRANCH}" 2>/dev/null || true
    git pull origin "${GIT_BRANCH}" 2>/dev/null || {
        warn "git pull 失败，尝试 reset 到远程最新..."
        git reset --hard "origin/${GIT_BRANCH}"
    }
else
    info "克隆代码到 ${DEPLOY_DIR} ..."
    mkdir -p "$(dirname "${DEPLOY_DIR}")"
    git clone -b "${GIT_BRANCH}" "${GIT_REPO}" "${DEPLOY_DIR}" 2>/dev/null || {
        warn "Gitee 克隆失败，尝试 GitHub 镜像..."
        git clone -b "${GIT_BRANCH}" "https://github.com/zj0882cn/WoWSimpleRegistration.git" "${DEPLOY_DIR}"
    }
    cd "${DEPLOY_DIR}"
fi
info "代码就绪，当前分支: $(git branch --show-current)"
info "最新提交: $(git log --oneline -1)"

# =====================================================================
# 步骤 3: 安装 Composer
# =====================================================================
step "3/8 安装 Composer"

cd "${DEPLOY_DIR}/application"

# 函数: 检测 composer.phar 是否可用
check_composer() {
    if [ ! -f "composer.phar" ]; then
        return 1
    fi
    # 测试 composer.phar 是否能正常运行
    php composer.phar --version &>/dev/null
    return $?
}

# 函数: 下载最新 Composer
download_composer() {
    info "下载最新版 Composer ..."
    # 尝试官方源
    curl -sS https://getcomposer.org/installer | php -- --quiet 2>/dev/null && return 0
    # 尝试国内镜像
    warn "官方源下载失败，尝试国内镜像..."
    curl -sS https://mirrors.aliyun.com/composer/composer.phar -o composer.phar 2>/dev/null && return 0
    curl -sS https://mirrors.tencent.com/composer/composer.phar -o composer.phar 2>/dev/null && return 0
    return 1
}

# 检查系统是否已安装 composer
if command -v composer &> /dev/null; then
    info "检测到系统已安装 Composer: $(composer --version 2>/dev/null | head -1)"
    COMPOSER_CMD="composer"
else
    # 使用本地 composer.phar
    if check_composer; then
        info "composer.phar 可用: $(php composer.phar --version 2>/dev/null | head -1)"
        COMPOSER_CMD="php composer.phar"
    else
        # 删除旧的 composer.phar 并重新下载
        rm -f composer.phar
        if download_composer; then
            info "Composer 下载成功"
            COMPOSER_CMD="php composer.phar"
        else
            error "Composer 下载失败！"
            echo ""
            echo "  手动安装方法："
            echo "  cd ${DEPLOY_DIR}/application"
            echo "  curl -sS https://getcomposer.org/installer | php"
            echo "  # 或使用宝塔面板的 Composer 功能"
            exit 1
        fi
    fi
fi

# 验证 Composer 可用
if ! ${COMPOSER_CMD} --version &>/dev/null; then
    error "Composer 无法运行，尝试重新下载..."
    rm -f composer.phar
    if download_composer; then
        COMPOSER_CMD="php composer.phar"
    else
        error "Composer 安装失败，请手动安装"
        exit 1
    fi
fi
info "Composer 就绪: $(${COMPOSER_CMD} --version 2>/dev/null | head -1)"

# =====================================================================
# 步骤 4: 安装 Composer 依赖
# =====================================================================
step "4/8 安装 Composer 依赖"

# 切换到官方 packagist 源 (避免失效的国内镜像)
info "配置 Composer 源..."
${COMPOSER_CMD} config -g repo.packagist composer https://packagist.org 2>/dev/null || true

# 同时设置项目级镜像 (双保险)
${COMPOSER_CMD} config repo.packagist composer https://packagist.org 2>/dev/null || true

# 清理可能的旧锁文件 (如果 composer.lock 与 composer.json 不匹配)
if [ -f "composer.lock" ]; then
    info "检测到 composer.lock，验证一致性..."
    if ! ${COMPOSER_CMD} validate --no-check-all 2>/dev/null; then
        warn "composer.lock 与 composer.json 不一致，删除锁文件重新安装..."
        rm -f composer.lock
    fi
fi

info "安装依赖..."
# 策略:
#   1. 先尝试正常安装 (带 fileinfo 检查)
#   2. 失败则跳过平台要求 (ext-fileinfo 等非关键扩展)
#   3. 最后兜底: 忽略所有平台要求
COMPOSER_INSTALL_OK=false

${COMPOSER_CMD} install --no-dev --no-interaction --no-progress 2>/dev/null && COMPOSER_INSTALL_OK=true

if [ "${COMPOSER_INSTALL_OK}" = "false" ]; then
    warn "标准安装失败，尝试忽略 ext-fileinfo..."
    ${COMPOSER_CMD} install --no-dev --no-interaction --no-progress --ignore-platform-req=ext-fileinfo 2>/dev/null && COMPOSER_INSTALL_OK=true
fi

if [ "${COMPOSER_INSTALL_OK}" = "false" ]; then
    warn "仍失败，尝试忽略所有平台要求..."
    ${COMPOSER_CMD} install --no-dev --no-interaction --no-progress --ignore-platform-reqs 2>&1 && COMPOSER_INSTALL_OK=true
fi

if [ "${COMPOSER_INSTALL_OK}" = "false" ]; then
    # 最后尝试: 不使用锁文件，从 composer.json 安装
    warn "锁文件可能过期，尝试删除锁文件后重新安装..."
    rm -f composer.lock
    ${COMPOSER_CMD} install --no-dev --no-interaction --no-progress --ignore-platform-reqs 2>&1 && COMPOSER_INSTALL_OK=true
fi

if [ "${COMPOSER_INSTALL_OK}" = "false" ]; then
    error "Composer 依赖安装失败！"
    echo ""
    echo "  排查步骤:"
    echo "  1. 检查网络: curl -sS https://packagist.org/ | head -5"
    echo "  2. 更新 Composer: php composer.phar self-update"
    echo "  3. 手动安装: cd ${DEPLOY_DIR}/application && php composer.phar install --no-dev --ignore-platform-reqs"
    echo "  4. 如果国内网络问题，使用镜像:"
    echo "     php composer.phar config repo.packagist composer https://mirrors.aliyun.com/composer/"
    exit 1
fi

info "Composer 依赖安装成功"

# 验证关键依赖
if [ ! -f "vendor/autoload.php" ]; then
    error "vendor/autoload.php 不存在！依赖安装可能不完整"
    exit 1
fi
info "vendor/autoload.php 验证通过"

if [ ! -d "vendor/voku/anti-xss" ]; then
    error "voku/anti-xss 包未安装！"
    exit 1
fi
info "voku/anti-xss 验证通过"

cd "${DEPLOY_DIR}"

# =====================================================================
# 步骤 5: 创建数据目录
# =====================================================================
step "5/8 创建数据目录"

mkdir -p application/data

# 初始化 JSON 数据文件 (如果不存在)
if [ ! -f "application/data/mobile_bindings.json" ]; then
    echo '[]' > application/data/mobile_bindings.json
    info "创建 mobile_bindings.json"
fi
if [ ! -f "application/data/sms_codes.json" ]; then
    echo '[]' > application/data/sms_codes.json
    info "创建 sms_codes.json"
fi
info "数据目录就绪: application/data/"

# =====================================================================
# 步骤 6: 生成配置文件
# =====================================================================
step "6/8 生成配置文件"

CONFIG_FILE="application/config/config.php"

if [ "${MODE}" = "update" ] && [ -f "${CONFIG_FILE}" ]; then
    info "更新模式: 保留现有 config.php"
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

// --- Client Download ---
$config['client_download_url'] = 'https://www.chromiecraft.com/fr/downloads/';
$config['client_download_baidu'] = true;
$config['client_download_baidu_url'] = 'https://pan.baidu.com/s/1xr-u8T3Qh909AUOxzij-tA';
$config['client_download_baidu_code'] = 'd7ai';
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

# =====================================================================
# 步骤 7: 设置权限
# =====================================================================
step "7/8 设置权限"

# 尝试检测 web 用户
WEB_USER="www-data"
if id -u "nginx" &>/dev/null; then
    WEB_USER="nginx"
elif id -u "www" &>/dev/null; then
    WEB_USER="www"
fi

chown -R "${WEB_USER}:${WEB_USER}" "${DEPLOY_DIR}" 2>/dev/null || warn "chown 失败（可能需要 root 权限），请手动执行: chown -R ${WEB_USER}:${WEB_USER} ${DEPLOY_DIR}"
chmod -R 755 "${DEPLOY_DIR}"
chmod -R 775 "${DEPLOY_DIR}/application/data"
chmod 644 "${DEPLOY_DIR}/application/config/config.php" 2>/dev/null || true
info "权限设置完成 (web 用户: ${WEB_USER})"

# =====================================================================
# 步骤 8: 验证部署
# =====================================================================
step "8/8 验证部署"

# 检查 config.php
if [ ! -f "application/config/config.php" ]; then
    error "config.php 不存在！"
    exit 1
fi
info "config.php 存在"

# 检查 vendor
if [ ! -d "application/vendor" ]; then
    error "vendor 目录不存在！Composer 依赖未安装成功"
    exit 1
fi
info "vendor 目录存在"

# PHP 语法快速检查
info "PHP 语法检查..."
SYNTAX_OK=true
for f in index.php password_login.php change_password.php reset_password.php \
         oneclick_verify.php sms_send.php sms_verify.php \
         application/include/mobile.php application/loader.php; do
    if [ -f "${DEPLOY_DIR}/${f}" ]; then
        if ! php -l "${DEPLOY_DIR}/${f}" &>/dev/null; then
            error "语法错误: ${f}"
            php -l "${DEPLOY_DIR}/${f}"
            SYNTAX_OK=false
        fi
    fi
done
if [ "${SYNTAX_OK}" = "true" ]; then
    info "PHP 语法检查全部通过"
else
    warn "存在语法错误，请检查上述文件"
fi

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

# =====================================================================
# 部署完成
# =====================================================================
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  部署完成！${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "访问地址: ${SITE_URL}"
echo "部署目录: ${DEPLOY_DIR}"
echo "Git 分支: ${GIT_BRANCH}"
echo "Git 提交: $(git log --oneline -1)"
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
