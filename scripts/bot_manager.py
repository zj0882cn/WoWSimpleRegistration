#!/usr/bin/env python3
"""
WoW Bot Manager - 后台挂机进程管理器

管理所有挂机角色的生命周期。通过任务队列与 PHP 后端通信。

Usage:
    python3 bot_manager.py start    # 启动管理器（后台运行）
    python3 bot_manager.py stop     # 停止管理器
    python3 bot_manager.py status   # 查看状态
"""

import os
import sys
import json
import time
import signal
import socket
import threading
import subprocess
import argparse
from pathlib import Path

# Configuration
BASE_DIR = Path(__file__).resolve().parent.parent
DATA_DIR = BASE_DIR / 'application' / 'data'
TASKS_FILE = DATA_DIR / 'bot_tasks.json'
STATUS_FILE = DATA_DIR / 'bot_status.json'
PID_FILE = DATA_DIR / 'bot_manager.pid'
LOG_FILE = DATA_DIR / 'bot_manager.log'
SOCKET_FILE = DATA_DIR / 'bot_manager.sock'

WOW_CLIENT_SCRIPT = BASE_DIR / 'scripts' / 'wow_client.py'

# Ensure data directory exists
DATA_DIR.mkdir(parents=True, exist_ok=True)

running = True


def log(msg):
    timestamp = time.strftime('%Y-%m-%d %H:%M:%S')
    with open(LOG_FILE, 'a') as f:
        f.write(f'[{timestamp}] {msg}\n')


def load_json(path, default=None):
    default = default or {}
    if path.exists():
        try:
            with open(path) as f:
                return json.load(f)
        except (json.JSONDecodeError, IOError):
            return default
    return default


def save_json(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, 'w') as f:
        json.dump(data, f, indent=2, ensure_ascii=False)


