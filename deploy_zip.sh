#!/bin/bash
# =====================================================================
# WoWSimpleRegistration ZIP 部署脚本
# 分支: mobile-login (手机一键登录)
# =====================================================================
# 适配用户工作流:
#   1. 从 Gitee 下载代码 .zip 文件
#   2. 将原有目录改名备份
#   3. 解压新代码到部署目录
#   4. 从备份中拷贝配置文件 (config.php + data/) 回来
#   5. 安装 Composer 依赖
#   6. 设置权限并验证
#
# 用法:
#   chmod +x deploy_zip.sh
#   ./deploy_zip.sh                # 完整部署（下载 zip + 安装）
#   ./deploy_zip.sh /path/to/zip   # 使用本地已下载的 zip 文件部署
#   ./deploy_zip.sh --keep-backup  # 部署后保留备份目录（默认删除）
#
# 与 deploy_119.sh 的区别:
#   deploy_119.sh = git clone/pull 方式（需要服务器能访问 Git）
#   deploy_zip.sh = zip 下载方式（适配用户手动下载工作流）
# =====================================================================

set -e

# ===== 配置项（按需修改）=====
DEPLOY_DIR="/www/wwwroot/WoWSimpleRegistration"
GITEE_REPO="https://gitee.com/zj555sadfs/WoWSimpleRegistration"
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

# 备份保留策略
KEEP_BACKUP=false
# =====================================================================

# 颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

