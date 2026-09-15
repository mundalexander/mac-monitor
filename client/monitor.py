#!/usr/bin/env python3
"""
Mac System Monitor Script
Monitors system stats (CPU, RAM, Disk, GPU) and sends to the server via API.
Probes Ollama / LM Studio tokens-per-second, lists loaded models,
parses Ollama server logs, and executes remote commands (e.g. LM Studio unload).
"""

import subprocess
import json
import urllib.request
import urllib.parse
import re
import os
import time
from datetime import datetime

SERVER_URL = "https://mund.bplaced.net/mac-monitor/submit.php"
REQUESTS_URL = "https://mund.bplaced.net/mac-monitor/requests.php"
COMMANDS_URL = "https://mund.bplaced.net/mac-monitor/commands.php"
API_TOKEN = "fseJLgBDetOAZizZjt_fv3AM-m0jUZYXZHEF7xrpOOw"
SERVER_ID = "mac"
LOG_FILE = "/Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor.log"
ERROR_LOG = "/Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor.err.log"
OLLAMA_LOG = os.path.expanduser("~/.ollama/logs/server.log")
STATE_FILE = "/Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor_state.json"
PROBE_INTERVAL = 300  # Sekunden zwischen TPS-Proben
OLLAMA_BASE = "http://127.0.0.1:11434"
LM_STUDIO_BASE = "http://127.0.0.1:1234"
LLAMA_CPP_BASE = "http://127.0.0.1:8080"
SHELLY_URL = "http://192.168.178.73/rpc/Shelly.GetStatus"
TPS_HISTORY_SIZE = 60  # Anzahl der TPS-Werte, die lokal im State gespeichert werden


def load_state():
    try:
        with open(STATE_FILE, 'r') as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        return {}


def save_state(state):
    tmp_file = STATE_FILE + ".tmp"
    with open(tmp_file, 'w') as f:
        json.dump(state, f)
    os.replace(tmp_file, STATE_FILE)


def _append_tps_history(state, server_id, value):
    """Append a TPS value to the local history for a given server type."""
    key = f'tps_history_{server_id}'
    history = state.get(key, [])
    history.append(value)
    if len(history) > TPS_HISTORY_SIZE:
        history = history[-TPS_HISTORY_SIZE:]
    state[key] = history
    return state


