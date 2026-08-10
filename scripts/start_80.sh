#!/bin/bash
# ============================================
# 快速启动脚本 — 使用 PHP 内置服务器 (端口 80)
#
# 适用于快速测试，不需要 Nginx
#
# 使用方法:
#   sudo bash scripts/start_80.sh
#
# 配合 DuckDNS 使用:
#   1. 先注册 DuckDNS 并更新 DNS 指向本机
#   2. 修改 config.php 的 baseurl 为 http://yourname.duckdns.org
#   3. 运行此脚本
# ============================================

SITE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT=${1:-80}

echo "========================================"
echo "  WoWSimpleRegistration"
echo "  PHP 内置服务器 — 端口 $PORT"
echo "========================================"
echo ""
echo "  网站目录: $SITE_DIR"
echo "  访问地址: http://localhost:$PORT"
echo ""

# 检查端口是否被占用
if command -v fuser &>/dev/null; then
    if fuser $PORT/tcp &>/dev/null; then
        echo "[警告] 端口 $PORT 被占用，正在释放..."
        fuser -k $PORT/tcp
        sleep 1
    fi
fi

# 检查 PHP
if ! command -v php &>/dev/null; then
    echo "[错误] PHP 未安装"
    exit 1
fi

echo "[INFO] PHP 版本: $(php -v | head -1)"
echo "[INFO] 启动服务器..."
echo "[INFO] 按 Ctrl+C 停止"
echo ""

cd "$SITE_DIR"
php -S 0.0.0.0:$PORT