info()  { echo -e "${GREEN}[INFO]${NC}  $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }
step()  { echo -e "\n${BLUE}===== $1 =====${NC}"; }

# 解析参数
LOCAL_ZIP=""
if [ "$1" = "--keep-backup" ]; then
    KEEP_BACKUP=true
elif [ -n "$1" ]; then
    LOCAL_ZIP="$1"
fi

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="${DEPLOY_DIR}_backup_${TIMESTAMP}"
TEMP_DIR="/tmp/wow_deploy_${TIMESTAMP}"

# =====================================================================
# 步骤 1: 检查环境
# =====================================================================
step "1/9 检查运行环境"

if ! command -v php &> /dev/null; then
    error "PHP 未安装！"
    echo ""
    echo "  安装方法："
    echo "  Ubuntu/Debian: apt install -y php-cli php-soap php-curl php-gd php-gmp php-mbstring php-xml php-fileinfo"
    echo "  CentOS/RHEL:   yum install -y php-cli php-soap php-curl php-gd php-gmp php-mbstring php-xml php-fileinfo"
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

# 检查必要扩展
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

# 检查 fileinfo（非必需）
if php -m 2>/dev/null | grep -qi "^fileinfo$"; then
    info "fileinfo 扩展已安装"
else
    warn "fileinfo 扩展未安装 (不影响运行，Composer 将跳过此检查)"
fi

# 检查 unzip
if ! command -v unzip &> /dev/null; then
    warn "unzip 未安装，尝试安装..."
    apt install -y unzip 2>/dev/null || yum install -y unzip 2>/dev/null || {
        error "unzip 安装失败，请手动安装: apt install unzip"
        exit 1
    }
fi
info "unzip 已安装"

# =====================================================================
# 步骤 2: 获取代码 (下载 zip 或使用本地 zip)
# =====================================================================
step "2/9 获取代码"

mkdir -p "${TEMP_DIR}"

if [ -n "${LOCAL_ZIP}" ]; then
    # 使用本地已下载的 zip 文件
    info "使用本地 zip 文件: ${LOCAL_ZIP}"
    if [ ! -f "${LOCAL_ZIP}" ]; then
        error "文件不存在: ${LOCAL_ZIP}"
        exit 1
    fi
    cp "${LOCAL_ZIP}" "${TEMP_DIR}/code.zip"
else
    # 从 Gitee 下载 zip
    ZIP_URL="${GITEE_REPO}/repository/archive/${GIT_BRANCH}.zip"
    info "从 Gitee 下载代码..."
    info "URL: ${ZIP_URL}"

    HTTP_CODE=$(curl -L -o "${TEMP_DIR}/code.zip" -w "%{http_code}" -s "${ZIP_URL}" 2>/dev/null)

    if [ "${HTTP_CODE}" != "200" ] || [ ! -s "${TEMP_DIR}/code.zip" ]; then
        error "下载失败 (HTTP ${HTTP_CODE})"
        echo ""
        echo "  替代方案:"
        echo "  1. 手动从浏览器下载 zip:"
        echo "     ${GITEE_REPO}/repository/archive/${GIT_BRANCH}.zip"
        echo "  2. 将下载的 zip 传到服务器，然后运行:"
        echo "     ./deploy_zip.sh /path/to/downloaded.zip"
        exit 1
    fi

    ZIP_SIZE=$(du -h "${TEMP_DIR}/code.zip" | cut -f1)
    info "下载完成 (${ZIP_SIZE})"
fi

# =====================================================================
# 步骤 3: 备份现有部署目录
# =====================================================================
step "3/9 备份现有部署"

if [ -d "${DEPLOY_DIR}" ]; then
    info "将现有目录备份为: ${BACKUP_DIR}"
    mv "${DEPLOY_DIR}" "${BACKUP_DIR}"
    info "备份完成"

    # 检查备份中是否有需要保留的配置文件
    PRESERVE_CONFIG=""
    PRESERVE_DATA=""

    if [ -f "${BACKUP_DIR}/application/config/config.php" ]; then
        PRESERVE_CONFIG="${BACKUP_DIR}/application/config/config.php"
        info "  发现 config.php — 将在解压后拷贝回来"
    else
        warn "  备份中未找到 config.php — 将生成新配置"
    fi

    if [ -d "${BACKUP_DIR}/application/data" ]; then
        PRESERVE_DATA="${BACKUP_DIR}/application/data"
        info "  发现 data/ 目录 — 将在解压后拷贝回来"
    else
        warn "  备份中未找到 data/ 目录 — 将创建空目录"
    fi
else
    info "部署目录不存在，这是首次部署"
    PRESERVE_CONFIG=""
    PRESERVE_DATA=""
fi

# =====================================================================
# 步骤 4: 解压新代码
# =====================================================================
step "4/9 解压新代码"

info "解压 zip 文件..."
cd "${TEMP_DIR}"
unzip -q -o "code.zip"

# Gitee zip 解压后目录名格式: WoWSimpleRegistration-{branch}
# 找到解压后的目录
EXTRACTED_DIR=$(find "${TEMP_DIR}" -maxdepth 1 -type d -name "WoWSimpleRegistration*" | head -1)

if [ -z "${EXTRACTED_DIR}" ]; then
    # 尝试其他可能的目录名
    EXTRACTED_DIR=$(find "${TEMP_DIR}" -maxdepth 1 -type d ! -path "${TEMP_DIR}" | head -1)
fi

if [ -z "${EXTRACTED_DIR}" ]; then
    error "解压后未找到代码目录"
    echo "  解压内容:"
    ls -la "${TEMP_DIR}"
    exit 1
fi

info "解压目录: ${EXTRACTED_DIR}"

# 验证解压内容包含关键文件
if [ ! -f "${EXTRACTED_DIR}/index.php" ]; then
    error "解压目录中未找到 index.php，可能 zip 内容不正确"
    exit 1
fi
info "解压内容验证通过 (index.php 存在)"

# 移动到部署目录
info "移动代码到部署目录: ${DEPLOY_DIR}"
mkdir -p "$(dirname "${DEPLOY_DIR}")"
mv "${EXTRACTED_DIR}" "${DEPLOY_DIR}"
info "代码已就位"

# =====================================================================
# 步骤 5: 拷贝配置文件 (从备份恢复)
# =====================================================================
step "5/9 恢复配置文件"

cd "${DEPLOY_DIR}"

# --- 恢复 config.php ---
if [ -n "${PRESERVE_CONFIG}" ]; then
    info "从备份拷贝 config.php ..."
    cp "${PRESERVE_CONFIG}" "${DEPLOY_DIR}/application/config/config.php"
    info "config.php 已恢复"

    # 同时拷贝备份文件（如果有）
    if [ -f "${BACKUP_DIR}/application/config/config.php.bak" ]; then
        cp "${BACKUP_DIR}/application/config/config.php.bak" "${DEPLOY_DIR}/application/config/" 2>/dev/null || true
        info "config.php.bak 已恢复"
    fi
else
    warn "无备份 config.php，生成新配置..."
    cat > "${DEPLOY_DIR}/application/config/config.php" << 'PHPEOF'
<?php
/**
 * WoWSimpleRegistration — 生产环境配置
 *
 * 自动生成 by deploy_zip.sh
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

    sed -i "s|__SITE_URL__|${SITE_URL}|g" "${DEPLOY_DIR}/application/config/config.php"
    sed -i "s|__PAGE_TITLE__|${PAGE_TITLE}|g" "${DEPLOY_DIR}/application/config/config.php"
    sed -i "s|__SOAP_HOST__|${SOAP_HOST}|g" "${DEPLOY_DIR}/application/config/config.php"
    sed -i "s|__SOAP_PORT__|${SOAP_PORT}|g" "${DEPLOY_DIR}/application/config/config.php"
    sed -i "s|__SOAP_USER__|${SOAP_USER}|g" "${DEPLOY_DIR}/application/config/config.php"
    sed -i "s|__SOAP_PASS__|${SOAP_PASS}|g" "${DEPLOY_DIR}/application/config/config.php"

    info "config.php 已生成（请检查 SOAP 密码等配置）"
fi

# --- 恢复 data/ 目录 ---
mkdir -p "${DEPLOY_DIR}/application/data"

if [ -n "${PRESERVE_DATA}" ]; then
    info "从备份拷贝 data/ 目录 ..."
    cp -r "${PRESERVE_DATA}/"* "${DEPLOY_DIR}/application/data/" 2>/dev/null || true
    info "data/ 目录已恢复"

    # 列出恢复的数据文件
    for f in "${DEPLOY_DIR}/application/data/"*.json; do
        [ -f "$f" ] && info "  - $(basename $f)"
    done
else
    info "创建空的 data/ 目录..."
    echo '[]' > "${DEPLOY_DIR}/application/data/mobile_bindings.json"
    echo '[]' > "${DEPLOY_DIR}/application/data/sms_codes.json"
    info "  - mobile_bindings.json (空)"
    info "  - sms_codes.json (空)"
fi

# =====================================================================
# 步骤 6: 安装 Composer
# =====================================================================
step "6/9 安装 Composer"

cd "${DEPLOY_DIR}/application"

# 函数: 检测 composer.phar 是否可用
check_composer() {
    if [ ! -f "composer.phar" ]; then
        return 1
    fi
    php composer.phar --version &>/dev/null
    return $?
}

# 函数: 下载最新 Composer
download_composer() {
    info "下载最新版 Composer ..."
    curl -sS https://getcomposer.org/installer | php -- --quiet 2>/dev/null && return 0
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
    if check_composer; then
        info "composer.phar 可用: $(php composer.phar --version 2>/dev/null | head -1)"
        COMPOSER_CMD="php composer.phar"
    else
        rm -f composer.phar
        if download_composer; then
            info "Composer 下载成功"
            COMPOSER_CMD="php composer.phar"
        else
            # 尝试从备份拷贝 vendor 目录（Composer 安装失败的兜底方案）
            if [ -n "${PRESERVE_DATA}" ] && [ -d "${BACKUP_DIR}/application/vendor" ]; then
                warn "Composer 下载失败！尝试从备份拷贝 vendor 目录..."
                cp -r "${BACKUP_DIR}/application/vendor" "${DEPLOY_DIR}/application/vendor"
                if [ -f "${DEPLOY_DIR}/application/vendor/autoload.php" ]; then
                    info "从备份恢复 vendor 成功（建议后续修复 Composer）"
                    COMPOSER_CMD=""
                else
                    error "Composer 下载失败且备份中无 vendor 目录"
                    echo ""
                    echo "  手动安装方法："
                    echo "  cd ${DEPLOY_DIR}/application"
                    echo "  curl -sS https://getcomposer.org/installer | php"
                    echo "  php composer.phar install --no-dev --ignore-platform-reqs"
                    exit 1
                fi
            else
                error "Composer 下载失败！"
                echo ""
                echo "  手动安装方法："
                echo "  cd ${DEPLOY_DIR}/application"
                echo "  curl -sS https://getcomposer.org/installer | php"
                echo "  php composer.phar install --no-dev --ignore-platform-reqs"
                exit 1
            fi
        fi
    fi
fi

# 验证 Composer 可用
if [ -n "${COMPOSER_CMD}" ] && ! ${COMPOSER_CMD} --version &>/dev/null; then
    error "Composer 无法运行，尝试重新下载..."
    rm -f composer.phar
    if download_composer; then
        COMPOSER_CMD="php composer.phar"
    else
        error "Composer 安装失败，请手动安装"
        exit 1
    fi
fi

if [ -n "${COMPOSER_CMD}" ]; then
    info "Composer 就绪: $(${COMPOSER_CMD} --version 2>/dev/null | head -1)"
fi

# =====================================================================
# 步骤 7: 安装 Composer 依赖
# =====================================================================
step "7/9 安装 Composer 依赖"

# 如果 vendor 已从备份恢复，跳过安装
if [ -f "vendor/autoload.php" ] && [ -d "vendor/voku/anti-xss" ]; then
    info "vendor 目录已存在（从备份恢复），跳过 Composer 安装"
    info "  如需更新依赖，请手动运行: ${COMPOSER_CMD} install --no-dev --ignore-platform-reqs"
else
    info "配置 Composer 源..."
    ${COMPOSER_CMD} config -g repo.packagist composer https://packagist.org 2>/dev/null || true
    ${COMPOSER_CMD} config repo.packagist composer https://packagist.org 2>/dev/null || true

    # 清理旧锁文件
    if [ -f "composer.lock" ]; then
        if ! ${COMPOSER_CMD} validate --no-check-all 2>/dev/null; then
            warn "composer.lock 不一致，删除锁文件..."
            rm -f composer.lock
        fi
    fi

    info "安装依赖..."
    COMPOSER_INSTALL_OK=false

    # 策略: 正常 → 忽略 fileinfo → 忽略所有平台要求 → 删锁重试
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
        warn "锁文件可能过期，删除后重试..."
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
        echo "  4. 国内镜像: php composer.phar config repo.packagist composer https://mirrors.aliyun.com/composer/"
        exit 1
    fi

    info "Composer 依赖安装成功"
fi

# 验证关键依赖
if [ ! -f "vendor/autoload.php" ]; then
    error "vendor/autoload.php 不存在！依赖安装不完整"
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
# 步骤 8: 设置权限
# =====================================================================
step "8/9 设置权限"

# 检测 web 用户
WEB_USER="www-data"
if id -u "nginx" &>/dev/null; then
    WEB_USER="nginx"
elif id -u "www" &>/dev/null; then
    WEB_USER="www"
fi

chown -R "${WEB_USER}:${WEB_USER}" "${DEPLOY_DIR}" 2>/dev/null || warn "chown 失败（可能需要 root），请手动: chown -R ${WEB_USER}:${WEB_USER} ${DEPLOY_DIR}"
chmod -R 755 "${DEPLOY_DIR}"
chmod -R 775 "${DEPLOY_DIR}/application/data"
chmod 644 "${DEPLOY_DIR}/application/config/config.php" 2>/dev/null || true
info "权限设置完成 (web 用户: ${WEB_USER})"

# =====================================================================
# 步骤 9: 验证部署
# =====================================================================
step "9/9 验证部署"

# 检查关键文件
VERIFY_OK=true

if [ ! -f "application/config/config.php" ]; then
    error "config.php 不存在！"
    VERIFY_OK=false
else
    info "config.php 存在"
fi

if [ ! -d "application/vendor" ]; then
    error "vendor 目录不存在！"
    VERIFY_OK=false
else
    info "vendor 目录存在"
fi

if [ ! -f "index.php" ]; then
    error "index.php 不存在！"
    VERIFY_OK=false
else
    info "index.php 存在"
fi

if [ ! -f "application/include/mobile.php" ]; then
    error "mobile.php 不存在！"
    VERIFY_OK=false
else
    info "mobile.php 存在"
fi

# PHP 语法检查
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
    VERIFY_OK=false
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
# 清理临时文件
# =====================================================================
info "清理临时文件..."
rm -rf "${TEMP_DIR}"

# 处理备份目录
if [ -d "${BACKUP_DIR}" ]; then
    if [ "${KEEP_BACKUP}" = "true" ]; then
        info "备份目录保留: ${BACKUP_DIR}"
    else
        info "删除备份目录: ${BACKUP_DIR}"
        rm -rf "${BACKUP_DIR}"
    fi
fi

# =====================================================================
# 部署完成
# =====================================================================
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  ZIP 部署完成！${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "  部署目录: ${DEPLOY_DIR}"
echo "  代码分支: ${GIT_BRANCH}"
echo "  配置来源: $([ -n "${PRESERVE_CONFIG}" ] && echo '从备份恢复' || echo '新生成')"
echo "  数据来源: $([ -n "${PRESERVE_DATA}" ] && echo '从备份恢复' || echo '空目录')"
echo ""

if [ "${VERIFY_OK}" = "true" ]; then
    echo -e "  ${GREEN}所有验证通过${NC}"
else
    echo -e "  ${YELLOW}部分验证未通过，请检查上方日志${NC}"
fi

echo ""
echo "  启动方式（选一种）:"
echo ""
echo "  [1] PHP 内置服务器（临时测试）:"
echo "      cd ${DEPLOY_DIR}"
echo "      php -S 0.0.0.0:${WEB_PORT} -t ."
echo ""
echo "  [2] Nginx + PHP-FPM（推荐生产）:"
echo "      配置站点根目录: ${DEPLOY_DIR}"
echo ""
echo "  Nginx 配置参考:"
echo "    server {"
echo "        listen ${WEB_PORT};"
echo "        server_name YOUR_SERVER_IP;"
echo "        root ${DEPLOY_DIR};"
echo "        index index.php;"
echo ""
echo "        location / {"
echo "            try_files \$uri \$uri/ /index.php?\$query_string;"
echo "        }"
echo ""
echo "        location ~ \.php\$ {"
echo "            fastcgi_pass unix:/run/php/php-fpm.sock;"
echo "            fastcgi_index index.php;"
echo "            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;"
echo "            include fastcgi_params;"
echo "        }"
echo ""
echo "        # 保护敏感目录"
echo "        location ~ /(application/config|application/data|tests|\.git) {"
echo "            deny all;"
echo "        }"
echo "    }"
echo ""
