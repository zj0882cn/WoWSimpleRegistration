#!/bin/bash
# ============================================================
#  WoWSimpleRegistration — 一键启动测试环境
#
#  启动三个本地服务:
#    1. Mock SOAP Server     (port 7878) — 模拟 AzerothCore 游戏服务器
#    2. Mock WeChat OAuth     (port 9190) — 模拟微信开放平台 API
#    3. PHP Web Server        (port 8080) — 注册网站主程序
#
#  用法:
#    cd /path/to/WoWSimpleRegistration/tests
#    bash start_test.sh
#
#  停止所有服务:
#    bash start_test.sh --stop
# ============================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
PID_DIR="$SCRIPT_DIR/.pids"

mkdir -p "$PID_DIR"

# ---- Colors ----
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

print_ok()   { echo -e "${GREEN}[OK]${NC} $1"; }
print_err()  { echo -e "${RED}[ERROR]${NC} $1"; }
print_info() { echo -e "${CYAN}[INFO]${NC} $1"; }
print_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }

# ---- Stop mode ----
if [ "$1" = "--stop" ]; then
    echo "Stopping all test services..."
    for pidfile in "$PID_DIR"/*.pid; do
        if [ -f "$pidfile" ]; then
            pid=$(cat "$pidfile")
            name=$(basename "$pidfile" .pid)
            if kill "$pid" 2>/dev/null; then
                print_ok "Stopped $name (PID $pid)"
            else
                print_warn "$name (PID $pid) was not running"
            fi
            rm -f "$pidfile"
        fi
    done
    # Also kill any PHP processes on our ports
    for port in 7878 9190 8080; do
        pids=$(lsof -ti:$port 2>/dev/null || true)
        if [ -n "$pids" ]; then
            kill $pids 2>/dev/null || true
            print_ok "Killed process on port $port"
        fi
    done
    echo "All services stopped."
    exit 0
fi

# ---- Check PHP ----
if ! command -v php &>/dev/null; then
    print_err "PHP is not installed. Please install PHP CLI first."
    print_info "Ubuntu/Debian: sudo apt install php-cli php-soap php-curl php-gd"
    print_info "CentOS/RHEL:   sudo yum install php-cli php-soap php-curl php-gd"
    exit 1
fi

print_info "PHP version: $(php -v | head -1)"

# ---- Copy test config ----
if [ ! -f "$PROJECT_DIR/application/config/config.php" ]; then
    cp "$SCRIPT_DIR/test_config.php" "$PROJECT_DIR/application/config/config.php"
    print_ok "Copied test_config.php -> application/config/config.php"
else
    # Check if it's already the test config
    if grep -q "mock_appid" "$PROJECT_DIR/application/config/config.php" 2>/dev/null; then
        print_info "Test config already in place."
    else
        print_warn "config.php already exists and may not be the test config."
        print_info "Backing up existing config and copying test config..."
        cp "$PROJECT_DIR/application/config/config.php" "$PROJECT_DIR/application/config/config.php.bak"
        cp "$SCRIPT_DIR/test_config.php" "$PROJECT_DIR/application/config/config.php"
        print_ok "Backed up original config.php -> config.php.bak"
        print_ok "Copied test_config.php -> application/config/config.php"
    fi
fi

# ---- Ensure data directory exists ----
mkdir -p "$PROJECT_DIR/application/data"
print_ok "Ensured application/data/ directory exists"

# ---- Start Mock SOAP Server (port 7878) ----
echo ""
print_info "Starting Mock SOAP Server on port 7878..."
nohup php -S 0.0.0.0:7878 "$SCRIPT_DIR/mock_soap_handler.php" > "$SCRIPT_DIR/soap_server.log" 2>&1 &
SOAP_PID=$!
echo $SOAP_PID > "$PID_DIR/soap.pid"
sleep 1
if kill -0 $SOAP_PID 2>/dev/null; then
    print_ok "Mock SOAP Server started (PID $SOAP_PID, port 7878)"
else
    print_err "Failed to start Mock SOAP Server. Check soap_server.log"
    exit 1
fi

# ---- Start Mock WeChat OAuth Server (port 9190) ----
print_info "Starting Mock WeChat OAuth Server on port 9190..."
nohup php -S 0.0.0.0:9190 "$SCRIPT_DIR/mock_wechat_oauth.php" > "$SCRIPT_DIR/wechat_oauth.log" 2>&1 &
WX_PID=$!
echo $WX_PID > "$PID_DIR/wechat.pid"
sleep 1
if kill -0 $WX_PID 2>/dev/null; then
    print_ok "Mock WeChat OAuth Server started (PID $WX_PID, port 9190)"
else
    print_err "Failed to start Mock WeChat OAuth Server. Check wechat_oauth.log"
    exit 1
fi

# ---- Start Main PHP Web Server (port 8080) ----
print_info "Starting Registration Website on port 8080..."
cd "$PROJECT_DIR"
nohup php -S 0.0.0.0:8080 > "$SCRIPT_DIR/web_server.log" 2>&1 &
WEB_PID=$!
echo $WEB_PID > "$PID_DIR/web.pid"
sleep 1
if kill -0 $WEB_PID 2>/dev/null; then
    print_ok "Registration Website started (PID $WEB_PID, port 8080)"
else
    print_err "Failed to start Registration Website. Check web_server.log"
    exit 1
fi

# ---- Summary ----
echo ""
echo "============================================================"
echo -e "${GREEN}  All test services are running!${NC}"
echo "============================================================"
echo ""
echo "  Registration Website:   http://localhost:8080"
echo "  Test Console:            http://localhost:8080/tests/test_console.php"
echo "  Mock WeChat QR Login:    http://localhost:9190/connect/qrconnect"
echo "  Mock WeChat API Status:  http://localhost:9190/mock_api/status"
echo "  Mock SOAP Server:        http://localhost:7878"
echo ""
echo "  Log files:"
echo "    $SCRIPT_DIR/soap_server.log"
echo "    $SCRIPT_DIR/wechat_oauth.log"
echo "    $SCRIPT_DIR/web_server.log"
echo ""
echo -e "  ${YELLOW}To stop all services:${NC} bash start_test.sh --stop"
echo "============================================================"
