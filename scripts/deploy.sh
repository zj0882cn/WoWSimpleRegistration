#!/bin/bash
# ============================================
# WoWSimpleRegistration 一键部署脚本
#
# 功能:
#   1. 安装 Nginx + PHP-FPM
#   2. 配置 Nginx 站点 (端口 80)
#   3. 可选: 配置 Let's Encrypt SSL (端口 443)
#   4. 设置 DuckDNS 自动更新
#   5. 启动服务
#
# 使用方法:
#   sudo bash scripts/deploy.sh
#
# ============================================

set -e

# ---- 颜色 ----
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
RED='\033[0;31m'
NC='\033[0m'

echo_info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
echo_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
echo_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# ---- 检查 root ----
if [ "$EUID" -ne 0 ]; then
    echo_error "请使用 root 权限运行: sudo bash scripts/deploy.sh"
    exit 1
fi

# ---- 配置 ----
SITE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DOMAIN=""
USE_SSL=false
USE_DUCKDNS=false

echo "========================================"
echo "  WoWSimpleRegistration 部署脚本"
echo "========================================"
echo ""

# 获取域名
read -p "请输入你的域名 (例如: wow.example.com 或 yourname.duckdns.org): " DOMAIN
if [ -z "$DOMAIN" ]; then
    echo_error "域名不能为空"
    exit 1
fi

# 询问是否使用 SSL
read -p "是否配置 HTTPS (Let's Encrypt)? [y/N]: " SSL_ANSWER
if [[ "$SSL_ANSWER" =~ ^[Yy]$ ]]; then
    USE_SSL=true
fi

# 询问是否配置 DuckDNS
read -p "是否使用 DuckDNS? [y/N]: " DUCK_ANSWER
if [[ "$DUCK_ANSWER" =~ ^[Yy]$ ]]; then
    USE_DUCKDNS=true
fi

echo ""
echo_info "域名: $DOMAIN"
echo_info "SSL: $USE_SSL"
echo_info "DuckDNS: $USE_DUCKDNS"
echo_info "网站目录: $SITE_DIR"
echo ""

# ============================================
# 步骤 1: 安装依赖
# ============================================
echo_info "安装 Nginx 和 PHP-FPM..."

if [ -f /etc/debian_version ]; then
    apt-get update -qq
    apt-get install -y -qq nginx php-fpm php-curl php-gd php-gmp php-mbstring php-xml
elif [ -f /etc/redhat-release ]; then
    yum install -y epel-release
    yum install -y nginx php-fpm php-curl php-gd php-gmp php-mbstring php-xml
else
    echo_warn "无法识别系统，请手动安装 Nginx + PHP-FPM"
fi

echo_info "Nginx 和 PHP-FPM 安装完成"

# ============================================
# 步骤 2: 配置 Nginx
# ============================================
echo_info "配置 Nginx 站点..."

NGINX_CONF="/etc/nginx/sites-available/wow-registration"
NGINX_LINK="/etc/nginx/sites-enabled/wow-registration"

# 根据系统查找 PHP-FPM socket
PHP_SOCK=$(find /run /var/run -name "php*-fpm.sock" 2>/dev/null | head -1)
if [ -z "$PHP_SOCK" ]; then
    PHP_SOCK="/run/php/php-fpm.sock"
fi

