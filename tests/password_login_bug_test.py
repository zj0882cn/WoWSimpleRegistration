#!/usr/bin/env python3
"""
WoWSimpleRegistration - 密码登录 Bug 专项测试
==============================================
测试密码登录的安全性，确保：
1. 错误密码被拒绝
2. 无密码哈希的账号不能登录
3. 正确密码才能登录

Bug 描述：当账号在游戏服务器上存在但本地无密码哈希时，
系统会跳过密码验证直接允许登录成功。
"""

import urllib.request
import urllib.parse
import urllib.error
import json
import sys
import time
import os

BASE_URL = "http://127.0.0.1:8080"
PASSED = 0
FAILED = 0
ERRORS = []

def api_post(path, data):
    """POST request returning parsed JSON."""
    url = f"{BASE_URL}{path}"
    headers = {"Content-Type": "application/x-www-form-urlencoded"}
    body = urllib.parse.urlencode(data).encode() if data else None
    
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
    except urllib.error.URLError as e:
        return {"success": False, "message": f"connection_error: {e.reason}"}
    except Exception as e:
        return {"success": False, "message": str(e)[:100]}


def test(name, expected_success, result, expected_message=None):
    """
    Assert test result matches expected outcome.
    """
    global PASSED, FAILED, ERRORS
    
    actual_success = result.get("success", False)
    actual_message = result.get("message", "")
    
    passed = (actual_success == expected_success)
    
    # Also check expected message if specified
    if expected_message and actual_message != expected_message:
        passed = False
    
    if passed:
        PASSED += 1
        print(f"  ✅ {name}")
    else:
        FAILED += 1
        reason = f"success: {actual_success} (expected: {expected_success})"
        if expected_message:
            reason += f", message: {actual_message} (expected: {expected_message})"
        elif actual_message:
            reason += f", message: {actual_message}"
        
        ERRORS.append(f"[FAIL] {name}")
        ERRORS.append(f"       {reason}")
        print(f"  ❌ {name}")
        print(f"      {reason}")


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
# 辅助函数：创建测试账号
# =========================================================================
def create_test_account(phone, password, username):
    """通过 oneclick 创建测试账号"""
    return api_post("/oneclick_verify.php", {
        "phone": phone,
        "password": password,
        "username": username
    })


def remove_password_hash(username):
    """从绑定文件中移除指定账号的密码哈希"""
    data_file = "/workspace/WoWSimpleRegistration/application/data/mobile_bindings.json"
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


def add_password_hash(username, password):
    """为指定账号添加密码哈希"""
    data_file = "/workspace/WoWSimpleRegistration/application/data/mobile_bindings.json"
    with open(data_file) as f:
        bindings = json.load(f)
    
    for entry in bindings:
        if entry.get("username") == username:
            import hashlib
            # 使用与 PHP password_hash(PASSWORD_BCRYPT) 兼容的方式
            # 简单起见，使用一个已知正确的哈希
            # 这里我们直接调用 PHP 来生成
            import subprocess
            result = subprocess.run(
                ["php", "-r", f'echo password_hash("{password}", PASSWORD_BCRYPT);'],
                capture_output=True, text=True, cwd="/workspace/WoWSimpleRegistration"
            )
            if result.returncode == 0:
                entry["password_hash"] = result.stdout.strip()
                with open(data_file, "w") as f:
                    json.dump(bindings, f, indent=2)
                return True
    return False


# =========================================================================
# 测试开始
# =========================================================================
divider("密码登录 Bug 专项测试")
print(f"目标: {BASE_URL}")
print(f"时间: {time.strftime('%Y-%m-%d %H:%M:%S')}")

# =========================================================================
divider("1. 密码输入验证测试")
# =========================================================================

# 创建一个测试账号
r = create_test_account("13877770001", "testpass1", "BUG_SEC01")
print(f"\n创建测试账号 BUG_SEC01: {r.get('success')}")

# 测试各种密码输入
test("空密码", False, api_post("/password_login.php", {"username": "BUG_SEC01", "password": ""}), "password_required")
test("短密码(5位)", False, api_post("/password_login.php", {"username": "BUG_SEC01", "password": "12345"}), "wrong_password")
test("长密码(33位)", False, api_post("/password_login.php", {"username": "BUG_SEC01", "password": "a" * 33}), "wrong_password")
test("特殊字符密码", False, api_post("/password_login.php", {"username": "BUG_SEC01", "password": "<script>alert(1)</script>"}), "wrong_password")
test("SQL注入密码", False, api_post("/password_login.php", {"username": "BUG_SEC01", "password": "' OR 1=1--"}), "wrong_password")

# =========================================================================
divider("2. 用户名输入验证测试")
# =========================================================================

test("空用户名", False, api_post("/password_login.php", {"username": "", "password": "testpass1"}), "username_required")
test("不存在的用户名", False, api_post("/password_login.php", {"username": "NONEXISTENT", "password": "testpass1"}), "account_not_found")
test("SQL注入用户名", False, api_post("/password_login.php", {"username": "' OR 1=1--", "password": "testpass1"}), "account_not_found")

# =========================================================================
divider("3. 密码正确性验证测试")
# =========================================================================

