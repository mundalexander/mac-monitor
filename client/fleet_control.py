#!/usr/bin/env python3
"""Fleet Control – Kommandozeile für Mensch und Agent (Jarvis, OpenClaw, Hermes).

    python3 client/fleet_control.py status [--json]
    python3 client/fleet_control.py start flash-next      # Profil starten (RAM frei räumen, Test, Messung)
    python3 client/fleet_control.py stop halogen          # Engine oder Profil stoppen
    python3 client/fleet_control.py free                  # Modelle entladen + Cache freigeben
    python3 client/fleet_control.py restart-agent openclaw
    python3 client/fleet_control.py measure flash-next    # RAM-Bedarf des laufenden Profils messen
    python3 client/fleet_control.py adopt flash-next --port 8731 --engine halogen --label "Halogen Flash · Qwen 3.8 Flash-Next"
    python3 client/fleet_control.py adopt-agent openclaw --pattern "openclaw.*gateway"

Exit-Codes: 0 ok · 1 Fehler · 2 falscher Aufruf · 3 bereits eine Aktion aktiv.
Alle Befehle unterstützen --json für maschinenlesbare Ausgabe.
Wirkt nur lokal auf diesem Gerät. Fernsteuerung über das Dashboard (Control-Tab).
"""
from __future__ import annotations

import argparse
import json
import shutil
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import control  # noqa: E402

DEFAULT_CONFIG = Path(__file__).resolve().parent / "config.json"


