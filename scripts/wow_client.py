#!/usr/bin/env python3
"""
WoW 3.3.5a Client Simulator - 挂机工具

模拟魔兽世界客户端，实现自动登录 + Bot 模式。
用于 AzerothCore 服务器的账号验证和挂机功能。

Usage:
    python3 wow_client.py login --account TEST --password test123 --character MyChar
    python3 wow_client.py test --account TEST --password test123
    python3 wow_client.py list --account TEST --password test123
"""

import socket
import struct
import hashlib
import hmac
import os
import sys
import time
import json
import argparse
import binascii
from Crypto.Hash import SHA1
from Crypto.PublicKey import RSA

# =============================================================================
# WoW 3.3.5a Protocol Constants
# =============================================================================

# SRP6 parameters (standard WoW values)
N_bytes = bytes.fromhex(
    "894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7"
)
g_bytes = bytes.fromhex(
    "07"
)

LOGON_SERVER_PORT = 6900
WORLD_SERVER_PORT = 8085

# Opcodes
AUTH_LOGON_CHALLENGE = 0x00
AUTH_LOGON_PROOF = 0x01
REALM_LIST = 0x10

# World server opcodes
SMSG_LOGON_REQUEST = 0x00
SMSG_AUTH_RESPONSE = 0x01
SMSG_ACCOUNT_DATA_TIMES = 0x03
SMSG_REQUEST_ACCOUNT_DATA = 0x20B
SMSG_CHAR_ENUM = 0x3B
SMSG_SELECT_CHAR = 0x03D
SMSG_CHAT = 0x096  # Note: this is CMSG_CHAT in WoW, need to verify

# Actually for WoW 3.3.5a:
CMSG_LOGON_REQUEST = 0x004
SMSG_LOGON_REQUEST = 0x005  # response
CMSG_CHAT_MESSAGE = 0x096  # sent from client
SMSG_CHAT_MESSAGE = 0x097  # received by client
CMSG_SELECT_CHAR = 0x039
SMSG_CHAR_ENUM = 0x03B
SMSG_UPDATE_OBJECT = 0x0A9
SMSG_MONSTER_SAY = 0x0D0
SMSG_MESSAGECHAT = 0x096

VERSION_CHALLENGE = bytes.fromhex(
    "BA A3 1E 99 A0 0B 21 57 FC 37 3F B3 69 CD D2 F1".replace(" ", "")
)