def get_system_stats():
    stats = {
        "timestamp": datetime.now().isoformat(),
        "hostname": None,
        "cpu_percent": None,
        "memory_percent": None,
        "disk_percent": None,
        "ram_used_gb": None,
        "ram_total_gb": None,
        "gpu": 0.0,
        "ollama": None,
        "shelly_power": None,
    }

    try:
        stats["hostname"] = "BigMac"

        # CPU usage from `top -l 1`
        cpu_output = subprocess.check_output(["top", "-l", "1"], stderr=subprocess.DEVNULL).decode()
        for line in cpu_output.split('\n'):
            if 'CPU usage:' in line:
                m = re.search(r'(\d+\.?\d*)\s*%\s*user,\s*(\d+\.?\d*)\s*%\s*sys', line)
                if m:
                    stats["cpu_percent"] = round(float(m.group(1)) + float(m.group(2)), 2)
                break

        # Memory usage from `vm_stat`
        mem_output = subprocess.check_output(["vm_stat"], stderr=subprocess.DEVNULL).decode()
        page_size = 16384
        free_pages = active_pages = wired_pages = inactive_pages = 0
        for line in mem_output.split('\n'):
            line = line.strip()
            if not line or ':' not in line:
                continue
            parts = line.split(':')
            key = parts[0].strip()
            num_str = parts[1].strip().rstrip('.')
            try:
                pages = float(num_str)
            except ValueError:
                continue
            if key == 'Pages active':
                active_pages = pages
            elif key == 'Pages wired down':
                wired_pages = pages
            elif key == 'Pages free':
                free_pages = pages
            elif key == 'Pages inactive':
                inactive_pages = pages
        total_pages = active_pages + wired_pages + free_pages + inactive_pages
        used_pages = active_pages + wired_pages
        if total_pages > 0:
            stats["memory_percent"] = round((used_pages / total_pages) * 100, 2)
            stats["ram_used_gb"] = round((used_pages * page_size) / (1024**3), 2)
            stats["ram_total_gb"] = round((total_pages * page_size) / (1024**3), 2)

        # Disk usage
        disk_output = subprocess.check_output(["df", "-h", "/"], stderr=subprocess.DEVNULL).decode()
        lines = disk_output.strip().split('\n')
        if len(lines) >= 2:
            parts = lines[1].split()
            for part in parts:
                if part.endswith('%'):
                    stats["disk_percent"] = int(part.replace('%', ''))
                    break

        # GPU usage — ioreg "Device Utilization %" matches Activity Monitor
        try:
            ioreg_out = subprocess.check_output(
                ["ioreg", "-r", "-d", "1", "-c", "AGXAccelerator"],
                stderr=subprocess.DEVNULL, timeout=5
            ).decode()
            m = re.search(r'"Device Utilization %"=(\d+)', ioreg_out)
            if m:
                stats["gpu"] = float(m.group(1))
        except Exception:
            pass

        # ── Loaded / available models ─────────────────────────────
        loaded = []
        available = []
        ollama_error = None

        # Ollama: /api/tags = alle Modelle, /api/ps = aktuell geladen
        try:
            ollama_resp = urllib.request.urlopen(OLLAMA_BASE + "/api/tags", timeout=5)
            ollama_data = json.loads(ollama_resp.read().decode())
            available = [{"name": m["name"], "size_gb": round(m.get("size", 0) / (1024**3), 1), "server": "ollama"} for m in ollama_data.get("models", [])]
            try:
                ps_resp = urllib.request.urlopen(OLLAMA_BASE + "/api/ps", timeout=5)
                ps_data = json.loads(ps_resp.read().decode())
                loaded = [{"name": m["name"], "size_vram_gb": round(m.get("size_vram", 0) / (1024**3), 1), "server": "ollama"} for m in ps_data.get("models", [])]
            except Exception:
                pass
        except Exception as e:
            err = str(e)
            if "Connection refused" in err or "urlopen error" in err:
                ollama_error = "ollama_offline"
            else:
                ollama_error = err

        # LM Studio: /v1/models listet ALLE heruntergeladenen Modelle.
        # Lade-Zustand steht in meta.state ("loaded" / "loaded-in-memory" / "not-loaded").
        # meta.size > 0 ist KEIN Indiz für geladen — size haben alle Modelle!
        try:
            lm_resp = urllib.request.urlopen(LM_STUDIO_BASE + "/v1/models", timeout=5)
            lm_data = json.loads(lm_resp.read().decode())
            for m in lm_data.get("data", []):
                model_id = m.get("id", "unknown")
                meta = m.get("meta", {}) or {}
                size_bytes = meta.get("size", 0) or 0
                size_gb = round(size_bytes / (1024**3), 1) if size_bytes else None
                lm_state = str(meta.get("state") or "").lower()
                if lm_state:
                    is_loaded = lm_state in ("loaded", "loaded-in-memory")
                else:
                    # Ältere LM-Studiod-Versionen: /v1/models listete nur geladene Modelle
                    is_loaded = True
                if is_loaded:
                    loaded.append({"name": model_id, "size_vram_gb": size_gb, "server": "lm-studio"})
                else:
                    available.append({"name": model_id, "size_gb": size_gb, "server": "lm-studio"})
        except Exception:
            pass  # LM Studio not running

        # llama.cpp (OpenAI-compatible API, port 8080) — listet nur das geladene Modell
        try:
            llama_resp = urllib.request.urlopen(LLAMA_CPP_BASE + "/v1/models", timeout=5)
            llama_data = json.loads(llama_resp.read().decode())
            for m in llama_data.get("data", []):
                model_id = m.get("id", "unknown")
                meta = m.get("meta", {}) or {}
                size_bytes = meta.get("size", 0) or 0
                size_gb = round(size_bytes / (1024**3), 1) if size_bytes else None
                if size_gb is not None and size_gb > 0:
                    loaded.append({"name": model_id, "size_vram_gb": size_gb, "server": "llama.cpp"})
                else:
                    loaded.append({"name": model_id, "size_vram_gb": None, "server": "llama.cpp"})
        except Exception:
            pass  # llama.cpp not running

        stats["ollama"] = {"loaded": loaded, "available": available, "error": ollama_error}

        # Shelly Plus Plug S — Solar-Leistung
        try:
            shelly_resp = urllib.request.urlopen(SHELLY_URL, timeout=3)
            shelly_data = json.loads(shelly_resp.read().decode())
            sw = shelly_data.get("switch:0") or {}
            apower = sw.get("apower")
            if apower is None:
                apower = (shelly_data.get("em:0") or {}).get("apower")
            if apower is not None:
                stats["shelly_power"] = round(float(apower), 1)
        except Exception:
            pass

    except Exception as e:
        write_error(f"get_system_stats error: {e}")

    return stats


