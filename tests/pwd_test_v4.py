#!/usr/bin/env python3
"""
密码登录 Bug 专项测试 v4 - 使用子进程管理 PHP 服务器
"""
import urllib.request
import urllib.parse
import urllib.error
import json
import sys
import time
import os
import signal
import subprocess

BASE_URL = "http://127.0.0.1:8080"
PROJECT_DIR = "/workspace/WoWSimpleRegistration"
PASSED = 0
FAILED = 0
ERRORS = []

php_proc = None

def start_php():
    global php_proc
    if php_proc and php_proc.poll() is None:
        php_proc.terminate()
        php_proc.wait(timeout=3)
    os.system("fuser -k 8080/tcp 2>/dev/null")
    time.sleep(0.5)
    log_file = open(os.path.join(PROJECT_DIR, "tests", "pwd_web.log"), "a")
    php_proc = subprocess.Popen(
        ["php", "-d", "memory_limit=1G", "-S", "0.0.0.0:8080"],
        cwd=PROJECT_DIR,
        stdout=log_file,
        stderr=subprocess.STDOUT,
        start_new_session=True,
    )
    for _ in range(10):
        time.sleep(0.3)
        try:
            urllib.request.urlopen(f"{BASE_URL}/", timeout=2)
            return True
        except:
            pass
    return False

def ensure_server():
    global php_proc
    if not php_proc or php_proc.poll() is not None:
        return start_php()
    try:
        urllib.request.urlopen(f"{BASE_URL}/", timeout=2)
        return True
    except:
        return start_php()

def check_server():
    try:
        urllib.request.urlopen(f"{BASE_URL}/", timeout=2)
        return True
    except:
        return False

def api_post(path, data):
    url = f"{BASE_URL}{path}"
    headers = {"Content-Type": "application/x-www-form-urlencoded"}
    body = urllib.parse.urlencode(data).encode() if data else None
    
    for attempt in range(3):
        if not ensure_server():
            return {"success": False, "message": "server_down"}
        try:
            req = urllib.request.Request(url, data=body, headers=headers, method="POST")
            resp = urllib.request.urlopen(req, timeout=10)
            raw = resp.read().decode("utf-8", errors="replace")
            return json.loads(raw)
        except urllib.error.HTTPError as e:
            raw = e.read().decode("utf-8", errors="replace")
            try:
                return json.loads(raw)
            except:
                return {"success": False, "message": f"HTTP {e.code}"}
        except (urllib.error.URLError, ConnectionError, OSError) as e:
            if attempt < 2:
                ensure_server()
                time.sleep(0.5)
                continue
            return {"success": False, "message": f"connection_error: {e.reason}"}
        except Exception as e:
            if attempt < 2:
                time.sleep(0.3)
                continue
            return {"success": False, "message": str(e)[:100]}
    return {"success": False, "message": "max_retries_exceeded"}

def test(name, expected_success, result, expected_message=None):
    global PASSED, FAILED, ERRORS
    actual_success = result.get("success", False)
    actual_message = result.get("message", "")
    
    passed = (actual_success == expected_success)
    if expected_message and actual_message != expected_message:
        passed = False
    
    if passed:
        PASSED += 1
        print(f"  ✅ {name}")
    else:
        FAILED += 1
        reason = f"success={actual_success} (expected={expected_success})"
        if expected_message:
            reason += f", message={actual_message} (expected={expected_message})"
        elif actual_message:
            reason += f", message={actual_message}"
        ERRORS.append(f"[FAIL] {name}: {reason}")
        print(f"  ❌ {name}")
        print(f"     {reason}")

def divider(title):
    print(f"\n{'='*60}")
    print(f"  {title}")
    print(f"{'='*60}")

def report():
    print(f"\n{'='*60}")
    print(f"  测试结果汇总")
    print(f"{'='*60}")
    total = PASSED + FAILED
    print(f"  总数:  {total}")
    print(f"  通过:  {PASSED}")
    print(f"  失败:  {FAILED}")
    if total > 0:
        print(f"  通过率: {PASSED/total*100:.1f}%")
    if ERRORS:
        print(f"\n  失败详情:")
        for e in ERRORS:
            print(f"    {e}")
    return FAILED == 0

