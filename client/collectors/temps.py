"""Temperaturen - ohne Root-Daemon.

Der frueher genutzte SMC-Root-Daemon entfaellt bewusst. Stattdessen werden
nur unprivilegierte Quellen verwendet:
  Linux : /sys/class/hwmon, /sys/class/thermal, 'sensors -j'
  macOS : 'powermetrics' nur wenn passwortlos erlaubt, sonst None
"""
from __future__ import annotations

import glob
import json
import platform
import re

from .system import run


def _linux_hwmon() -> float | None:
    best: float | None = None
    for path in glob.glob("/sys/class/hwmon/hwmon*/temp*_input"):
        try:
            with open(path, "r", encoding="utf-8") as fh:
                val = float(fh.read().strip()) / 1000.0
        except (OSError, ValueError):
            continue
        if 5.0 < val < 130.0:
            best = val if best is None else max(best, val)
    if best is not None:
        return best
    for path in glob.glob("/sys/class/thermal/thermal_zone*/temp"):
        try:
            with open(path, "r", encoding="utf-8") as fh:
                val = float(fh.read().strip()) / 1000.0
        except (OSError, ValueError):
            continue
        if 5.0 < val < 130.0:
            best = val if best is None else max(best, val)
    return best


def _linux_sensors() -> float | None:
    out = run(["sensors", "-j"], timeout=5.0)
    if not out.strip().startswith("{"):
        return None
    try:
        data = json.loads(out)
    except json.JSONDecodeError:
        return None
    best: float | None = None

    def walk(node) -> None:
        nonlocal best
        if isinstance(node, dict):
            for key, val in node.items():
                if isinstance(val, (int, float)) and re.search(r"temp\d+_input", key):
                    f = float(val)
                    if 5.0 < f < 130.0:
                        best = f if best is None else max(best, f)
                else:
                    walk(val)

    walk(data)
    return best


def _macos_powermetrics() -> float | None:
    out = run(["powermetrics", "--samplers", "smc", "-n", "1", "-i", "200"], timeout=10.0)
    m = re.search(r"CPU die temperature:\s*([\d.]+)", out)
    if m:
        return float(m.group(1))
    m = re.search(r"GPU die temperature:\s*([\d.]+)", out)
    return float(m.group(1)) if m else None


def cpu_temperature() -> float | None:
    if platform.system() == "Darwin":
        return _macos_powermetrics()
    return _linux_hwmon() or _linux_sensors()
