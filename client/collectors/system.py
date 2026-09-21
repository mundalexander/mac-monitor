"""CPU, RAM, Load - macOS und Linux."""
from __future__ import annotations

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
_prev_cpu: tuple[int, int] | None = None


def cpu_percent_linux() -> float:
    """Delta-basiert aus /proc/stat - stabil und ohne Fremdpakete."""
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
    prev = _prev_cpu
    _prev_cpu = (total, idle)
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
