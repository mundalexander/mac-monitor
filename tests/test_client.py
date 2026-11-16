#!/usr/bin/env python3
"""Tests für den Fleet-Monitor-Client (ohne Fremdpakete).

Ein kleiner Testserver bildet den Vertrag der PHP-Endpunkte nach
(pair.php, submit.php, requests.php, commands.php). Geprüft werden
Kopplung, Dateirechte, Header-Authentifizierung, Widerruf und dass
nirgends mehr ein Token mitgeschickt wird.

    python3 -m unittest discover -s tests -v
"""
from __future__ import annotations

import io
import json
import os
import stat
import sys
import tempfile
import threading
import unittest
from contextlib import redirect_stdout
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "client"))

import monitor  # noqa: E402

PIN = "482913"


class FakeServer:
    """Minimaler Nachbau der Server-Logik aus server/auth.php + Endpunkten."""

    def __init__(self) -> None:
        self.devices: dict[str, dict] = {}
        self.submits: list[dict] = []
        self.calls: list[dict] = []
        self.commands: list[dict] = []
        self.seen_headers: list[dict] = []
        outer = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a):  # still
                pass

            def _json(self, code: int, body: dict) -> None:
                raw = json.dumps(body).encode()
                self.send_response(code)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                self.end_headers()
                self.wfile.write(raw)

            def _device(self):
                did = self.headers.get("X-Fleet-Device", "")
                key = self.headers.get("X-Fleet-Key", "")
                d = outer.devices.get(did)
                if d and not d["revoked"] and d["key"] == key:
                    return did
                return None

            def _body(self) -> dict:
                n = int(self.headers.get("Content-Length") or 0)
                return json.loads(self.rfile.read(n) or b"{}")

            def do_GET(self):
                outer.seen_headers.append(dict(self.headers))
                if self.path.startswith("/commands.php"):
                    did = self._device()
                    if not did:
                        return self._json(401, {"error": "device_not_paired"})
                    pending = [c for c in outer.commands if not c["done"] and c["server_id"] == did]
                    return self._json(200, {"commands": pending})
                self._json(404, {})

            def do_POST(self):
                outer.seen_headers.append(dict(self.headers))
                data = self._body()
                if self.path == "/pair.php":
                    if data.get("pin") != PIN:
                        return self._json(401, {"error": "invalid_pin", "message": "PIN falsch."})
                    key = os.urandom(32).hex()
                    outer.devices[data["machine_id"]] = {"key": key, "revoked": False}
                    return self._json(200, {"ok": True, "machine_id": data["machine_id"],
                                            "device_key": key, "known": data["machine_id"] == "evo-x3"})
                did = self._device()
                if not did:
                    return self._json(401, {"error": "device_not_paired"})
                if self.path == "/submit.php":
                    outer.submits.append(data)
                    return self._json(200, {"ok": True, "server_id": did})
                if self.path == "/requests.php":
                    outer.calls.extend(data.get("calls", []))
                    return self._json(200, {"ok": True})
                if self.path == "/commands.php":
                    for c in outer.commands:
                        if c["id"] == data.get("id") and c["server_id"] == did:
                            c["done"] = True
                            return self._json(200, {"ok": True})
                    return self._json(404, {"ok": False})
                self._json(404, {})

        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), H)
        self.url = f"http://127.0.0.1:{self.httpd.server_address[1]}"
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def close(self) -> None:
        self.httpd.shutdown()
        self.httpd.server_close()


