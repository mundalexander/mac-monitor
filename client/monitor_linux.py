#!/usr/bin/env python3
"""
Linux System Monitor Client — Evo-X3
Monitors system stats (CPU, RAM, GPU via rocm-smi, Ollama models)
and sends to the server via API.

Target: sascha-EVO-X3 (Ubuntu 24.04, Radeon 8060S, ROCm 7.2.0)

Usage:
    python3 monitor_linux.py             # single sample
    bash run_monitor_linux.sh            # loop mode (every 10s)

Systemd service:
    See run_monitor_linux.sh header for unit file.
"""

import subprocess
import re
import json
import urllib.request
import os
import time
from datetime import datetime

# ── Configuration ─────────────────────────────────────────────────────────
SERVER_URL  = "https://mund.bplaced.net/mac-monitor/submit.php"
REQUESTS_URL  = "https://mund.bplaced.net/mac-monitor/requests.php"
PROBE_INTERVAL = 300  # Sekunden zwischen token/s Proben
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

API_TOKEN   = _load_token()
SERVER_ID   = "evo-x3"
HOSTNAME    = "sascha-EVO-X3"

# Ollama API
OLLAMA_TAGS_URL = "http://127.0.0.1:11434/api/tags"
OLLAMA_PS_URL   = "http://127.0.0.1:11434/api/ps"

# llama-server instances (OpenAI-compatible /v1/models)
LLAMA_SERVERS = [
    {"url": "http://127.0.0.1:8080", "name": "llama-server-35b"},
    {"url": "http://127.0.0.1:8081", "name": "llama-server-122b"},
    {"url": "http://127.0.0.1:1234", "name": "lm-studio"},
]

# GPU — rocm-smi binary
ROCM_SMI = "/opt/rocm/bin/rocm-smi"  # standard ROCm path
# Fallback paths
ROCM_SMI_FALLBACKS = [
    "/usr/bin/rocm-smi",
    "/usr/local/bin/rocm-smi",
    "rocm-smi",  # hope it's in PATH
]

# Shelly plug (optional, None to disable)
SHELLY_URL = None  # "http://192.168.178.73/rpc/Shelly.GetStatus"

# Logging
LOG_DIR     = os.path.expanduser("~/.local/share/mac-monitor")
LOG_FILE    = os.path.join(LOG_DIR, "monitor_linux.log")
ERROR_LOG   = os.path.join(LOG_DIR, "monitor_linux.err.log")
STATE_FILE  = os.path.join(LOG_DIR, "monitor_linux_state.json")


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


# ── CPU usage via /proc/stat ──────────────────────────────────────────────
_prev_cpu = None


def get_cpu_percent():
    """Calculate CPU usage since last call using /proc/stat."""
    global _prev_cpu
    try:
        with open("/proc/stat", "r") as f:
            line = f.readline()
        parts = line.split()
        # user, nice, system, idle, iowait, irq, softirq, steal, guest, guest_nice
        fields = [int(x) for x in parts[1:]]
        idle = fields[3] + (fields[4] if len(fields) > 4 else 0)
        total = sum(fields)

        if _prev_cpu is None:
            # Fresh process: sample twice over 0.5s and compute the delta
            time.sleep(0.5)
            with open("/proc/stat", "r") as f:
                line = f.readline()
            parts = line.split()
            fields = [int(x) for x in parts[1:]]
            idle2 = fields[3] + (fields[4] if len(fields) > 4 else 0)
            total2 = sum(fields)
            _prev_cpu = (idle2, total2)
            total_diff = total2 - total
            idle_diff = idle2 - idle
            if total_diff > 0:
                usage = (1.0 - idle_diff / total_diff) * 100.0
                return round(max(0.0, min(100.0, usage)), 1)
            return 0.0

        prev_idle, prev_total = _prev_cpu
        _prev_cpu = (idle, total)

        total_diff = total - prev_total
        idle_diff = idle - prev_idle

        if total_diff <= 0:
            return 0.0

        usage = (1.0 - idle_diff / total_diff) * 100.0
        return round(max(0.0, min(100.0, usage)), 1)
    except Exception as e:
        write_error(f"CPU parse error: {e}")
        return 0.0


