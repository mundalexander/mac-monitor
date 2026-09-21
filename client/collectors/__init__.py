"""Collector-Registry. Jeder Collector ist optional und darf fehlschlagen,
ohne den Agenten zu stoppen."""
from __future__ import annotations

import platform
from typing import Any, Callable

from . import gpu, ollama, power, system, temps


def safe(fn: Callable[..., Any], *args, **kwargs) -> Any:
    try:
        return fn(*args, **kwargs)
    except Exception:  # noqa: BLE001 - Collector darf nie den Agent killen
        return None


OS_NAME = platform.system().lower()  # 'darwin' | 'linux' | ...

__all__ = ["gpu", "ollama", "power", "system", "temps", "safe", "OS_NAME"]
