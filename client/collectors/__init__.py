"""Collector-Registry. Jeder Collector ist optional und darf fehlschlagen,
ohne den Agenten zu stoppen."""
from __future__ import annotations

import platform
from typing import Any, Callable

from . import gpu, ollama, power, system, temps

# ── Legacy compatibility shims for monitor_linux.py ───────────────────────
# These re-export the old flat-function API so existing code keeps working.
def get_cpu_percent():
    """Legacy: returns float CPU percent."""
    return system.cpu_percent()

def get_ram_stats():
    """Legacy: returns (ram_percent, ram_used_gb, ram_total_gb).
    system.memory() already returns (used_gb, total_gb)."""
    used_gb, total_gb = system.memory()
    if total_gb == 0:
        return (0.0, 0.0, 0.0)
    percent = (used_gb / total_gb) * 100
    return (percent, round(used_gb, 2), round(total_gb, 2))

def get_gpu_stats():
    """Legacy: returns (gpu_percent, vram_used_gb, vram_total_gb, gpu_temp).
    Strix Halo unified memory – no per-device VRAM reporting via ROCm.
    Reports GPU util + total system RAM as shared pool.
    GPU-Temperatur via temps.cpu_temperature() (hwmon/sensors)."""
    g = gpu.snapshot(unified_memory_total_gb=124.0)
    # ROCm on Strix Halo returns {gpu_pct, vram_total_gb, source} — no "type" key
    if not g:
        return (-1.0, 0.0, 0.0, None)
    gpu_pct = g.get("gpu_pct", -1.0)
    if gpu_pct < 0:
        gpu_pct = g.get("utilization_percent", -1.0)
    # GPU-Temperatur: temps.cpu_temperature() nutzt hwmon/thermal/sensors
    gpu_temp = None
    try:
        gpu_temp = temps.cpu_temperature()
    except Exception:
        pass
    # ROCm: no vram_used/vram_total_mb available on Strix Halo
    return (gpu_pct, 0.0, 124.0, gpu_temp)

def get_shelly_power(base_url=None):
    """Legacy: returns float power or None."""
    return power.shelly_power(base_url) if base_url else None
# ───────────────────────────────────────────────────────────────────────────


def safe(fn: Callable[..., Any], *args, **kwargs) -> Any:
    try:
        return fn(*args, **kwargs)
    except Exception:  # noqa: BLE001 - Collector darf nie den Agent killen
        return None


OS_NAME = platform.system().lower()  # 'darwin' | 'linux' | ...

__all__ = ["gpu", "ollama", "power", "system", "temps", "safe", "OS_NAME",
           "get_cpu_percent", "get_ram_stats", "get_gpu_stats", "get_shelly_power"]