# ── RAM usage via /proc/meminfo ───────────────────────────────────────────
def get_ram_stats():
    """Returns (ram_percent, ram_used_gb, ram_total_gb)."""
    try:
        info = {}
        with open("/proc/meminfo", "r") as f:
            for line in f:
                parts = line.split(":")
                if len(parts) == 2:
                    key = parts[0].strip()
                    val = int(parts[1].strip().split()[0]) * 1024  # bytes
                    info[key] = val

        total = info.get("MemTotal", 0)
        avail = info.get("MemAvailable", info.get("MemFree", 0))
        used = total - avail

        if total > 0:
            ram_percent = round((used / total) * 100, 1)
            ram_used_gb = round(used / (1024 ** 3), 2)
            ram_total_gb = round(total / (1024 ** 3), 2)
            return ram_percent, ram_used_gb, ram_total_gb
        return 0.0, 0.0, 0.0
    except Exception as e:
        write_error(f"RAM parse error: {e}")
        return 0.0, 0.0, 0.0


# ── GPU usage via rocm-smi ────────────────────────────────────────────────
def find_rocm_smi():
    """Find the rocm-smi binary."""
    # Check standard path first
    if os.path.isfile(ROCM_SMI) and os.access(ROCM_SMI, os.X_OK):
        return ROCM_SMI
    # Fallbacks
    for path in ROCM_SMI_FALLBACKS:
        if path == "rocm-smi":
            # Check if in PATH
            try:
                subprocess.check_output(["which", "rocm-smi"], stderr=subprocess.DEVNULL)
                return "rocm-smi"
            except Exception:
                continue
        if os.path.isfile(path) and os.access(path, os.X_OK):
            return path
    return None


def get_gpu_stats():
    """Returns (gpu_percent, vram_used_gb, vram_total_gb, gpu_temp).
    Uses rocm-smi --showuse --showmeminfo --json for structured output."""
    rocm = find_rocm_smi()
    if not rocm:
        write_error("rocm-smi not found — GPU stats unavailable")
        return -1.0, None, None, None

    try:
        # Get GPU utilization and memory info in JSON
        output = subprocess.check_output(
            [rocm, "--showuse", "--showmeminfo", "vram", "--json"],
            stderr=subprocess.DEVNULL, timeout=10
        ).decode()

        data = json.loads(output)

        # rocm-smi --json output structure:
        # { "card0": { "GPU use (%)": "0", "VRAM Total (B)": "34359738368", "VRAM Used (B)": "1234567", ... } }
        # On Evo-X3 with Radeon 8060S (gfx1151), there's one card.
        # Unified memory: VRAM Total may report the full 96GB or a partition.

        # Find first card entry
        card_key = None
        for k in data:
            if k.startswith("card"):
                card_key = k
                break

        if not card_key:
            # Some rocm-smi versions use different keys
            for k in data:
                card_key = k
                break

        if not card_key:
            return -1.0, None, None, None

        card = data[card_key]

        # GPU utilization
        gpu_percent = -1.0
        gpu_temp = None
        for key in ("GPU use (%)", "GPU_USE", "gpu_use"):
            if key in card:
                try:
                    gpu_percent = float(str(card[key]).strip())
                except (ValueError, TypeError):
                    pass
                break

        # VRAM
        vram_used_bytes = None
        vram_total_bytes = None
        for key in ("VRAM Total Used Memory (B)", "VRAM Used (B)", "VRAM_USED", "vram_used"):
            if key in card:
                try:
                    vram_used_bytes = int(str(card[key]).strip())
                except (ValueError, TypeError):
                    pass
                break
        for key in ("VRAM Total Memory (B)", "VRAM Total (B)", "VRAM_TOTAL", "vram_total"):
            if key in card:
                try:
                    vram_total_bytes = int(str(card[key]).strip())
                except (ValueError, TypeError):
                    pass
                break

        vram_used_gb = round(vram_used_bytes / (1024 ** 3), 2) if vram_used_bytes else None
        vram_total_gb = round(vram_total_bytes / (1024 ** 3), 2) if vram_total_bytes else None

        # GPU temperature via amd-smi (independent of rocm-smi)
        try:
            res = subprocess.run(
                ["/opt/rocm-7.2.0/bin/amd-smi", "metric", "--gpu", "0"],
                capture_output=True, text=True, timeout=10
            )
            for tline in res.stdout.splitlines():
                ts = tline.strip()
                if ts.startswith("EDGE:"):
                    m = re.search(r"(\d+)", ts)
                    if m:
                        gpu_temp = int(m.group(1))
                    break
        except Exception:
            pass

        return gpu_percent, vram_used_gb, vram_total_gb, gpu_temp

    except subprocess.TimeoutExpired:
        write_error("rocm-smi timed out")
        return -1.0, None, None, None
    except json.JSONDecodeError as e:
        write_error(f"rocm-smi JSON parse error: {e}")
        return -1.0, None, None, None
    except Exception as e:
        write_error(f"GPU stats error: {e}")
        return -1.0, None, None, None