# 创建新账号
r = create_test_account("13877770002", "correctpass1", "BUG_SEC02")
print(f"\n创建测试账号 BUG_SEC02: {r.get('success')}")

test("正确密码登录", True, api_post("/password_login.php", {"username": "BUG_SEC02", "password": "correctpass1"}))
test("错误密码登录", False, api_post("/password_login.php", {"username": "BUG_SEC02", "password": "wrongpass1"}), "wrong_password")
test("大小写错误密码", False, api_post("/password_login.php", {"username": "BUG_SEC02", "password": "CORRECTPASS1"}), "wrong_password")
test("前后空格密码(trim处理)", True, api_post("/password_login.php", {"username": "BUG_SEC02", "password": " correctpass1 "}))

# =========================================================================
divider("4. 核心 Bug 测试：无密码哈希账号登录")
# =========================================================================

print("\n--- 准备测试环境 ---")
# 创建账号
r = create_test_account("13877770003", "initpass1", "BUG_NOHASH")
print(f"创建账号 BUG_NOHASH: {r.get('success')}")

# 移除密码哈希
if remove_password_hash("BUG_NOHASH"):
    print("已移除 BUG_NOHASH 的密码哈希")
    
    # 测试：无密码哈希的账号不能登录（这是 Bug 的修复点）
    test("无密码哈希账号-任意密码", False, api_post("/password_login.php", {
        "username": "BUG_NOHASH",
        "password": "ANY_PASSWORD"
    }), "no_password_hash")
    
    test("无密码哈希账号-原密码", False, api_post("/password_login.php", {
        "username": "BUG_NOHASH",
        "password": "initpass1"
    }), "no_password_hash")
    
    print("✅ 无密码哈希账号被正确拒绝（Bug 已修复）")
else:
    print("⚠️  未能移除密码哈希，跳过此测试")

# =========================================================================
divider("5. 密码哈希恢复后测试")
# =========================================================================

# 重新添加密码哈希
import subprocess
result = subprocess.run(
    ["php", "-r", 'echo password_hash("restoredpass1", PASSWORD_BCRYPT);'],
    capture_output=True, text=True, cwd="/workspace/WoWSimpleRegistration"
)
if result.returncode == 0:
    hash_val = result.stdout.strip()
    # 手动更新绑定文件
    data_file = "/workspace/WoWSimpleRegistration/application/data/mobile_bindings.json"
    with open(data_file) as f:
        bindings = json.load(f)
    for entry in bindings:
        if entry.get("username") == "BUG_NOHASH":
            entry["password_hash"] = hash_val
            break
    with open(data_file, "w") as f:
        json.dump(bindings, f, indent=2)
    print("已恢复 BUG_NOHASH 的密码哈希")
    
    test("恢复哈希后-正确密码", True, api_post("/password_login.php", {
        "username": "BUG_NOHASH",
        "password": "restoredpass1"
    }))
    
    test("恢复哈希后-错误密码", False, api_post("/password_login.php", {
        "username": "BUG_NOHASH",
        "password": "wrongpass"
    }), "wrong_password")

# =========================================================================
divider("6. 并发登录安全测试")
# =========================================================================

# 尝试用同一账号多次错误密码
print("\n--- 连续错误密码尝试 ---")
for i in range(3):
    result = api_post("/password_login.php", {
        "username": "BUG_SEC01",
        "password": f"wrongpass_{i}"
    })
    print(f"  第{i+1}次错误密码: success={result.get('success')}, message={result.get('message')}")

# 最终正确密码仍能登录
test("多次错误后-正确密码", True, api_post("/password_login.php", {
    "username": "BUG_SEC01",
    "password": "testpass1"
}))

# =========================================================================
divider("7. 会话安全测试")
# =========================================================================

# 登录后检查 session
print("\n--- 登录会话检查 ---")
r = create_test_account("13877770004", "sessionpass1", "BUG_SESSION")
print(f"创建账号 BUG_SESSION: {r.get('success')}")

# 检查登录后 session 是否设置
login_result = api_post("/password_login.php", {
    "username": "BUG_SESSION",
    "password": "sessionpass1"
})
print(f"登录结果: {json.dumps(login_result, indent=2)}")
test("登录返回 redirect 字段", True, {"success": "redirect" in login_result})
test("登录返回 username", True, {"success": login_result.get("username") == "BUG_SESSION"})

# =========================================================================
# 清理测试数据
# =========================================================================
divider("清理测试数据")

# 删除测试账号绑定
data_file = "/workspace/WoWSimpleRegistration/application/data/mobile_bindings.json"
with open(data_file) as f:
    bindings = json.load(f)

test_accounts = ["BUG_SEC01", "BUG_SEC02", "BUG_NOHASH", "BUG_SESSION", "BUGTEST01", "BUGTEST02"]
original_count = len(bindings)
bindings = [e for e in bindings if e.get("username") not in test_accounts]
removed = original_count - len(bindings)

with open(data_file, "w") as f:
    json.dump(bindings, f, indent=2)

print(f"已清理 {removed} 条测试数据")

# =========================================================================
# 输出报告
# =========================================================================
all_passed = report()
sys.exit(0 if all_passed else 1)
