#!/usr/bin/env python3
"""mac-monitor Agent - einheitlich fuer macOS und Linux.

Start:
    python3 client/monitor.py --config client/config.json
    python3 client/monitor.py --once --dry-run      # Testlauf ohne Server

Der Agent laeuft weiter, auch wenn Ollama, GPU-Tools, Shelly oder der
Server nicht erreichbar sind. Messwerte werden lokal gepuffert.
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

from collectors import gpu, ollama, power, system, temps, safe  # noqa: E402
from spool import Spool  # noqa: E402

AGENT_VERSION = "1.0.0"
DEFAULT_CONFIG = Path(__file__).resolve().parent / "config.json"


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
        "server_url": "http://127.0.0.1:8770",
        "api_token": "mac-monitor-test-token",
        "machine_id": default_machine_id(),
        "machine_name": socket.gethostname(),
        "ollama_url": "http://127.0.0.1:11434",
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
    cfg["backend_url"] = normalize_url(cfg["backend_url"]) or cfg["ollama_url"]
    cfg["interval_seconds"] = max(2, int(cfg["interval_seconds"]))
    cfg["api_url"] = cfg["server_url"] + "/api/v1"
    return cfg


# --------------------------------------------------------------------------
# HTTP
# --------------------------------------------------------------------------
def post_json(url: str, payload: dict, token: str, timeout: float = 8.0) -> tuple[int, dict]:
    body = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=body, method="POST", headers={
        "Content-Type": "application/json",
        "X-API-Token": token,
        "User-Agent": f"mac-monitor-agent/{AGENT_VERSION}",
    })
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

    def collect(self) -> dict:
        sysinfo = safe(system.snapshot) or {}
        ram_total = sysinfo.get("ram_total_gb", 0.0)
        g = safe(gpu.snapshot, ram_total) or {}
        oll = safe(ollama.snapshot, self.cfg["ollama_url"]) or {}
        t = safe(self.tps.stats) or {}
        temp = safe(temps.cpu_temperature)
        watt = safe(power.shelly_power, self.cfg.get("shelly_url", ""),
                    int(self.cfg.get("shelly_channel") or 0))

        vram_total = g.get("vram_total_gb")
        vram_used = g.get("vram_used_gb")
        # Apple Silicon / iGPU ohne eigene VRAM-Meldung: Ollama kennt die Belegung.
        if vram_used is None and oll.get("vram_models_gb"):
            vram_used = oll["vram_models_gb"]

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
        }
        machine = dict(self.static)
        if vram_total:
            machine["vram_total_gb"] = vram_total
        return {"machine": machine, "sample": sample,
                "tps_events": safe(self.tps.drain_events) or [],
                "_ollama_reachable": bool(oll.get("reachable"))}

    def send(self, payload: dict) -> bool:
        payload = {k: v for k, v in payload.items() if not k.startswith("_")}
        code, body = post_json(self.cfg["api_url"] + "/ingest", payload,
                               self.cfg["api_token"], float(self.cfg["timeout_seconds"]))
        if 200 <= code < 300:
            return True
        if code == 401:
            print("[error] Token abgelehnt (HTTP 401). api_token pruefen.")
        elif code == 400:
            print(f"[error] Payload abgelehnt: {body.get('message', '')}")
        elif code == 429:
            print("[warn] Rate Limit erreicht.")
        elif code == 0:
            print(f"[warn] Server nicht erreichbar: {body.get('error', '')}")
        else:
            print(f"[warn] Unerwartete Antwort HTTP {code}.")
        return False

    def flush_spool(self) -> None:
        pending = self.spool.peek_all()
        if not pending:
            return
        sent = 0
        for item in pending[:20]:
            if not self.send(item):
                break
            sent += 1
        if sent:
            self.spool.remove_first(sent)
            print(f"[info] {sent} gepufferte Messung(en) nachgesendet "
                  f"({len(self.spool)} verbleiben).")

    def tick(self, dry_run: bool = False) -> dict:
        payload = self.collect()
        s = payload["sample"]
        line = (f"cpu {s['cpu_pct']:5.1f}%  gpu {s['gpu_pct']:5.1f}%  "
                f"ram {s['ram_used_gb']:6.2f}/{s['ram_total_gb']:.2f} GB  "
                f"tps {s['tps_current']:6.2f} (Ø {s['tps_avg']:.2f}, peak {s['tps_peak']:.2f})  "
                f"models {len(s['loaded_models'])}  "
                f"ollama {'ok' if payload['_ollama_reachable'] else '--'}")
        print(time.strftime("%H:%M:%S ") + line)

        if dry_run:
            return payload
        if self.send(payload):
            self.backoff = 0.0
            self.flush_spool()
        else:
            self.spool.add({k: v for k, v in payload.items() if not k.startswith("_")})
            self.backoff = min(300.0, (self.backoff or float(self.cfg["interval_seconds"])) * 2)
            jitter = random.uniform(0, self.backoff * 0.2)
            wait = self.backoff + jitter
            print(f"[info] Messung gepuffert ({len(self.spool)} in Warteschlange), "
                  f"naechster Versuch in {wait:.0f}s.")
            time.sleep(wait)
        return payload

    def run(self, once: bool = False, dry_run: bool = False) -> None:
        interval = int(self.cfg["interval_seconds"])
        print(f"mac-monitor Agent {AGENT_VERSION}")
        print(f"  Maschine : {self.static['id']} ({self.static['platform']})")
        print(f"  Server   : {self.cfg['api_url']}")
        print(f"  Ollama   : {self.cfg['ollama_url']}")
        print(f"  Intervall: {interval}s   Slots: {self.static['max_slots']}")
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