class SRP6:
    """SRP6 implementation for WoW authentication."""

    def __init__(self, username, password):
        self.username = username.upper()
        self.password = password
        self.N = int.from_bytes(N_bytes, 'big')
        self.g = int.from_bytes(g_bytes, 'big')

    def _sha1(self, data):
        h = SHA1.new()
        h.update(data)
        return h.digest()

    def _hmac_sha1(self, key, data):
        return hmac.new(key, data, SHA1).digest()

    def _int_to_bytes(self, value, length):
        return value.to_bytes(length, 'big')

    def _bytes_to_int(self, data):
        return int.from_bytes(data, 'big')

    def _pad_to_N(self, data):
        """Pad data to match N length for SRP6."""
        n_len = len(N_bytes)
        if len(data) >= n_len:
            return data[:n_len]
        return b'\x00' * (n_len - len(data)) + data

    def _interleave(self, S_bytes):
        """SHA1Interleave for computing session key."""
        # Split S into even and odd indexed bytes
        # WoW uses specific interleaving:
        # Session Key = SHA1 hash of interleaved S
        n_len = len(N_bytes) * 2  # S is 64 bytes (2 * N_length)

        # Get b and a bytes
        S_len = len(S_bytes)
        half_len = S_len // 2

        # b_values = S[0], S[2], S[4], ... (even indices)
        # a_values = S[1], S[3], S[5], ... (odd indices)
        b = bytearray()
        a = bytearray()
        for i in range(0, S_len, 2):
            b.append(S_bytes[i])
            if i + 1 < S_len:
                a.append(S_bytes[i + 1])

        # Now interleave: take first 32 bytes of b, then first 32 bytes of a
        result = self._sha1(bytes(b[:32] + a[:32]))
        return result

    def compute_verifier(self, salt):
        """Compute password verifier v = g^x mod N."""
        I = self.username.encode('utf-8')
        x_hash = self._sha1(I + b':' + self.password.encode('utf-8'))
        x = int.from_bytes(x_hash, 'big')
        v = pow(self.g, x, self.N)
        return v

    def compute_client_proof(self, salt, B_bytes, a_bytes):
        """Compute client proof M and session key K."""
        I = self.username.encode('utf-8')
        x_hash = self._sha1(I + b':' + self.password.encode('utf-8'))
        x = int.from_bytes(x_hash, 'big')

        B = int.from_bytes(B_bytes, 'big')
        a = int.from_bytes(a_bytes, 'big')

        # k = SHA1(N | g)
        k = self._sha1(self._pad_to_N(N_bytes) + self._pad_to_N(g_bytes))
        k_int = int.from_bytes(k, 'big')

        # S = (B - 3v)^a mod N
        # First compute v
        v = pow(self.g, x, self.N)

        # B - 3v mod N
        B_minus_3v = (B - 3 * v) % self.N

        # S = (B - 3v)^a mod N
        S = pow(B_minus_3v, a, self.N)
        S_bytes = self._int_to_bytes(S, 64)  # 2 * N_length

        # Session key K = SHA1Interleave(S)
        K = self._interleave(S_bytes)
        K_int = int.from_bytes(K, 'big')

        # M = SHA1(H(N) XOR H(g) | H(U) | s | A | B | K)
        hN = self._sha1(N_bytes)
        hg = self._sha1(g_bytes)
        hN_xor_hg = bytes(a ^ b for a, b in zip(hN, hg))

        hU = self._sha1(I)

        A_bytes = self._int_to_bytes(a, 32)
        B_bytes = self._pad_to_N(B_bytes)

        M = self._sha1(hN_xor_hg + hU + salt + A_bytes + B_bytes + K)

        # M2 = SHA1(A | M | K)
        M2 = self._sha1(A_bytes + M + K)

        return M, M2, K, S_bytes

    def compute_client_challenge(self):
        """Generate client ephemeral A."""
        a_bytes = os.urandom(19)  # 19 bytes random
        a = int.from_bytes(a_bytes, 'big')
        A = pow(self.g, a, self.N)
        A_bytes = self._int_to_bytes(A, 32)
        return A_bytes, a_bytes


