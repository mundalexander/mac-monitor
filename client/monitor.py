#!/usr/bin/env python3
"""mac-monitor Agent - einheitlich fuer macOS und Linux.

Start:
    python3 client/monitor.py --config client/config.json
    python3 client/monitor.py --once --dry-run      # Testlauf ohne Server
    python3 client/monitor.py --dry-run --print-payload  # Payload-Check

Der Agent laeuft weiter, auch wenn Ollama, LM Studio, GPU-Tools, Shelly oder der
Server nicht erreichbar sind. Messwerte werden lokal gepuffert.

Zielarchitektur: Ein Client auf allen Plattformen (Linux/macOS, AMD/NVIDIA/Apple).
Die Collector-Registry entscheidet plattformneutral welche Quellen aktiv sind.
"""
from __future__ import annotations

import argparse
import json
import os
import platform
import random
import socket
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from collectors import gpu, lm_studio, ollama, power, system, temps, safe  # noqa: E402
from backends import GufoBackend, HalogenBackend  # noqa: E402
from spool import Spool  # noqa: E402

AGENT_VERSION = "1.1.0"
DEFAULT_CONFIG = Path(__file__).resolve().parent / "config.json"

# TPS-Probe-Intervall und TTL (portiert aus monitor_linux.py)
PROBE_INTERVAL = 90   # Sekunden zwischen token/s Proben
TPS_TTL = 240         # Wert gilt nach ... s als veraltet


# --------------------------------------------------------------------------
# Konfiguration
# --------------------------------------------------------------------------
def normalize_url(url: str) -> str:
    url = (url or "").strip()
    if not url:
        return ""
    if "://" not in url:
        url = "http://" + url
    return url.rstrip("/")


def default_machine_id() -> str:
    raw = socket.gethostname().split(".")[0].strip().lower()
    return "".join(c if c.isalnum() or c in "-_" else "-" for c in raw) or "unknown-host"


def load_config(path: Path) -> dict:
    cfg = {
        # bplaced legacy server (submit/data/commands/requests PHP endpoints)
        "server_url": "https://mund.bplaced.net/mac-monitor",
        "api_token": "",
        "machine_id": default_machine_id(),
        "machine_name": socket.gethostname(),
        "ollama_url": "http://127.0.0.1:11434",
        "lm_studio_url": "http://127.0.0.1:1234",
        "halogen_url": "http://127.0.0.1:8731",
        "gufo_url": "http://127.0.0.1:8081",
        "kv_alarm_threshold": 90.0,
        "backend_url": "",
        "shelly_url": "",
        "shelly_channel": 0,
        "interval_seconds": 10,
        "max_slots": 2,
        "labels": [],
        "spool_path": "~/.mac-monitor/spool.jsonl",
        "spool_max_items": 2000,
        "verify_tls": True,
        "timeout_seconds": 8,
        # Legacy PHP server endpoints (computed from server_url if absent)
        "submit_url": "",
        "requests_url": "",
        "commands_url": "",
    }
    if path and path.is_file():
        try:
            cfg.update(json.loads(path.read_text(encoding="utf-8")))
        except (json.JSONDecodeError, OSError) as exc:
            print(f"[warn] Konfiguration nicht lesbar ({exc}) - nutze Defaults.")

    # ENV ueberschreibt Datei
    env_map = {
        "MM_SERVER_URL": "server_url", "MM_API_TOKEN": "api_token",
        "MM_MACHINE_ID": "machine_id", "MM_MACHINE_NAME": "machine_name",
        "OLLAMA_BASE_URL": "ollama_url", "MM_BACKEND_URL": "backend_url",
        "MM_SHELLY_URL": "shelly_url",
        "MM_LM_STUDIO_URL": "lm_studio_url",
        "MM_HALOGEN_URL": "halogen_url",
        "MM_GUFO_URL": "gufo_url",
        "MM_SUBMIT_URL": "submit_url",
        "MM_REQUESTS_URL": "requests_url",
        "MM_COMMANDS_URL": "commands_url",
    }
    for env, key in env_map.items():
        if os.environ.get(env):
            cfg[key] = os.environ[env]
    if os.environ.get("MM_INTERVAL"):
        try:
            cfg["interval_seconds"] = int(os.environ["MM_INTERVAL"])
        except ValueError:
            pass

    cfg["server_url"] = normalize_url(cfg["server_url"])
    cfg["ollama_url"] = normalize_url(cfg["ollama_url"])
    cfg["lm_studio_url"] = normalize_url(cfg["lm_studio_url"])
    cfg["halogen_url"] = normalize_url(cfg["halogen_url"])
    cfg["gufo_url"] = normalize_url(cfg["gufo_url"])
    cfg["backend_url"] = normalize_url(cfg["backend_url"]) or cfg["ollama_url"]
    cfg["interval_seconds"] = max(2, int(cfg["interval_seconds"]))

    # Legacy PHP endpoints: compute from server_url if not explicitly set
    base = cfg["server_url"]
    if not cfg.get("submit_url"):
        cfg["submit_url"] = base + "/submit.php"
    if not cfg.get("requests_url"):
        cfg["requests_url"] = base + "/requests.php"
    if not cfg.get("commands_url"):
        cfg["commands_url"] = base + "/commands.php"

    # api_url for the new-style REST API (kept for forward compat)
    cfg["api_url"] = base + "/api/v1"
    return cfg


