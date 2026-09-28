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
import subprocess
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


# ── LLM API Request Collector ──────────────────────────────────────────────────────
def _parse_dur_ms(s):
    """Go-Dauer '4.2s'/'24ms'/'157µs'/'42ns' → Millisekunden."""
    s = s.strip()
    try:
        if s.endswith('ms'):
            return float(s[:-2])
        if s.endswith('µs') or s.endswith('us'):
            return float(s[:-2]) / 1000
        if s.endswith('ns'):
            return float(s[:-2]) / 1_000_000
        if s.endswith('s'):
            return float(s[:-1]) * 1000
    except ValueError:
        pass
    return None


def collect_llm_requests(state):
    """Sammelt neue LLM-API-Requests aus allen Backends.

    Halogen: uvicorn Access-Log via podman logs (--timestamps).
    Ollama:  GIN-Log-Zeilen aus ~/.local/share/ollama/ollama.log.
    Gibt (calls, new_last_ts) zurück."""
    import re as _re
    calls = []
    last_ts = int(state.get("req_last_ts", 0))
    now_ts = int(datetime.now().timestamp())

    # ── Halogen (uvicorn access log) ──
    try:
        cmd = ["podman", "logs", "--timestamps"]
        if last_ts > 0:
            cmd += ["--since", datetime.fromtimestamp(last_ts).isoformat(timespec="seconds")]
        else:
            cmd += ["--tail", "50"]
        cmd.append("halogen")
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=12)
        pat = _re.compile(
            r'^(\S+)\s+INFO:\s+([\d.]+):\d+\s+-\s+"(\w+)\s+(\S+)[^"]*"\s+(\d{3})')
        for line in (r.stdout + r.stderr).splitlines():
            m = pat.match(line)
            if not m:
                continue
            ts_s, ip, method, endpoint, status = m.groups()
            if endpoint in ("/health", "/metrics"):
                continue
            try:
                ts = int(datetime.fromisoformat(ts_s).timestamp())
            except Exception:
                ts = now_ts
            calls.append({"ts": ts, "ip": ip, "method": method,
                        "endpoint": endpoint, "duration_ms": None,
                        "status": int(status)})
            if ts > last_ts:
                last_ts = ts
    except Exception:
        pass

    # ── Ollama (GIN log) ──
    ollama_log = os.path.expanduser("~/.local/share/ollama/ollama.log")
    if os.path.exists(ollama_log):
        try:
            off = int(state.get("ollama_log_offset", 0))
            size = os.path.getsize(ollama_log)
            if size < off:
                off = 0  # log rotiert
            gin_re = _re.compile(
                r'\[GIN\]\s+(\d{4}/\d{2}/\d{2})\s+-\s+(\d{2}:\d{2}:\d{2})\s+\|\s+(\d+)\s+\|\s+([\d.]+\w*)\s+\|\s+(\S+)\s+\|\s+(\w+)\s+"([^"]+)"')
            with open(ollama_log) as f:
                f.seek(off)
                lines = f.readlines()
                state["ollama_log_offset"] = f.tell()
            for line in lines:
                m = gin_re.match(line.strip())
                if not m:
                    continue
                d, t, status, dur, ip, method, endpoint = m.groups()
                try:
                    ts = int(datetime.strptime(f"{d} {t}", "%Y/%m/%d %H:%M:%S").timestamp())
                except Exception:
                    continue
                calls.append({"ts": ts, "ip": ip, "method": method,
                            "endpoint": endpoint, "duration_ms": _parse_dur_ms(dur),
                            "status": int(status)})
                if ts > last_ts:
                    last_ts = ts
        except Exception:
            pass

    if last_ts == 0:
        last_ts = now_ts
    return calls, last_ts


# ── Telegram Watchdog ────────────────────────────────────────────────
TELEGRAM_CONFIG = os.path.expanduser("~/.config/mac-monitor/telegram.json")