class BotManager:
    """管理所有挂机 WoW 客户端实例"""

    def __init__(self):
        self.bots = {}  # {bot_id: {process, account, character, status, started_at}}
        self.tasks = []
        self.running = True
        self.load_state()

    def load_state(self):
        """加载之前的状态"""
        status = load_json(STATUS_FILE, {})
        self.tasks = load_json(TASKS_FILE, [])
        log(f"Bot Manager loaded: {len(status.get('bots', {}))} existing bots, {len(self.tasks)} pending tasks")

    def save_state(self):
        """保存当前状态"""
        status_data = {
            'bots': {k: {**v, 'process': None} for k, v in self.bots.items()},
            'updated_at': time.strftime('%Y-%m-%d %H:%M:%S'),
            'bot_count': len(self.bots),
        }
        save_json(STATUS_FILE, status_data)

    def process_tasks(self):
        """处理任务队列"""
        if not self.tasks:
            return

        remaining_tasks = []
        for task in self.tasks:
            action = task.get('action')
            if action == 'start':
                success, msg = self.start_bot(
                    task['account'],
                    task['character'],
                    task.get('password', ''),
                    task.get('host', '127.0.0.1')
                )
                task['result'] = 'success' if success else 'failed'
                task['message'] = msg
                task['processed_at'] = time.strftime('%Y-%m-%d %H:%M:%S')
                log(f"Task processed: {action} {task['account']}/{task['character']} -> {msg}")
            elif action == 'stop':
                self.stop_bot(task['account'], task['character'])
                task['result'] = 'success'
                task['processed_at'] = time.strftime('%Y-%m-%d %H:%M:%S')

        # Clear processed tasks
        save_json(TASKS_FILE, [])
        self.save_state()

    def start_bot(self, account, character, password, host):
        """启动一个新的 WoW 客户端"""
        bot_id = f"{account}_{character}"

        if bot_id in self.bots:
            log(f"Bot {bot_id} already running")
            return True, "角色已在挂机中"

        if not WOW_CLIENT_SCRIPT.exists():
            return False, "WoW client script not found"

        cmd = [
            sys.executable,
            str(WOW_CLIENT_SCRIPT),
            'login',
            '--account', account,
            '--password', password,
            '--character', character,
            '--host', host,
        ]

        try:
            process = subprocess.Popen(
                cmd,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                bufsize=1,
                start_new_session=True  # Detach from parent
            )

            self.bots[bot_id] = {
                'process': process,
                'account': account,
                'character': character,
                'host': host,
                'password': password,
                'status': 'starting',
                'started_at': time.strftime('%Y-%m-%d %H:%M:%S'),
                'pid': process.pid,
            }

            log(f"Starting bot {bot_id} (PID: {process.pid})")
            return True, "挂机中"

        except Exception as e:
            log(f"Failed to start bot {bot_id}: {e}")
            return False, str(e)

    def stop_bot(self, account, character):
        """停止一个挂机实例"""
        bot_id = f"{account}_{character}"

        if bot_id not in self.bots:
            log(f"Bot {bot_id} not running")
            return False, "角色未在挂机中"

        bot = self.bots[bot_id]
        process = bot['process']

        if process and process.poll() is None:
            try:
                process.terminate()
                log(f"Terminated bot {bot_id} (PID: {bot['pid']})")
            except Exception as e:
                log(f"Error terminating bot {bot_id}: {e}")

        del self.bots[bot_id]
        self.save_state()
        return True, "已停止"

    def stop_all(self):
        """停止所有挂机实例"""
        for bot_id, bot in list(self.bots.items()):
            process = bot['process']
            if process and process.poll() is None:
                try:
                    process.terminate()
                except:
                    pass
            log(f"Stopped bot {bot_id}")
        self.bots.clear()
        self.save_state()

    def check_bots(self):
        """检查所有 bot 进程是否存活"""
        dead_bots = []
        for bot_id, bot in self.bots.items():
            process = bot['process']
            if process and process.poll() is not None:
                # Process has exited
                exit_code = process.returncode
                log(f"Bot {bot_id} exited with code {exit_code}")
                dead_bots.append(bot_id)

        for bot_id in dead_bots:
            del self.bots[bot_id]

        if dead_bots:
            self.save_state()

    def get_status(self):
        """获取当前状态"""
        self.check_bots()
        return {
            'bots': {
                k: {
                    'account': v['account'],
                    'character': v['character'],
                    'status': v['status'] if v['process'] and v['process'].poll() is None else 'offline',
                    'started_at': v['started_at'],
                    'pid': v.get('pid'),
                }
                for k, v in self.bots.items()
            },
            'bot_count': len(self.bots),
            'uptime': time.strftime('%Y-%m-%d %H:%M:%S'),
        }

    def run(self):
        """主循环 - 监听任务并管理 bots"""
        global running

        # Write PID file
        with open(PID_FILE, 'w') as f:
            f.write(str(os.getpid()))

        # Start socket server
        server_socket = None
        try:
            # Remove old socket
            if SOCKET_FILE.exists():
                SOCKET_FILE.unlink()

            server_socket = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            server_socket.setblocking(False)
            server_socket.bind(str(SOCKET_FILE))
            server_socket.listen(5)

            log(f"Bot Manager started (PID: {os.getpid()})")
            log(f"Listening on {SOCKET_FILE}")

            while running:
                self.check_bots()
                self.process_tasks()

                # Check for incoming connections
                try:
                    conn, addr = server_socket.accept()
                    data = conn.recv(4096).decode('utf-8')
                    if data:
                        response = self.handle_command(data.strip())
                        conn.sendall(json.dumps(response).encode('utf-8'))
                    conn.close()
                except BlockingIOError:
                    pass

                # Check for new tasks
                tasks = load_json(TASKS_FILE, [])
                if tasks:
                    self.tasks = tasks
                    self.process_tasks()

                time.sleep(1)

        except KeyboardInterrupt:
            log("Bot Manager interrupted")
        finally:
            if server_socket:
                server_socket.close()
            self.stop_all()
            if PID_FILE.exists():
                PID_FILE.unlink()
            if SOCKET_FILE.exists():
                SOCKET_FILE.unlink()
            log("Bot Manager stopped")

    def handle_command(self, cmd_str):
        """处理来自客户端的命令"""
        try:
            cmd = json.loads(cmd_str) if cmd_str.startswith('{') else {'action': cmd_str}
        except json.JSONDecodeError:
            cmd = {'action': cmd_str}

        action = cmd.get('action', cmd_str)

        if action == 'status':
            return self.get_status()
        elif action == 'list':
            return self.get_status()
        elif action == 'stop_all':
            self.stop_all()
            return {'success': True, 'message': 'All bots stopped'}
        elif action == 'ping':
            return {'success': True, 'message': 'pong'}
        else:
            return {'success': False, 'message': f'Unknown action: {action}'}


