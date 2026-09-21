"""Ollama: installierte Modelle, geladene Modelle, TPS.

TPS-Fix
-------
Frueher wurde TPS aus Logzeilen geraten und blieb als Altwert stehen.
Jetzt gibt es drei klar getrennte Quellen mit Prioritaet:

1. Exakte Events aus dem lokalen Spool (~/.mac-monitor/tps.jsonl), den
   integrations/agent_client.py aus der Ollama-Antwort schreibt
   (eval_count / eval_duration).
2. Optionales Log-Parsing mit persistentem Offset (kein Vollscan).
3. Kein Wert -> 0.0 statt eingefrorener Altwert.
"""
from __future__ import annotations

import json
import os
import time
import urllib.error
import urllib.request
from pathlib import Path

DEFAULT_URL = "http://127.0.0.1:11434"
SPOOL = Path(os.path.expanduser("~/.mac-monitor/tps.jsonl"))
GB = 1024 ** 3

# Zeitfenster
CURRENT_WINDOW = 30      # Sekunden: "TPS jetzt"
AVG_WINDOW = 300         # Sekunden: Durchschnitt und Peak


def _get(url: str, path: str, timeout: float = 4.0):
    try:
        req = urllib.request.Request(url.rstrip("/") + path,
                                     headers={"Accept": "application/json"})
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except (urllib.error.URLError, OSError, json.JSONDecodeError, ValueError):
        return None


def available(url: str = DEFAULT_URL) -> bool:
    return _get(url, "/api/tags") is not None


def installed_models(url: str = DEFAULT_URL) -> list[str]:
    data = _get(url, "/api/tags") or {}
    return [m.get("name", "") for m in data.get("models", []) if m.get("name")]


def loaded_models(url: str = DEFAULT_URL) -> list[dict]:
    """/api/ps: aktuell im Speicher gehaltene Modelle."""
    data = _get(url, "/api/ps") or {}
    out = []
    for m in data.get("models", []):
        out.append({
            "name": m.get("name") or m.get("model") or "",
            "size_gb": round((m.get("size") or 0) / GB, 2),
            "vram_gb": round((m.get("size_vram") or 0) / GB, 2),
            "expires_at": m.get("expires_at", ""),
        })
    return [m for m in out if m["name"]]


class TPSTracker:
    """Fensterbasierte TPS-Berechnung aus exakten Messereignissen."""

    def __init__(self, spool: Path = SPOOL) -> None:
        self.spool = spool
        self._offset = 0
        self._events: list[dict] = []
        self._pending: list[dict] = []

    # -- Spool lesen (inkrementell, rotationssicher) ------------------------
    def _read_spool(self) -> None:
        try:
            if not self.spool.is_file():
                return
            size = self.spool.stat().st_size
            if size < self._offset:      # Datei rotiert/geleert
                self._offset = 0
            if size == self._offset:
                return
            with self.spool.open("r", encoding="utf-8", errors="ignore") as fh:
                fh.seek(self._offset)
                for line in fh:
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        ev = json.loads(line)
                    except json.JSONDecodeError:
                        continue
                    ev = self._normalize(ev)
                    if ev:
                        self._events.append(ev)
                        self._pending.append(ev)
                self._offset = fh.tell()
        except OSError:
            return

    @staticmethod
    def _normalize(ev: dict) -> dict | None:
        """Akzeptiert rohe Ollama-Antworten und fertige Events."""
        if not isinstance(ev, dict):
            return None
        ts = int(ev.get("ts") or time.time())
        tokens = int(ev.get("eval_count") or ev.get("eval_tokens") or 0)
        ns = float(ev.get("eval_duration") or 0)
        seconds = float(ev.get("eval_seconds") or (ns / 1e9 if ns else 0))
        rate = float(ev.get("tps") or 0)
        if rate <= 0 and tokens > 0 and seconds > 0:
            rate = tokens / seconds
        if rate <= 0:
            return None
        return {
            "ts": ts, "model": str(ev.get("model") or "")[:160],
            "task_id": str(ev.get("task_id") or "")[:120],
            "reservation_id": str(ev.get("reservation_id") or "")[:64],
            "eval_count": tokens,
            "eval_seconds": round(seconds, 3),
            "prompt_eval_count": int(ev.get("prompt_eval_count") or 0),
            "prompt_eval_duration": float(ev.get("prompt_eval_duration") or 0),
            "tps": round(rate, 2),
            "source": str(ev.get("source") or "spool")[:32],
        }

    def add_event(self, ev: dict) -> None:
        norm = self._normalize(ev)
        if norm:
            self._events.append(norm)
            self._pending.append(norm)

    # -- Auswertung ---------------------------------------------------------
    def stats(self) -> dict:
        self._read_spool()
        now = time.time()
        self._events = [e for e in self._events if now - e["ts"] <= AVG_WINDOW]

        recent = [e for e in self._events if now - e["ts"] <= CURRENT_WINDOW]
        # "jetzt" = gewichteter Durchsatz im Fenster, nicht letzter Einzelwert
        cur = 0.0
        if recent:
            tokens = sum(e["eval_count"] for e in recent)
            secs = sum(e["eval_seconds"] for e in recent)
            cur = (tokens / secs) if secs > 0 else max(e["tps"] for e in recent)
        avg = (sum(e["tps"] for e in self._events) / len(self._events)) if self._events else 0.0
        peak = max((e["tps"] for e in self._events), default=0.0)
        return {
            "tps_current": round(cur, 2),   # 0.0 wenn gerade nichts laeuft
            "tps_avg": round(avg, 2),
            "tps_peak": round(peak, 2),
            "active_jobs": len({e["task_id"] for e in recent if e["task_id"]}),
            "window_events": len(self._events),
        }

    def drain_events(self, limit: int = 50) -> list[dict]:
        """Noch nicht uebertragene Messungen fuer den Server."""
        out = self._pending[:limit]
        self._pending = self._pending[limit:]
        return out


def snapshot(url: str = DEFAULT_URL) -> dict:
    loaded = loaded_models(url)
    return {
        "reachable": available(url),
        "loaded_models": [m["name"] for m in loaded],
        "loaded_detail": loaded,
        "installed_count": len(installed_models(url)),
        "vram_models_gb": round(sum(m["vram_gb"] for m in loaded), 2),
    }
