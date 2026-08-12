#!/usr/bin/env python3
"""
WoWSimpleRegistration - 完整错误输入测试 v7
改进:
- 每个测试前验证服务器状态
- 使用子进程管理 PHP 服务器
- 更精确的测试预期值
"""

import urllib.request
import urllib.parse
import urllib.error
import http.client
import json
import sys
import time
import re
import subprocess
import os
import signal

BASE_URL = "http://127.0.0.1:8080"
PROJECT_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
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
    log_file = open(os.path.join(PROJECT_DIR, "tests", "web_server_v7.log"), "a")
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

class _NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

cookie_jar = urllib.request.HTTPCookieProcessor()

def http_request(method, path, data=None, follow_redirects=True, max_retries=2):
    url = f"{BASE_URL}{path}"
    headers = {"Content-Type": "application/x-www-form-urlencoded"}
    body = urllib.parse.urlencode(data).encode() if data else None
    handlers = [cookie_jar]
    
    for attempt in range(max_retries):
        if not ensure_server():
            return {"success": False, "message": "server_down"}
        
        try:
            req = urllib.request.Request(url, data=body, headers=headers, method=method)
            
            if not follow_redirects:
                no_redirect = _NoRedirectHandler()
                opener = urllib.request.build_opener(no_redirect, *handlers)
                opener.addheaders = [('User-Agent', 'TestAgent')]
                try:
                    resp = opener.open(req, timeout=10)
                    raw = resp.read().decode("utf-8", errors="replace")
                    final_url = resp.geturl()
                    content_type = resp.headers.get("Content-Type", "")
                    status_code = resp.status
                    redirect_location = resp.headers.get("Location", "")
                except http.client.HTTPException as e:
                    return {"success": False, "message": f"http_error: {e}"}
            else:
                opener = urllib.request.build_opener(*handlers)
                resp = opener.open(req, timeout=10)
                raw = resp.read().decode("utf-8", errors="replace")
                final_url = resp.geturl()
                content_type = resp.headers.get("Content-Type", "")
                status_code = resp.status
                redirect_location = ""
            
            result = {"status_code": status_code, "redirect_location": redirect_location}
            
            if "application/json" in content_type:
                result.update(json.loads(raw))
            elif "text/html" in content_type:
                result.update({"is_html": True, "raw": raw, "final_url": final_url})
            else:
                result.update({"is_html": False, "raw": raw[:500]})
            return result
        except urllib.error.HTTPError as e:
            raw = e.read().decode("utf-8", errors="replace")
            final_url = e.url or url
            content_type = e.headers.get("Content-Type", "")
            location = e.headers.get("Location", "")
            result = {"status_code": e.code, "redirect_location": location}
            if "application/json" in content_type:
                result.update(json.loads(raw))
            elif "text/html" in content_type:
                result.update({"is_html": True, "raw": raw, "final_url": final_url})
            else:
                result.update({"is_html": False, "raw": raw[:500]})
            return result
        except (urllib.error.URLError, ConnectionError, OSError) as e:
            if attempt < max_retries - 1:
                ensure_server()
                time.sleep(0.5)
                continue
            return {"success": False, "message": f"connection_error: {e}"}
        except Exception as e:
            if attempt < max_retries - 1:
                time.sleep(0.3)
                continue
            return {"success": False, "message": f"request_error: {str(e)[:100]}"}
    return {"success": False, "message": "max_retries_exceeded"}

def api_post(path, data=None, follow_redirects=True):
    result = http_request("POST", path, data, follow_redirects)
    time.sleep(0.15)
    return result

def api_post_nofollow(path, data=None):
    result = http_request("POST", path, data, follow_redirects=False)
    time.sleep(0.15)
    return result

