"""Stromverbrauch ueber Shelly-Steckdosen (Gen1 und Gen2, optional)."""
from __future__ import annotations

import json
import urllib.error
import urllib.request


def _get(url: str, timeout: float = 3.0):
    try:
        with urllib.request.urlopen(url, timeout=timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except (urllib.error.URLError, OSError, json.JSONDecodeError, ValueError):
        return None


def shelly_power(base_url: str, channel: int = 0) -> float | None:
    """Gibt Watt zurueck oder None, wenn die Steckdose nicht erreichbar ist."""
    if not base_url:
        return None
    base = base_url.rstrip("/")
    if "://" not in base:
        base = "http://" + base

    # Gen2/Gen3 (Plus/Pro)
    data = _get(f"{base}/rpc/Switch.GetStatus?id={channel}")
    if isinstance(data, dict) and "apower" in data:
        try:
            return float(data["apower"])
        except (TypeError, ValueError):
            return None

    # Gen1
    data = _get(f"{base}/meter/{channel}")
    if isinstance(data, dict) and "power" in data:
        try:
            return float(data["power"])
        except (TypeError, ValueError):
            return None

    data = _get(f"{base}/status")
    if isinstance(data, dict):
        meters = data.get("meters") or []
        if len(meters) > channel and "power" in meters[channel]:
            try:
                return float(meters[channel]["power"])
            except (TypeError, ValueError):
                return None
    return None
