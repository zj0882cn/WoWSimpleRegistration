#!/bin/bash
# ============================================
# DuckDNS 自动更新脚本
#
# 将此脚本加入 crontab，每 5 分钟执行一次:
#   crontab -e
#   */5 * * * * /path/to/duckdns_update.sh >/dev/null 2>&1
#
# 使用前请修改下面的变量:
#   DUCK_DOMAIN  — 你的 DuckDNS 子域名（不含 .duckdns.org）
#   DUCK_TOKEN   — DuckDNS 提供的 token
#   SERVER_IP    — 你的服务器 IP（留空则自动检测公网 IP）
# ============================================

DUCK_DOMAIN="your-subdomain"       # DuckDNS 子域名（不含 .duckdns.org）
DUCK_TOKEN="your-duckdns-token"     # DuckDNS 提供的 token
SERVER_IP=""                        # 你的服务器 IP（留空则自动检测公网 IP）

# 如果未指定 IP，自动获取公网 IP
if [ -z "$SERVER_IP" ]; then
    SERVER_IP=$(curl -s https://api.ipify.org 2>/dev/null)
    if [ -z "$SERVER_IP" ]; then
        echo "[$(date)] 无法获取公网 IP"
        exit 1
    fi
fi

# 更新 DuckDNS
RESULT=$(curl -s "https://www.duckdns.org/update?domains=${DUCK_DOMAIN}&token=${DUCK_TOKEN}&ip=${SERVER_IP}")

if [ "$RESULT" = "OK" ]; then
    echo "[$(date)] DuckDNS 更新成功: ${DUCK_DOMAIN}.duckdns.org -> ${SERVER_IP}"
else
    echo "[$(date)] DuckDNS 更新失败: ${RESULT}"
    exit 1
fi
