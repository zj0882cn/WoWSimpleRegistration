#!/bin/bash
# =====================================================================
# WoWSimpleRegistration 环境搭建脚本
# 分支: mobile-login (手机一键登录)
# =====================================================================
# 前提: 用户已完成以下操作:
#   1. 从 Git 下载代码 zip
#   2. 将旧目录改名（如 WoWSimpleRegistration → WoWSimpleRegistration_old）
#   3. 解压新代码到部署目录
#
# 本脚本只负责:
#   1. 从旧目录拷贝配置文件 (config.php + data/)
#   2. 安装 Composer 依赖
#   3. 设置权限
#   4. 验证部署
#
# 用法:
#   chmod +x deploy_zip.sh
#   ./deploy_zip.sh                      # 自动检测同级 _old 目录恢复配置
#   ./deploy_zip.sh /path/to/old_dir     # 指定旧目录路径
#   ./deploy_zip.sh --no-restore         # 全新部署，不恢复旧配置
# =====================================================================

set -e

# ===== 配置项（按需修改）=====
DEPLOY_DIR="/www/wwwroot/WoWSimpleRegistration"
WEB_PORT="9000"

# SOAP 配置（worldserver 在本机，用 127.0.0.1）
SOAP_HOST="127.0.0.1"
SOAP_PORT="7878"
SOAP_USER="admin"
SOAP_PASS="YOUR_SOAP_PASSWORD"

# 站点配置（仅全新部署时生成 config.php 用到）
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

# 解析参数
OLD_DIR=""
NO_RESTORE=false
if [ "$1" = "--no-restore" ]; then
    NO_RESTORE=true
elif [ -n "$1" ]; then
    OLD_DIR="$1"
fi

# =====================================================================
# 步骤 1: 检查环境
# =====================================================================
step "1/5 检查运行环境"

if ! command -v php &> /dev/null; then
    error "PHP 未安装！"
    echo ""
    echo "  Ubuntu/Debian: apt install -y php-cli php-soap php-curl php-gd php-gmp php-mbstring php-xml php-fileinfo"
    echo "  宝塔面板: 软件商店 → 安装 PHP 8.0+ → 安装扩展 soap/curl/gd/gmp/mbstring/fileinfo"
    exit 1
fi
info "PHP: $(php -v 2>/dev/null | head -1)"

# PHP 版本 >= 7.4
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')
if [ "${PHP_MAJOR}" -lt 7 ] || ([ "${PHP_MAJOR}" -eq 7 ] && [ "${PHP_MINOR}" -lt 4 ]); then
    error "PHP 版本过低 (需要 7.4+)，当前: ${PHP_MAJOR}.${PHP_MINOR}"
    exit 1
fi
info "PHP 版本检查通过 (${PHP_MAJOR}.${PHP_MINOR})"

# 必需扩展
MISSING_EXT=()
for ext in soap curl gd gmp mbstring xml; do
    php -m 2>/dev/null | grep -qi "^${ext}$" || MISSING_EXT+=("$ext")