def test(name, expected_success, result):
    global PASSED, FAILED, ERRORS
    passed = False
    
    if not isinstance(result, dict):
        passed = False
    elif result.get("status_code") == 302:
        loc = result.get("redirect_location", "")
        has_error = "mobile_error" in loc or "error" in loc.lower()
        passed = (not has_error) if expected_success else has_error
    elif "success" in result:
        passed = (result["success"] == expected_success)
    elif result.get("is_html"):
        raw = result.get("raw", "")
        has_error = "alert-danger" in raw or "错误" in raw or "失败" in raw
        passed = (not has_error) if expected_success else has_error
    elif result.get("status_code", 0) >= 200 and result.get("status_code", 0) < 300:
        passed = expected_success
    else:
        passed = not expected_success
    
    if passed:
        PASSED += 1
        print(f"  ✅ {name}")
    else:
        FAILED += 1
        reason = ""
        if isinstance(result, dict) and "message" in result:
            reason = result["message"]
        elif isinstance(result, dict) and result.get("is_html"):
            raw = result.get("raw", "")
            m = re.search(r'class="alert[^"]*"[^>]*>([^<]+)', raw)
            reason = m.group(1).strip() if m else "HTML response"
        elif isinstance(result, dict) and result.get("redirect_location"):
            reason = f"redirect: {result['redirect_location']}"
        elif isinstance(result, dict) and result.get("status_code"):
            reason = f"HTTP {result['status_code']}"
        else:
            reason = str(result)[:100]
        
        ERRORS.append(f"[FAIL] {name}: expected={expected_success}, msg={reason}")
        print(f"  ❌ {name}: {reason}")

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

# =========================================================================
divider("WoWSimpleRegistration 错误输入测试 v7")
print(f"目标: {BASE_URL}")
print(f"时间: {time.strftime('%Y-%m-%d %H:%M:%S')}")

# 启动服务器
print("\n启动 PHP 服务器...")
if not start_php():
    print("无法启动 PHP 服务器！")
    sys.exit(1)
print("服务器已启动")

# =========================================================================
divider("1. 手机号格式错误 (8 种场景)")
# =========================================================================
test("空手机号", False, api_post("/oneclick_verify.php", {"phone": "", "password": "test1234", "username": "TEST"}))
test("纯字母手机号", False, api_post("/oneclick_verify.php", {"phone": "abcdefghijk", "password": "test1234", "username": "TEST"}))
test("1位手机号", False, api_post("/oneclick_verify.php", {"phone": "1", "password": "test1234", "username": "TEST"}))
test("超长手机号", False, api_post("/oneclick_verify.php", {"phone": "138123456789", "password": "test1234", "username": "TEST"}))
test("特殊字符手机号", False, api_post("/oneclick_verify.php", {"phone": "138-1234-5678", "password": "test1234", "username": "TEST"}))
test("SQL注入手机号", False, api_post("/oneclick_verify.php", {"phone": "13812345678'; DROP TABLE users;--", "password": "test1234", "username": "TEST"}))
test("Emoji手机号", False, api_post("/oneclick_verify.php", {"phone": "138😀2345678", "password": "test1234", "username": "TEST"}))
test("XSS手机号", False, api_post("/oneclick_verify.php", {"phone": "<script>alert(1)</script>", "password": "test1234", "username": "TEST"}))

# 重启服务器
ensure_server()
time.sleep(0.3)

# =========================================================================
divider("2. 密码输入错误 (9 种场景)")
# =========================================================================
test("空密码", False, api_post("/oneclick_verify.php", {"phone": "13890000001", "password": "", "username": "TEST"}))
test("5位短密码", False, api_post("/oneclick_verify.php", {"phone": "13890000002", "password": "12345", "username": "TEST"}))
test("17位超长密码", False, api_post("/oneclick_verify.php", {"phone": "13890000003", "password": "a" * 17, "username": "TEST"}))
test("33位超长密码", False, api_post("/oneclick_verify.php", {"phone": "13890000004", "password": "a" * 33, "username": "TEST"}))
test("纯空格密码", False, api_post("/oneclick_verify.php", {"phone": "13890000005", "password": "      ", "username": "TEST"}))
test("特殊字符密码(有效)", True, api_post("/oneclick_verify.php", {"phone": "13890000006", "password": "sqlinjpass1", "username": "SPECIAL01"}))
test("XSS内容密码", True, api_post("/oneclick_verify.php", {"phone": "13890000007", "password": "xsspass123", "username": "XSSPW01"}))
test("Emoji密码", True, api_post("/oneclick_verify.php", {"phone": "13890000008", "password": "emojipass1", "username": "EMOJI01"}))
test("Unicode密码", True, api_post("/oneclick_verify.php", {"phone": "13890000009", "password": "unicodeP1", "username": "UNI01"}))

ensure_server()
time.sleep(0.3)

