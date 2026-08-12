#!/usr/bin/env python3
"""
Stable PHP CGI Wrapper - Spawns isolated PHP CGI processes per request
This avoids the instability of PHP's built-in development server.
"""
import http.server
import subprocess
import os
import sys
import urllib.parse

DOC_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP_BIN = 'php'
PHP_CGI = os.environ.get('PHP_CGI', 'php-cgi')

class PHPCGIHandler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self._handle_request('GET')
    
    def do_POST(self):
        self._handle_request('POST')
    
    def _handle_request(self, method):
        # Parse URL
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path
        
        # Map URL to PHP file
        if path == '/' or path == '':
            php_file = 'index.php'
        else:
            php_file = path.lstrip('/')
        
        # Check if file exists
        full_path = os.path.join(DOC_ROOT, php_file)
        if not os.path.exists(full_path):
            self.send_error(404, 'Not Found')
            return
        
        # Read request body (POST data)
        content_length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(content_length) if content_length > 0 else b''
        
        # Build PHP CGI environment
        env = os.environ.copy()
        env['DOCUMENT_ROOT'] = DOC_ROOT
        env['SCRIPT_FILENAME'] = full_path
        env['SCRIPT_NAME'] = '/' + php_file
        env['REQUEST_METHOD'] = method
        env['REQUEST_URI'] = self.path
        env['QUERY_STRING'] = parsed.query
        env['SERVER_PROTOCOL'] = 'HTTP/1.1'
        env['HTTP_HOST'] = self.headers.get('Host', 'localhost')
        env['HTTP_USER_AGENT'] = self.headers.get('User-Agent', 'TestAgent')
        env['HTTP_COOKIE'] = self.headers.get('Cookie', '')
        env['REMOTE_ADDR'] = '127.0.0.1'
        
        if method == 'POST':
            env['CONTENT_TYPE'] = self.headers.get('Content-Type', 'application/x-www-form-urlencoded')
            env['CONTENT_LENGTH'] = str(content_length)
        
        # Convert headers
        for key, value in self.headers.items():
            http_key = 'HTTP_' + key.upper().replace('-', '_')
            env[http_key] = value
        
        # Run PHP CGI with web SAPI simulation
        try:
            # Build a wrapper script that sets up the environment and includes the target file
            wrapper = f'''<?php
$_SERVER = {repr(env)};
$_SERVER['REQUEST_METHOD'] = '{method}';
$_SERVER['REQUEST_URI'] = {repr(self.path)};
$_SERVER['SCRIPT_NAME'] = {repr('/' + php_file)};
$_SERVER['SCRIPT_FILENAME'] = {repr(full_path)};
$_SERVER['DOCUMENT_ROOT'] = {repr(DOC_ROOT)};
$_SERVER['HTTP_HOST'] = {self.headers.get('Host', 'localhost')};
$_SERVER['HTTP_USER_AGENT'] = {self.headers.get('User-Agent', 'TestAgent')};
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {{
    $_POST = array();
    $raw = file_get_contents('php://input');
    parse_str($raw, $_POST);
}}
session_start();
include '{full_path}';
'''
            
            php_proc = subprocess.Popen(
                [PHP_BIN, '-d', 'variables_order=EGPCS', '-r', wrapper],
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                cwd=DOC_ROOT
            )
            try:
                stdout, stderr = php_proc.communicate(input=body, timeout=30)
            except subprocess.TimeoutExpired:
                php_proc.kill()
                stdout, stderr = php_proc.communicate()
                self.send_error(504, 'Gateway Timeout')
                return
            
            if php_proc.returncode != 0:
                error_msg = stderr.decode('utf-8', errors='replace')
                print(f"PHP Error: {error_msg}", file=sys.stderr)
                self.send_error(500, f'PHP Error: {error_msg[:200]}')
                return
            
            # Parse HTTP response from PHP output
            output = stdout.decode('utf-8', errors='replace')
            self._send_php_response(output)
            
        except subprocess.TimeoutExpired:
            self.send_error(504, 'Gateway Timeout')
        except Exception as e:
            self.send_error(500, str(e))
    
    def _send_php_response(self, output):
        """Parse PHP CGI output (headers + body) and send HTTP response."""
        # Split headers and body
        if '\r\n\r\n' in output:
            headers_part, body = output.split('\r\n\r\n', 1)
        elif '\n\n' in output:
            headers_part, body = output.split('\n\n', 1)
        else:
            headers_part = ''
            body = output
        
        # Parse headers
        status_code = 200
        content_type = 'text/html; charset=utf-8'
        extra_headers = {}
        
        for line in headers_part.split('\n'):
            line = line.strip()
            if line.startswith('Status:'):
                try:
                    status_code = int(line.split(':', 1)[1].strip().split(' ')[0])
                except:
                    pass
            elif line.startswith('Content-Type:'):
                content_type = line.split(':', 1)[1].strip()
            elif line.startswith('Location:'):
                extra_headers['Location'] = line.split(':', 1)[1].strip()
            elif ':' in line:
                key, value = line.split(':', 1)
                if key.lower() not in ('status', 'content-type', 'location', 'transfer-encoding', 'set-cookie'):
                    extra_headers[key] = value.strip()
        
        # Handle redirect
        if status_code in (301, 302) and 'Location' in extra_headers:
            self.send_response(status_code)
            self.send_header('Location', extra_headers['Location'])
            self.send_header('Content-Length', '0')
            self.end_headers()
            return
        
        # Send response
        encoded_body = body.encode('utf-8')
        self.send_response(status_code)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(encoded_body)))
        for key, value in extra_headers.items():
            self.send_header(key, value)
        self.end_headers()
        self.wfile.write(encoded_body)
    
    def log_message(self, format, *args):
        pass  # Suppress logging

if __name__ == '__main__':
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8080
    server = http.server.HTTPServer(('0.0.0.0', port), PHPCGIHandler)
    print(f'Stable PHP Server running on port {port}...')
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    server.server_close()