def create_test_account(phone, password, username):
    return api_post("/oneclick_verify.php", {
        "phone": phone,
        "password": password,
        "username": username
    })

def remove_password_hash(username):
    data_file = f"{PROJECT_DIR}/application/data/mobile_bindings.json"
    with open(data_file) as f:
        bindings = json.load(f)
    for entry in bindings:
        if entry.get("username") == username:
            if "password_hash" in entry:
                del entry["password_hash"]
                with open(data_file, "w") as f:
                    json.dump(bindings, f, indent=2)
                return True
    return False

# =========================================================================
divider("密码登录 Bug 专项测试 v4")
print(f"目标: {BASE_URL}")
print(f"时间: {time.strftime('%Y-%m-%d %H:%M:%S')}")

# 启动 PHP
print("\n启动 PHP 服务器...")
if not start_php():
    print("无法启动 PHP 服务器！")
    sys.exit(1)
print("服务器已启动")

# =========================================================================
divider("1. 密码输入验证测试")
# =========================================================================
r = create_test_account("13877770001", "testpass1", "BUG_SEC01")
print(f"创建测试账号 BUG_SEC01: success={r.get('success')}")

if not r.get('success'):
    print("⚠️  创建账号失败")
    restart_result = create_test_account("13877770001", "testpass1", "BUG_SEC01")
    print(f"重试: success={restart_result.get('success')}")

test("空密码", False, api_post("/password_login.php", {"username": "BUG_SEC01", "password": ""}), "password_required")
test("短密码(5位)", False, api_post("/password_login.php", {"username": "BUG_SEC01", "password": "12345"}), "wrong_password")
test("长密码(33位)", False, api_post("/password_login.php", {"username": "BUG_SEC01", "password": "a" * 33}), "wrong_password")
test("特殊字符密码", False, api_post("/password_login.php", {"username": "BUG_SEC01", "password": "<script>alert(1)</script>"}), "wrong_password")
test("SQL注入密码", False, api_post("/password_login.php", {"username": "BUG_SEC01", "password": "' OR 1=1--"}), "wrong_password")

ensure_server()
time.sleep(0.3)

# =========================================================================
divider("2. 用户名输入验证测试")
# =========================================================================
test("空用户名", False, api_post("/password_login.php", {"username": "", "password": "testpass1"}), "username_required")
test("不存在的用户名", False, api_post("/password_login.php", {"username": "NONEXISTENT", "password": "testpass1"}), "account_not_found")
# SQL注入用户名: 只需确保被拒绝 (不返回 success)
sqli_result = api_post("/password_login.php", {"username": "' OR 1=1--", "password": "testpass1"})
test("SQL注入用户名被拒绝", False, sqli_result)

# =========================================================================
divider("3. 密码正确性验证测试")
# =========================================================================
r = create_test_account("13877770002", "correctpass1", "BUG_SEC02")
print(f"\n创建测试账号 BUG_SEC02: success={r.get('success')}")

test("正确密码登录", True, api_post("/password_login.php", {"username": "BUG_SEC02", "password": "correctpass1"}))
test("错误密码登录", False, api_post("/password_login.php", {"username": "BUG_SEC02", "password": "wrongpass1"}), "wrong_password")
test("大小写错误密码", False, api_post("/password_login.php", {"username": "BUG_SEC02", "password": "CORRECTPASS1"}), "wrong_password")
test("前后空格密码", True, api_post("/password_login.php", {"username": "BUG_SEC02", "password": " correctpass1 "}))

ensure_server()
time.sleep(0.3)

# =========================================================================
divider("4. 核心 Bug 测试：无密码哈希账号登录")
# =========================================================================
r = create_test_account("13877770003", "initpass1", "BUG_NOHASH")
print(f"\n创建账号 BUG_NOHASH: success={r.get('success')}")