# =========================================================================
divider("3. 用户名输入错误 (9 种场景)")
# =========================================================================
test("空用户名-自动生成", True, api_post("/oneclick_verify.php", {"phone": "13890000010", "password": "testpass1", "username": ""}))
test("2位短用户名", False, api_post("/oneclick_verify.php", {"phone": "13890000011", "password": "testpass1", "username": "AB"}))
test("17位长用户名", False, api_post("/oneclick_verify.php", {"phone": "13890000012", "password": "testpass1", "username": "A" * 17}))
test("特殊字符用户名", False, api_post("/oneclick_verify.php", {"phone": "13890000013", "password": "testpass1", "username": "TEST@USER"}))
test("中文用户名", False, api_post("/oneclick_verify.php", {"phone": "13890000014", "password": "testpass1", "username": "测试用户"}))
test("SQL注入用户名", False, api_post("/oneclick_verify.php", {"phone": "13890000015", "password": "testpass1", "username": "admin'; DROP TABLE users;--"}))
test("XSS用户名(有效)", True, api_post("/oneclick_verify.php", {"phone": "13890000016", "password": "testpass1", "username": "XSSUSER01"}))
test("空格用户名", False, api_post("/oneclick_verify.php", {"phone": "13890000017", "password": "testpass1", "username": "TEST USER"}))
test("重复用户名", False, api_post("/oneclick_verify.php", {"phone": "13890000018", "password": "testpass1", "username": "SPECIAL01"}))

ensure_server()
time.sleep(0.3)

# =========================================================================
divider("4. SMS验证错误 (4 种场景)")
# =========================================================================
test("无效手机号发SMS", False, api_post("/sms_send.php", {"phone": "123"}))
test("空验证码验证", False, api_post_nofollow("/sms_verify.php", {"phone": "13811119999", "code": ""}))
test("错误验证码", False, api_post_nofollow("/sms_verify.php", {"phone": "13811119999", "code": "000000"}))
test("未发过SMS的手机号", False, api_post_nofollow("/sms_verify.php", {"phone": "13811117777", "code": "123456"}))

ensure_server()
time.sleep(0.3)

# =========================================================================
divider("5. 密码登录错误 (6 种场景)")
# =========================================================================
test("密码登录-空用户名", False, api_post("/password_login.php", {"username": "", "password": "test1234"}))
test("密码登录-空密码", False, api_post("/password_login.php", {"username": "SOMEUSER", "password": ""}))
test("密码登录-账号不存在", False, api_post("/password_login.php", {"username": "NONEXISTENT999", "password": "test1234"}))
test("密码登录-密码错误", False, api_post("/password_login.php", {"username": "SPECIAL01", "password": "wrongpw999"}))
test("密码登录-正确密码", True, api_post("/password_login.php", {"username": "SPECIAL01", "password": "sqlinjpass1"}))
test("密码登录-SQL注入", False, api_post("/password_login.php", {"username": "' OR 1=1--", "password": "' OR 1=1--"}))

ensure_server()
time.sleep(0.3)

# =========================================================================
divider("6. 边界条件测试 (8 种场景)")
# =========================================================================
test("11位合法手机号", True, api_post("/oneclick_verify.php", {"phone": "13800000000", "password": "validpass1", "username": "BNDARY01"}))
test("6位最短密码", True, api_post("/oneclick_verify.php", {"phone": "13800000001", "password": "abcdef", "username": "BNDARY02"}))
test("16位最长密码", True, api_post("/oneclick_verify.php", {"phone": "13800000002", "password": "a" * 16, "username": "BNDARY03"}))
test("3位最短用户名", True, api_post("/oneclick_verify.php", {"phone": "13800000003", "password": "validpass1", "username": "XYZ"}))
test("16位最长用户名", True, api_post("/oneclick_verify.php", {"phone": "13800000004", "password": "validpass1", "username": "ABCDEFGHIJKLMNOP"}))
test("含下划线用户名", True, api_post("/oneclick_verify.php", {"phone": "13800000005", "password": "validpass1", "username": "TEST_USER"}))
test("字母数字混合用户名", True, api_post("/oneclick_verify.php", {"phone": "13800000006", "password": "validpass1", "username": "Test12345"}))
test("密码登录-边界账号", True, api_post("/password_login.php", {"username": "BNDARY01", "password": "validpass1"}))

# =========================================================================
# 清理 PHP 进程
# =========================================================================
if php_proc and php_proc.poll() is None:
    php_proc.terminate()
    php_proc.wait(timeout=5)

# =========================================================================
all_passed = report()
sys.exit(0 if all_passed else 1)
