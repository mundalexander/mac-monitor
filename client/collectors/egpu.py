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


# llama.cpp llama-server auf der eGPU (llama-nvidia.service)
LLAMA_NVIDIA_URL = "http://127.0.0.1:8750"


def _prom_metrics(url: str) -> dict:
    """GET <url>/metrics (Prometheus-Text) → {name: float}; Fehler → {}."""
    try:
        with urllib.request.urlopen(url + "/metrics", timeout=3.0) as resp:
            text = resp.read().decode("utf-8", errors="replace")
    except (urllib.error.URLError, OSError):
        return {}
    out = {}
    for line in text.splitlines():
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if len(parts) >= 2:
            try:
                out[parts[0]] = float(parts[1])
            except ValueError:
                pass
    return out


def nvidia_engine() -> dict:
    """KV-/Slot-KPIs des llama-server auf der NVIDIA (Port 8750).

    Fehler tolerant: Server weg → nur alive=False-Felder (kein Altwert-Einfrieren).
    kv_pct = kv_cache_usage_ratio × 100 (Positionen im 8k-Pool).
    """
    out = {"nvidia_alive": 0, "nvidia_kv_pct": None, "nvidia_kv_tokens": None,
           "nvidia_decode_tps": None, "nvidia_requests_busy": None,
           "nvidia_prompt_tps": None}
    m = _prom_metrics(LLAMA_NVIDIA_URL)
    if not m:
        return out
    out["nvidia_alive"] = 1
    # b12387 hat keinen usage_ratio-Gauge — n_tokens_max (belegte Positionen
    # über alle Slots) durch Slot-Kapazität n_ctx × Slots ist der ehrliche Proxy.
    ntok = m.get("llamacpp:n_tokens_max")
    if ntok is not None:
        out["nvidia_kv_tokens"] = int(ntok)
        try:
            with urllib.request.urlopen(LLAMA_NVIDIA_URL + "/slots", timeout=3.0) as r:
                slots = json.loads(r.read().decode("utf-8", errors="replace"))
            n_ctx = int(slots[0].get("n_ctx", 0)) if slots else 0
            cap = n_ctx * max(1, len(slots))
            if cap > 0:
                out["nvidia_kv_pct"] = round(min(100.0, ntok / cap * 100), 1)
        except (urllib.error.URLError, OSError, ValueError, IndexError, TypeError):
            pass
    if "llamacpp:predicted_tokens_seconds" in m:
        out["nvidia_decode_tps"] = round(m["llamacpp:predicted_tokens_seconds"], 1)
    if "llamacpp:prompt_tokens_seconds" in m:
        out["nvidia_prompt_tps"] = round(m["llamacpp:prompt_tokens_seconds"], 1)
    if "llamacpp:requests_processing" in m:
        out["nvidia_requests_busy"] = int(m["llamacpp:requests_processing"])
    return out
