"""HTTP-Server: Router, Auth, statisches Dashboard. Nur Python-Standardbibliothek.

Start:  python -m server.app
"""
from __future__ import annotations

import json
import mimetypes
import re
import sys
import threading
import time
import traceback
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

if __package__ in (None, ""):  # Direktstart ohne -m
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
    from server import api, db, scheduler, settings  # type: ignore
else:
    from . import api, db, scheduler, settings

DASHBOARD_DIR = Path(__file__).resolve().parent / "dashboard"
MAX_BODY = 512 * 1024  # 512 KB
RES_RE = re.compile(r"^/reservations/([A-Za-z0-9_\-]{1,64})(?:/(start|heartbeat|complete|fail|cancel))?$")

# Einfaches Rate Limit pro IP (Schreibzugriffe).
_rl_lock = threading.Lock()
_rl: dict[str, list[float]] = {}
RL_WINDOW = 10.0
RL_MAX = 120


def rate_limited(ip: str) -> bool:
    now = time.monotonic()
    with _rl_lock:
        hits = [t for t in _rl.get(ip, []) if now - t < RL_WINDOW]
        hits.append(now)
        _rl[ip] = hits
        if len(_rl) > 2000:
            _rl.clear()
        return len(hits) > RL_MAX


class Handler(BaseHTTPRequestHandler):
    server_version = "mac-monitor/1.0"
    protocol_version = "HTTP/1.1"

    # --- Infrastruktur ----------------------------------------------------
    def log_message(self, fmt: str, *args) -> None:  # leiser Standardlog
        if "--verbose" in sys.argv:
            sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))

    def _send(self, code: int, body: bytes, ctype: str,
              extra: dict[str, str] | None = None) -> None:
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, X-API-Token")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, DELETE, OPTIONS")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Cache-Control", "no-store")
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def _json(self, code: int, obj) -> None:
        self._send(code, json.dumps(obj, ensure_ascii=False).encode("utf-8"),
                   "application/json; charset=utf-8")

    def _read_json(self) -> dict:
        length = int(self.headers.get("Content-Length") or 0)
        if length <= 0:
            return {}
        if length > MAX_BODY:
            raise api.ValidationError("Payload zu gross.")
        raw = self.rfile.read(length)
        try:
            data = json.loads(raw.decode("utf-8"))
        except (json.JSONDecodeError, UnicodeDecodeError) as exc:
            raise api.ValidationError(f"Ungueltiges JSON: {exc}") from exc
        return data

    def _token_ok(self) -> bool:
        token = (self.headers.get("X-API-Token")
                 or self.headers.get("Authorization", "").replace("Bearer ", "")
                 or parse_qs(urlparse(self.path).query).get("token", [""])[0])
        return token == settings.API_TOKEN

    # --- Routing ----------------------------------------------------------
    def do_OPTIONS(self) -> None:  # noqa: N802
        self._send(204, b"", "text/plain")

    def do_HEAD(self) -> None:  # noqa: N802
        self.do_GET()

    def do_GET(self) -> None:  # noqa: N802
        self._route("GET")

    def do_POST(self) -> None:  # noqa: N802
        self._route("POST")

    def do_DELETE(self) -> None:  # noqa: N802
        self._route("DELETE")

    def _route(self, method: str) -> None:
        parsed = urlparse(self.path)
        path = parsed.path.rstrip("/") or "/"
        q = parse_qs(parsed.query)
        try:
            if not path.startswith(settings.API_PREFIX):
                return self._static(path)

            route = path[len(settings.API_PREFIX):] or "/"
            write = method in ("POST", "DELETE")

            if write and rate_limited(self.client_address[0]):
                return self._json(429, {"status": "error", "error": "rate_limited",
                                        "retry_after_seconds": int(RL_WINDOW)})
            needs_auth = write or not settings.ALLOW_ANONYMOUS_READ
            if needs_auth and route != "/health" and not self._token_ok():
                return self._json(401, {"status": "error", "error": "unauthorized",
                                        "message": "Header 'X-API-Token' fehlt oder ist falsch."})

            code, body = self._dispatch(method, route, q)
            return self._json(code, body)

        except api.ValidationError as exc:
            self._json(400, {"status": "error", "error": "invalid_request",
                             "message": str(exc)})
        except BrokenPipeError:
            pass
        except Exception as exc:  # noqa: BLE001
            db.log_event("error", "http", f"{path}: {exc}")
            if "--verbose" in sys.argv:
                traceback.print_exc()
            self._json(500, {"status": "error", "error": "internal_error",
                             "message": "Interner Fehler, Details im Serverlog."})

    def _dispatch(self, method: str, route: str, q: dict) -> tuple[int, dict]:
        if method == "GET":
            table = {
                "/health": api.health, "/": api.health,
                "/config": api.config_public,
                "/machines": api.machines,
            }
            if route in table:
                return table[route]()
            if route == "/capacity":
                return api.capacity(q)
            if route == "/slots":
                return api.slots(q)
            if route in ("/agent/discovery", "/discovery"):
                return api.discovery(q)
            if route == "/series":
                return api.series(q)
            if route == "/tps/summary":
                return api.tps_summary(q)
            if route == "/events":
                return api.events(q)
            if route == "/reservations":
                return api.list_reservations(q)
            m = RES_RE.match(route)
            if m and not m.group(2):
                return api.get_reservation(m.group(1))

        elif method == "POST":
            if route == "/ingest":
                return api.ingest(self._read_json())
            if route == "/tps":
                return api.tps(self._read_json())
            if route == "/reservations":
                return api.create_reservation(self._read_json())
            if route == "/maintenance":
                return api.maintenance()
            m = RES_RE.match(route)
            if m and m.group(2):
                return api.reservation_action(m.group(1), m.group(2))

        elif method == "DELETE":
            m = RES_RE.match(route)
            if m and not m.group(2):
                return api.reservation_action(m.group(1), "cancel")

        return 404, {"status": "error", "error": "not_found",
                     "message": f"Route {route} existiert nicht.",
                     "api_url": settings.API_URL}

    # --- Dashboard --------------------------------------------------------
    def _static(self, path: str) -> None:
        name = "index.html" if path == "/" else path.lstrip("/")
        target = (DASHBOARD_DIR / name).resolve()
        if not str(target).startswith(str(DASHBOARD_DIR.resolve())) or not target.is_file():
            return self._send(404, b"Not found", "text/plain; charset=utf-8")
        ctype = mimetypes.guess_type(target.name)[0] or "application/octet-stream"
        if ctype.startswith("text/") or ctype in ("application/javascript", "application/json"):
            ctype += "; charset=utf-8"
        self._send(200, target.read_bytes(), ctype)


def _housekeeping() -> None:
    while True:
        time.sleep(60)
        try:
            db.init_db()
            scheduler.expire_stale()
            if int(time.time()) % 3600 < 60:
                db.run_retention()
        except Exception as exc:  # noqa: BLE001
            db.log_event("error", "housekeeping", str(exc))


def main() -> None:
    db.init_db()
    db.log_event("info", "server", f"Start auf {settings.HOST}:{settings.PORT}")
    threading.Thread(target=_housekeeping, daemon=True).start()
    httpd = ThreadingHTTPServer((settings.HOST, settings.PORT), Handler)
    httpd.daemon_threads = True
    print("mac-monitor Produktivsystem")
    print(f"  Dashboard : {settings.PUBLIC_URL}/")
    print(f"  API       : {settings.API_URL}")
    print(f"  Discovery : {settings.API_URL}/agent/discovery")
    print(f"  Datenbank : {settings.DB_PATH}")
    print("  Beenden mit STRG+C")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nServer gestoppt.")
    finally:
        httpd.server_close()


if __name__ == "__main__":
    main()
