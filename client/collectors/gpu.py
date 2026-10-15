"""GPU und VRAM - ROCm (AMD), NVIDIA, Apple Silicon.

Alle Quellen sind optional. Faellt eine Quelle aus, liefert der Collector
None statt zu stehenbleiben (kein eingefrorener Altwert).
"""
from __future__ import annotations

import json
import platform
import re
import shutil

from .system import run

GB = 1024 ** 3


def _has(binary: str) -> bool:
    return shutil.which(binary) is not None


# --------------------------------------------------------------------------
# AMD / ROCm
# --------------------------------------------------------------------------
def _meminfo_bytes(area: str) -> tuple[float | None, float | None]:
    """rocm-smi --showmeminfo <area> --json -> (used_bytes, total_bytes).

    Area 'vram' oder 'gtt'. Felder sind explizit Bytes (B), keine Prozente.
    """
    out = run(["rocm-smi", "--showmeminfo", area, "--json"], timeout=8.0)
    if not out.strip().startswith("{"):
        return None, None
    try:
        data = json.loads(out)
    except json.JSONDecodeError:
        return None, None
    used = total = None
    for card in data.values():
        if not isinstance(card, dict):
            continue
        for key, val in card.items():
            k = key.lower()
            num = re.sub(r"[^0-9.]", "", str(val))
            if not num:
                continue
            try:
                f = float(num)
            except ValueError:
                continue
            if "used" in k:
                used = f
            elif "total memory" in k or "total" in k:
                total = f
    return used, total


def rocm() -> dict | None:
    if not _has("rocm-smi"):
        return None

    # Ground-Truth in Bytes: dedizierte VRAM (1 GiB) ist nutlos, die GPU
    # allokiert aus System-RAM via GTT. --showmemuse liefert nur PROZENTE
    # und wird hier bewusst NICHT mehr fuer vram_used_gb verwendet.
    # Hinweis: `--showmeminfo vram,gtt` liefert auf dieser rocm-smi-Version
    # kein JSON ("No JSON data to report"), daher beide Areas separat abfragen.
    vram_used_b, vram_total_b = _meminfo_bytes("vram")
    gtt_used_b, gtt_total_b = _meminfo_bytes("gtt")

    util = None
    out = run(["rocm-smi", "--showuse", "--json"], timeout=8.0)
    if out.strip().startswith("{"):
        try:
            data = json.loads(out)
            for card in data.values():
                if not isinstance(card, dict):
                    continue
                for key, val in card.items():
                    if "gpu use" in key.lower():
                        num = re.sub(r"[^0-9.]", "", str(val))
                        if num:
                            util = float(num)
        except json.JSONDecodeError:
            pass

    vram_used_gb = vram_total_gb = None
    total_b = (vram_total_b or 0) + (gtt_total_b or 0)
    if total_b > 0:
        used_b = (vram_used_b or 0) + (gtt_used_b or 0)
        vram_used_gb = round(used_b / GB, 2)
        vram_total_gb = round(total_b / GB, 2)

    if util is not None or vram_total_gb is not None:
        return {"gpu_pct": util, "vram_used_gb": vram_used_gb,
                "vram_total_gb": vram_total_gb, "source": "rocm-smi"}

    # Textfallback fuer aeltere rocm-smi Versionen
    txt = run(["rocm-smi", "--showuse", "--showmemuse"], timeout=8.0)
    util_m = re.search(r"GPU use \(%\)\s*:?\s*(\d+)", txt)
    if util_m:
        return {"gpu_pct": float(util_m.group(1)), "vram_used_gb": None,
                "vram_total_gb": None, "source": "rocm-smi-text"}
    return None


# --------------------------------------------------------------------------
# NVIDIA
# --------------------------------------------------------------------------
def nvidia() -> dict | None:
    if not _has("nvidia-smi"):
        return None
    out = run(["nvidia-smi",
               "--query-gpu=utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw",
               "--format=csv,noheader,nounits"], timeout=8.0)
    line = out.strip().splitlines()[0] if out.strip() else ""
    if not line:
        return None
    parts = [p.strip() for p in line.split(",")]

    def f(i: int) -> float | None:
        try:
            return float(parts[i])
        except (IndexError, ValueError):
            return None

    return {"gpu_pct": f(0), "vram_used_gb": (f(1) or 0) / 1024,
            "vram_total_gb": (f(2) or 0) / 1024, "temp_c": f(3),
            "power_w": f(4), "source": "nvidia-smi"}


# --------------------------------------------------------------------------
# Apple Silicon
# --------------------------------------------------------------------------
def apple() -> dict | None:
    if platform.system() != "Darwin":
        return None
    # ioreg ist ohne Root lesbar; Werte sind je nach Modell unterschiedlich benannt.
    out = run(["ioreg", "-r", "-d", "1", "-w", "0", "-c", "IOAccelerator"], timeout=8.0)
    util = None
    m = re.search(r'"Device Utilization %"\s*=\s*(\d+)', out)
    if m:
        util = float(m.group(1))
    else:
        m = re.search(r'"GPU Core Utilization"\s*=\s*(\d+)', out)
        if m:
            util = float(m.group(1)) / 10_000_000.0
            util = max(0.0, min(100.0, util))
    alloc = re.search(r'"In use system memory"\s*=\s*(\d+)', out)
    vram_used = float(alloc.group(1)) / GB if alloc else None
    if util is None and vram_used is None:
        return None
    return {"gpu_pct": util, "vram_used_gb": vram_used, "vram_total_gb": None,
            "source": "ioreg"}


def snapshot(unified_memory_total_gb: float = 0.0) -> dict:
    """Erste erfolgreiche Quelle gewinnt."""
    for fn in (rocm, nvidia, apple):
        try:
            res = fn()
        except Exception:  # noqa: BLE001
            res = None
        if res:
            # Apple Silicon: VRAM ist Unified Memory.
            if res.get("vram_total_gb") in (None, 0) and unified_memory_total_gb:
                res["vram_total_gb"] = unified_memory_total_gb
            return {k: v for k, v in res.items() if v is not None}
    return {"source": "none"}


def gpu_model() -> str:
    if platform.system() == "Darwin":
        out = run(["system_profiler", "SPDisplaysDataType"], timeout=15.0)
        m = re.search(r"Chipset Model:\s*(.+)", out)
        return m.group(1).strip() if m else "Apple GPU"
    if _has("rocm-smi"):
        out = run(["rocm-smi", "--showproductname"], timeout=8.0)
        m = re.search(r"Card [Ss]eries:\s*(.+)", out) or re.search(r"Card model:\s*(.+)", out)
        if m:
            return m.group(1).strip()
        return "AMD GPU"
    if _has("nvidia-smi"):
        out = run(["nvidia-smi", "--query-gpu=name", "--format=csv,noheader"], timeout=8.0)
        if out.strip():
            return out.strip().splitlines()[0]
    return ""
