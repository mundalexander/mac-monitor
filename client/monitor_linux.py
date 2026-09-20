#!/usr/bin/env python3
"""
monitor_linux.py — Entry-Point für den Linux System Monitor (Evo-X3).

Modularer Aufbau:
  collectors.py  → CPU, RAM, GPU, Shelly
  backends.py    → LLM-Backend-Interface (LM Studio, Ollama, llama-server)
  tps_probe.py   → Benchmark (einmalig, manuell)

Usage:
    python3 monitor_linux.py             # single sample
    bash run_monitor_linux.sh            # loop mode (every 10s)

Systemd service:
    See run_monitor_linux.sh header for unit file.
"""

import json
import os
import time
import urllib.request
from datetime import datetime

from collectors import get_cpu_percent, get_ram_stats, get_gpu_stats, get_shelly_power
from backends import get_all_model_stats, LMStudioBackend, OllamaBackend, HalogenBackend

# ── Configuration ─────────────────────────────────────────────────────────
SERVER_URL   = "https://mund.bplaced.net/mac-monitor/submit.php"
REQUESTS_URL = "https://mund.bplaced.net/mac-monitor/requests.php"
COMMANDS_URL = "https://mund.bplaced.net/mac-monitor/commands.php"
# PROBE_INTERVAL entfällt — Live-TPS wird jeden Poll-Zyklus (10s) gemessen


def _load_token() -> str:
    """API-Token externalisiert: $MAC_MONITOR_TOKEN > ~/.config/mac-monitor/config.json."""
    tok = os.environ.get("MAC_MONITOR_TOKEN", "").strip()
    if tok:
        return tok
    try:
        with open(os.path.expanduser("~/.config/mac-monitor/config.json")) as f:
            tok = (json.load(f).get("token") or "").strip()
        if tok:
            return tok
    except Exception:
        pass
    return "YOUR_API_TOKEN_HERE"


API_TOKEN = _load_token()
SERVER_ID = "evo-x3"
HOSTNAME  = "sascha-EVO-X3"

# Shelly plug (optional, None to disable)
SHELLY_URL = None  # "http://192.168.178.73/rpc/Shelly.GetStatus"

# LM Studio + Ollama URLs
LM_STUDIO_URL = "http://127.0.0.1:1234"
OLLAMA_URL    = "http://127.0.0.1:11434"

# Live-TPS Backends (persistent zwischen Polls für Delta-Berechnung)
_lm_backend = LMStudioBackend(LM_STUDIO_URL)
_ol_backend = OllamaBackend(OLLAMA_URL)
_hg_backend = HalogenBackend("http://127.0.0.1:8731")

# Logging
LOG_DIR    = os.path.expanduser("~/.local/share/mac-monitor")
LOG_FILE   = os.path.join(LOG_DIR, "monitor_linux.log")
ERROR_LOG  = os.path.join(LOG_DIR, "monitor_linux.err.log")
STATE_FILE = os.path.join(LOG_DIR, "monitor_linux_state.json")


# ── Logging & State ───────────────────────────────────────────────────────
def ensure_log_dir():
    os.makedirs(LOG_DIR, exist_ok=True)


def write_log(msg):
    ensure_log_dir()
    with open(LOG_FILE, "a") as f:
        f.write(f"{datetime.now().isoformat()} - {msg}\n")


def write_error(msg):
    ensure_log_dir()
    with open(ERROR_LOG, "a") as f:
        f.write(f"{datetime.now().isoformat()} - {msg}\n")


def load_state():
    try:
        with open(STATE_FILE) as f:
            return json.load(f)
    except Exception:
        return {}


def save_state(state):
    try:
        with open(STATE_FILE, "w") as f:
            json.dump(state, f)
    except Exception as e:
        write_error(f"State save error: {e}")


