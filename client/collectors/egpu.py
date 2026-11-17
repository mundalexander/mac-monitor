"""eGPU (NVIDIA RTX 4070 SUPER via nvidia-smi) + Gaming-PC-Strom via Home Assistant.

Beide Quellen sind optional und liefern None/None-Felder statt zu fehlschlagen
(kein eingefrorener Altwert, kein Abbruch des Agenten).
"""
from __future__ import annotations

import json
import shutil
import time
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
               "--query-gpu=utilization.gpu,memory.used,memory.total,power.draw,temperature.gpu",
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
            "egpu_temp": f(4),
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


# TPS-Delta-Bildung: llamacpp:predicted_tokens_seconds / prompt_tokens_seconds
# sind LEBENSDURCHSCHNITTE seit Serverstart — sie zeigen > 0 obwohl idle. Echte
# Momentwerte entstehen aus den Kumulativ-Zählern (tokens_predicted_total,
# prompt_tokens_total) als Delta / Δt zwischen zwei Collector-Ticks.
_TPS_PREV: dict[str, dict] = {}   # url -> {"pt": float, "dt": float, "ts": float}


def _delta_tps(url: str, m: dict) -> tuple[float | None, float | None]:
    """(decode_tps, prompt_tps) aus Zähler-Deltas; ohne zweiten Tick → None.

    Counter-Rücklauf (Server-Neustart) zählt als kein Delta. Wenn nichts
    verarbeitet wird (requests_processing == 0) und kein Delta ansteht, ist
    der ehrliche Wert 0.0 — nicht der Lebensdurchschnitt.
    """
    now = time.time()
    pt = m.get("llamacpp:prompt_tokens_total")
    dt = m.get("llamacpp:tokens_predicted_total")
    busy = m.get("llamacpp:requests_processing")
    prev = _TPS_PREV.get(url)
    decode: float | None = None
    prompt: float | None = None
    if prev and pt is not None and dt is not None:
        span = now - float(prev["ts"])
        if span > 0:
            d_dec = dt - float(prev["dt"])
            d_pro = pt - float(prev["pt"])
            if d_dec >= 0:
                decode = round(d_dec / span, 1)
            if d_pro >= 0:
                prompt = round(d_pro / span, 1)
    if pt is not None and dt is not None:
        _TPS_PREV[url] = {"pt": pt, "dt": dt, "ts": now}
    if busy == 0:
        if decode is None or decode <= 0:
            decode = 0.0
        if prompt is None or prompt <= 0:
            prompt = 0.0
    return decode, prompt


def nvidia_engine() -> dict:
    """KV-/Slot-KPIs des llama-server auf der NVIDIA (Port 8750).

    Fehler tolerant: Server weg → nur alive=False-Felder (kein Altwert-Einfrieren).
    kv_pct = n_tokens_max / (n_ctx × Slots).
    decode/prompt-TPS als Delta aus Kumulativ-Zählern (siehe _delta_tps).
    """
    out = {"nvidia_alive": 0, "nvidia_kv_pct": None, "nvidia_kv_tokens": None,
           "nvidia_decode_tps": None, "nvidia_requests_busy": None,
           "nvidia_prompt_tps": None, "nvidia_draft_acc": None,
           "nvidia_ctx_total": None}
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
            out["nvidia_ctx_total"] = cap or None
            if cap > 0:
                out["nvidia_kv_pct"] = round(min(100.0, ntok / cap * 100), 1)
        except (urllib.error.URLError, OSError, ValueError, IndexError, TypeError):
            pass
    decode, prompt = _delta_tps(LLAMA_NVIDIA_URL, m)
    # Delta braucht zwei Ticks; bis dahin bzw. ohne Zähler auf die (veralteten)
    # Lebensdurchschnitt-Gauges zurückfallen, damit das Feld nie grundlos fehlt.
    if decode is None and "llamacpp:predicted_tokens_seconds" in m:
        decode = round(m["llamacpp:predicted_tokens_seconds"], 1)
    if prompt is None and "llamacpp:prompt_tokens_seconds" in m:
        prompt = round(m["llamacpp:prompt_tokens_seconds"], 1)
    out["nvidia_decode_tps"] = decode
    out["nvidia_prompt_tps"] = prompt
    if "llamacpp:requests_processing" in m:
        out["nvidia_requests_busy"] = int(m["llamacpp:requests_processing"])
        if out["nvidia_requests_busy"] == 0:
            # Nichts wird verarbeitet → TPS sind ehrlich 0, nie Lebensdurchschnitt.
            out["nvidia_decode_tps"] = 0.0
            out["nvidia_prompt_tps"] = 0.0
    # MTP-Entscheidungs-Gesundheit: Akzeptanz als Lebens Verhältnis (kumulativ
    # seit Serverstart). Sinkt der Wert deutlich, stimmt etwas mit Sidecar oder
    # Prompt-Mix nicht. Ohne Speculative-Decoding bleibt das Feld None.
    drafted = m.get("llamacpp:spec_decode_num_draft_tokens_total")
    accepted = m.get("llamacpp:spec_decode_num_accepted_tokens_total")
    if drafted and accepted is not None and drafted > 0:
        out["nvidia_draft_acc"] = round(accepted / drafted * 100.0, 1)
    return out