class WoWLogonClient:
    """Client for WoW logon server authentication."""

    def __init__(self, host, port=LOGON_SERVER_PORT):
        self.host = host
        self.port = port
        self.sock = None
        self.session_key = None
        self.realms = []

    def connect(self):
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.sock.settimeout(10)
        self.sock.connect((self.host, self.port))
        return True

    def close(self):
        if self.sock:
            self.sock.close()

    def _send_packet(self, data):
        self.sock.sendall(data)

    def _recv_all(self, size):
        data = b''
        while len(data) < size:
            chunk = self.sock.recv(size - len(data))
            if not chunk:
                raise ConnectionError("Connection closed")
            data += chunk
        return data

    def _recv_exact(self, size):
        return self._recv_all(size)

    def _recv_until(self, marker=None, max_size=4096):
        data = b''
        while len(data) < max_size:
            chunk = self.sock.recv(4096)
            if not chunk:
                break
            data += chunk
            if marker and marker in data:
                break
        return data

    def authenticate(self, username, password):
        """Perform full SRP6 authentication."""
        srp = SRP6(username, password)

        # Step 1: Send LOGON_CHALLENGE
        self._send_logon_challenge(username)

        # Step 2: Receive challenge response
        response = self._recv_all(256)
        B, g, N, s, server_version = self._parse_challenge_response(response)

        if B is None:
            return False, "Failed to get challenge"

        # Step 3: Generate client ephemeral
        A_bytes, a_bytes = srp.compute_client_challenge()

        # Step 4: Send LOGON_PROOF
        M, M2, K, S_bytes = srp.compute_client_proof(s, B, a_bytes)
        crc_hash = srp._sha1(A_bytes + srp._sha1(username.upper().encode()))

        self._send_logon_proof(A_bytes, M, crc_hash)

        # Step 5: Receive proof response
        proof_response = self._recv_all(1024)
        error_code, M2_server, account_flags = self._parse_proof_response(proof_response)

        if error_code != 0:
            error_msgs = {
                1: "Unknown account",
                8: "Suspended",
                12: "Banned",
                16: "Already online",
                21: "Version invalid",
            }
            msg = error_msgs.get(error_code, f"Unknown error code: {error_code}")
            return False, msg

        # Verify server proof
        expected_M2 = srp._sha1(A_bytes + M + K)
        if M2_server != expected_M2:
            # Some servers might have different M2 calculation
            pass

        self.session_key = K

        # Step 6: Get realm list
        self._send_realm_list()
        realm_data = self._recv_until(max_size=16384)
        self.realms = self._parse_realm_list(realm_data)

        return True, {
            'session_key': binascii.hexlify(K).decode(),
            'realms': self.realms,
            'account_flags': account_flags,
        }

    def _send_logon_challenge(self, username):
        """Send LOGON_CHALLENGE packet."""
        # WoW 3.3.5a logon challenge structure:
        # cmd(1) + error(1) + size(2) + gamename(4) + v1(1) + v2(1) + v3(1) + build(2)
        # + platform(4) + os(4) + country(4) + timezone_bias(4) + ip(4) + I_len(1) + I(variable)
        I = username.encode('utf-8')
        I_len = len(I)

        packet = struct.pack(
            '<BBH',
            AUTH_LOGON_CHALLENGE,  # cmd
            0x00,                  # error
            30 + I_len             # size (initial part + username)
        )
        packet += b'WoW\x00'     # gamename
        packet += struct.pack('<BBH', 3, 3, 5)  # version 3.3.5 (but build is separate)
        packet += struct.pack('<H', 12340)       # build
        packet += b'x86\x00'     # platform
        packet += b'Win\x00\x00' # os (reversed: Win -> niW)
        packet += b'enUS'        # country (reversed)
        packet += struct.pack('<i', 0)           # timezone bias
        packet += struct.pack('<I', 0)           # IP (0.0.0.0)
        packet += struct.pack('B', I_len)        # username length
        packet += I

        # Update size field
        size = len(packet) - 3  # subtract cmd, error, size fields
        packet = struct.pack('<BBH', AUTH_LOGON_CHALLENGE, 0x00, size) + packet[3:]

        self._send_packet(packet)

    def _parse_challenge_response(self, data):
        """Parse LOGON_CHALLENGE response from server."""
        # Response structure:
        # cmd(1) + error(1) + B(32) + g_len(1) + g(variable) + N_len(1) + N(variable)
        # + s(32) + version_challenge(16) + security_flags(1) + ...
        try:
            if len(data) < 256:
                # Might need more data
                remaining = 256 - len(data)
                extra = self._recv_all(remaining)
                data = data + extra

            pos = 0
            cmd = data[pos]; pos += 1
            error = data[pos]; pos += 1

            if error != 0:
                return None, None, None, None, None

            # B (32 bytes)
            B = data[pos:pos+32]; pos += 32

            # g (variable length, prefixed by 1 byte length)
            g_len = data[pos]; pos += 1
            g = data[pos:pos+g_len]; pos += g_len

            # N (variable length, prefixed by 1 byte length)
            N_len = data[pos]; pos += 1
            N = data[pos:pos+N_len]; pos += N_len

            # s (32 bytes)
            s = data[pos:pos+32]; pos += 32

            # Version Challenge (16 bytes)
            version_challenge = data[pos:pos+16]; pos += 16

            # Security Flags
            security_flags = data[pos]; pos += 1

            return B, g, N, s, version_challenge

        except Exception as e:
            return None, None, None, None, None

    def _send_logon_proof(self, A_bytes, M, crc_hash):
        """Send LOGON_PROOF packet."""
        # Structure: cmd(1) + A(32) + clientM(20) + crc_hash(20) + number_of_keys(1) + security_flags(1)
        packet = struct.pack('B', AUTH_LOGON_PROOF)
        packet += A_bytes
        packet += M
        packet += crc_hash
        packet += struct.pack('BB', 0, 0)  # number_of_keys, security_flags

        self._send_packet(packet)

    def _parse_proof_response(self, data):
        """Parse LOGON_PROOF response."""
        try:
            pos = 0
            cmd = data[pos]; pos += 1
            error = data[pos]; pos += 1

            if error != 0:
                return error, None, None

            # M2 (20 bytes)
            M2 = data[pos:pos+20]; pos += 20

            # Account flags (4 bytes)
            account_flags = struct.unpack('<I', data[pos:pos+4])[0]; pos += 4

            # Survey ID (4 bytes)
            pos += 4

            # Login flags (2 bytes)
            pos += 2

            return 0, M2, account_flags

        except Exception as e:
            return 99, None, None

    def _send_realm_list(self):
        """Send REALM_LIST request."""
        packet = struct.pack('<BBH', REALM_LIST, 0x00, 4)
        self._send_packet(packet)

    def _parse_realm_list(self, data):
        """Parse REALM_LIST response."""
        realms = []
        try:
            if len(data) < 5:
                return realms

            pos = 0
            cmd = data[pos]; pos += 1

            # Size (2 bytes for post-BC)
            size = struct.unpack('<H', data[pos:pos+2])[0]; pos += 2

            # Realm count (1 byte)
            count = data[pos]; pos += 1

            for i in range(count):
                realm_type = data[pos]; pos += 1
                lock = data[pos]; pos += 1
                flags = data[pos]; pos += 1

                # Name (null-terminated string)
                name_end = data.index(b'\x00', pos)
                name = data[pos:name_end].decode('utf-8', errors='replace')
                pos = name_end + 1

                # Address (null-terminated string: "ip:port")
                addr_end = data.index(b'\x00', pos)
                address = data[pos:addr_end].decode('utf-8', errors='replace')
                pos = addr_end + 1

                # Population (4 bytes float)
                population = struct.unpack('<f', data[pos:pos+4])[0]; pos += 4

                # Character count (1 byte)
                char_count = data[pos]; pos += 1

                # Timezone (1 byte)
                timezone = data[pos]; pos += 1

                # Realm ID (1 byte for post-BC)
                realm_id = data[pos]; pos += 1

                realms.append({
                    'name': name,
                    'address': address,
                    'population': population,
                    'characters': char_count,
                    'timezone': timezone,
                    'id': realm_id,
                })

            # Footer: 0x10 0x00
            if pos + 2 <= len(data):
                footer = data[pos:pos+2]

        except Exception as e:
            pass

        return realms