def probe_ollama_tps():
    """Probe Ollama generation speed (exact via eval_count/eval_duration)."""
    try:
        ps = json.loads(urllib.request.urlopen(OLLAMA_BASE + "/api/ps", timeout=5).read().decode())
        models = ps.get("models", [])
        if not models:
            return None
        model = models[0]["name"]
        body = json.dumps({
            "model": model, "prompt": "Count from 1 to 10, separated by commas.",
            "stream": False, "options": {"num_predict": 32},
        }).encode("utf-8")
        req = urllib.request.Request(OLLAMA_BASE + "/api/generate", data=body,
                                     headers={"Content-Type": "application/json"}, method="POST")
        with urllib.request.urlopen(req, timeout=60) as resp:
            data = json.loads(resp.read().decode())
        eval_count = data.get("eval_count", 0)
        eval_duration = data.get("eval_duration", 0)  # nanoseconds
        if eval_count and eval_duration:
            return round(eval_count / (eval_duration / 1e9), 1)
    except Exception:
        return None
    return None


def probe_lm_studio_tps():
    """Probe LM Studio generation speed via /v1/chat/completions.
    Bevorzugt LM Studios exakte Decode-Zeit aus usage.timing, sonst completion_tokens/elapsed."""
    try:
        body = json.dumps({
            "model": "",
            "messages": [{"role": "user", "content": "Count from 1 to 10, separated by commas."}],
            "max_tokens": 32,
            "stream": False,
        }).encode("utf-8")
        req = urllib.request.Request(LM_STUDIO_BASE + "/v1/chat/completions", data=body,
                                     headers={"Content-Type": "application/json"}, method="POST")
        start = time.time()
        with urllib.request.urlopen(req, timeout=60) as resp:
            data = json.loads(resp.read().decode())
        elapsed = time.time() - start
        usage = data.get("usage", {}) or {}
        completion_tokens = usage.get("completion_tokens", 0)
        # LM Studio kann exakte Decode-Zeit liefern (usage.timing) — bevorzugen, falls vorhanden
        predict_sec = None
        timing = usage.get("timing") or {}
        if isinstance(timing, dict):
            for key in ("predict_time_sec", "predict_sec", "generation_time_sec"):
                if float(timing.get(key) or 0) > 0:
                    predict_sec = float(timing[key])
                    break
        if completion_tokens and predict_sec:
            return round(completion_tokens / predict_sec, 1)
        if completion_tokens and elapsed > 0:
            return round(completion_tokens / elapsed, 1)
    except Exception:
        return None
    return None


def _lm_studio_loaded_models():
    """Model ids, die in LM Studio aktuell geladen sind (via /v1/models + meta.state)."""
    loaded_ids = []
    try:
        resp = urllib.request.urlopen(LM_STUDIO_BASE + "/v1/models", timeout=5)
        data = json.loads(resp.read().decode())
        for m in data.get("data", []):
            meta = m.get("meta", {}) or {}
            state = str(meta.get("state") or "").lower()
            if state in ("loaded", "loaded-in-memory") or not state:
                loaded_ids.append(m.get("id"))
    except Exception:
        pass
    return [mid for mid in loaded_ids if mid]


def _lm_studio_unload(model_id):
    """Modell in LM Studio entladen — native API: POST /lmstudio/models/unload."""
    try:
        body = json.dumps({"identifier": model_id}).encode("utf-8")
        req = urllib.request.Request(LM_STUDIO_BASE + "/lmstudio/models/unload", data=body,
                                     headers={"Content-Type": "application/json"}, method="POST")
        with urllib.request.urlopen(req, timeout=30) as resp:
            resp.read()
            return 200 <= resp.status < 300
    except Exception as e:
        write_error(f"LM Studio unload error ({model_id}): {e}")
        return False


