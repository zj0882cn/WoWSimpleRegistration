#!/usr/bin/env python3
"""
SOAP Bridge Server - Acts as a proxy between PHP and the remote AzerothCore SOAP server.

PHP's network functions are sandboxed, but Python can reach external hosts through the
HTTP proxy. This server listens on a local port and forwards SOAP commands to the
remote worldserver.

Usage:
    python3 soap_bridge.py [host] [port]

    Default: listens on 127.0.0.1:7999

PHP usage:
    POST http://127.0.0.1:7999/
    Body: JSON {"command": "account create USER PASS"}
    Returns: JSON {"success": true, "message": "..."}
"""

import http.server
import urllib.request
import urllib.error
import json
import sys
import os
import base64
import re
import html as html_module

# Remove proxy for local operation (we handle SOAP forwarding ourselves)
for var in ['NO_PROXY', 'no_proxy']:
    os.environ[var] = '127.0.0.1,localhost'

# Configure the real SOAP server
SOAP_HOST = os.environ.get('SOAP_HOST', '119.3.216.43')
SOAP_PORT = int(os.environ.get('SOAP_PORT', '7878'))
SOAP_URI = os.environ.get('SOAP_URI', 'urn:AC')
SOAP_USER = os.environ.get('SOAP_USER', 'admin')
SOAP_PASS = os.environ.get('SOAP_PASS', 'abcd12')

BRIDGE_HOST = '127.0.0.1'
BRIDGE_PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 7999


def send_soap_command(command):
    """Send a SOAP command to the real AzerothCore server."""
    safe_command = html_module.escape(command, quote=True)
    soap_envelope = f'''<?xml version="1.0" encoding="UTF-8"?>
<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="{SOAP_URI}">
<SOAP-ENV:Body>
<ns1:executeCommand>
<command>{safe_command}</command>
</ns1:executeCommand>
</SOAP-ENV:Body>
</SOAP-ENV:Envelope>'''

    auth = base64.b64encode(f"{SOAP_USER}:{SOAP_PASS}".encode()).decode()
    url = f"http://{SOAP_HOST}:{SOAP_PORT}/"

    req = urllib.request.Request(url, data=soap_envelope.encode(), method='POST')
    req.add_header('Content-Type', 'text/xml')
    req.add_header('Authorization', f'Basic {auth}')

    try:
        resp = urllib.request.urlopen(req, timeout=15)
        body = resp.read().decode('utf-8')
        return parse_soap_response(body)
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8')
        return {'success': False, 'message': f'HTTP {e.code}: {body[:200]}'}
    except urllib.error.URLError as e:
        return {'success': False, 'message': f'Connection error: {e.reason}'}
    except Exception as e:
        return {'success': False, 'message': f'Error: {str(e)}'}


def parse_soap_response(xml_body):
    """Parse SOAP response XML and extract the result."""
    # Check for SOAP faults
    if 'SOAP-ENV:Fault' in xml_body:
        match = re.search(r'<faultstring>(.*?)</faultstring>', xml_body, re.DOTALL)
        if match:
            return {'success': False, 'message': match.group(1).strip()}
        return {'success': False, 'message': 'SOAP Fault received'}

    # Extract result
    match = re.search(r'<result>(.*?)</result>', xml_body, re.DOTALL)
    if match:
        return {'success': True, 'message': match.group(1).strip()}

    return {'success': True, 'message': xml_body.strip()}


class SOAPBridgeHandler(http.server.BaseHTTPRequestHandler):
    """HTTP handler that accepts commands and forwards them as SOAP."""

    def do_GET(self):
        """Handle GET requests (command via query params)."""
        command = self.path.lstrip('/')
        if not command:
            self.send_json({'status': 'ok', 'service': 'SOAP Bridge', 'target': f'{SOAP_HOST}:{SOAP_PORT}'})
            return

        result = send_soap_command(command)
        self.send_json(result)

    def do_POST(self):
        """Handle POST requests (command in JSON body)."""
        content_length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(content_length).decode('utf-8') if content_length > 0 else '{}'

        try:
            data = json.loads(body)
            command = data.get('command', '')
        except json.JSONDecodeError:
            command = body.strip()

        if not command:
            self.send_json({'success': False, 'message': 'No command provided'})
            return

        result = send_soap_command(command)
        self.send_json(result)

    def send_json(self, data):
        """Send JSON response."""
        response = json.dumps(data, ensure_ascii=False)
        self.send_response(200)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(response.encode())))
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(response.encode())

    def log_message(self, format, *args):
        """Override to reduce log noise."""
        pass


if __name__ == '__main__':
    server = http.server.HTTPServer((BRIDGE_HOST, BRIDGE_PORT), SOAPBridgeHandler)
    print(f"SOAP Bridge running on http://{BRIDGE_HOST}:{BRIDGE_PORT}")
    print(f"Target: {SOAP_HOST}:{SOAP_PORT} (URI: {SOAP_URI})")
    print(f"Press Ctrl+C to stop")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nBridge stopped.")
        server.server_close()
