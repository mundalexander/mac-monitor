"""Zentrale Konfiguration. Alle URLs und Pfade kommen NUR von hier.

Reihenfolge: ENV > .env Datei > Default.
"""
from __future__ import annotations

import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def _load_dotenv() -> None:
    env_file = os.environ.get("MM_ENV_FILE", str(ROOT / ".env"))
    p = Path(env_file)
    if not p.is_file():
        return
    for raw in p.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, val = line.partition("=")
        key = key.strip()
        val = val.strip().strip('"').strip("'")
        os.environ.setdefault(key, val)


_load_dotenv()


def _s(key: str, default: str) -> str:
    return os.environ.get(key, default)


def _i(key: str, default: int) -> int:
    try:
        return int(os.environ.get(key, default))
    except (TypeError, ValueError):
        return default


def _f(key: str, default: float) -> float:
    try:
        return float(os.environ.get(key, default))
    except (TypeError, ValueError):
        return default


def normalize_base_url(url: str) -> str:
    """Entfernt trailing slashes, ergaenzt Schema. Fix fuer kaputte URLs."""
    url = (url or "").strip()
    if not url:
        return ""
    if "://" not in url:
        url = "http://" + url
    return url.rstrip("/")


# --- Netzwerk ---------------------------------------------------------------
HOST = _s("MM_HOST", "0.0.0.0")
PORT = _i("MM_PORT", 8770)

# Oeffentliche Basis-URL, unter der Dashboard + API erreichbar sind.
PUBLIC_URL = normalize_base_url(_s("MM_PUBLIC_URL", f"http://127.0.0.1:{PORT}"))
API_PREFIX = "/api/v1"
API_URL = PUBLIC_URL + API_PREFIX

# --- Auth (Testbetrieb: bewusst statisches Token) ---------------------------
API_TOKEN = _s("MM_API_TOKEN", "mac-monitor-test-token")
# Dashboard/Read-Zugriff ohne Token erlauben (Testsystem).
ALLOW_ANONYMOUS_READ = _s("MM_ALLOW_ANONYMOUS_READ", "1") == "1"

# --- Datenhaltung -----------------------------------------------------------
DB_PATH = _s("MM_DB_PATH", str(ROOT / "data" / "mac-monitor.sqlite3"))
RETENTION_RAW_DAYS = _i("MM_RETENTION_RAW_DAYS", 14)
RETENTION_TPS_DAYS = _i("MM_RETENTION_TPS_DAYS", 30)
RETENTION_EVENT_DAYS = _i("MM_RETENTION_EVENT_DAYS", 30)

# --- Scheduler (Option C) ---------------------------------------------------
SAMPLE_INTERVAL = _i("MM_SAMPLE_INTERVAL", 10)
# Maschine gilt als offline, wenn laenger als X Sekunden kein Sample kam.
OFFLINE_AFTER = _i("MM_OFFLINE_AFTER", 45)
# Reservierung ohne start() verfaellt nach X Sekunden.
RESERVATION_TTL = _i("MM_RESERVATION_TTL", 120)
# Laufender Job ohne Heartbeat verfaellt nach X Sekunden.
HEARTBEAT_TIMEOUT = _i("MM_HEARTBEAT_TIMEOUT", 90)
# Sicherheitsreserve, die nie verplant wird.
RAM_HEADROOM_GB = _f("MM_RAM_HEADROOM_GB", 2.0)
VRAM_HEADROOM_GB = _f("MM_VRAM_HEADROOM_GB", 0.5)
# Gewichte des Capacity-Scores (Summe wird normalisiert).
W_VRAM = _f("MM_W_VRAM", 28.0)
W_RAM = _f("MM_W_RAM", 22.0)
W_GPU_IDLE = _f("MM_W_GPU_IDLE", 14.0)
W_CPU_IDLE = _f("MM_W_CPU_IDLE", 8.0)
W_TPS = _f("MM_W_TPS", 16.0)
# Bereits geladenes Modell spart den Kaltstart (bei 14B leicht 20-60 s).
W_MODEL_LOADED = _f("MM_W_MODEL_LOADED", 12.0)
# Liegt fuer das angefragte Modell keine Messung vor, wird die allgemeine
# TPS-Historie der Maschine nur abgewertet verwendet (sie stammt von einem
# anderen Modell und ueberschaetzt die Leistung sonst).
TPS_FALLBACK_DISCOUNT = _f("MM_TPS_FALLBACK_DISCOUNT", 0.6)
MACHINES_FILE = _s("MM_MACHINES_FILE", str(ROOT / "config" / "machines.json"))