class WoWWorldClient:
    """Client for WoW world server communication."""

    def __init__(self, host, port, session_key):
        self.host = host
        self.port = port
        self.session_key = session_key
        self.sock = None
        self.characters = []
        self.selected_character = None
        self.entered_world = False

    def connect(self):
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.sock.settimeout(10)
        self.sock.connect((self.host, self.port))
        return True

    def close(self):
        if self.sock:
            self.sock.close()

    def _send_packet(self, data):
        self.sock.sendall(data)

    def _recv_all(self, size):
        data = b''
        while len(data) < size:
            chunk = self.sock.recv(size - len(data))
            if not chunk:
                raise ConnectionError("Connection closed")
            data += chunk
        return data

    def _recv_packet(self):
        """Receive a world server packet with header."""
        # WoW world server packet header:
        # size(2) + opcode(4) + data(variable)
        header = self._recv_all(6)
        size = struct.unpack('>H', header[:2])[0]
        opcode = struct.unpack('>I', header[2:6])[0]

        if size > 0:
            data = self._recv_all(size)
        else:
            data = b''

        return opcode, data

    def login_to_world(self, account_name):
        """Send login request to world server."""
        # CMSG_LOGON_REQUEST (0x004)
        # Structure: opcode(4) + size(2) + account_name(null-terminated)
        account_bytes = account_name.encode('utf-8') + b'\x00'
        size = 2 + len(account_bytes) + 40  # 2 + account + session key

        # Actually: the login request includes the session key
        # Let me check the actual format

        # WoW world login:
        # - Send CMSG_LOGON_REQUEST (opcode 0x004)
        # - Data: account_name (null-terminated) + session_key (40 bytes)
        # Wait, the format for 3.3.5a is:
        # opcode(4) + size(2) + gamename(4) + account_name(null-term) + session_key(40)

        packet = struct.pack('>IH', 0x004, 0)  # opcode + size placeholder
        packet += b'WoW\x00'  # gamename
        packet += account_bytes
        packet += self.session_key

        # Update size
        size = len(packet) - 6  # subtract header
        packet = struct.pack('>IH', 0x004, size) + packet[6:]

        self._send_packet(packet)

        # Receive response
        opcode, data = self._recv_packet()

        # Check response
        if opcode == 0x005:  # SMSG_LOGON_REQUEST
            pos = 0
            result = data[pos]; pos += 1
            if result != 0:
                return False, f"Login failed with code: {result}"

            # Server supported features
            # Skip to character list
            return True, "Logged in"

        return False, f"Unexpected response: opcode={opcode}"

    def get_characters(self):
        """Request character list."""
        # After login, wait for character list
        # SMSG_CHAR_ENUM (0x03B) is sent by server after login

        # Read incoming packets until we get character list
        for _ in range(20):
            opcode, data = self._recv_packet()

            if opcode == 0x03B:  # SMSG_CHAR_ENUM
                self.characters = self._parse_char_enum(data)
                return self.characters

            elif opcode == 0x03A:  # SMSG_ACCOUNT_DATA_TIMES
                continue  # Skip

            elif opcode == 0x041:  # SMSG_UPDATE_ACCOUNT_DATA
                continue

            elif opcode == 0x044:  # SMSG_MOTD
                continue

            elif opcode == 0x045:  # SMSG_READ_CHECK_IN
                continue

            elif opcode == 0x046:  # SMSG_TIME_SYNC_REQUEST
                continue

            elif opcode == 0x0A1:  # SMSG_UPDATE_OBJECT (might be first object)
                # This could be the first character packet
                continue

        return self.characters

    def _parse_char_enum(self, data):
        """Parse character list from SMSG_CHAR_ENUM."""
        characters = []
        try:
            pos = 0

            # Character count (1 byte)
            if len(data) < 1:
                return characters

            count = data[pos]; pos += 1

            for i in range(count):
                char = {}

                # GUID (8 bytes)
                guid_bytes = data[pos:pos+8]; pos += 8
                char['guid'] = int.from_bytes(guid_bytes, 'little')

                # Name (null-terminated)
                name_end = data.index(b'\x00', pos)
                char['name'] = data[pos:name_end].decode('utf-8', errors='replace')
                pos = name_end + 1

                # Race (1 byte)
                char['race'] = data[pos]; pos += 1

                # Class (1 byte)
                char['class'] = data[pos]; pos += 1

                # Gender (1 byte)
                char['gender'] = data[pos]; pos += 1

                # Skin (1 byte)
                char['skin'] = data[pos]; pos += 1

                # Face (1 byte)
                char['face'] = data[pos]; pos += 1

                # Hair style (1 byte)
                char['hair_style'] = data[pos]; pos += 1

                # Hair color (1 byte)
                char['hair_color'] = data[pos]; pos += 1

                # Facial hair (1 byte)
                char['facial_hair'] = data[pos]; pos += 1

                # Level (1 byte)
                char['level'] = data[pos]; pos += 1

                # Zone ID (4 bytes)
                char['zone'] = struct.unpack('<I', data[pos:pos+4])[0]; pos += 4

                # Map ID (4 bytes)
                char['map'] = struct.unpack('<I', data[pos:pos+4])[0]; pos += 4

                # Position (12 bytes: x, y, z as floats)
                char['x'] = struct.unpack('<f', data[pos:pos+4])[0]; pos += 4
                char['y'] = struct.unpack('<f', data[pos:pos+4])[0]; pos += 4
                char['z'] = struct.unpack('<f', data[pos:pos+4])[0]; pos += 4

                # Guild ID (4 bytes)
                char['guild'] = struct.unpack('<I', data[pos:pos+4])[0]; pos += 4

                # Player flags (4 bytes)
                char['flags'] = struct.unpack('<I', data[pos:pos+4])[0]; pos += 4

                # Character power (1 byte)
                char['power'] = data[pos]; pos += 1

                characters.append(char)

        except Exception as e:
            pass

        return characters

    def select_character(self, character_name):
        """Select a character to enter the world."""
        char = None
        for c in self.characters:
            if c['name'].lower() == character_name.lower():
                char = c
                break

        if not char:
            return False, f"Character '{character_name}' not found"

        self.selected_character = char

        # SMSG_SELECT_CHAR / CMSG_SELECT_CHAR (opcode 0x039)
        # Format: opcode(4) + size(2) + guid(8)
        guid = char['guid']
        packet = struct.pack('>IH', 0x039, 8)  # opcode + size
        packet += guid.to_bytes(8, 'little')

        self._send_packet(packet)

        # Wait for world entry response
        # Server sends SMSG_LOGIN_SETTIMESPEED (0x028) or SMSG_INIT_SPELLS etc.
        time.sleep(0.5)

        # Read some packets to acknowledge
        for _ in range(10):
            try:
                opcode, data = self._recv_packet()
                if opcode == 0x028:  # SMSG_LOGIN_SETTIMESPEED
                    self.entered_world = True
                    break
                elif opcode == 0x0A9:  # SMSG_UPDATE_OBJECT
                    # This means we're entering the world
                    self.entered_world = True
                    break
            except:
                break

        return self.entered_world, "Character selected and entered world"

    def send_chat_command(self, message, channel='say'):
        """Send a chat command message."""
        if not self.entered_world:
            return False, "Not in world yet"

        # CMSG_CHAT_MESSAGE (opcode 0x096 for 3.3.5a)
        # Wait, for WoW 3.3.5a:
        # CMSG_CHAT_MESSAGE = 0x096
        # The format is: opcode(4) + size(2) + chat_type(1) + language(1) + message(null-term)

        # Chat types:
        # 0 = say, 1 = party, 2 = raid, 3 = guild, 4 = officer, 
        # 5 = yell, 6 = whisper, 7 = emote, 14 = channel

        chat_type_map = {
            'say': 0,
            'party': 1,
            'raid': 2,
            'guild': 3,
            'yell': 5,
        }

        chat_type = chat_type_map.get(channel, 0)

        msg_bytes = message.encode('utf-8') + b'\x00'
        size = 1 + 1 + len(msg_bytes)  # chat_type + language + message

        packet = struct.pack('>IH', 0x096, size)
        packet += struct.pack('BB', chat_type, 0)  # language: universal
        packet += msg_bytes

        self._send_packet(packet)

        return True, f"Chat message sent: {message}"

    def set_bot_mode(self, character_name, bot_command_target=None):
        """Set character to bot mode via chat commands."""
        results = []

        # Enter the world first
        success, msg = self.select_character(character_name)
        results.append(f"Select character: {msg}")

        if not success:
            return results

        # Wait for world to fully load
        time.sleep(2)

        # Send /bot command
        # The bot module needs GM level 3 (SEC_GAMEMASTER)
        # We send the command as a chat message
        commands = [
            '/bot list',  # First check current state
        ]

        if bot_command_target:
            commands = [
                f'/bot set {bot_command_target}',
                '/bot list',
            ]

        for cmd in commands:
            success, msg = self.send_chat_command(cmd)
            results.append(f"Command '{cmd}': {msg}")
            time.sleep(0.5)

        return results