def send_telegram(text):
    """Telegram-Message via Bot API; Fehler nur ins Log."""
    try:
        with open(TELEGRAM_CONFIG) as f:
            cfg = json.load(f)
        body = json.dumps({"chat_id": cfg["chat_id"], "text": text}).encode()
        req = urllib.request.Request(
            f"https://api.telegram.org/bot{cfg['bot_token']}/sendMessage",
            data=body,
            headers={"Content-Type": "application/json"},
            method="POST")
        urllib.request.urlopen(req, timeout=10).read()
        return True
    except Exception as e:
        write_error(f"Telegram send error: {e}")
        return False


def check_backend_watchdog(state):
    """Halogen alive→dead / dead→alive → Telegram-Alarm (edge-triggered).

    Unterdrückung: state['alert_suppress_until'] wird von Stop/Restart-Commands
    gesetzt (bewusster Stop → kein Alarm)."""
    now = int(datetime.now().timestamp())
    was_alive = state.get("halogen_alive")
    is_alive = _hg_backend.is_running()

    if was_alive is None:
        # Erster Lauf: nur erfassen, kein Alarm
        state["halogen_alive"] = is_alive
        return

    suppressed = now < int(state.get("alert_suppress_until", 0))

    if was_alive and not is_alive:
        state["halogen_alive"] = False
        state["halogen_down_since"] = now
        if suppressed:
            write_log("Halogen down — unterdrückt (bewusster Stop)")
            return
        code = "?"
        try:
            r = subprocess.run(
                ["podman", "inspect", "-f", "{{.State.ExitCode}}", "halogen"],
                capture_output=True, text=True, timeout=5)
            code = r.stdout.strip() or "?"
        except Exception:
            pass
        t = datetime.now().strftime("%H:%M")
        send_telegram(f"🚨 Halogen DOWN — Exit-Code {code} — {t}\n"
                     f"Host: {HOSTNAME} · mund.bplaced.net/mac-monitor")
        write_log(f"ALERT: Halogen down, Exit-Code {code}")
    elif (not was_alive) and is_alive:
        state["halogen_alive"] = True
        down_since = int(state.get("halogen_down_since", 0))
        mins = max(1, (now - down_since) // 60) if down_since else 0
        state["halogen_down_since"] = 0
        if suppressed:
            write_log("Halogen wieder da — unterdrückt (bewusster Restart)")
        else:
            t = datetime.now().strftime("%H:%M")
            extra = f" (nach {mins} Min Auszeit)" if mins else ""
            send_telegram(f"✅ Halogen wieder online — {t}{extra}")
            write_log("ALERT: Halogen wieder online")


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

    # Backend Watchdog: Halogen-Tod/-Erholung → Telegram
    # DEAKTIVIERT 2026-09-22 auf Saschas Wunsch — vorerst kein Telegram-Alarm
    # check_backend_watchdog(state)
    # save_state(state)

    # LLM API Requests → requests.php (Halogen + Ollama)
    calls, new_ts = collect_llm_requests(state)
    if calls:
        try:
            body = json.dumps({"token": API_TOKEN, "calls": calls}).encode()
            req = urllib.request.Request(
                REQUESTS_URL, data=body,
                headers={"Content-Type": "application/json"}, method="POST")
            urllib.request.urlopen(req, timeout=10).read()
            state["req_last_ts"] = new_ts
            save_state(state)
            write_log(f"Sent {len(calls)} LLM API requests")
        except Exception as e:
            write_error(f"LLM requests send error: {e}")

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
                st = load_state()
                st["alert_suppress_until"] = int(datetime.now().timestamp()) + 180
                save_state(st)
            except Exception as e:
                write_error(f"Halogen restart error: {e}")
                success = False

        elif action == "halogen_stop":
            try:
                subprocess.run(["podman", "stop", "halogen"],
                               capture_output=True, timeout=30)
                success = True
                write_log("Halogen container stopped")
                st = load_state()
                st["alert_suppress_until"] = int(datetime.now().timestamp()) + 600
                save_state(st)
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
