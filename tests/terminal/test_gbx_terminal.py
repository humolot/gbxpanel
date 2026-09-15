"""
Protocol tests for scripts/gbx-terminal using the echo backend.

    python3 tests/terminal/test_gbx_terminal.py

When "php" is on PATH, a token issued by the Laravel TerminalManager is also verified
against the daemon, so both implementations stay compatible.
"""

import base64
import hashlib
import hmac
import importlib.machinery
import importlib.util
import json
import os
import shutil
import socket
import struct
import subprocess
import sys
import tempfile
import time
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DAEMON = os.path.join(ROOT, "scripts", "gbx-terminal")
PANEL = os.path.join(ROOT, "panel")
KEY = "test-signing-key-0123456789abcdef"


def b64url(raw):
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii")


def make_token(key=KEY, ip="127.0.0.1", expires_in=60, nonce=None):
    payload = b64url(json.dumps({"u": 1, "n": "admin", "e": int(time.time()) + expires_in, "r": nonce or os.urandom(8).hex(), "ip": ip}).encode())
    sig = b64url(hmac.new(key.encode(), payload.encode(), hashlib.sha256).digest())
    return payload + "." + sig


def free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class Client:
    def __init__(self, port, token, headers=""):
        self.sock = socket.create_connection(("127.0.0.1", port), timeout=5)
        key = base64.b64encode(os.urandom(16)).decode()
        self.sock.sendall(
            (
                "GET /?token=%s HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                "Sec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n%s\r\n" % (token, key, headers)
            ).encode()
        )
        self.buffer = b""
        while b"\r\n\r\n" not in self.buffer:
            chunk = self.sock.recv(4096)
            if not chunk:
                break
            self.buffer += chunk
        head, _, self.buffer = self.buffer.partition(b"\r\n\r\n")
        self.status = int(head.split(b" ")[1]) if head else 0
        self.accept = key

    def send(self, obj, opcode=1):
        data = json.dumps(obj).encode() if opcode == 1 else obj
        mask = os.urandom(4)
        header = bytes([0x80 | opcode])
        if len(data) < 126:
            header += bytes([0x80 | len(data)])
        else:
            header += bytes([0x80 | 126]) + struct.pack("!H", len(data))
        self.sock.sendall(header + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))

    def _read(self, n):
        while len(self.buffer) < n:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise ConnectionError("closed")
            self.buffer += chunk
        out, self.buffer = self.buffer[:n], self.buffer[n:]
        return out

    def frame(self):
        b1, b2 = self._read(2)
        length = b2 & 0x7F
        if length == 126:
            length = struct.unpack("!H", self._read(2))[0]
        elif length == 127:
            length = struct.unpack("!Q", self._read(8))[0]
        return b1 & 0x0F, self._read(length)

    def read_until(self, needle, timeout=5):
        end, seen = time.time() + timeout, b""
        while time.time() < end:
            opcode, data = self.frame()
            if opcode == 8:
                return seen, data
            if opcode == 2:
                seen += data
                if needle in seen:
                    return seen, None
        raise AssertionError("timeout waiting for %r (got %r)" % (needle, seen))

    def close(self):
        self.sock.close()


class TerminalDaemonTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.port = free_port()
        cls.tmp = tempfile.mkdtemp()
        ready = os.path.join(cls.tmp, "ready")
        cls.proc = subprocess.Popen(
            [sys.executable, DAEMON, "--backend", "echo", "--key", KEY, "--port", str(cls.port), "--ready-file", ready],
            stderr=subprocess.PIPE,
        )
        for _ in range(100):
            if os.path.exists(ready):
                break
            time.sleep(0.05)
        else:
            raise RuntimeError("daemon did not start")

    @classmethod
    def tearDownClass(cls):
        cls.proc.terminate()
        cls.proc.wait(5)
        cls.proc.stderr.close()
        shutil.rmtree(cls.tmp, ignore_errors=True)

    def test_health_endpoint(self):
        with socket.create_connection(("127.0.0.1", self.port), timeout=5) as s:
            s.sendall(b"GET /health HTTP/1.1\r\nHost: x\r\n\r\n")
            self.assertIn(b"200 OK", s.recv(1024))

    def test_rejects_invalid_signature(self):
        client = Client(self.port, make_token(key="wrong-key"))
        self.assertEqual(403, client.status)
        client.close()

    def test_session_echo_resize_and_exit(self):
        client = Client(self.port, make_token())
        self.assertEqual(101, client.status)
        client.read_until(b"echo backend ready")
        client.send({"t": "i", "d": "uptime\r"})
        client.read_until(b"uptime\r")
        client.send({"t": "r", "c": 132, "r": 40})
        client.read_until(b"[resize 132x40]")
        client.send({"t": "k"})
        client.send({"t": "i", "d": "exit\r"})
        _, reason = client.read_until(b"never")
        self.assertIn(b"shell exited", reason)
        client.close()

    def test_large_input_frame(self):
        client = Client(self.port, make_token())
        client.read_until(b"echo backend ready")
        payload = "x" * 5000
        client.send({"t": "i", "d": payload})
        seen, _ = client.read_until(payload.encode())
        self.assertIn(payload.encode(), seen)
        client.close()

    def test_token_is_single_use(self):
        token = make_token()
        first = Client(self.port, token)
        self.assertEqual(101, first.status)
        second = Client(self.port, token)
        self.assertEqual(403, second.status)
        first.close()
        second.close()

    def test_rejects_expired_token(self):
        client = Client(self.port, make_token(expires_in=-5))
        self.assertEqual(403, client.status)
        client.close()

    def test_forwarded_address_must_match_token(self):
        ok = Client(self.port, make_token(ip="203.0.113.7"), "X-Forwarded-For: 10.0.0.1, 203.0.113.7\r\n")
        self.assertEqual(101, ok.status)
        ok.close()
        bad = Client(self.port, make_token(ip="203.0.113.7"), "X-Forwarded-For: 198.51.100.9\r\n")
        self.assertEqual(403, bad.status)
        bad.close()

    def test_plain_http_is_refused(self):
        with socket.create_connection(("127.0.0.1", self.port), timeout=5) as s:
            s.sendall(b"GET /?token=x HTTP/1.1\r\nHost: x\r\n\r\n")
            self.assertIn(b"426", s.recv(1024))


@unittest.skipUnless(shutil.which("php") and os.path.exists(os.path.join(PANEL, "vendor", "autoload.php")), "php not available")
class LaravelTokenCompatibilityTest(unittest.TestCase):
    def test_panel_token_verifies_in_daemon(self):
        app_key = "base64:" + base64.b64encode(b"k" * 32).decode()
        code = (
            "require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';"
            "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
            "config(['app.key' => '%s']);"
            "$u = new App\\Models\\User(); $u->id = 7; $u->username = 'ops';"
            "echo app(App\\Services\\TerminalManager::class)->token($u, '198.51.100.20');" % app_key
        )
        token = subprocess.check_output(["php", "-r", code], cwd=PANEL).decode().strip()

        loader = importlib.machinery.SourceFileLoader("gbx_terminal", DAEMON)
        spec = importlib.util.spec_from_loader("gbx_terminal", loader)
        module = importlib.util.module_from_spec(spec)
        loader.exec_module(module)

        verifier = module.TokenVerifier(module.KeyStore(None, base64.b64decode(app_key[7:])))
        claims = verifier.verify(token, "198.51.100.20")
        self.assertEqual(7, claims["u"])
        self.assertEqual("ops", claims["n"])
        with self.assertRaises(PermissionError):
            verifier.verify(token, "198.51.100.20")


if __name__ == "__main__":
    unittest.main(verbosity=2)
