#!/usr/bin/env python3
"""
collectors.py — Hardware-naher Daten-Sammlung (CPU, RAM, GPU, Shelly).
Backend-unabhängig. Wird von monitor_linux.py importiert.
"""

import subprocess
import re
import json
import os
import time
from datetime import datetime

# ── Logging (geteilt mit monitor_linux.py) ────────────────────────────────
LOG_DIR  = os.path.expanduser("~/.local/share/mac-monitor")
ERROR_LOG = os.path.join(LOG_DIR, "monitor_linux.err.log")


def _write_error(msg):
    try:
        os.makedirs(LOG_DIR, exist_ok=True)
        with open(ERROR_LOG, "a") as f:
            f.write(f"{datetime.now().isoformat()} - {msg}\n")
    except Exception:
        pass


# ── CPU usage via /proc/stat ──────────────────────────────────────────────
_prev_cpu = None


def get_cpu_percent():
    """Calculate CPU usage since last call using /proc/stat."""
    global _prev_cpu
    try:
        with open("/proc/stat", "r") as f:
            line = f.readline()
        parts = line.split()
        fields = [int(x) for x in parts[1:]]
        idle = fields[3] + (fields[4] if len(fields) > 4 else 0)
        total = sum(fields)

        if _prev_cpu is None:
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
        _write_error(f"CPU parse error: {e}")
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
        _write_error(f"RAM parse error: {e}")
        return 0.0, 0.0, 0.0


# ── GPU via rocm-smi + /proc/meminfo (VRAM-Fix) ──────────────────────────
ROCM_SMI = "/opt/rocm/bin/rocm-smi"
ROCM_SMI_FALLBACKS = [
    "/usr/bin/rocm-smi",
    "/usr/local/bin/rocm-smi",
    "rocm-smi",
]


def _find_rocm_smi():
    if os.path.isfile(ROCM_SMI) and os.access(ROCM_SMI, os.X_OK):
        return ROCM_SMI
    for path in ROCM_SMI_FALLBACKS:
        if path == "rocm-smi":
            try:
                subprocess.check_output(["which", "rocm-smi"], stderr=subprocess.DEVNULL)
                return "rocm-smi"
            except Exception:
                continue
        if os.path.isfile(path) and os.access(path, os.X_OK):
            return path
    return None


def _get_vram_from_meminfo():
    """VRAM-Fix: Auf der 8060S (Unified Memory) meldet rocm-smi nur 1 GB.
    Stattdessen: /proc/meminfo → MemTotal = VRAM total, MemTotal-MemAvailable = VRAM used."""
    try:
        info = {}
        with open("/proc/meminfo", "r") as f:
            for line in f:
                parts = line.split(":")
                if len(parts) == 2:
                    key = parts[0].strip()
                    val = int(parts[1].strip().split()[0]) * 1024
                    info[key] = val
        total = info.get("MemTotal", 0)
        avail = info.get("MemAvailable", info.get("MemFree", 0))
        used = total - avail
        if total > 0:
            return round(used / (1024 ** 3), 2), round(total / (1024 ** 3), 2)
    except Exception:
        pass
    return None, None


def get_gpu_stats():
    """Returns (gpu_percent, vram_used_gb, vram_total_gb, gpu_temp).
    GPU-Utilization + Temp: rocm-smi / amd-smi.
    VRAM: /proc/meminfo (Unified-Memory-Fix für 8060S)."""
    gpu_percent = -1.0
    gpu_temp = None

    # GPU utilization via rocm-smi
    rocm = _find_rocm_smi()
    if rocm:
        try:
            output = subprocess.check_output(
                [rocm, "--showuse", "--json"],
                stderr=subprocess.DEVNULL, timeout=10
            ).decode()
            data = json.loads(output)
            card_key = None
            for k in data:
                if k.startswith("card"):
                    card_key = k
                    break
            if not card_key:
                for k in data:
                    card_key = k
                    break
            if card_key:
                card = data[card_key]
                for key in ("GPU use (%)", "GPU_USE", "gpu_use"):
                    if key in card:
                        try:
                            gpu_percent = float(str(card[key]).strip())
                        except (ValueError, TypeError):
                            pass
                        break
        except Exception as e:
            _write_error(f"rocm-smi GPU% error: {e}")

    # GPU temperature via amd-smi
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

    # VRAM via /proc/meminfo (Unified Memory Fix)
    vram_used_gb, vram_total_gb = _get_vram_from_meminfo()

    return gpu_percent, vram_used_gb, vram_total_gb, gpu_temp


# ── Shelly power (optional) ───────────────────────────────────────────────
def get_shelly_power(shelly_url=None):
    if not shelly_url:
        return None
    try:
        import urllib.request
        resp = urllib.request.urlopen(shelly_url, timeout=3)
        data = json.loads(resp.read().decode())
        return data.get("switch:0", {}).get("apower", None)
    except Exception:
        return None