cat > "$NGINX_CONF" << NGINXEOF
server {
    listen 80;
    server_name ${DOMAIN};

    root ${SITE_DIR};
    index index.php index.html;

    # 微信验证文件 (MP_verify_*.txt)
    location ~ ^/MP_verify_[A-Za-z0-9]+\.txt$ {
        root ${SITE_DIR};
        try_files \$uri =404;
    }

    # 主站
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # PHP 处理
    location ~ \.php$ {
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # 禁止访问敏感文件
    location ~ /\.(ht|git|env) {
        deny all;
    }
    location ~ /(application/config|application/data|tests) {
        deny all;
    }
}
NGINXEOF

# 启用站点
mkdir -p /etc/nginx/sites-enabled
ln -sf "$NGINX_CONF" "$NGINX_LINK"

# 禁用默认站点（如果存在）
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

# 测试 Nginx 配置
if nginx -t 2>/dev/null; then
    echo_info "Nginx 配置测试通过"
else
    echo_warn "Nginx 配置测试失败，请手动检查: nginx -t"
fi

# ============================================
# 步骤 3: 配置 SSL (可选)
# ============================================
if [ "$USE_SSL" = true ]; then
    echo_info "配置 Let's Encrypt SSL..."

    if [ -f /etc/debian_version ]; then
        apt-get install -y -qq certbot python3-certbot-nginx
    elif [ -f /etc/redhat-release ]; then
        yum install -y certbot python3-certbot-nginx
    fi

    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email
    echo_info "SSL 配置完成"
fi

# ============================================
# 步骤 4: 更新 config.php
# ============================================
echo_info "更新 config.php 中的 baseurl..."

CONFIG_FILE="${SITE_DIR}/application/config/config.php"
PROTOCOL="http"
if [ "$USE_SSL" = true ]; then
    PROTOCOL="https"
fi

# 替换 baseurl
if [ -f "$CONFIG_FILE" ]; then
    sed -i "s|getenv('WOW_BASEURL') ?: 'http://localhost:8080'|getenv('WOW_BASEURL') ?: '${PROTOCOL}://${DOMAIN}'|" "$CONFIG_FILE"
    echo_info "baseurl 已更新为: ${PROTOCOL}://${DOMAIN}"
fi

# ============================================
# 步骤 5: 配置 DuckDNS (可选)
# ============================================
if [ "$USE_DUCKDNS" = true ]; then
    echo_info "配置 DuckDNS..."

    read -p "DuckDNS 子域名 (不含 .duckdns.org): " DUCK_DOMAIN
    read -p "DuckDNS Token: " DUCK_TOKEN

    # 配置 DuckDNS 更新脚本
    DUCK_SCRIPT="/usr/local/bin/duckdns_update.sh"
    cat > "$DUCK_SCRIPT" << DUCKEOF
#!/bin/bash
DOMAIN="${DUCK_DOMAIN}"
TOKEN="${DUCK_TOKEN}"
curl -s "https://www.duckdns.org/update?domains=\${DOMAIN}&token=\${TOKEN}&ip=" >/dev/null 2>&1
DUCKEOF
    chmod +x "$DUCK_SCRIPT"

    # 加入 crontab
    (crontab -l 2>/dev/null; echo "*/5 * * * * ${DUCK_SCRIPT} >/dev/null 2>&1") | crontab -

    # 立即执行一次
    bash "$DUCK_SCRIPT"

    echo_info "DuckDNS 配置完成: ${DUCK_DOMAIN}.duckdns.org"
fi

# ============================================
# 步骤 6: 设置权限
# ============================================
echo_info "设置文件权限..."

chown -R www-data:www-data "$SITE_DIR" 2>/dev/null || chown -R nginx:nginx "$SITE_DIR" 2>/dev/null || true
chmod -R 755 "$SITE_DIR"
chmod -R 775 "${SITE_DIR}/application/data" 2>/dev/null || true

# ============================================
# 步骤 7: 启动服务
# ============================================
echo_info "启动服务..."

systemctl restart php*-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
systemctl enable nginx
systemctl restart nginx

echo ""
echo "========================================"
echo -e "${GREEN}  部署完成！${NC}"
echo "========================================"
echo ""
echo "  访问地址: ${PROTOCOL}://${DOMAIN}"
echo ""
echo "  下一步:"
echo "    1. 在微信公众平台配置网页授权域名: ${DOMAIN}"
echo "    2. 下载验证文件 MP_verify_*.txt 到网站根目录"
echo "    3. 测试微信登录"
echo ""

if [ "$USE_SSL" = true ]; then
    echo "  SSL 证书已自动配置，HTTPS 可用"
else
    echo -e "  ${YELLOW}注意: 微信公众号 OAuth 可能要求 HTTPS${NC}"
    echo "  如需配置 SSL，运行: sudo certbot --nginx -d ${DOMAIN}"
fi
echo ""