class ClientTests(unittest.TestCase):
    def setUp(self) -> None:
        self.srv = FakeServer()
        self.tmp = tempfile.TemporaryDirectory()
        self.cred = os.path.join(self.tmp.name, "cfg", "device.json")
        self.cfgfile = Path(self.tmp.name) / "config.json"
        self.cfgfile.write_text(json.dumps({
            "server_url": self.srv.url, "machine_id": "evo-x3",
            "credential_path": self.cred, "spool_path": os.path.join(self.tmp.name, "spool.jsonl"),
            "timeout_seconds": 3,
        }))
        self._env = {k: os.environ.pop(k) for k in list(os.environ) if k.startswith("MM_")}

    def tearDown(self) -> None:
        self.srv.close()
        self.tmp.cleanup()
        for k in list(os.environ):
            if k.startswith("MM_"):
                del os.environ[k]
        os.environ.update(self._env)

    def cfg(self) -> dict:
        with redirect_stdout(io.StringIO()):
            return monitor.load_config(self.cfgfile)

    def pair(self, pin: str = PIN, mid: str = "evo-x3") -> int:
        with redirect_stdout(io.StringIO()):
            return monitor.pair(self.cfg(), mid, "Evo X3", pin=pin)

    # -- Kopplung ----------------------------------------------------------
    def test_pair_success_writes_private_credential(self) -> None:
        self.assertEqual(self.pair(), 0)
        p = Path(self.cred)
        self.assertTrue(p.is_file())
        if os.name == "posix":
            self.assertEqual(stat.S_IMODE(p.stat().st_mode), 0o600)
            self.assertEqual(stat.S_IMODE(p.parent.stat().st_mode), 0o700)
        data = json.loads(p.read_text())
        self.assertEqual(data["machine_id"], "evo-x3")
        self.assertEqual(len(data["device_key"]), 64)
        self.assertNotIn("pin", json.dumps(data).lower())

    def test_pair_wrong_pin_writes_nothing(self) -> None:
        self.assertEqual(self.pair(pin="000000"), 1)
        self.assertFalse(Path(self.cred).exists())

    def test_pair_refuses_plain_http_for_remote_servers(self) -> None:
        cfg = self.cfg()
        cfg["server_url"] = "http://example.org/mac-monitor"
        cfg["pair_url"] = cfg["server_url"] + "/pair.php"
        with redirect_stdout(io.StringIO()):
            self.assertEqual(monitor.pair(cfg, "evo-x3", "x", pin=PIN), 2)

    def test_open_permissions_are_repaired(self) -> None:
        self.pair()
        if os.name != "posix":
            self.skipTest("nur POSIX")
        os.chmod(self.cred, 0o644)
        self.cfg()
        self.assertEqual(stat.S_IMODE(os.stat(self.cred).st_mode), 0o600)

    # -- Konfiguration -----------------------------------------------------
    def test_credential_binds_machine_id(self) -> None:
        self.pair(mid="mac")
        os.environ["MM_MACHINE_ID"] = "something-else"
        cfg = self.cfg()
        self.assertEqual(cfg["machine_id"], "mac")
        self.assertEqual(monitor.auth_headers(cfg)["X-Fleet-Device"], "mac")

    def test_env_overrides_file(self) -> None:
        os.environ["MM_INTERVAL"] = "30"
        os.environ["MM_SERVER_URL"] = self.srv.url + "/"
        cfg = self.cfg()
        self.assertEqual(cfg["interval_seconds"], 30)
        self.assertEqual(cfg["server_url"], self.srv.url)
        self.assertEqual(cfg["submit_url"], self.srv.url + "/submit.php")

    def test_old_api_token_is_ignored(self) -> None:
        raw = json.loads(self.cfgfile.read_text())
        raw["api_token"] = "fseJLg-old"
        self.cfgfile.write_text(json.dumps(raw))
        buf = io.StringIO()
        with redirect_stdout(buf):
            cfg = monitor.load_config(self.cfgfile)
        self.assertNotIn("api_token", cfg)
        self.assertIn("ignoriert", buf.getvalue())

    def test_server_url_taken_from_credential_when_not_configured(self) -> None:
        self.pair()
        raw = json.loads(self.cfgfile.read_text())
        raw.pop("server_url")
        self.cfgfile.write_text(json.dumps(raw))
        cfg = self.cfg()
        self.assertEqual(cfg["server_url"], self.srv.url)
        self.assertEqual(cfg["commands_url"], self.srv.url + "/commands.php")

    # -- Betrieb -----------------------------------------------------------
    def test_submit_uses_headers_and_no_token(self) -> None:
        self.pair()
        cfg = self.cfg()
        payload = {"host": "evo", "ts": 1790000000, "cpu": 1, "gpu": 2, "ram_percent": 3}
        code, _ = monitor.post_json(cfg["submit_url"], payload, monitor.auth_headers(cfg))
        self.assertEqual(code, 200)
        self.assertEqual(len(self.srv.submits), 1)
        self.assertNotIn("token", self.srv.submits[0])
        h = self.srv.seen_headers[-1]
        self.assertEqual(h.get("X-Fleet-Device"), "evo-x3")
        self.assertNotIn("X-API-Token", h)

    def test_unpaired_submit_is_rejected(self) -> None:
        cfg = self.cfg()
        self.assertEqual(monitor.auth_headers(cfg), {})
        code, body = monitor.post_json(cfg["submit_url"], {"cpu": 1}, monitor.auth_headers(cfg))
        self.assertEqual(code, 401)
        self.assertEqual(body["error"], "device_not_paired")

    def test_status_reports_valid_and_revoked(self) -> None:
        self.pair()
        with redirect_stdout(io.StringIO()):
            self.assertEqual(monitor.status(self.cfg()), 0)
        self.srv.devices["evo-x3"]["revoked"] = True
        buf = io.StringIO()
        with redirect_stdout(buf):
            self.assertEqual(monitor.status(self.cfg()), 1)
        self.assertIn("abgelehnt", buf.getvalue())

    def test_commands_only_for_own_device_and_marked_done(self) -> None:
        self.pair()
        self.srv.commands = [
            {"id": "a1", "action": "noop_test", "server_id": "evo-x3", "done": False},
            {"id": "b2", "action": "noop_test", "server_id": "mac", "done": False},
        ]
        cfg = self.cfg()
        code, body = monitor.get_json(cfg["commands_url"], monitor.auth_headers(cfg))
        self.assertEqual(code, 200)
        self.assertEqual([c["id"] for c in body["commands"]], ["a1"])
        agent = monitor.Agent.__new__(monitor.Agent)
        agent.cfg = cfg
        with redirect_stdout(io.StringIO()):
            agent._mark_command_done("a1", True)
        self.assertTrue(self.srv.commands[0]["done"])
        self.assertFalse(self.srv.commands[1]["done"])

    def test_request_log_is_attributed_via_headers(self) -> None:
        self.pair()
        agent = monitor.Agent.__new__(monitor.Agent)
        agent.cfg = self.cfg()
        agent._send_request_log({"ts": 1790000000, "ip": "x", "method": "POST",
                                 "endpoint": "/v1/chat/completions", "status": 200})
        self.assertEqual(len(self.srv.calls), 1)
        self.assertEqual(self.srv.seen_headers[-1].get("X-Fleet-Device"), "evo-x3")

    def test_unpair_removes_local_key(self) -> None:
        self.pair()
        argv = sys.argv
        sys.argv = ["monitor.py", "--config", str(self.cfgfile), "--unpair"]
        try:
            with redirect_stdout(io.StringIO()):
                self.assertEqual(monitor.main(), 0)
        finally:
            sys.argv = argv
        self.assertFalse(Path(self.cred).exists())


class SpoolTests(unittest.TestCase):
    def test_spool_is_bounded_and_ordered(self) -> None:
        from spool import Spool
        with tempfile.TemporaryDirectory() as d:
            s = Spool(os.path.join(d, "s.jsonl"), max_items=3)
            for i in range(5):
                s.add({"i": i})
            self.assertEqual([x["i"] for x in s.peek_all()], [2, 3, 4])
            s.remove_first(2)
            self.assertEqual([x["i"] for x in s.peek_all()], [4])


if __name__ == "__main__":
    unittest.main(verbosity=2)
