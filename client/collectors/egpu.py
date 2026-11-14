"""eGPU (NVIDIA RTX 4070 SUPER via nvidia-smi) + Gaming-PC-Strom via Home Assistant.

Beide Quellen sind optional und liefern None/None-Felder statt zu fehlschlagen
(kein eingefrorener Altwert, kein Abbruch des Agenten).
"""
from __future__ import annotations

import json
import shutil
import urllib.error
import urllib.request

from .system import run

# Home Assistant (Gaming-PC Steckdosen-Sensor)
HA_BASE_URL = "http://192.168.178.185:8123"
HA_TOKEN_FILE = "/home/sascha/.openclaw/ha-token.key"
HA_GAMING_PC_ENTITY = "sensor.gaming_pc_leistung"


def egpu() -> dict | None:
    """nvidia-smi → egpu_percent, egpu_vram_used_mb, egpu_vram_total_mb, egpu_watt.

    Fehler tolerant: kein nvidia-smi / leere Ausgabe → None.
    """
    if shutil.which("nvidia-smi") is None:
        return None
    out = run(["nvidia-smi",
               "--query-gpu=utilization.gpu,memory.used,memory.total,power.draw",
               "--format=csv,noheader,nounits"], timeout=8.0)
    line = out.strip().splitlines()[0] if out.strip() else ""
    if not line:
        return None
    parts = [p.strip() for p in line.split(",")]

    def f(i: int) -> float | None:
        try:
            return float(parts[i])
        except (IndexError, ValueError):
            return None

    pct = f(0)
    if pct is not None:
        pct = max(0.0, min(100.0, pct))
    return {"egpu_percent": pct,
            "egpu_vram_used_mb": f(1),
            "egpu_vram_total_mb": f(2),
            "egpu_watt": f(3),
            "source": "nvidia-smi"}


def _ha_token() -> str | None:
    """Token nur zur Laufzeit von der Platte lesen — niemals loggen/committen."""
    try:
        with open(HA_TOKEN_FILE, "r", encoding="utf-8") as f:
            tok = f.read().strip()
        return tok or None
    except OSError:
        return None


def gaming_pc_watt() -> float | None:
    """Home Assistant REST: sensor.gaming_pc_leistung → Watt (float) oder None."""
    token = _ha_token()
    if not token:
        return None
    url = f"{HA_BASE_URL}/api/states/{HA_GAMING_PC_ENTITY}"
    req = urllib.request.Request(url, headers={"Authorization": f"Bearer {token}"})
    try:
        with urllib.request.urlopen(req, timeout=3.0) as resp:
            data = json.loads(resp.read().decode("utf-8"))
    except (urllib.error.URLError, OSError, json.JSONDecodeError, ValueError):
        return None
    state = data.get("state") if isinstance(data, dict) else None
    try:
        w = float(state)
    except (TypeError, ValueError):
        return None  # z. B. 'unavailable' / 'unknown'
    return max(0.0, w)
