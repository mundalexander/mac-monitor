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
def rocm() -> dict | None:
    if not _has("rocm-smi"):
        return None
    out = run(["rocm-smi", "--showuse", "--showmemuse", "--json"], timeout=8.0)
    if out.strip().startswith("{"):
        try:
            data = json.loads(out)
        except json.JSONDecodeError:
            data = {}
        for card in data.values():
            if not isinstance(card, dict):
                continue
            util = vram_used = vram_total = None
            for key, val in card.items():
                k = key.lower()
                num = re.sub(r"[^0-9.]", "", str(val))
                if not num:
                    continue
                try:
                    f = float(num)
                except ValueError:
                    continue
                if "gpu use" in k or k.startswith("gpu use"):
                    util = f
                elif "memory allocated" in k or "vram total used" in k:
                    vram_used = f
                elif "vram total memory" in k or "memory total" in k:
                    vram_total = f
            if util is not None or vram_total is not None:
                # ROCm liefert je nach Version Bytes oder MB.
                def norm(v: float | None) -> float | None:
                    if v is None:
                        return None
                    if v > 10 ** 9:
                        return v / GB
                    if v > 10 ** 5:
                        return v / 1024
                    return v
                return {"gpu_pct": util, "vram_used_gb": norm(vram_used),
                        "vram_total_gb": norm(vram_total), "source": "rocm-smi"}

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