# ── Ollama models ─────────────────────────────────────────────────────────
def get_ollama_stats():
    """Returns {loaded: [...], available: [...], error: None|str}."""
    try:
        # Get available models
        resp = urllib.request.urlopen(OLLAMA_TAGS_URL, timeout=5)
        data = json.loads(resp.read().decode())
        available = [
            {"name": m["name"], "size_gb": round(m.get("size", 0) / (1024 ** 3), 1), "server": "ollama"}
            for m in data.get("models", [])
        ]
    except Exception as e:
        err = str(e)
        if "Connection refused" in err or "urlopen error" in err:
            return {"loaded": [], "available": [], "error": "ollama_offline"}
        return {"loaded": [], "available": [], "error": err}

    # Get loaded (running) models from Ollama
    loaded = []
    try:
        ps_resp = urllib.request.urlopen(OLLAMA_PS_URL, timeout=5)
        ps_data = json.loads(ps_resp.read().decode())
        loaded = [
            {"name": m["name"], "size_vram_gb": round(m.get("size_vram", 0) / (1024 ** 3), 1), "server": "ollama"}
            for m in ps_data.get("models", [])
        ]
    except Exception:
        pass  # /api/ps might not be available on older Ollama versions

    # Also check llama-server instances (OpenAI-compatible API).
    # LM Studio: the native API (/api/v0/models) reports an explicit per-model load state
    # ("loaded"/"not-loaded") — loaded models land in "loaded", the rest in "available".
    # Fallback (older LM Studio without native API): /v1/models catalog, listed as available only.
    # llama-server: /v1/models only reports actually loaded models.
    LM_STUDIO_SERVERS = {"lm-studio"}

    for srv in LLAMA_SERVERS:
        try:
            if srv["name"] in LM_STUDIO_SERVERS:
                # LM Studio: native API reports the loaded models (explicit state),
                # /v1/models lists the full catalog — merge both.
                lm_loaded = []
                try:
                    resp2 = urllib.request.urlopen(srv["url"] + "/api/v0/models", timeout=3)
                    data2 = json.loads(resp2.read().decode())
                    for m in data2.get("data", []):
                        if m.get("type") and m.get("type") != "llm":
                            continue  # skip embedding models
                        if m.get("state") == "loaded":
                            lm_loaded.append(m.get("id", "unknown"))
                except Exception:
                    pass  # native API unavailable — no load-state info
                resp3 = urllib.request.urlopen(srv["url"] + "/v1/models", timeout=3)
                data3 = json.loads(resp3.read().decode())
                for m in data3.get("data", []):
                    model_id = m.get("id", "unknown")
                    if model_id in lm_loaded:
                        loaded.append({"name": model_id, "server": srv["name"]})
                    else:
                        available.append({"name": model_id, "server": srv["name"]})
                continue
            resp2 = urllib.request.urlopen(srv["url"] + "/v1/models", timeout=3)
            data2 = json.loads(resp2.read().decode())
            for m in data2.get("data", []):
                model_id = m.get("id", "unknown")
                meta = m.get("meta", {})
                size_bytes = meta.get("size", 0)
                size_gb = round(size_bytes / (1024 ** 3), 1) if size_bytes else None
                # llama-server: /v1/models only returns actually loaded models
                loaded.append({
                    "name": model_id,
                    "size_vram_gb": size_gb,
                    "server": srv["name"]
                })
        except Exception:
            if srv["name"] in LM_STUDIO_SERVERS:
                # Older LM Studio without native API: catalog as available only
                try:
                    resp2 = urllib.request.urlopen(srv["url"] + "/v1/models", timeout=3)
                    data2 = json.loads(resp2.read().decode())
                    for m in data2.get("data", []):
                        available.append({"name": m.get("id", "unknown"), "server": srv["name"]})
                except Exception:
                    pass  # LM Studio not running

    return {"loaded": loaded, "available": available, "error": None}


# ── Shelly power (optional) ───────────────────────────────────────────────
def get_shelly_power():
    if not SHELLY_URL:
        return None
    try:
        resp = urllib.request.urlopen(SHELLY_URL, timeout=3)
        data = json.loads(resp.read().decode())
        return data.get("switch:0", {}).get("apower", None)
    except Exception:
        return None