# ── Main collection ───────────────────────────────────────────────────────
def collect_and_send():
    # CPU
    cpu = get_cpu_percent()

    # RAM
    ram_percent, ram_used_gb, ram_total_gb = get_ram_stats()

    # GPU (VRAM via /proc/meminfo — Unified Memory Fix)
    gpu_percent, vram_used_gb, vram_total_gb, gpu_temp = get_gpu_stats()

    # LLM Backends (Ollama + LM Studio + llama-server)
    llm_stats = get_all_model_stats()

    # Shelly (optional)
    shelly_power = get_shelly_power(SHELLY_URL)

    # Live-TPS: jede Poll-Zyklus (10s) /slots pollen, Delta berechnen
    state = load_state()
    lm_prev = state.get("lm_live_state", {})
    ol_prev = state.get("ol_live_state", {})
    lm_tps, lm_new_state = _lm_backend.live_tps(lm_prev)
    ol_tps, ol_new_state = _ol_backend.live_tps(ol_prev)
    hg_tps, hg_new_state = _hg_backend.live_tps(state.get("hg_live_state", {}))
    state["hg_live_state"] = hg_new_state
    state["lm_live_state"] = lm_new_state
    state["ol_live_state"] = ol_new_state
    # TPS nur senden wenn aktiv generiert wird, sonst null
    tps = lm_tps        # LM Studio live TPS
    ollama_tps = ol_tps  # Ollama live TPS
    # Halogen hat Vorrang: fertiger Engine-Gauge, aktivste Quelle
    active_tps = hg_tps if hg_tps is not None else ollama_tps
    save_state(state)

    # Build payload
    payload = {
        "token":             API_TOKEN,
        "host":              HOSTNAME,
        "server_id":         SERVER_ID,
        "ts":                int(datetime.now().timestamp()),
        "cpu":               cpu,
        "gpu":               gpu_percent,
        "gpu_temp":          gpu_temp,
        "tokens_per_second": active_tps,   # → tps in DB (Halogen > Ollama)
        "lm_studio_tps":     tps,            # → lm_studio_tps in DB
        "ram_percent":       ram_percent,
        "ram_used_gb":       ram_used_gb,
        "ram_total_gb":      ram_total_gb,
        "vram_used_gb":      vram_used_gb,
        "vram_total_gb":     vram_total_gb,
        "ollama":            llm_stats,
        "shelly_power":      shelly_power,
    }

    # Log
    write_log(
        f"Stats: CPU={cpu}%, RAM={ram_percent}% ({ram_used_gb}/{ram_total_gb} GB), "
        f"GPU={gpu_percent}%, VRAM={vram_used_gb}/{vram_total_gb} GB, "
        f"LLM={len(llm_stats.get('loaded', []))} loaded"
    )

    # Send
    try:
        data = json.dumps(payload).encode("utf-8")
        req = urllib.request.Request(
            SERVER_URL, data=data,
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=10) as response:
            result = response.read().decode()
            write_log(f"Server response: {result}")
            return True
    except Exception as e:
        write_error(f"Send error: {e}")
        return False


# ── Command polling ───────────────────────────────────────────────────────
def poll_commands():
    """Poll pending commands from bplaced, execute them, mark done."""
    try:
        url = COMMANDS_URL + "?token=" + API_TOKEN
        req = urllib.request.Request(url, method="GET")
        with urllib.request.urlopen(req, timeout=10) as resp:
            data = json.loads(resp.read().decode())
    except Exception as e:
        write_error(f"Command poll error: {e}")
        return

    for cmd in data.get("commands", []):
        action = cmd.get("action", "")
        srv_id = cmd.get("server_id", "")
        model  = cmd.get("model_id", "")
        cmd_id = cmd.get("id", "")

        if srv_id != SERVER_ID:
            continue

        success = False

        if action == "ollama_unload" and model:
            ol = OllamaBackend()
            success = ol.unload(model)
            if success:
                write_log(f"Ollama unload OK: {model}")

        elif action == "lm_studio_unload" and model:
            lm = LMStudioBackend()
            success = lm.unload(model)
            if success:
                write_log(f"LM Studio unloaded: {model}")
                state = load_state()
                state["last_tps"] = None
                save_state(state)

        elif action == "halogen_restart":
            try:
                subprocess.run(["podman", "restart", "halogen"],
                               capture_output=True, timeout=60)
                success = True
                write_log("Halogen container restarted")
            except Exception as e:
                write_error(f"Halogen restart error: {e}")
                success = False

        elif action == "halogen_stop":
            try:
                subprocess.run(["podman", "stop", "halogen"],
                               capture_output=True, timeout=30)
                success = True
                write_log("Halogen container stopped")
            except Exception as e:
                write_error(f"Halogen stop error: {e}")
                success = False

        elif action == "halogen_start":
            try:
                subprocess.run(["podman", "start", "halogen"],
                               capture_output=True, timeout=60)
                success = True
                write_log("Halogen container started")
            except Exception as e:
                write_error(f"Halogen start error: {e}")
                success = False

        elif action == "lmstudio_start":
            try:
                subprocess.run(["systemctl", "--user", "start", "lmstudio"],
                               capture_output=True, timeout=30)
                success = True
                write_log("LM Studio started")
            except Exception as e:
                write_error(f"LM Studio start error: {e}")
                success = False

        elif action == "lmstudio_stop":
            try:
                subprocess.run(["systemctl", "--user", "stop", "lmstudio"],
                               capture_output=True, timeout=30)
                success = True
                write_log("LM Studio stopped")
            except Exception as e:
                write_error(f"LM Studio stop error: {e}")
                success = False

        # Mark command done
        try:
            body = json.dumps({"token": API_TOKEN, "id": cmd_id, "done": True}).encode()
            req3 = urllib.request.Request(
                COMMANDS_URL,
                data=body,
                headers={"Content-Type": "application/json"}, method="POST",
            )
            urllib.request.urlopen(req3, timeout=10).read()
        except Exception as e:
            write_error(f"Mark done error ({cmd_id}): {e}")


# ── Entry point ───────────────────────────────────────────────────────────
def main():
    ensure_log_dir()
    poll_commands()
    collect_and_send()


if __name__ == "__main__":
    main()