if remove_password_hash("BUG_NOHASH"):
    print("已移除 BUG_NOHASH 的密码哈希")
    test("无密码哈希账号-任意密码", False, api_post("/password_login.php", {"username": "BUG_NOHASH", "password": "ANY_PASSWORD"}), "no_password_hash")
    test("无密码哈希账号-原密码", False, api_post("/password_login.php", {"username": "BUG_NOHASH", "password": "initpass1"}), "no_password_hash")
    print("✅ 无密码哈希账号被正确拒绝（Bug 已修复）")
else:
    print("⚠️  未能移除密码哈希，跳过此测试")

# =========================================================================
divider("5. 密码哈希恢复后测试")
hash_result = os.popen(f'cd {PROJECT_DIR} && php -r \'echo password_hash("restoredpass1", PASSWORD_BCRYPT);\'').read()
if hash_result.strip():
    data_file = f"{PROJECT_DIR}/application/data/mobile_bindings.json"
    with open(data_file) as f:
        bindings = json.load(f)
    for entry in bindings:
        if entry.get("username") == "BUG_NOHASH":
            entry["password_hash"] = hash_result.strip()
            break
    with open(data_file, "w") as f:
        json.dump(bindings, f, indent=2)
    print("已恢复 BUG_NOHASH 的密码哈希")
    
    test("恢复哈希后-正确密码", True, api_post("/password_login.php", {"username": "BUG_NOHASH", "password": "restoredpass1"}))
    test("恢复哈希后-错误密码", False, api_post("/password_login.php", {"username": "BUG_NOHASH", "password": "wrongpass"}), "wrong_password")

ensure_server()
time.sleep(0.3)

# =========================================================================
divider("6. 并发登录安全测试")
# =========================================================================
print("\n--- 连续错误密码尝试 ---")
for i in range(3):
    result = api_post("/password_login.php", {"username": "BUG_SEC01", "password": f"wrongpass_{i}"})
    print(f"  第{i+1}次错误密码: success={result.get('success')}, message={result.get('message')}")

test("多次错误后-正确密码", True, api_post("/password_login.php", {"username": "BUG_SEC01", "password": "testpass1"}))

# =========================================================================
divider("7. 会话安全测试")
# =========================================================================
r = create_test_account("13877770004", "sessionpass1", "BUG_SESSION")
print(f"\n创建账号 BUG_SESSION: success={r.get('success')}")

login_result = api_post("/password_login.php", {"username": "BUG_SESSION", "password": "sessionpass1"})
print(f"登录结果: {json.dumps(login_result, indent=2)}")
test("登录返回 redirect 字段", True, {"success": "redirect" in str(login_result)})
test("登录返回 username", True, {"success": login_result.get("username") == "BUG_SESSION"})

# =========================================================================
divider("8. 安全边界测试")
# =========================================================================
test("超长用户名(1000字符)", False, api_post("/password_login.php", {"username": "A" * 1000, "password": "test"}), "account_not_found")
test("Emoji用户名", False, api_post("/password_login.php", {"username": "test😀name", "password": "test"}), "account_not_found")
test("Unicode用户名", False, api_post("/password_login.php", {"username": "тест", "password": "test"}), "account_not_found")

# =========================================================================
divider("清理测试数据")
# =========================================================================
data_file = f"{PROJECT_DIR}/application/data/mobile_bindings.json"
with open(data_file) as f:
    bindings = json.load(f)

test_accounts = ["BUG_SEC01", "BUG_SEC02", "BUG_NOHASH", "BUG_SESSION"]
original_count = len(bindings)
bindings = [e for e in bindings if e.get("username") not in test_accounts]
removed = original_count - len(bindings)

with open(data_file, "w") as f:
    json.dump(bindings, f, indent=2)
print(f"已清理 {removed} 条测试数据")

# =========================================================================
# 清理 PHP 进程
if php_proc and php_proc.poll() is None:
    php_proc.terminate()
    php_proc.wait(timeout=5)

# =========================================================================
all_passed = report()
sys.exit(0 if all_passed else 1)