done
if [ ${#MISSING_EXT[@]} -gt 0 ]; then
    error "缺少 PHP 扩展: ${MISSING_EXT[*]}"
    echo "  宝塔面板: PHP 设置 → 安装扩展 → soap, curl, gd, gmp, mbstring"
    exit 1
fi
info "PHP 扩展检查通过"

# fileinfo（非必需）
if php -m 2>/dev/null | grep -qi "^fileinfo$"; then
    info "fileinfo 扩展已安装"
else
    warn "fileinfo 未安装 (不影响运行，Composer 将跳过)"
fi

# =====================================================================
# 步骤 2: 恢复配置文件
# =====================================================================
step "2/5 恢复配置文件"

cd "${DEPLOY_DIR}"

# 自动检测旧目录（同级目录下以 _old 或 _backup 结尾的目录）
if [ "${NO_RESTORE}" = "false" ] && [ -z "${OLD_DIR}" ]; then
    for candidate in "${DEPLOY_DIR}_old" "${DEPLOY_DIR}_backup" \
                     "$(dirname ${DEPLOY_DIR})/WoWSimpleRegistration_old" \
                     "$(dirname ${DEPLOY_DIR})/WoWSimpleRegistration_backup"; do
        if [ -d "${candidate}" ] && [ -f "${candidate}/application/config/config.php" ]; then
            OLD_DIR="${candidate}"
            break
        fi
    done
fi

if [ "${NO_RESTORE}" = "true" ]; then
    info "跳过配置恢复（全新部署）"
    OLD_DIR=""
elif [ -z "${OLD_DIR}" ]; then
    warn "未找到旧目录，将生成新配置"
else
    info "从旧目录恢复配置: ${OLD_DIR}"
fi

# --- 恢复 config.php ---
mkdir -p application/config
if [ -n "${OLD_DIR}" ] && [ -f "${OLD_DIR}/application/config/config.php" ]; then
    cp "${OLD_DIR}/application/config/config.php" application/config/config.php
    info "config.php 已恢复"
else
    warn "生成新 config.php（请修改 SOAP 密码等配置）"
    cat > application/config/config.php << 'PHPEOF'
<?php
$config['baseurl'] = '__SITE_URL__';
$config['page_title'] = '__PAGE_TITLE__';
$config['language'] = 'chinese-simplified';
$config['supported_langs'] = ['chinese-simplified' => '简体中文', 'english' => 'English'];
$config['debug_mode'] = false;
$config['realmlist'] = 'YOUR_SERVER_IP';
$config['game_version'] = '3.3.5a (12340)';
$config['client_download_url'] = 'https://www.chromiecraft.com/fr/downloads/';
$config['client_download_baidu'] = true;
$config['client_download_baidu_url'] = 'https://pan.baidu.com/s/1xr-u8T3Qh909AUOxzij-tA';
$config['client_download_baidu_code'] = 'd7ai';
$config['expansion'] = '2';
$config['server_core'] = 1;
$config['battlenet_support'] = false;
$config['srp6_support'] = false;
$config['disable_top_players'] = true;
$config['disable_online_players'] = true;
$config['disable_changepassword'] = true;
$config['template'] = 'light';
$config['soap_for_register'] = true;
$config['soap_host'] = '__SOAP_HOST__';
$config['soap_port'] = '__SOAP_PORT__';
$config['soap_uri'] = 'urn:AC';
$config['soap_style'] = 'SOAP_RPC';
$config['soap_username'] = '__SOAP_USER__';
$config['soap_password'] = '__SOAP_PASS__';
$config['soap_ca_command'] = 'account create {USERNAME} {PASSWORD}';
$config['soap_asa_command'] = 'account set addon {USERNAME} {EXPANSION}';
$config['mobile_enabled'] = true;
$config['sms_provider'] = 'demo';
$config['numberauth_provider'] = 'demo';
$config['captcha_type'] = 4;
$config['script_version'] = '2.1.0';
PHPEOF
    sed -i "s|__SITE_URL__|${SITE_URL}|g" application/config/config.php
    sed -i "s|__PAGE_TITLE__|${PAGE_TITLE}|g" application/config/config.php
    sed -i "s|__SOAP_HOST__|${SOAP_HOST}|g" application/config/config.php
    sed -i "s|__SOAP_PORT__|${SOAP_PORT}|g" application/config/config.php
    sed -i "s|__SOAP_USER__|${SOAP_USER}|g" application/config/config.php
    sed -i "s|__SOAP_PASS__|${SOAP_PASS}|g" application/config/config.php
    info "config.php 已生成"
fi

# --- 恢复 data/ 目录 ---
mkdir -p application/data
if [ -n "${OLD_DIR}" ] && [ -d "${OLD_DIR}/application/data" ]; then
    cp -r "${OLD_DIR}/application/data/"* application/data/ 2>/dev/null || true
    info "data/ 目录已恢复"
else
    echo '[]' > application/data/mobile_bindings.json
    echo '[]' > application/data/sms_codes.json
    info "data/ 目录已创建（空）"
fi

# =====================================================================
# 步骤 3: 安装 Composer 依赖
# =====================================================================
step "3/5 安装 Composer 依赖"

cd "${DEPLOY_DIR}/application"

# --- 获取 Composer 命令 ---
COMPOSER_CMD=""
if command -v composer &> /dev/null; then
    COMPOSER_CMD="composer"
    info "系统 Composer: $(composer --version 2>/dev/null | head -1)"
elif [ -f "composer.phar" ] && php composer.phar --version &>/dev/null; then
    COMPOSER_CMD="php composer.phar"
    info "本地 composer.phar 可用"
else
    info "下载 Composer ..."
    rm -f composer.phar
    # 回退: 官方源 → 阿里云 → 腾讯云
    curl -sS https://getcomposer.org/installer | php -- --quiet 2>/dev/null \
        || curl -sS https://mirrors.aliyun.com/composer/composer.phar -o composer.phar 2>/dev/null \
        || curl -sS https://mirrors.tencent.com/composer/composer.phar -o composer.phar 2>/dev/null

    if [ -f "composer.phar" ] && php composer.phar --version &>/dev/null; then
        COMPOSER_CMD="php composer.phar"
        info "Composer 下载成功"
    else
        # 最后兜底: 从旧目录拷贝 vendor
        if [ -n "${OLD_DIR}" ] && [ -d "${OLD_DIR}/application/vendor" ]; then
            warn "Composer 下载失败，从旧目录拷贝 vendor ..."
            cp -r "${OLD_DIR}/application/vendor" .
            [ -f "vendor/autoload.php" ] && info "vendor 已从旧目录恢复" || { error "vendor 恢复失败"; exit 1; }
        else
            error "Composer 下载失败！请手动安装: curl -sS https://getcomposer.org/installer | php"
            exit 1
        fi
    fi
fi

# --- 安装依赖 (4级回退) ---
if [ -f "vendor/autoload.php" ] && [ -d "vendor/voku/anti-xss" ]; then
    info "vendor 已存在，跳过安装"
else
    info "安装依赖..."
    ${COMPOSER_CMD} config -g repo.packagist composer https://packagist.org 2>/dev/null || true

    INSTALL_OK=false

    # 第1级: 标准安装
    ${COMPOSER_CMD} install --no-dev --no-interaction --no-progress 2>/dev/null && INSTALL_OK=true

    # 第2级: 忽略 ext-fileinfo
    [ "${INSTALL_OK}" = "false" ] && {
        warn "标准安装失败，忽略 ext-fileinfo 重试..."
        ${COMPOSER_CMD} install --no-dev --no-interaction --no-progress --ignore-platform-req=ext-fileinfo 2>/dev/null && INSTALL_OK=true
    }

    # 第3级: 忽略所有平台要求
    [ "${INSTALL_OK}" = "false" ] && {
        warn "仍失败，忽略所有平台要求重试..."
        ${COMPOSER_CMD} install --no-dev --no-interaction --no-progress --ignore-platform-reqs 2>&1 && INSTALL_OK=true
    }

    # 第4级: 删锁重试
    [ "${INSTALL_OK}" = "false" ] && {
        warn "锁文件可能过期，删除后重试..."
        rm -f composer.lock
        ${COMPOSER_CMD} install --no-dev --no-interaction --no-progress --ignore-platform-reqs 2>&1 && INSTALL_OK=true
    }

    [ "${INSTALL_OK}" = "false" ] && {
        error "Composer 依赖安装失败！"
        echo "  手动安装: cd ${DEPLOY_DIR}/application && ${COMPOSER_CMD} install --no-dev --ignore-platform-reqs"
        exit 1
    }
    info "Composer 依赖安装成功"
fi

# 验证关键依赖
[ -f "vendor/autoload.php" ] || { error "vendor/autoload.php 不存在"; exit 1; }
[ -d "vendor/voku/anti-xss" ] || { error "voku/anti-xss 未安装"; exit 1; }
info "依赖验证通过"

cd "${DEPLOY_DIR}"

# =====================================================================
# 步骤 4: 设置权限
# =====================================================================
step "4/5 设置权限"

WEB_USER="www-data"
id -u "nginx" &>/dev/null && WEB_USER="nginx"
id -u "www" &>/dev/null && WEB_USER="www"

chown -R "${WEB_USER}:${WEB_USER}" "${DEPLOY_DIR}" 2>/dev/null || warn "chown 失败，请手动: chown -R ${WEB_USER}:${WEB_USER} ${DEPLOY_DIR}"
chmod -R 755 "${DEPLOY_DIR}"
chmod -R 775 "${DEPLOY_DIR}/application/data"
chmod 644 "${DEPLOY_DIR}/application/config/config.php" 2>/dev/null || true
info "权限设置完成 (web 用户: ${WEB_USER})"

# =====================================================================
# 步骤 5: 验证部署
# =====================================================================
step "5/5 验证部署"

VERIFY_OK=true

# 关键文件检查
for f in application/config/config.php application/vendor/autoload.php \
         index.php application/include/mobile.php application/loader.php; do
    if [ ! -f "${f}" ]; then
        error "缺失: ${f}"
        VERIFY_OK=false
    fi
done
[ "${VERIFY_OK}" = "true" ] && info "关键文件检查通过"

# PHP 语法检查
SYNTAX_OK=true
for f in index.php password_login.php change_password.php reset_password.php \
         oneclick_verify.php sms_send.php sms_verify.php \
         application/include/mobile.php application/loader.php; do
    [ -f "${f}" ] && ! php -l "${f}" &>/dev/null && { error "语法错误: ${f}"; SYNTAX_OK=false; }
done
[ "${SYNTAX_OK}" = "true" ] && info "PHP 语法检查通过" || VERIFY_OK=false

# SOAP 连接测试
info "测试 SOAP 连接 (${SOAP_HOST}:${SOAP_PORT})..."
SOAP_TEST=$(php -r "
try {
    \$c = new SoapClient(null, [
        'location' => 'http://${SOAP_HOST}:${SOAP_PORT}/',
        'uri' => 'urn:AC', 'style' => SOAP_RPC,
        'login' => '${SOAP_USER}', 'password' => '${SOAP_PASS}',
        'connection_timeout' => 5
    ]);
    \$r = \$c->executeCommand(new SoapParam('server info', 'command'));
    echo 'OK:' . substr(\$r, 0, 80);
} catch(Exception \$e) { echo 'FAIL:' . \$e->getMessage(); }
" 2>/dev/null)

if [[ "${SOAP_TEST}" == "OK:"* ]]; then
    info "SOAP 连接成功"
else
    warn "SOAP 连接失败: ${SOAP_TEST:5}"
    echo "  请确认 worldserver 运行中，端口 ${SOAP_PORT} 已开放"
fi

# =====================================================================
# 完成
# =====================================================================
echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  环境搭建完成！${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "  部署目录: ${DEPLOY_DIR}"
echo "  配置来源: $([ -n "${OLD_DIR}" ] && echo '从旧目录恢复' || echo '新生成')"
echo "  启动命令: cd ${DEPLOY_DIR} && php -S 0.0.0.0:${WEB_PORT} -t ."
echo ""

if [ "${VERIFY_OK}" = "true" ]; then
    echo -e "  ${GREEN}所有验证通过${NC}"
else
    echo -e "  ${YELLOW}部分验证未通过，请检查上方日志${NC}"
fi
echo ""
