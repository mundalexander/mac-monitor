"""LM Studio: geladene Modelle, Load-State, TPS-Probe, Request-Counts.

Plattformneutral — funktioniert auf Linux und macOS, solange LM Studio
auf 127.0.0.1:1234 (oder einer konfigurierbaren URL) laeuft.

Alle Funktionen sind safe: wenn LM Studio nicht laeuft, liefern sie
None / leere Listen, ohne den Agenten zu crashen.
"""
from __future__ import annotations

import json
import os
import subprocess
import time
import urllib.error
import urllib.request
from typing import Optional

DEFAULT_URL = "http://127.0.0.1:1234"
GB = 1024 ** 3

# Nicht-generative Modell-Typen — diese werden aus Load-State und Katalog gefiltert
_NON_GENERATIVE_TYPES = {"embeddings", "embedding", "tts", "stt"}


def _get(url: str, path: str, timeout: float = 4.0):
    """HTTP GET JSON helper. Returns parsed JSON or None."""
    try:
        req = urllib.request.Request(
            url.rstrip("/") + path,
            headers={"Accept": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except (urllib.error.URLError, OSError, json.JSONDecodeError, ValueError):
        return None


def _post_json(url: str, path: str, body: dict, timeout: float = 30.0):
    """HTTP POST JSON helper. Returns (status_code, parsed_json_or_None, raw_text_or_None)."""
    data = json.dumps(body).encode("utf-8")
    req = urllib.request.Request(
        url.rstrip("/") + path,
        data=data,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8")
            try:
                return resp.status, json.loads(raw), raw
            except json.JSONDecodeError:
                return resp.status, None, raw
    except urllib.error.HTTPError as exc:
        try:
            raw = exc.read().decode("utf-8")
            return exc.code, json.loads(raw), raw
        except Exception:
            return exc.code, None, ""
    except (urllib.error.URLError, OSError, socket_timeout := __import__("socket").timeout):
        return 0, None, ""


def available(url: str = DEFAULT_URL) -> bool:
    """Prueft ob LM Studio erreichbar ist."""
    return _get(url, "/api/v0/models") is not None or _get(url, "/v1/models") is not None


def loaded_models(url: str = DEFAULT_URL) -> list[dict]:
    """Gibt geladene generative Modelle zurueck (llm/vlm, keine embeddings).

    Nutzt /api/v0/models fuer den explicit load-state.
    Falls die native API nicht verfuegbar ist, wird /v1/models als Fallback
    verwendet (dort sind nur geladene Modelle gelistet).
    """
    out: list[dict] = []

    # Native API: explicit per-model load state
    native = _get(url, "/api/v0/models", timeout=3.0)
    if native:
        for m in native.get("data", []):
            mtype = str(m.get("type") or "").lower()
            if mtype in _NON_GENERATIVE_TYPES:
                continue
            state = str(m.get("state") or "").lower()
            if state in ("loaded", "loaded-in-memory"):
                mid = m.get("id", "unknown")
                out.append({
                    "name": mid,
                    "server": "lm-studio",
                    "req_count": int(m.get("total_requests", 0) or 0),
                    "req_dur_ms": round((m.get("total_duration", 0) or 0) / 1_000_000),
                })
        # If native API is available, return its result — do NOT fall
        # through to /v1/models (which lists the full catalog, not just
        # loaded models, and would falsely mark everything as loaded).
        return out

    # Fallback ONLY if native API is unavailable: /v1/models on llama-server
    # lists only actually-loaded models. On older LM Studio without native
    # API, this is the best we can do.
    catalog = _get(url, "/v1/models", timeout=3.0)
    if catalog:
        for m in catalog.get("data", []):
            mid = m.get("id", "unknown")
            out.append({"name": mid, "server": "lm-studio", "req_count": 0, "req_dur_ms": 0})

    return out


def catalog_models(url: str = DEFAULT_URL) -> list[dict]:
    """Vollstaendiger Modellkatalog (auch nicht geladene)."""
    out: list[dict] = []
    native = _get(url, "/api/v0/models", timeout=3.0)
    if native:
        # Build set of loaded generative model IDs for cross-reference
        loaded = loaded_models(url)
        loaded_ids = {m["name"] for m in loaded}
        for m in native.get("data", []):
            mtype = str(m.get("type") or "").lower()
            if mtype in _NON_GENERATIVE_TYPES:
                continue
            mid = m.get("id", "unknown")
            out.append({
                "name": mid,
                "server": "lm-studio",
                "loaded": mid in loaded_ids,
            })
        return out

    # Fallback: /v1/models (no load-state info available)
    catalog = _get(url, "/v1/models", timeout=3.0)
    if catalog:
        for m in catalog.get("data", []):
            out.append({"name": m.get("id", "unknown"), "server": "lm-studio", "loaded": True})
    return out


def request_counts(url: str = DEFAULT_URL) -> tuple[int, int]:
    """Liefert (total_requests, total_duration_ms) aller geladenen generativen Modelle."""
    total_req = 0
    total_dur_ms = 0
    native = _get(url, "/api/v0/models", timeout=3.0)
    if native:
        for m in native.get("data", []):
            mtype = str(m.get("type") or "").lower()
            if mtype in _NON_GENERATIVE_TYPES:
                continue
            state = str(m.get("state") or "").lower()
            if state in ("loaded", "loaded-in-memory"):
                total_req += int(m.get("total_requests", 0) or 0)
                total_dur_ms += round((m.get("total_duration", 0) or 0) / 1_000_000)
    return total_req, total_dur_ms


def probe_tps(url: str = DEFAULT_URL) -> tuple[Optional[float], Optional[dict]]:
    """Misst die Generation-Speed des ersten geladenen generativen Modells.

    Sendet eine kleine Streaming-Anfrage und misst tokens/sekunde aus den
    Chunk-Intervallen. Liefert (tps|None, probe_call_record|None).

    Das probe_call_record kann an requests.php gemeldet werden.
    """
    # Finde erstes geladenes generatives Modell
    model = None
    native = _get(url, "/api/v0/models", timeout=3.0)
    if native:
        for m in native.get("data", []):
            mtype = str(m.get("type") or "").lower()
            if mtype in _NON_GENERATIVE_TYPES:
                continue
            state = str(m.get("state") or "").lower()
            if state in ("loaded", "loaded-in-memory"):
                model = m.get("id")
                break

    if not model:
        return None, None

    body = json.dumps({
        "model": model,
        "messages": [{"role": "user", "content": "Count from 1 to 20, separated by commas."}],
        "max_tokens": 48,
        "stream": True,
    }).encode("utf-8")

    req = urllib.request.Request(
        url.rstrip("/") + "/v1/chat/completions",
        data=body,
        headers={"Content-Type": "application/json"},
        method="POST",
    )

    t0 = time.time()
    try:
        resp = urllib.request.urlopen(req, timeout=30)
        first = last = None
        n_chunks = 0
        for raw in resp:
            line = raw.decode("utf-8", errors="replace").strip()
            if not line.startswith("data:"):
                continue
            payload_s = line[5:].strip()
            if payload_s == "[DONE]":
                break
            try:
                chunk = json.loads(payload_s)
            except json.JSONDecodeError:
                continue
            delta = (chunk.get("choices") or [{}])[0].get("delta", {})
            token_text = delta.get("content") or delta.get("reasoning_content") or ""
            if token_text:
                t = time.time()
                if first is None:
                    first = t
                last = t
                n_chunks += 1

        t_total = time.time() - t0
        call = {
            "ts": int(time.time()),
            "ip": _machine_id(),
            "method": "POST",
            "endpoint": "/v1/chat/completions",
            "duration_ms": round(t_total * 1000, 1),
            "status": 200,
        }
        if n_chunks >= 2 and first is not None and last is not None and last > first:
            return round(n_chunks / (last - first), 1), call
        return None, call

    except Exception:
        call = {
            "ts": int(time.time()),
            "ip": _machine_id(),
            "method": "POST",
            "endpoint": "/v1/chat/completions",
            "duration_ms": round((time.time() - t0) * 1000, 1),
            "status": 503,
        }
        return None, call


def unload_model(model_id: str, lms_bin: str = "") -> bool:
    """Entlaedt ein Modell per lms CLI.

    LM Studio hat in dieser Version keine REST-Unload-Route, daher der
    CLI-Weg. Liefert True bei Erfolg.
    """
    binpath = lms_bin or os.path.expanduser("~/.lmstudio/bin/lms")
    if not os.path.isfile(binpath) or not os.access(binpath, os.X_OK):
        binpath = "lms"
    try:
        res = subprocess.run(
            [binpath, "unload", model_id],
            capture_output=True, text=True, timeout=30,
        )
        if res.returncode == 0:
            return True
    except FileNotFoundError:
        pass
    except Exception:
        pass
    return False


def _machine_id() -> str:
    """Kurze Machine-ID fuer Request-Logging (Server-ID des Clients)."""
    import socket
    raw = socket.gethostname().split(".")[0].strip().lower()
    return "".join(c if c.isalnum() or c in "-_" else "-" for c in raw) or "unknown-host"


def snapshot(url: str = DEFAULT_URL) -> dict:
    """Vollstaendige LM-Studio-Snapshot fuer den modularen Client.

    Liefert:
      - reachable: bool
      - loaded: list[dict] (name, server, req_count, req_dur_ms)
      - catalog: list[dict] (name, server, loaded)
      - req_count: int (total requests across loaded models)
      - req_dur_ms: int (total duration ms across loaded models)
    """
    loaded = loaded_models(url)
    catalog = catalog_models(url)
    req_count, req_dur_ms = request_counts(url)

    return {
        "reachable": available(url),
        "loaded": loaded,
        "catalog": catalog,
        "req_count": req_count,
        "req_dur_ms": req_dur_ms,
    }