# --------------------------------------------------------------------------
# HTTP helpers
# --------------------------------------------------------------------------
def post_json(url: str, payload: dict, token: str, timeout: float = 8.0) -> tuple[int, dict]:
    body = json.dumps(payload).encode("utf-8")
    headers = {"Content-Type": "application/json",
               "User-Agent": f"mac-monitor-agent/{AGENT_VERSION}"}
    if token:
        headers["X-API-Token"] = token
    req = urllib.request.Request(url, data=body, method="POST", headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8")
            return resp.status, (json.loads(raw) if raw.strip() else {})
    except urllib.error.HTTPError as exc:
        try:
            return exc.code, json.loads(exc.read().decode("utf-8"))
        except Exception:  # noqa: BLE001
            return exc.code, {}
    except (urllib.error.URLError, OSError, json.JSONDecodeError, socket.timeout) as exc:
        return 0, {"error": str(exc)}


def get_json(url: str, timeout: float = 8.0) -> tuple[int, dict]:
    req = urllib.request.Request(url, headers={
        "Accept": "application/json",
        "User-Agent": f"mac-monitor-agent/{AGENT_VERSION}",
    })
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8")
            return resp.status, (json.loads(raw) if raw.strip() else {})
    except urllib.error.HTTPError as exc:
        try:
            return exc.code, json.loads(exc.read().decode("utf-8"))
        except Exception:
            return exc.code, {}
    except (urllib.error.URLError, OSError, json.JSONDecodeError, socket.timeout):
        return 0, {}


# --------------------------------------------------------------------------
# State persistence (for TPS probe timing and request count deltas)
# --------------------------------------------------------------------------
STATE_DIR = os.path.expanduser("~/.local/share/mac-monitor")
STATE_FILE = os.path.join(STATE_DIR, "monitor_state.json")


def load_state() -> dict:
    try:
        with open(STATE_FILE) as f:
            return json.load(f)
    except Exception:
        return {}


def save_state(state: dict) -> None:
    try:
        os.makedirs(STATE_DIR, exist_ok=True)
        with open(STATE_FILE, "w") as f:
            json.dump(state, f)
    except Exception as exc:
        print(f"[warn] State save error: {exc}")


# --------------------------------------------------------------------------
# Telegram-Alarm (KV-Pool) — portiert aus monitor_linux.py
# --------------------------------------------------------------------------
TELEGRAM_CONFIG = os.path.expanduser("~/.config/mac-monitor/telegram.json")


def send_telegram(text: str) -> bool:
    """Telegram-Message via Bot API; Fehler nie fatal fuer den Agenten."""
    try:
        with open(TELEGRAM_CONFIG) as f:
            cfg = json.load(f)
        body = json.dumps({"chat_id": cfg["chat_id"], "text": text}).encode()
        req = urllib.request.Request(
            f"https://api.telegram.org/bot{cfg['bot_token']}/sendMessage",
            data=body,
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        urllib.request.urlopen(req, timeout=10).read()
        return True
    except Exception as exc:  # noqa: BLE001 - Alarm darf nie den Client killen
        print(f"[warn] Telegram send error: {exc}")
        return False


# --------------------------------------------------------------------------
# Agent
# --------------------------------------------------------------------------
class Agent:
    def __init__(self, cfg: dict) -> None:
        self.cfg = cfg
        self.tps = ollama.TPSTracker()
        self.spool = Spool(cfg["spool_path"], int(cfg["spool_max_items"]))
        self.backoff = 0.0
        self.static = self._static_info()
        self._probe_call: dict | None = None  # pending TPS probe call record
        # KV-Pool Backends (Gufo hat Vorrang, Halogen als Fallback)
        self._gufo = GufoBackend(cfg.get("gufo_url") or "http://127.0.0.1:8081")
        self._halogen = HalogenBackend(cfg.get("halogen_url") or "http://127.0.0.1:8731")

    def _static_info(self) -> dict:
        _, ram_total = safe(system.memory) or (0.0, 0.0)
        return {
            "id": str(self.cfg["machine_id"])[:64],
            "name": str(self.cfg["machine_name"])[:120],
            "platform": platform.system().lower(),
            "backend_url": self.cfg["backend_url"],
            "labels": list(self.cfg.get("labels") or []),
            "cpu_model": safe(system.cpu_model) or "",
            "gpu_model": safe(gpu.gpu_model) or "",
            "ram_total_gb": round(ram_total, 2),
            "max_slots": int(self.cfg.get("max_slots") or 1),
            "agent_version": AGENT_VERSION,
        }

    # -- TPS Probing -------------------------------------------------------
    def _probe_tps(self) -> None:
        """LM Studio + Ollama TPS-Probe, alle PROBE_INTERVAL Sekunden.

        Portiert aus monitor_linux.py. Werte veralten nach TPS_TTL.
        Schreibt das probe_call_record fuer das Request-Logging.
        """
        state = load_state()
        now = time.time()
        if now - float(state.get("last_probe_ts", 0)) < PROBE_INTERVAL:
            return

        state["last_probe_ts"] = int(now)

        # LM Studio TPS probe
        lm_tps, lm_call = safe(lm_studio.probe_tps, self.cfg["lm_studio_url"]) or (None, None)
        if lm_tps is not None:
            state["last_lm_studio_tps"] = lm_tps
            state["last_lm_studio_tps_ts"] = int(now)
        if lm_call:
            self._probe_call = lm_call

        # Ollama TPS probe
        ollama_tps = safe(self._probe_ollama_tps)
        if ollama_tps is not None:
            state["last_ollama_tps"] = ollama_tps
            state["last_ollama_tps_ts"] = int(now)

        save_state(state)

    def _probe_ollama_tps(self) -> float | None:
        """Probe Ollama generation speed — nur wenn ein Modell geladen ist."""
        try:
            ps_data = safe(ollama._get, self.cfg["ollama_url"], "/api/ps") or {}
            models = ps_data.get("models", [])
            if not models:
                return None
            model = models[0].get("name") or models[0].get("model") or ""
            if not model:
                return None
            body = json.dumps({
                "model": model,
                "prompt": "Count from 1 to 10, separated by commas.",
                "stream": False,
                "options": {"num_predict": 32},
            }).encode("utf-8")
            req = urllib.request.Request(
                self.cfg["ollama_url"].rstrip("/") + "/api/generate",
                data=body,
                headers={"Content-Type": "application/json"},
                method="POST",
            )
            with urllib.request.urlopen(req, timeout=60) as resp:
                data = json.loads(resp.read().decode())
            eval_count = data.get("eval_count", 0)
            eval_duration = data.get("eval_duration", 0)
            if eval_count and eval_duration:
                return round(eval_count / (eval_duration / 1e9), 1)
        except Exception:
            return None
        return None

    def _fresh_tps(self, state: dict, key: str) -> float | None:
        """Liefert TPS-Wert wenn noch frisch, sonst None."""
        val = state.get(key)
        ts = float(state.get(key + "_ts", 0))
        if val is not None and time.time() - ts <= TPS_TTL:
            return val
        return None

    # -- Request count deltas ---------------------------------------------
    def _request_deltas(self, oll_snap: dict, lms_snap: dict) -> dict:
        """Berechnet Delta-Werte fuer ollama_req_count/dur_ms und lms_req_count/dur_ms.

        Vergleicht kumulativzaehler mit dem letzten Zyklus.
        """
        state = load_state()

        # Ollama: total requests/duration from /api/ps
        ollama_total_req = 0
        ollama_total_dur_ms = 0
        try:
            for m in (oll_snap.get("loaded_detail") or []):
                # ollama collector doesn't have req counts; use /api/ps directly
                pass
        except Exception:
            pass

        # Fetch Ollama /api/ps for request counts (cumulative)
        ollama_req_count = 0
        ollama_req_dur_ms = 0
        try:
            ps_data = safe(ollama._get, self.cfg["ollama_url"], "/api/ps") or {}
            for m in ps_data.get("models", []):
                ollama_req_count += int(m.get("total_requests", 0) or 0)
                ollama_req_dur_ms += round((m.get("total_duration", 0) or 0) / 1_000_000)
        except Exception:
            pass

        # LM Studio: total from snapshot
        lms_total_req = lms_snap.get("req_count", 0)
        lms_total_dur_ms = lms_snap.get("req_dur_ms", 0)

        # Deltas
        prev_ollama_req = state.get("prev_ollama_req_count", 0)
        prev_ollama_dur = state.get("prev_ollama_req_dur_ms", 0)
        prev_lms_req = state.get("prev_lms_req_count", 0)
        prev_lms_dur = state.get("prev_lms_req_dur_ms", 0)

        if ollama_req_count >= prev_ollama_req:
            delta_ollama_req = ollama_req_count - prev_ollama_req
            delta_ollama_dur = max(0, ollama_req_dur_ms - prev_ollama_dur)
        else:
            delta_ollama_req = 0
            delta_ollama_dur = 0

        if lms_total_req >= prev_lms_req:
            delta_lms_req = lms_total_req - prev_lms_req
            delta_lms_dur = max(0, lms_total_dur_ms - prev_lms_dur)
        else:
            delta_lms_req = 0
            delta_lms_dur = 0

        # Save current as previous for next cycle
        state["prev_ollama_req_count"] = ollama_req_count
        state["prev_ollama_req_dur_ms"] = ollama_req_dur_ms
        state["prev_lms_req_count"] = lms_total_req
        state["prev_lms_req_dur_ms"] = lms_total_dur_ms
        save_state(state)

        return {
            "ollama_req_count": delta_ollama_req,
            "ollama_req_dur_ms": delta_ollama_dur,
            "lms_req_count": delta_lms_req,
            "lms_req_dur_ms": delta_lms_dur,
        }

    # -- Request Logging --------------------------------------------------
    def _send_request_log(self, call: dict) -> None:
        """Sendet ein API-Call-Record an requests.php."""
        if not call:
            return
        payload = {
            "token": self.cfg.get("api_token", ""),
            "calls": [call],
        }
        try:
            post_json(self.cfg["requests_url"], payload,
                      "", float(self.cfg["timeout_seconds"]))
        except Exception:
            pass  # Request-Logging darf nicht crashen

    # -- Command Polling --------------------------------------------------
    def _poll_and_execute_commands(self) -> None:
        """Holt Pending Commands von commands.php und fuehrt sie aus.

        Portiert aus monitor_linux.py. Unterstuetzt:
          - lm_studio_unload: entlaedt LM Studio Modelle per lms CLI
          - ollama_unload: entlaedt Ollama Modelle per API
        """
        try:
            code, data = get_json(self.cfg["commands_url"],
                                  float(self.cfg["timeout_seconds"]))
            if code != 200 or not isinstance(data, dict):
                return
            for cmd in data.get("commands", []):
                cmd_server = cmd.get("server_id")
                if cmd_server and cmd_server != self.cfg["machine_id"]:
                    continue
                cmd_id = cmd.get("id")
                action = cmd.get("action")
                success = False

                if action == "lm_studio_unload":
                    model_id = cmd.get("model_id")
                    if model_id:
                        success = safe(lm_studio.unload_model, model_id) or False
                    else:
                        # Unload all loaded models
                        loaded = safe(lm_studio.loaded_models, self.cfg["lm_studio_url"]) or []
                        success = bool(loaded)
                        for m in loaded:
                            mid = m.get("name", "")
                            if mid and not (safe(lm_studio.unload_model, mid) or False):
                                success = False

                elif action == "ollama_unload":
                    try:
                        ps_data = safe(ollama._get, self.cfg["ollama_url"], "/api/ps") or {}
                        success = True
                        for m in ps_data.get("models", []):
                            mname = m.get("name") or m.get("model") or ""
                            if not mname:
                                continue
                            body = json.dumps({"model": mname, "keep_alive": 0}).encode("utf-8")
                            req = urllib.request.Request(
                                self.cfg["ollama_url"].rstrip("/") + "/api/generate",
                                data=body,
                                headers={"Content-Type": "application/json"},
                                method="POST",
                            )
                            try:
                                with urllib.request.urlopen(req, timeout=10) as r2:
                                    r2.read()
                            except Exception:
                                success = False
                    except Exception:
                        success = False
                else:
                    continue

                self._mark_command_done(cmd_id, success)
                print(f"[info] Command executed: {action} id={cmd_id} success={success}")

        except Exception as exc:
            print(f"[warn] Command poll error: {exc}")

    def _mark_command_done(self, cmd_id, success: bool) -> None:
        """Command auf dem Server als erledigt markieren."""
        payload = {
            "token": self.cfg.get("api_token", ""),
            "id": cmd_id,
            "done": True,
            "success": bool(success),
        }
        try:
            post_json(self.cfg["commands_url"], payload,
                      "", float(self.cfg["timeout_seconds"]))
        except Exception:
            pass

    # -- Build legacy payload for PHP server -------------------------------
    def _kv_pool(self) -> tuple[dict | None, str | None]:
        """KV-Pool-Fuellstand. Fallback-Kette: Gufo -> Halogen.

        Liefert ({'used', 'total', 'pct'}, source) oder (None, None).
        """
        for name, backend in (("gufo", self._gufo), ("halogen", self._halogen)):
            try:
                kv = backend.get_kv_pool()
            except Exception:  # noqa: BLE001
                kv = None
            if kv:
                return kv, name
        return None, None

    def _build_legacy_payload(self, sample: dict, oll_snap: dict, lms_snap: dict,
                              tps_deltas: dict, lm_studio_tps: float | None,
                              ollama_tps: float | None,
                              kv_pool: dict | None = None) -> dict:
        """Baut den Payload fuer das PHP submit.php Endpoint.

        Das Dashboard (data.php) konsumiert diese Felder direkt.
        """
        # Merge loaded models from ollama + lm_studio
        loaded_models = []
        for m in (oll_snap.get("loaded_detail") or []):
            loaded_models.append({
                "name": m.get("name", ""),
                "size_vram_gb": m.get("vram_gb", 0),
                "server": "ollama",
            })
        for m in (lms_snap.get("loaded") or []):
            loaded_models.append({
                "name": m.get("name", ""),
                "server": "lm-studio",
                "req_count": m.get("req_count", 0),
                "req_dur_ms": m.get("req_dur_ms", 0),
            })

        # Available models (catalog from both sources)
        available_models = []
        for m in (oll_snap.get("loaded_models") or []):
            # Ollama installed but not loaded — from installed list minus loaded
            pass  # ollama collector doesn't expose this separately
        for m in (lms_snap.get("catalog") or []):
            if not m.get("loaded"):
                available_models.append({"name": m.get("name", ""), "server": "lm-studio"})

        ollama_dict = {
            "loaded": loaded_models,
            "available": available_models,
            "error": None if oll_snap.get("reachable") else "ollama_offline",
        }

        return {
            "token": self.cfg.get("api_token", ""),
            "host": self.cfg.get("machine_name", socket.gethostname()),
            "server_id": self.cfg.get("machine_id", default_machine_id()),
            "ts": int(time.time()),
            "cpu": sample.get("cpu_pct", 0.0),
            "gpu": sample.get("gpu_pct", 0.0),
            "gpu_temp": sample.get("temp_c"),
            "tokens_per_second": ollama_tps,       # Ollama TPS (legacy field name)
            "lm_studio_tps": lm_studio_tps,
            "ram_percent": round((sample.get("ram_used_gb", 0) / max(sample.get("ram_total_gb", 1), 0.001)) * 100, 1) if sample.get("ram_total_gb") else 0.0,
            "ram_used_gb": sample.get("ram_used_gb", 0.0),
            "ram_total_gb": sample.get("ram_total_gb", 0.0),
            "vram_used_gb": sample.get("vram_used_gb", 0.0),
            "vram_total_gb": sample.get("vram_total_gb", 0.0),
            "ollama": ollama_dict,
            "shelly_power": sample.get("power_w"),
            "ollama_req_count": tps_deltas.get("ollama_req_count", 0),
            "ollama_req_dur_ms": tps_deltas.get("ollama_req_dur_ms", 0),
            "lms_req_count": tps_deltas.get("lms_req_count", 0),
            "lms_req_dur_ms": tps_deltas.get("lms_req_dur_ms", 0),
            # KV-Pool (Dashboard-Gauge + 90% Alarm), Feldnamen wie submit.php
            "halogen_kv_pool_used": kv_pool["used"] if kv_pool else None,
            "halogen_kv_pool_total": kv_pool["total"] if kv_pool else None,
            "halogen_kv_pool_pct": kv_pool["pct"] if kv_pool else None,
        }

    def collect(self) -> dict:
        """Sammelt alle Messwerte (modular + legacy Felder)."""
        sysinfo = safe(system.snapshot) or {}
        ram_total = sysinfo.get("ram_total_gb", 0.0)
        g = safe(gpu.snapshot, ram_total) or {}
        oll = safe(ollama.snapshot, self.cfg["ollama_url"]) or {}
        lms = safe(lm_studio.snapshot, self.cfg["lm_studio_url"]) or {}
        t = safe(self.tps.stats) or {}
        temp = safe(temps.cpu_temperature)
        watt = safe(power.shelly_power, self.cfg.get("shelly_url", ""),
                    int(self.cfg.get("shelly_channel") or 0))

        vram_total = g.get("vram_total_gb")
        vram_used = g.get("vram_used_gb")
        # Apple Silicon / iGPU ohne eigene VRAM-Meldung: Ollama kennt die Belegung.
        if vram_used is None and oll.get("vram_models_gb"):
            vram_used = oll["vram_models_gb"]

        # TPS probing (LM Studio + Ollama)
        self._probe_tps()
        state = load_state()
        lm_studio_tps = self._fresh_tps(state, "last_lm_studio_tps")
        ollama_tps = self._fresh_tps(state, "last_ollama_tps")

        # Request count deltas
        deltas = self._request_deltas(oll, lms)

        # KV-Pool-Fuellstand (Gufo -> Halogen) + Edge-triggered Alarm
        kv_pool, kv_source = safe(self._kv_pool) or (None, None)
        kv_pct = float(kv_pool["pct"]) if kv_pool else 0.0
        threshold = float(self.cfg.get("kv_alarm_threshold", 90.0))
        kv_was_high = bool(state.get("kv_pool_high", False))
        if kv_pool and kv_pct > threshold and not kv_was_high:
            state["kv_pool_high"] = True
            state["kv_pool_high_since"] = int(time.time())
            send_telegram(
                f"\u26a0\ufe0f KV-Pool {kv_pct}% ({kv_source}) \u2014 "
                f"{kv_pool['used']}/{kv_pool['total']} Positionen \u2014 "
                f"Engine verschiebt Regionen, API-Slowdown droht."
            )
            print(f"[alarm] KV-Pool high {kv_pct}% ({kv_source})")
        elif kv_pool and kv_pct <= threshold and kv_was_high:
            state["kv_pool_high"] = False
            state["kv_pool_high_since"] = 0
            print(f"[info] KV-Pool recovered: {kv_pct}% ({kv_source})")
        save_state(state)

        # Send pending probe call record to requests.php
        if self._probe_call:
            self._send_request_log(self._probe_call)
            self._probe_call = None

        sample = {
            "ts": int(time.time()),
            "cpu_pct": sysinfo.get("cpu_pct", 0.0),
            "load1": sysinfo.get("load1", 0.0),
            "ram_used_gb": sysinfo.get("ram_used_gb", 0.0),
            "ram_total_gb": ram_total,
            "gpu_pct": g.get("gpu_pct", 0.0),
            "vram_used_gb": vram_used or 0.0,
            "vram_total_gb": vram_total or 0.0,
            "temp_c": temp if temp is not None else None,
            "power_w": watt if watt is not None else None,
            "loaded_models": oll.get("loaded_models", []),
            "tps_current": t.get("tps_current", 0.0),
            "tps_avg": t.get("tps_avg", 0.0),
            "tps_peak": t.get("tps_peak", 0.0),
            "active_jobs": t.get("active_jobs", 0),
            # Additional fields for dashboard
            "lm_studio_tps": lm_studio_tps,
            "ollama_tps": ollama_tps,
            "ollama_req_count": deltas.get("ollama_req_count", 0),
            "ollama_req_dur_ms": deltas.get("ollama_req_dur_ms", 0),
            "lms_req_count": deltas.get("lms_req_count", 0),
            "lms_req_dur_ms": deltas.get("lms_req_dur_ms", 0),
            "kv_pool_pct": kv_pct,
            "kv_pool_used": kv_pool["used"] if kv_pool else None,
            "kv_pool_total": kv_pool["total"] if kv_pool else None,
            "kv_source": kv_source,
        }
        machine = dict(self.static)
        if vram_total:
            machine["vram_total_gb"] = vram_total

        # Build legacy payload for PHP submit.php
        legacy_payload = self._build_legacy_payload(
            sample, oll, lms, deltas, lm_studio_tps, ollama_tps, kv_pool
        )

        return {
            "machine": machine,
            "sample": sample,
            "tps_events": safe(self.tps.drain_events) or [],
            "legacy_payload": legacy_payload,
            "_ollama_reachable": bool(oll.get("reachable")),
            "_lms_reachable": bool(lms.get("reachable")),
        }

    def send_legacy(self, payload: dict) -> bool:
        """Sendet den Legacy-Payload an das PHP submit.php Endpoint."""
        code, body = post_json(self.cfg["submit_url"], payload,
                               "", float(self.cfg["timeout_seconds"]))
        if 200 <= code < 300:
            return True
        if code == 401:
            print("[error] Token abgelehnt (HTTP 401). api_token pruefen.")
        elif code == 0:
            print(f"[warn] Server nicht erreichbar: {body.get('error', '')}")
        else:
            print(f"[warn] Unerwartete Antwort HTTP {code}: {body}")
        return False

    def send(self, payload: dict) -> bool:
        """Sendet den modularen Payload an die REST API (falls verfuegbar)."""
        payload = {k: v for k, v in payload.items() if not k.startswith("_")}
        code, body = post_json(self.cfg["api_url"] + "/ingest", payload,
                               self.cfg.get("api_token", ""),
                               float(self.cfg["timeout_seconds"]))
        if 200 <= code < 300:
            return True
        return False

    def flush_spool(self) -> None:
        pending = self.spool.peek_all()
        if not pending:
            return
        sent = 0
        for item in pending[:20]:
            if not self.send_legacy(item):
                break
            sent += 1
        if sent:
            self.spool.remove_first(sent)
            print(f"[info] {sent} gepufferte Messung(en) nachgesendet "
                  f"({len(self.spool)} verbleiben).")

    def tick(self, dry_run: bool = False) -> dict:
        payload = self.collect()
        s = payload["sample"]
        lms_loaded = len([m for m in (payload.get("legacy_payload", {}).get("ollama", {}).get("loaded", [])) if m.get("server") == "lm-studio"])
        line = (f"cpu {s['cpu_pct']:5.1f}%  gpu {s['gpu_pct']:5.1f}%  "
                f"ram {s['ram_used_gb']:6.2f}/{s['ram_total_gb']:.2f} GB  "
                f"ollama_tps {s.get('ollama_tps') or 0:6.2f}  "
                f"lms_tps {s.get('lm_studio_tps') or 0:6.2f}  "
                f"models {len(s['loaded_models'])}+{lms_loaded}  "
                f"ollama {'ok' if payload['_ollama_reachable'] else '--'}  "
                f"lms {'ok' if payload['_lms_reachable'] else '--'}")
        print(time.strftime("%H:%M:%S ") + line)

        if dry_run:
            return payload

        # Send to legacy PHP server
        legacy = payload.get("legacy_payload")
        if legacy:
            if self.send_legacy(legacy):
                self.backoff = 0.0
                self.flush_spool()
            else:
                self.spool.add(legacy)
                self.backoff = min(300.0, (self.backoff or float(self.cfg["interval_seconds"])) * 2)
                jitter = random.uniform(0, self.backoff * 0.2)
                wait = self.backoff + jitter
                print(f"[info] Messung gepuffert ({len(self.spool)} in Warteschlange), "
                      f"naechster Versuch in {wait:.0f}s.")
                time.sleep(wait)

        # Poll for pending commands
        self._poll_and_execute_commands()

        return payload

    def run(self, once: bool = False, dry_run: bool = False) -> None:
        interval = int(self.cfg["interval_seconds"])
        print(f"mac-monitor Agent {AGENT_VERSION}")
        print(f"  Maschine   : {self.static['id']} ({self.static['platform']})")
        print(f"  Submit     : {self.cfg['submit_url']}")
        print(f"  Ollama     : {self.cfg['ollama_url']}")
        print(f"  LM Studio  : {self.cfg['lm_studio_url']}")
        print(f"  Commands   : {self.cfg['commands_url']}")
        print(f"  Intervall  : {interval}s   Slots: {self.static['max_slots']}")
        print("  Beenden mit STRG+C")
        system.cpu_percent()  # Delta-Basis initialisieren
        time.sleep(0.4)
        while True:
            start = time.time()
            try:
                self.tick(dry_run)
            except KeyboardInterrupt:
                raise
            except Exception as exc:  # noqa: BLE001
                print(f"[error] Zyklus fehlgeschlagen: {exc}")
            if once:
                return
            time.sleep(max(0.5, interval - (time.time() - start)))


def main() -> int:
    ap = argparse.ArgumentParser(description="mac-monitor Agent")
    ap.add_argument("--config", default=str(DEFAULT_CONFIG))
    ap.add_argument("--once", action="store_true", help="nur einen Zyklus")
    ap.add_argument("--dry-run", action="store_true", help="nichts senden")
    ap.add_argument("--print-payload", action="store_true")
    args = ap.parse_args()

    cfg = load_config(Path(args.config))
    agent = Agent(cfg)
    try:
        if args.print_payload:
            print(json.dumps(agent.collect(), indent=2, ensure_ascii=False))
            return 0
        agent.run(once=args.once, dry_run=args.dry_run)
    except KeyboardInterrupt:
        print("\nAgent gestoppt.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())