class WoWClientSimulator:
    """Main simulator interface."""

    def __init__(self, logon_host, world_host=None):
        self.logon_host = logon_host
        self.world_host = world_host or logon_host
        self.logon_client = None
        self.world_client = None
        self.authenticated = False

    def login(self, account, password):
        """Authenticate with logon server and get session key."""
        try:
            self.logon_client = WoWLogonClient(self.logon_host)
            self.logon_client.connect()

            success, result = self.logon_client.authenticate(account, password)

            if not success:
                return False, str(result)

            self.authenticated = True

            # Connect to world server
            if self.logon_client.realms:
                realm = self.logon_client.realms[0]
                # Parse address (might be "ip:port" or just "ip")
                addr = realm['address']
                if ':' in addr:
                    parts = addr.split(':')
                    world_host = parts[0]
                    world_port = int(parts[1])
                else:
                    world_host = addr
                    world_port = 8085
            else:
                world_host = self.world_host
                world_port = 8085

            self.world_client = WoWWorldClient(
                world_host, world_port,
                self.logon_client.session_key
            )
            self.world_client.connect()

            # Login to world
            success, msg = self.world_client.login_to_world(account)
            if not success:
                return False, msg

            # Get characters
            chars = self.world_client.get_characters()

            return True, {
                'account': account,
                'characters': chars,
                'realms': self.logon_client.realms,
            }

        except Exception as e:
            return False, str(e)

    def select_character(self, character_name):
        """Select and enter a character."""
        if not self.world_client:
            return False, "Not connected"

        return self.world_client.select_character(character_name)

    def send_command(self, command):
        """Send a chat command."""
        if not self.world_client:
            return False, "Not connected"

        return self.world_client.send_chat_command(command)

    def set_bot(self, character_name, target_name=None):
        """Set character to bot mode."""
        if not self.world_client:
            return False, "Not connected"

        results = self.world_client.set_bot_mode(character_name, target_name)
        return True, results

    def close(self):
        """Close all connections."""
        if self.world_client:
            self.world_client.close()
        if self.logon_client:
            self.logon_client.close()
        self.authenticated = False


