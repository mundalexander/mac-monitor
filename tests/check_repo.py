#!/usr/bin/env python3
"""Statische Repo-Prüfungen für 1.2.0 (ohne PHP-Laufzeit lauffähig).

- keine Tokens/Geheimnisse mehr in server/ und client/
- keine Laufzeitdaten im Repo (Datenbank, Client-Konfiguration, Queue)
- jede HTML-Seite hat das PIN-Gate, jeder Endpunkt eine Zugriffsprüfung
- alle require-Aufrufe sind *_once (sonst "Cannot redeclare" bei config/auth)
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SERVER = ROOT / "server"
problems: list[str] = []


def fail(msg: str) -> None:
    problems.append(msg)


# 1) Verbotene Muster (Token-Ära)
FORBIDDEN = [
    (r"SECRET_TOKEN", "SECRET_TOKEN"),
    (r"DASHBOARD_TOKEN", "DASHBOARD_TOKEN"),
    (r"monitor_secret\(", "monitor_secret()"),
    (r"X-API-Token", "X-API-Token-Header"),
    (r"X-Monitor-Token|HTTP_X_MONITOR_TOKEN", "X-Monitor-Token-Header"),
    (r"Bearer\s+['\"]?\s*\+?\s*ctlToken|Bearer macmon", "Bearer-Token"),
    (r"fseJLg", "alter Klartext-Schlüssel"),
    (r"localStorage\.(get|set)Item\(['\"]mm_token", "Token in localStorage"),
    (r"dashboard_token", "dashboard_token-Login"),
]
for f in list(SERVER.rglob("*.php")) + list(SERVER.rglob("*.js")) + list((ROOT / "client").rglob("*.py")):
    text = f.read_text(encoding="utf-8", errors="ignore")
    for pat, label in FORBIDDEN:
        if re.search(pat, text):
            fail(f"{f.relative_to(ROOT)}: enthält {label}")

# 2) Laufzeitdaten dürfen nicht im Repo liegen
for rel in ["client/config.json", "server/metrics.sqlite", "server/secrets.php",
            "server/commands.json", "server/webauthn-credentials.json", "server/unload_queue.php"]:
    if (ROOT / rel).exists():
        fail(f"{rel} gehört nicht ins Repository")

# 3) Gates
PAGES = ["index.php", "calendar.php", "board.php", "autonomy.php", "fleet.php",
         "slot-planner.php", "devices.php"]
for p in PAGES:
    if "mm_gate_page()" not in (SERVER / p).read_text(encoding="utf-8"):
        fail(f"server/{p}: mm_gate_page() fehlt")

GATES = {
    "submit.php": "mm_device_require", "requests.php": "mm_device_require",
    "commands.php": "mm_device_require", "ingest.php": "mm_device_require",
    "data.php": "mm_require_api_access", "fleet-api.php": "mm_require_api_access",
    "slots.php": "mm_require_api_access", "slot-api.php": "mm_require_api_access",
    "board-api.php": "mm_require_api_access", "autonomy-api.php": "mm_require_api_access",
    "unload-request.php": "mm_require_session", "devices-api.php": "mm_require_session",
    "pair.php": "mm_login_check",
    "control-api.php": "mm_require_api_access", "capacity.php": "mm_require_api_access",
}
for p, fn in GATES.items():
    if fn + "(" not in (SERVER / p).read_text(encoding="utf-8"):
        fail(f"server/{p}: {fn}() fehlt")

# 4) Nur require_once (config.php bindet auth.php ein und umgekehrt)
for f in SERVER.rglob("*.php"):
    for i, line in enumerate(f.read_text(encoding="utf-8").splitlines(), 1):
        if re.match(r"\s*(require|include)\s+__DIR__", line):
            fail(f"{f.relative_to(ROOT)}:{i}: require ohne _once")

# 5) Jede Seite bindet das neue Design ein, keine externen Schriften
for p in PAGES:
    if "assets/apple.css" not in (SERVER / p).read_text(encoding="utf-8"):
        fail(f"server/{p}: assets/apple.css fehlt")
if "cdn.ui.porsche.com" in (SERVER / "assets/ui.css").read_text(encoding="utf-8"):
    fail("assets/ui.css lädt noch externe Schriften")

# 6) Keine Emojis als UI-Icons (1.2.1)
EMOJI = re.compile("[\U0001F000-\U0001FAFF\u2600-\u2714\u2716-\u27BF\u2B00-\u2BFF\u23E9-\u23FF]")
for p in PAGES + ["config.php", "data.php"]:
    if EMOJI.search((SERVER / p).read_text(encoding="utf-8")):
        fail(f"server/{p}: enthält Emoji-Icons")

# 7) Kommandozentrale: keine alte Recovery-API mehr im Frontend (1.3)
if "recovery-api.php" in (SERVER / "index.php").read_text(encoding="utf-8"):
    fail("server/index.php: nutzt noch recovery-api.php")

if problems:
    print("Repo-Prüfung FEHLGESCHLAGEN:")
    for p in problems:
        print("  -", p)
    sys.exit(1)
print(f"Repo-Prüfung ok ({len(PAGES)} Seiten, {len(GATES)} Endpunkte, keine Tokens).")