def _ollama_unload_all():
    """Alle geladenen Ollama-Modelle entladen (keep_alive=0)."""
    try:
        ps = json.loads(urllib.request.urlopen(OLLAMA_BASE + "/api/ps", timeout=5).read().decode())
        ok = True
        for m in ps.get("models", []):
            body = json.dumps({"model": m["name"], "keep_alive": 0}).encode("utf-8")
            req = urllib.request.Request(OLLAMA_BASE + "/api/generate", data=body,
                                         headers={"Content-Type": "application/json"}, method="POST")
            try:
                with urllib.request.urlopen(req, timeout=10) as resp:
                    resp.read()
            except Exception:
                ok = False
        return ok
    except Exception as e:
        write_error(f"Ollama unload error: {e}")
        return False


def _mark_command_done(cmd_id, success):
    """Command auf dem Server als erledigt markieren."""
    try:
        payload = json.dumps({"token": API_TOKEN, "id": cmd_id, "done": True,
                              "success": bool(success)}).encode("utf-8")
        req = urllib.request.Request(COMMANDS_URL, data=payload,
                                      headers={"Content-Type": "application/json"}, method="POST")
        urllib.request.urlopen(req, timeout=10).read()
    except Exception as e:
        write_error(f"_mark_command_done error: {e}")


def poll_and_execute_commands():
    """Pending Commands vom Server holen und ausführen (z.B. LM Studio unload)."""
    try:
        with urllib.request.urlopen(COMMANDS_URL, timeout=10) as resp:
            data = json.loads(resp.read().decode())
        for cmd in data.get("commands", []):
            # Commands für andere Maschinen ignorieren
            cmd_server = cmd.get("server_id")
            if cmd_server and cmd_server != SERVER_ID:
                continue
            cmd_id = cmd.get("id")
            action = cmd.get("action")
            success = False
            if action == "lm_studio_unload":
                model_id = cmd.get("model_id")
                targets = [model_id] if model_id else _lm_studio_loaded_models()
                success = bool(targets)
                for mid in targets:
                    if not _lm_studio_unload(mid):
                        success = False
            elif action == "ollama_unload":
                success = _ollama_unload_all()
            else:
                continue
            _mark_command_done(cmd_id, success)
            write_log(f"Executed {action} id={cmd_id} success={success}")
    except Exception as e:
        write_error(f"poll_and_execute_commands error: {e}")


def parse_ollama_logs():
    """Parse new Ollama GIN log lines since last run."""
    calls = []
    state = load_state()
    last_offset = state.get('ollama_log_offset', 0)

    if not os.path.exists(OLLAMA_LOG):
        return calls, last_offset

    file_size = os.path.getsize(OLLAMA_LOG)
    if file_size < last_offset:
        last_offset = 0  # log rotated

    try:
        with open(OLLAMA_LOG, 'r') as f:
            f.seek(last_offset)
            new_lines = f.readlines()
            new_offset = f.tell()
    except Exception:
        return calls, last_offset

    # [GIN] 2026/08/16 - 12:50:39 | 200 |  4.226518833s |       127.0.0.1 | POST     "/api/chat"
    gin_re = re.compile(
        r'\[GIN\]\s+(\d{4}/\d{2}/\d{2})\s+-\s+(\d{2}:\d{2}:\d{2})\s+\|\s+(\d+)\s+\|\s+([\d.]+\w*)\s+\|\s+(\S+)\s+\|\s+(\w+)\s+"([^"]+)"'
    )
    for line in new_lines:
        m = gin_re.match(line.strip())
        if not m:
            continue
        date_str, time_str, status, duration_str, ip, method, endpoint = m.groups()
        # Parse duration to ms: "4.226518833s", "24.800416ms", "157.75µs", "42ns"
        dur_str = duration_str.strip()
        try:
            if dur_str.endswith('ms'):
                duration_ms = float(dur_str[:-2])
            elif dur_str.endswith('µs') or dur_str.endswith('us'):
                duration_ms = float(dur_str[:-2]) / 1000
            elif dur_str.endswith('ns'):
                duration_ms = float(dur_str[:-2]) / 1_000_000
            elif dur_str.endswith('s'):
                duration_ms = float(dur_str[:-1]) * 1000
            else:
                duration_ms = 0.0
        except ValueError:
            duration_ms = 0.0
        # Parse timestamp
        try:
            ts = int(datetime.strptime(f"{date_str} {time_str}", "%Y/%m/%d %H:%M:%S").timestamp())
        except ValueError:
            continue
        calls.append({
            "ts": ts,
            "ip": ip.strip(),
            "method": method,
            "endpoint": endpoint,
            "duration_ms": round(duration_ms, 1),
            "status": int(status),
        })

    return calls, new_offset