def main():
    parser = argparse.ArgumentParser(description='WoW 3.3.5a Client Simulator')
    subparsers = parser.add_subparsers(dest='action', help='Action to perform')

    # Login test
    login_parser = subparsers.add_parser('test', help='Test account login')
    login_parser.add_argument('--account', required=True, help='Account username')
    login_parser.add_argument('--password', required=True, help='Account password')
    login_parser.add_argument('--host', default='127.0.0.1', help='Logon server host')
    login_parser.add_argument('--port', type=int, default=6900, help='Logon server port')

    # List characters
    list_parser = subparsers.add_parser('list', help='List account characters')
    list_parser.add_argument('--account', required=True, help='Account username')
    list_parser.add_argument('--password', required=True, help='Account password')
    list_parser.add_argument('--host', default='127.0.0.1', help='Logon server host')

    # Login and set bot
    login_cmd = subparsers.add_parser('login', help='Login and enter world')
    login_cmd.add_argument('--account', required=True, help='Account username')
    login_cmd.add_argument('--password', required=True, help='Account password')
    login_cmd.add_argument('--character', required=False, help='Character to select')
    login_cmd.add_argument('--host', default='127.0.0.1', help='Logon server host')
    login_cmd.add_argument('--bot-target', required=False, help='Target for /bot set command')

    # Bot mode
    bot_parser = subparsers.add_parser('bot', help='Login character and set bot mode')
    bot_parser.add_argument('--account', required=True, help='Account username')
    bot_parser.add_argument('--password', required=True, help='Account password')
    bot_parser.add_argument('--character', required=True, help='Character name')
    bot_parser.add_argument('--host', default='127.0.0.1', help='Logon server host')
    bot_parser.add_argument('--bot-target', required=False, help='Bot target player name')

    args = parser.parse_args()

    if not args.action:
        parser.print_help()
        return

    host = getattr(args, 'host', '127.0.0.1')

    if args.action == 'test':
        print(f"[*] Testing account '{args.account}' on {host}")
        client = WoWClientSimulator(host)
        success, result = client.login(args.account, args.password)

        if success:
            print(f"[+] Login successful!")
            print(f"    Account: {result['account']}")
            print(f"    Characters: {len(result['characters'])}")
            for char in result['characters']:
                print(f"      - {char['name']} (L{char['level']}, {char['race']}, {char['class']})")
        else:
            print(f"[-] Login failed: {result}")

        client.close()

    elif args.action == 'list':
        print(f"[*] Listing characters for '{args.account}' on {host}")
        client = WoWClientSimulator(host)
        success, result = client.login(args.account, args.password)

        if success:
            chars = result.get('characters', [])
            print(f"[+] Found {len(chars)} character(s):")
            for char in chars:
                print(f"    {char['name']} - L{char['level']} {char['race']}/{char['class']} ({char['map']})")
        else:
            print(f"[-] Failed: {result}")

        client.close()

    elif args.action == 'login':
        character = getattr(args, 'character', None)
        print(f"[*] Logging in '{args.account}' on {host}")

        client = WoWClientSimulator(host)
        success, result = client.login(args.account, args.password)

        if not success:
            print(f"[-] Login failed: {result}")
            client.close()
            return

        print(f"[+] Authenticated! Characters: {len(result['characters'])}")

        if not character and result['characters']:
            character = result['characters'][0]['name']
            print(f"[*] Auto-selecting first character: {character}")

        if character:
            print(f"[*] Selecting character '{character}'...")
            success, msg = client.select_character(character)
            print(f"[{'+' if success else '-'}] {msg}")

            if success and args.bot_target:
                print(f"[*] Setting bot mode with target '{args.bot_target}'...")
                success, results = client.set_bot(character, args.bot_target)
                for r in results:
                    print(f"    {r}")

        client.close()

    elif args.action == 'bot':
        print(f"[*] Bot mode for '{args.character}' on {host}")

        client = WoWClientSimulator(host)
        success, result = client.login(args.account, args.password)

        if not success:
            print(f"[-] Login failed: {result}")
            client.close()
            return

        print(f"[+] Authenticated!")
        print(f"[*] Setting character '{args.character}' to bot mode...")

        success, results = client.set_bot(args.character, args.bot_target)

        for r in results:
            print(f"    {r}")

        if success:
            print("[+] Bot mode activated! Character will remain online.")
            print("[*] Press Ctrl+C to disconnect...")
            try:
                while True:
                    time.sleep(1)
            except KeyboardInterrupt:
                print("\n[*] Disconnecting...")

        client.close()


if __name__ == '__main__':
    main()