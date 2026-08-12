#!/usr/bin/env python3
"""
Mock SOAP Server - Python implementation for stability
Simulates AzerothCore SOAP endpoint for testing
"""
import http.server
import json
import re
import os
import html

DATA_FILE = os.path.join(os.path.dirname(__file__), 'mock_accounts.json')

def load_accounts():
    if os.path.exists(DATA_FILE):
        try:
            with open(DATA_FILE) as f:
                return json.load(f)
        except:
            pass
    return {}

def save_accounts(accounts):
    with open(DATA_FILE, 'w') as f:
        json.dump(accounts, f, indent=2)

def process_command(command):
    accounts = load_accounts()
    result = ''
    
    if not command:
        result = 'Empty command.'
    elif re.match(r'^account\s+create\s+(\S+)\s+(\S+)$', command, re.IGNORECASE):
        m = re.match(r'^account\s+create\s+(\S+)\s+(\S+)$', command, re.IGNORECASE)
        user = m.group(1).upper()
        if user in accounts:
            result = f'Account with name {m.group(1)} already exist!'
        else:
            accounts[user] = {
                'username': user,
                'password': m.group(2),
                'expansion': '0',
                'created': __import__('datetime').datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            }
            save_accounts(accounts)
            result = f'Account created: {m.group(1)}'
    elif re.match(r'^account\s+set\s+addon\s+(\S+)\s+(\d+)$', command, re.IGNORECASE):
        m = re.match(r'^account\s+set\s+addon\s+(\S+)\s+(\d+)$', command, re.IGNORECASE)
        user = m.group(1).upper()
        if user not in accounts:
            result = f'Account not exist: {m.group(1)}'
        else:
            accounts[user]['expansion'] = m.group(2)
            save_accounts(accounts)
            result = f'Expansion set to {m.group(2)} for account {m.group(1)}.'
    elif re.match(r'^account\s+set\s+password\s+(\S+)\s+(\S+)\s+(\S+)$', command, re.IGNORECASE):
        m = re.match(r'^account\s+set\s+password\s+(\S+)\s+(\S+)\s+(\S+)$', command, re.IGNORECASE)
        user = m.group(1).upper()
        if user not in accounts:
            result = f'Account not exist: {m.group(1)}'
        elif m.group(2) != m.group(3):
            result = 'Passwords do not match.'
        else:
            accounts[user]['password'] = m.group(2)
            save_accounts(accounts)
            result = f'The password for account {m.group(1)} was changed to {m.group(2)}.'
    elif re.match(r'^account\s+delete\s+(\S+)$', command, re.IGNORECASE):
        m = re.match(r'^account\s+delete\s+(\S+)$', command, re.IGNORECASE)
        user = m.group(1).upper()
        if user not in accounts:
            result = f'Account not exist: {m.group(1)}'
        else:
            del accounts[user]
            save_accounts(accounts)
            result = f'Account {m.group(1)} deleted.'
    elif re.match(r'^account\s+list$', command, re.IGNORECASE):
        if not accounts:
            result = 'No accounts exist.'
        else:
            lines = [f'Accounts ({len(accounts)}):']
            for u, a in accounts.items():
                lines.append(f"  - {u} | exp={a['expansion']} | pw={a['password']} | created={a['created']}")
            result = '\n'.join(lines)
    elif re.match(r'^account\s+exists\s+(\S+)$', command, re.IGNORECASE):
        m = re.match(r'^account\s+exists\s+(\S+)$', command, re.IGNORECASE)
        user = m.group(1).upper()
        if user in accounts:
            result = f'Account exists: {m.group(1)}'
        else:
            result = f'Account not exist: {m.group(1)}'
    elif re.match(r'^server\s+info$', command, re.IGNORECASE):
        result = f'Mock AzerothCore Server | Accounts: {len(accounts)} | Uptime: test mode'
    elif re.match(r'^help$', command, re.IGNORECASE):
        result = 'Available commands:\n  account create {user} {pass}\n  account set addon {user} {exp}\n  account set password {user} {pass} {pass}\n  account delete {user}\n  account list\n  account exists {user}\n  server info\n  help'
    else:
        result = f'Unknown command: {command}'
    
    return result

class SOAPHandler(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        content_length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(content_length).decode('utf-8') if content_length > 0 else ''
        
        # Extract command from SOAP envelope
        command = ''
        m = re.search(r'<command[^>]*>(.*?)</command>', body, re.IGNORECASE | re.DOTALL)
        if m:
            command = html.unescape(m.group(1).strip())
        else:
            m = re.search(r'<ns1:command[^>]*>(.*?)</ns1:command>', body, re.IGNORECASE | re.DOTALL)
            if m:
                command = html.unescape(m.group(1).strip())
        
        # Process command
        result_message = process_command(command)
        
        # Build SOAP response
        response = f'''<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
 xmlns:ns1="urn:MaNGOS"
 xmlns:xsd="http://www.w3.org/2001/XMLSchema"
 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
 xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/"
 SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
<SOAP-ENV:Body>
<ns1:executeCommandResponse>
<result xsi:type="xsd:string">{html.escape(result_message)}</result>
</ns1:executeCommandResponse>
</SOAP-ENV:Body>
</SOAP-ENV:Envelope>'''
        
        self.send_response(200)
        self.send_header('Content-Type', 'text/xml; charset=utf-8')
        self.send_header('Content-Length', str(len(response)))
        self.end_headers()
        self.wfile.write(response.encode('utf-8'))
    
    def log_message(self, format, *args):
        pass  # Suppress logging

if __name__ == '__main__':
    port = int(__import__('sys').argv[1]) if len(__import__('sys').argv) > 1 else 7878
    server = http.server.HTTPServer(('0.0.0.0', port), SOAPHandler)
    print(f'Mock SOAP Server running on port {port}...')
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    server.server_close()