def probe_tokens_per_second():
    """Probe generation speed of the first loaded LM Studio model (streaming).
    Returns (tps|None, probe_call|None)."""
    lm = next((s["url"] for s in LLAMA_SERVERS if s["name"] == "lm-studio"), "http://127.0.0.1:1234")
    model = None
    try:
        resp = urllib.request.urlopen(lm + "/api/v0/models", timeout=3)
        data = json.loads(resp.read().decode())
        for m in data.get("data", []):
            if m.get("type") and m.get("type") != "llm":
                continue
            if m.get("state") == "loaded":
                model = m.get("id")
                break
    except Exception:
        return None, None
    if not model:
        return None, None
    body = json.dumps({
        "model": model,
        "messages": [{"role": "user", "content": "Count from 1 to 20, separated by commas."}],
        "max_tokens": 48,
        "stream": True,
    }).encode("utf-8")
    req = urllib.request.Request(
        lm + "/v1/chat/completions", data=body,
        headers={"Content-Type": "application/json"}, method="POST",
    )
    t0 = time.time()
    try:
        resp = urllib.request.urlopen(req, timeout=30)
        first = last = None
        n_chunks = 0
        for raw in resp:
            line = raw.decode("utf-8", errors="replace").strip()
            if not line.startswith("data:"):
                continue
            payload_s = line[5:].strip()
            if payload_s == "[DONE]":
                break
            try:
                chunk = json.loads(payload_s)
            except Exception:
                continue
            delta = (chunk.get("choices") or [{}])[0].get("delta", {})
            token_text = delta.get("content") or delta.get("reasoning_content") or ""
            if token_text:
                t = time.time()
                if first is None:
                    first = t
                last = t
                n_chunks += 1
        t_total = time.time() - t0
        call = {
            "ts": int(time.time()), "ip": "evo-x3", "method": "POST",
            "endpoint": "/v1/chat/completions",
            "duration_ms": round(t_total * 1000, 1), "status": 200,
        }
        if n_chunks >= 2 and first is not None and last is not None and last > first:
            return round(n_chunks / (last - first), 1), call
        return None, call
    except Exception:
        call = {
            "ts": int(time.time()), "ip": "evo-x3", "method": "POST",
            "endpoint": "/v1/chat/completions",
            "duration_ms": round((time.time() - t0) * 1000, 1), "status": 503,
        }
        return None, call


def send_probe_record(call):
    """Report the token/s probe as an LLM request record (Anfragen section)."""
    try:
        body = json.dumps({"token": API_TOKEN, "calls": [call]}).encode("utf-8")
        req = urllib.request.Request(
            REQUESTS_URL, data=body,
            headers={"Content-Type": "application/json"}, method="POST",
        )
        urllib.request.urlopen(req, timeout=10).read()
    except Exception as e:
        write_error(f"Probe report error: {e}")


# ── Main collection ───────────────────────────────────────────────────────
def collect_and_send():
    # CPU
    cpu = get_cpu_percent()

    # RAM
    ram_percent, ram_used_gb, ram_total_gb = get_ram_stats()

    # GPU
    gpu_percent, vram_used_gb, vram_total_gb, gpu_temp = get_gpu_stats()

    # Ollama
    ollama = get_ollama_stats()

    # Shelly (optional)
    shelly_power = get_shelly_power()

    # Token/s probe (cached via state file, every PROBE_INTERVAL)
    state = load_state()
    tps = state.get("last_tps")
    now = time.time()
    if now - float(state.get("last_probe_ts", 0)) >= PROBE_INTERVAL:
        tps_new, probe_call = probe_tokens_per_second()
        state["last_probe_ts"] = int(now)
        if tps_new is not None:
            state["last_tps"] = tps_new
            tps = tps_new
        save_state(state)
        if probe_call:
            send_probe_record(probe_call)

    # Build payload
    payload = {
        "token":         API_TOKEN,
        "host":          HOSTNAME,
        "server_id":     SERVER_ID,
        "ts":            int(datetime.now().timestamp()),
        "cpu":           cpu,
        "gpu":           gpu_percent,
        "gpu_temp":     gpu_temp,
        "tokens_per_second": tps,
        "ram_percent":   ram_percent,
        "ram_used_gb":   ram_used_gb,
        "ram_total_gb":  ram_total_gb,
        "vram_used_gb":  vram_used_gb,
        "vram_total_gb": vram_total_gb,
        "ollama":        ollama,
        "shelly_power":  shelly_power,
    }

    # Log
    write_log(
        f"Stats: CPU={cpu}%, RAM={ram_percent}% ({ram_used_gb}/{ram_total_gb} GB), "
        f"GPU={gpu_percent}%, VRAM={vram_used_gb}/{vram_total_gb} GB, "
        f"Ollama={len(ollama.get('loaded', []))} loaded"
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


def main():
    ensure_log_dir()
    collect_and_send()


if __name__ == "__main__":
    main()