def send_to_server(stats):
    try:
        payload = {
            "token": API_TOKEN,
            "host": stats["hostname"] or "unknown",
            "server_id": SERVER_ID,
            "ts": int(datetime.now().timestamp()),
            "cpu": stats["cpu_percent"] or 0.0,
            "gpu": stats.get("gpu", 0.0),
            "ram_percent": stats["memory_percent"] or 0.0,
            "ram_used_gb": stats.get("ram_used_gb"),
            "ram_total_gb": stats.get("ram_total_gb"),
            "ollama": stats.get("ollama"),
            "shelly_power": stats.get("shelly_power"),
            "tokens_per_second": stats.get("tokens_per_second"),
            "lm_studio_tps": stats.get("lm_studio_tps"),
        }
        data = json.dumps(payload).encode('utf-8')
        req = urllib.request.Request(SERVER_URL, data=data, headers={"Content-Type": "application/json"}, method="POST")
        with urllib.request.urlopen(req, timeout=10) as response:
            return response.read().decode()
    except Exception as e:
        write_error(f"Error sending to server: {str(e)}")
        return None


def send_ollama_requests(calls, new_offset):
    state = load_state()
    if not calls:
        state['ollama_log_offset'] = new_offset
        save_state(state)
        return
    try:
        payload = {"token": API_TOKEN, "calls": calls}
        data = json.dumps(payload).encode('utf-8')
        req = urllib.request.Request(REQUESTS_URL, data=data, headers={"Content-Type": "application/json"}, method="POST")
        with urllib.request.urlopen(req, timeout=10) as response:
            result = response.read().decode()
            write_log(f"Sent {len(calls)} ollama requests: {result}")
            state['ollama_log_offset'] = new_offset
            save_state(state)
    except Exception as e:
        write_error(f"Error sending ollama requests: {str(e)}")


def write_error(msg):
    with open(ERROR_LOG, "a") as f:
        f.write(f"{datetime.now().isoformat()} - {msg}\n")


def write_log(msg):
    with open(LOG_FILE, "a") as f:
        f.write(f"{datetime.now().isoformat()} - {msg}\n")


def main():
    # 1. Collect and send system stats
    stats = get_system_stats()

    # 2. TPS probe (cached via state, every PROBE_INTERVAL)
    state = load_state()
    now = time.time()
    if now - float(state.get('last_probe_ts', 0)) >= PROBE_INTERVAL:
        ollama_tps = probe_ollama_tps()
        lm_studio_tps = probe_lm_studio_tps()
        state['last_probe_ts'] = int(now)

        if ollama_tps is not None:
            _append_tps_history(state, 'ollama', ollama_tps)
            state['last_ollama_tps'] = ollama_tps

        if lm_studio_tps is not None:
            _append_tps_history(state, 'lm-studio', lm_studio_tps)
            state['last_lm_studio_tps'] = lm_studio_tps

        save_state(state)

    # Letzter bekannter TPS-Wert als Datenpunkt mitsenden (wie GPU-Leistung)
    stats["tokens_per_second"] = state.get('last_ollama_tps')
    stats["lm_studio_tps"] = state.get('last_lm_studio_tps')

    write_log(f"Collected stats: CPU={stats['cpu_percent']}%, RAM={stats['memory_percent']}%, Disk={stats['disk_percent']}%, Shelly={stats.get('shelly_power')}W")
    result = send_to_server(stats)
    if result:
        write_log(f"Sent to server: {result}")
    else:
        write_error("Failed to send stats to server")

    # 3. Poll and execute pending commands from server (e.g. LM Studio unload)
    poll_and_execute_commands()

    # 4. Parse and send Ollama API request logs
    calls, new_offset = parse_ollama_logs()
    send_ollama_requests(calls, new_offset)


if __name__ == "__main__":
    main()