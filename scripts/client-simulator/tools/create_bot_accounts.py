#!/usr/bin/env python3
"""
批量创建 bot 账号脚本
使用与 AzerothCore 完全相同的 SRP6 算法生成 salt + verifier

SRP6 公式:
  N = 0x894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7
  g = 7
  x = SHA1(salt || SHA1(UPPER(username) + ":" + UPPER(password)))
  v = g^x mod N
"""

import hashlib
import os
import subprocess
import sys

# ── SRP6 常量 ──
N = int("894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7", 16)
G = 7
SALT_LENGTH = 32
VERIFIER_LENGTH = 32
EXPANSION = 2  # WotLK

# ── MySQL 配置 ──
MYSQL_HOST = "127.0.0.1"
MYSQL_PORT = 3306
MYSQL_USER = "acore"
MYSQL_PASS = "acore"
MYSQL_DB   = "acore_auth"


def make_registration_data(username: str, password: str) -> tuple:
    """
    与 Acore::Crypto::SRP6::MakeRegistrationData 相同逻辑
    返回 (salt_hex, verifier_hex)
    """
    username = username.upper()
    password = password.upper()

    salt = os.urandom(SALT_LENGTH)

    # inner = SHA1(username || ":" || password)
    inner = hashlib.sha1(f"{username}:{password}".encode()).digest()

    # x_hash = SHA1(salt || inner)
    x_hash = hashlib.sha1(salt + inner).digest()

    # v = g^x mod N
    # C++: BigNumber(SHA1_digest) 默认 littleEndian, ToByteArray<32>() 默认 littleEndian
    x_int = int.from_bytes(x_hash, 'little')
    v_int = pow(G, x_int, N)
    verifier = v_int.to_bytes(VERIFIER_LENGTH, 'little')

    return salt.hex(), verifier.hex()


def execute_sql(sql: str) -> bool:
    """通过 mysql CLI 执行 SQL"""
    result = subprocess.run(
        [
            "mysql",
            "-h", MYSQL_HOST,
            "-P", str(MYSQL_PORT),
            "-u", MYSQL_USER,
            f"-p{MYSQL_PASS}",
            MYSQL_DB,
            "-e", sql,
        ],
        capture_output=True, text=True
    )
    if result.returncode != 0 and result.stderr:
        print(f"  [错误] {result.stderr.strip()}", file=sys.stderr)
        return False
    return True


def main():
    import argparse

    parser = argparse.ArgumentParser(description="批量创建 bot 账号")
    parser.add_argument("--count", type=int, default=100, help="创建数量 (默认 100)")
    parser.add_argument("--conf", default="../bot_secrets.conf", help="配置文件输出路径")
    parser.add_argument("--prefix", default="bot", help="账号名前缀 (默认 bot)")
    parser.add_argument("--password", default=None, help="指定密码 (默认随机生成)")
    args = parser.parse_args()

    BOT_COUNT = args.count
    BOT_PREFIX = args.prefix
    BOT_PASSWORD = args.password or os.urandom(24).hex()  # 48字符十六进制随机密码
    CONF_PATH = os.path.join(os.path.dirname(__file__), args.conf)

    print(f"开始创建 {BOT_COUNT} 个 bot 账号...")
    print(f"用户名格式: {BOT_PREFIX}001 ~ {BOT_PREFIX}{BOT_COUNT:03d}")
    print(f"密码:      {BOT_PASSWORD}")
    print()

    success_count = 0
    skip_count = 0
    fail_count = 0

    for i in range(1, BOT_COUNT + 1):
        username = f"{BOT_PREFIX}{i:03d}"

        # 检查账号是否已存在
        check = subprocess.run(
            [
                "mysql", "-h", MYSQL_HOST, "-P", str(MYSQL_PORT),
                "-u", MYSQL_USER, f"-p{MYSQL_PASS}", MYSQL_DB,
                "-N", "-e", f"SELECT COUNT(*) FROM account WHERE username='{username.upper()}'"
            ],
            capture_output=True, text=True
        )
        if check.stdout.strip() == "1":
            print(f"  [{i:3d}] {username} 已存在，跳过")
            skip_count += 1
            continue

        salt_hex, verifier_hex = make_registration_data(username, BOT_PASSWORD)

        sql = (
            f"INSERT INTO account(username, salt, verifier, expansion, joindate) "
            f"VALUES(UPPER('{username}'), UNHEX('{salt_hex}'), UNHEX('{verifier_hex}'), {EXPANSION}, NOW())"
        )

        if execute_sql(sql):
            print(f"  [{i:3d}] {username} +")
            success_count += 1
        else:
            fail_count += 1

    # 初始化 realmcharacters（同步所有新建账号）
    print()
    print("同步 realmcharacters...")
    execute_sql(
        "INSERT INTO realmcharacters (realmid, acctid, numchars) "
        "SELECT realmlist.id, account.id, 0 "
        "FROM realmlist, account "
        "LEFT JOIN realmcharacters ON acctid=account.id "
        "WHERE acctid IS NULL"
    )

    print(f"\n-- 结果 --")
    print(f"  成功: {success_count}")
    print(f"  跳过(已存在): {skip_count}")
    print(f"  失败: {fail_count}")

    # 验证
    result = subprocess.run(
        [
            "mysql", "-h", MYSQL_HOST, "-P", str(MYSQL_PORT),
            "-u", MYSQL_USER, f"-p{MYSQL_PASS}", MYSQL_DB,
            "-N", "-e", "SELECT COUNT(*) FROM account"
        ],
        capture_output=True, text=True
    )
    print(f"\n数据库总账号数: {result.stdout.strip()}")

    # 写入配置文件
    os.makedirs(os.path.dirname(CONF_PATH), exist_ok=True)
    conf_content = f"""\
# bot 账号配置文件 (client-simulator 读取, 勿泄露)
# 生成日期: {os.popen('date -Iseconds').read().strip() if os.name != 'nt' else ''}
# 账号数: {BOT_COUNT}

BOT_COUNT = {BOT_COUNT}
BOT_USERNAME_PREFIX = "{BOT_PREFIX}"
BOT_PASSWORD = "{BOT_PASSWORD}"
BOT_START_ID = 1

# 并发启动间隔 (毫秒), 防服务器瞬时冲击
BOT_SPAWN_INTERVAL_MS = 500
"""
    with open(CONF_PATH, "w") as f:
        f.write(conf_content)

    os.chmod(CONF_PATH, 0o600)  # 仅 owner 可读写
    print(f"\n配置文件写入: {os.path.abspath(CONF_PATH)} (权限 600)")


if __name__ == "__main__":
    main()