def signal_handler(signum, frame):
    global running
    log(f"Received signal {signum}, shutting down...")
    running = False


def send_command(cmd_data):
    """向运行中的 bot manager 发送命令"""
    if not SOCKET_FILE.exists():
        return {'success': False, 'message': 'Bot Manager not running'}

    try:
        sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        sock.settimeout(3)
        sock.connect(str(SOCKET_FILE))
        sock.sendall(json.dumps(cmd_data).encode('utf-8'))
        response = sock.recv(4096).decode('utf-8')
        sock.close()
        return json.loads(response) if response else {'success': False, 'message': 'No response'}
    except (ConnectionRefusedError, FileNotFoundError):
        return {'success': False, 'message': 'Bot Manager not running'}
    except json.JSONDecodeError:
        return {'success': False, 'message': 'Invalid response'}


def start_daemon():
    """以守护进程方式启动 bot manager"""
    if PID_FILE.exists():
        with open(PID_FILE) as f:
            old_pid = int(f.read().strip())
        try:
            os.kill(old_pid, 0)
            log(f"Bot Manager already running (PID: {old_pid})")
            print(f"Bot Manager already running (PID: {old_pid})")
            return False
        except (OSError, ProcessLookupError):
            # Not running, clean up old PID
            PID_FILE.unlink()

    # Fork to background
    if os.fork() > 0:
        sys.exit(0)

    # Second fork for true daemon
    os.setsid()
    if os.fork() > 0:
        sys.exit(0)

    # Redirect IO
    sys.stdout.flush()
    sys.stderr.flush()

    manager = BotManager()
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    manager.run()
    return True


def main():
    parser = argparse.ArgumentParser(description='WoW Bot Manager')
    parser.add_argument('action', choices=['start', 'stop', 'status', 'restart', 'daemon'],
                        help='Action to perform')
    args = parser.parse_args()

    if args.action == 'start':
        # Start in foreground
        manager = BotManager()
        signal.signal(signal.SIGTERM, signal_handler)
        signal.signal(signal.SIGINT, signal_handler)
        manager.run()

    elif args.action == 'daemon':
        start_daemon()

    elif args.action == 'stop':
        # Send stop command
        result = send_command({'action': 'stop_all'})
        # Also kill from PID file
        if PID_FILE.exists():
            try:
                with open(PID_FILE) as f:
                    pid = int(f.read().strip())
                os.kill(pid, 15)
                print(f"Sent SIGTERM to PID {pid}")
            except (OSError, ValueError) as e:
                print(f"Error: {e}")
        print(json.dumps(result, indent=2, ensure_ascii=False))

    elif args.action == 'status':
        result = send_command({'action': 'status'})
        if not result.get('success') and result.get('message') == 'Bot Manager not running':
            print("Bot Manager is not running")
            # Show last saved status
            status = load_json(STATUS_FILE, {})
            if status:
                print(f"Last saved status: {json.dumps(status, indent=2, ensure_ascii=False)}")
        else:
            print(json.dumps(result, indent=2, ensure_ascii=False))

    elif args.action == 'restart':
        print("Stopping Bot Manager...")
        send_command({'action': 'stop_all'})
        if PID_FILE.exists():
            try:
                with open(PID_FILE) as f:
                    pid = int(f.read().strip())
                os.kill(pid, 15)
            except:
                pass
        time.sleep(1)
        print("Starting Bot Manager...")
        start_daemon()
        print("Bot Manager restarted")


if __name__ == '__main__':
    main()