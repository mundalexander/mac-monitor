"""CPU, RAM, Load - macOS und Linux."""
from __future__ import annotations

import json
import os
import platform
import re
import subprocess
import time

GB = 1024 ** 3


def run(cmd: list[str], timeout: float = 5.0) -> str:
    try:
        out = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout,
                             check=False)
        return out.stdout if out.returncode == 0 else ""
    except (OSError, subprocess.SubprocessError):
        return ""


# --------------------------------------------------------------------------
# CPU
# --------------------------------------------------------------------------
# State-File für CPU-Delta: überlebt Prozess-Neustarts (run_monitor_linux.sh
# startet alle 10s einen neuen Python-Prozess → globale _prev_cpu ist immer None).
_CPU_STATE_FILE = os.path.expanduser("~/.local/share/mac-monitor/cpu_state.json")
_prev_cpu: tuple[int, int] | None = None


def _load_cpu_state() -> tuple[int, int] | None:
    """Lädt letzten total/idle aus dem State-File (prozessübergreifend)."""
    try:
        with open(_CPU_STATE_FILE, "r", encoding="utf-8") as fh:
            data = json.load(fh)
        return (int(data["total"]), int(data["idle"]))
    except (OSError, ValueError, KeyError, json.JSONDecodeError):
        return None


def _save_cpu_state(total: int, idle: int) -> None:
    """Speichert total/idle ins State-File für den nächsten Prozess-Zyklus."""
    try:
        os.makedirs(os.path.dirname(_CPU_STATE_FILE), exist_ok=True)
        with open(_CPU_STATE_FILE, "w", encoding="utf-8") as fh:
            json.dump({"total": total, "idle": idle}, fh)
    except OSError:
        pass


def cpu_percent_linux() -> float:
    """Delta-basiert aus /proc/stat - stabil und ohne Fremdpakete.

    Nutzt ein State-File für prozessübergreifendes Delta (run_monitor_linux.sh
    startet jeden Zyklus einen neuen Prozess → In-Memory _prev_cpu ist None).
    Fallback auf In-Memory _prev_cpu für macOS-Modus oder langlaufende Prozesse.
    """
    global _prev_cpu
    try:
        with open("/proc/stat", "r", encoding="utf-8") as fh:
            parts = fh.readline().split()
    except OSError:
        return 0.0
    if len(parts) < 5 or parts[0] != "cpu":
        return 0.0
    vals = [int(v) for v in parts[1:] if v.isdigit()]
    idle = vals[3] + (vals[4] if len(vals) > 4 else 0)
    total = sum(vals)

    # 1. Versuch: In-Memory _prev_cpu (langlaufender Prozess)
    prev = _prev_cpu
    # 2. Versuch: State-File (neuer Prozess via run_monitor_linux.sh)
    if prev is None:
        prev = _load_cpu_state()

    # Aktuelle Werte persistieren (In-Memory + State-File)
    _prev_cpu = (total, idle)
    _save_cpu_state(total, idle)

    if not prev:
        return 0.0
    dt, di = total - prev[0], idle - prev[1]
    if dt <= 0:
        return 0.0
    return max(0.0, min(100.0, (1.0 - di / dt) * 100.0))


def cpu_percent_macos() -> float:
    """top -l 2 liefert einen echten Delta-Wert (erster Durchlauf ist Muell)."""
    out = run(["top", "-l", "2", "-n", "0", "-s", "1"], timeout=8.0)
    idles = re.findall(r"(\d+\.\d+)%\s+idle", out)
    if idles:
        return max(0.0, min(100.0, 100.0 - float(idles[-1])))
    # Fallback ueber Load Average und Kernzahl
    la = os.getloadavg()[0] if hasattr(os, "getloadavg") else 0.0
    cores = os.cpu_count() or 1
    return max(0.0, min(100.0, la / cores * 100.0))


def cpu_percent() -> float:
    return cpu_percent_macos() if platform.system() == "Darwin" else cpu_percent_linux()


# --------------------------------------------------------------------------
# RAM
# --------------------------------------------------------------------------
def memory_linux() -> tuple[float, float]:
    total = avail = 0
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as fh:
            for line in fh:
                if line.startswith("MemTotal:"):
                    total = int(line.split()[1]) * 1024
                elif line.startswith("MemAvailable:"):
                    avail = int(line.split()[1]) * 1024
                if total and avail:
                    break
    except OSError:
        return 0.0, 0.0
    return (total - avail) / GB, total / GB


def memory_macos() -> tuple[float, float]:
    total_b = 0
    out = run(["sysctl", "-n", "hw.memsize"])
    if out.strip().isdigit():
        total_b = int(out.strip())
    vm = run(["vm_stat"])
    page = 4096
    m = re.search(r"page size of (\d+) bytes", vm)
    if m:
        page = int(m.group(1))
    stats = dict(re.findall(r"^(.*?):\s+(\d+)\.", vm, re.MULTILINE))

    def g(key: str) -> int:
        return int(stats.get(key, 0))

    # "belegt" = alles ausser frei und spekulativ/cached-file
    free_pages = g("Pages free") + g("Pages speculative") + g("File-backed pages")
    used_b = max(0, total_b - free_pages * page)
    return used_b / GB, total_b / GB


def memory() -> tuple[float, float]:
    return memory_macos() if platform.system() == "Darwin" else memory_linux()


def load1() -> float:
    try:
        return os.getloadavg()[0]
    except (OSError, AttributeError):
        return 0.0


def cpu_model() -> str:
    if platform.system() == "Darwin":
        return run(["sysctl", "-n", "machdep.cpu.brand_string"]).strip() or platform.processor()
    try:
        with open("/proc/cpuinfo", "r", encoding="utf-8") as fh:
            for line in fh:
                if line.lower().startswith("model name"):
                    return line.split(":", 1)[1].strip()
    except OSError:
        pass
    return platform.processor() or platform.machine()


def snapshot() -> dict:
    used, total = memory()
    return {
        "cpu_pct": round(cpu_percent(), 1),
        "load1": round(load1(), 2),
        "ram_used_gb": round(used, 2),
        "ram_total_gb": round(total, 2),
        "collected_at": int(time.time()),
    }