def load_cfg(path: Path) -> dict:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def save_cfg(path: Path, cfg: dict) -> None:
    if path.exists():
        shutil.copy2(path, path.with_suffix(f".json.bak-{time.strftime('%Y%m%d-%H%M%S')}"))
    tmp = path.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(cfg, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    tmp.replace(path)


def build_controller(cfg: dict) -> control.Controller:
    """Controller mit Ollama-/LM-Studio-Entladern aus den vorhandenen Collectors."""
    ccfg = dict(cfg.get("control") or {})
    ccfg.setdefault("ollama_url", cfg.get("ollama_url", "http://127.0.0.1:11434"))
    ccfg.setdefault("lm_studio_url", cfg.get("lm_studio_url", "http://127.0.0.1:1234"))
    unloaders = {}
    try:
        from collectors import lm_studio, ollama

        def unload_ollama() -> bool:
            data = ollama._get(ccfg["ollama_url"], "/api/ps") or {}
            names = [m.get("name") or m.get("model") for m in data.get("models", [])]
            for n in filter(None, names):
                control.http_post_json(ccfg["ollama_url"].rstrip("/") + "/api/generate",
                                       {"model": n, "keep_alive": 0}, timeout=30)
            return bool(names)

        def unload_lms() -> bool:
            loaded = lm_studio.loaded_models(ccfg["lm_studio_url"]) or []
            for m in loaded:
                lm_studio.unload_model(m.get("name", ""))
            return bool(loaded)

        unloaders = {"ollama": unload_ollama, "lm_studio": unload_lms}
    except Exception:  # noqa: BLE001 - Collector optional
        pass
    return control.Controller(ccfg, unloaders=unloaders)


def out(data, as_json: bool) -> None:
    if as_json:
        print(json.dumps(data, indent=2, ensure_ascii=False))
        return
    if isinstance(data, dict) and "profiles" in data:
        print(f"Phase      : {data['message'] or control.PHASE_LABEL.get(data['phase'] or 'idle', '')}")
        print(f"RAM frei   : {data['ram_available_gb']:.1f} / {data['ram_total_gb']:.1f} GB")
        print(f"Cache frei : {'eingerichtet' if data['can_free_cache'] else 'nicht eingerichtet'}")
        print("\nProfile")
        for p in data["profiles"]:
            ram = f"{p['ram_gb']:.1f} GB" if p["ram_gb"] else "unbekannt"
            print(f"  {'●' if p['running'] else '○'} {p['id']:<14} {p['label']:<40} RAM {ram}"
                  + (f" · {p['tps']} tok/s" if p.get("tps") else ""))
        if data["agents"]:
            print("\nAgenten")
            for a in data["agents"]:
                print(f"  {'●' if a['running'] else '○'} {a['id']:<14} {a['label']}")
        return
    print(json.dumps(data, ensure_ascii=False))


def main() -> int:
    ap = argparse.ArgumentParser(description="Fleet Control – lokale Engine- und Agentensteuerung")
    ap.add_argument("--config", default=str(DEFAULT_CONFIG))
    ap.add_argument("--json", action="store_true")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("status")
    s = sub.add_parser("start"); s.add_argument("profile")
    s = sub.add_parser("stop"); s.add_argument("target", help="Engine-Gruppe oder Profil-ID")
    sub.add_parser("free")
    s = sub.add_parser("restart-agent"); s.add_argument("agent")
    s = sub.add_parser("measure"); s.add_argument("profile")
    s = sub.add_parser("adopt", help="laufenden Prozess als Profil übernehmen")
    s.add_argument("profile"); s.add_argument("--port", type=int, required=True)
    s.add_argument("--engine", default=""); s.add_argument("--label", default="")
    s.add_argument("--health", default="")
    s = sub.add_parser("adopt-agent", help="laufenden Agenten übernehmen")
    s.add_argument("agent"); s.add_argument("--pattern", required=True)
    s.add_argument("--label", default=""); s.add_argument("--health", default="")
    a = ap.parse_args()

    path = Path(a.config)
    cfg = load_cfg(path)
    ctl = build_controller(cfg)
    try:
        if a.cmd == "status":
            out(ctl.snapshot(), a.json)
        elif a.cmd == "start":
            out(ctl.start_profile(a.profile), a.json)
        elif a.cmd == "stop":
            out(ctl.stop(a.target), a.json)
        elif a.cmd == "free":
            out(ctl.free_memory(), a.json)
        elif a.cmd == "restart-agent":
            out(ctl.restart_agent(a.agent), a.json)
        elif a.cmd == "measure":
            if a.profile not in ctl.cfg["profiles"] or not ctl.profile_running(a.profile):
                print(f"Profil '{a.profile}' läuft nicht.", file=sys.stderr)
                return 1
            out(ctl.measure(a.profile), a.json)
        elif a.cmd == "adopt":
            if not control.ID_RE.match(a.profile):
                print("Profil-ID: a-z, 0-9, Punkt, Bindestrich (max. 40).", file=sys.stderr)
                return 2
            info = control.Controller.adopt(a.port)
            prof = cfg.setdefault("control", {}).setdefault("profiles", {}).get(a.profile, {})
            prof.update({"start": info["start"], "cwd": info["cwd"], "port": a.port})
            if a.engine:
                prof["engine"] = a.engine
            if a.label:
                prof["label"] = a.label
            if a.health:
                prof["health"] = a.health
            cfg["control"]["profiles"][a.profile] = prof
            save_cfg(path, cfg)
            ctl = build_controller(cfg)
            m = ctl.measure(a.profile)
            out({"adopted": a.profile, "start": info["start"], "cwd": info["cwd"],
                 "ram_gb": m["ram_gb"], "config": str(path)}, a.json)
        elif a.cmd == "adopt-agent":
            pids = control.pids_by_pattern([a.pattern])
            if not pids:
                print(f"Kein Prozess passt zu '{a.pattern}'.", file=sys.stderr)
                return 1
            info = control.Controller.adopt(0, a.pattern)
            ag = cfg.setdefault("control", {}).setdefault("agents", {}).get(a.agent, {})
            ag.update({"start": info["start"], "cwd": info["cwd"], "process_patterns": [a.pattern]})
            if a.label:
                ag["label"] = a.label
            if a.health:
                ag["health"] = a.health
            cfg["control"]["agents"][a.agent] = ag
            save_cfg(path, cfg)
            out({"adopted_agent": a.agent, "start": info["start"], "config": str(path)}, a.json)
    except control.ControlError as exc:
        msg = str(exc)
        if a.json:
            print(json.dumps({"ok": False, "error": msg}, ensure_ascii=False))
        else:
            print(f"Fehler: {msg}", file=sys.stderr)
        return 3 if "bereits eine Steueraktion" in msg else 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
