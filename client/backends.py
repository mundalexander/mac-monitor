#!/usr/bin/env python3
"""
backends.py — LLM-Backend-Interface + Implementierungen.
Skalierbar auf LM Studio, Ollama, llama.cpp (llama-server), und weitere.

Jedes Backend spricht (fast) OpenAI-kompatible API.
Nur Endpunkte und 2-3 Spezialfälle unterscheiden sich.
"""

import json
import subprocess
import urllib.request
from datetime import datetime
import os
import time

LOG_DIR = os.path.expanduser("~/.local/share/mac-monitor")
ERROR_LOG = os.path.join(LOG_DIR, "monitor_linux.err.log")


def _write_error(msg):
    try:
        os.makedirs(LOG_DIR, exist_ok=True)
        with open(ERROR_LOG, "a") as f:
            f.write(f"{datetime.now().isoformat()} - {msg}\n")
    except Exception:
        pass


def _http_get(url, timeout=5):
    """GET request → parsed JSON."""
    resp = urllib.request.urlopen(url, timeout=timeout)
    return json.loads(resp.read().decode())


def _http_post(url, body_dict, timeout=10):
    """POST JSON request → parsed JSON."""
    body = json.dumps(body_dict).encode("utf-8")
    req = urllib.request.Request(
        url, data=body,
        headers={"Content-Type": "application/json"}, method="POST",
    )
    resp = urllib.request.urlopen(req, timeout=timeout)
    return json.loads(resp.read().decode())


# ── Base Interface ────────────────────────────────────────────────────────
class LLMBackend:
    """Base class for all LLM backends."""
    name: str = "generic"
    url: str = ""

    def status(self) -> dict:
        """Returns {'loaded': [...], 'available': [...], 'error': None|str}."""
        raise NotImplementedError

    def live_tps(self) -> float | None:
        """Returns current tokens/sec if actively generating, None otherwise."""
        return None

    def unload(self, model: str) -> bool:
        """Unload a model. Returns True on success."""
        raise NotImplementedError

    def is_running(self) -> bool:
        """Check if the backend service is reachable."""
        try:
            _http_get(self.url + "/v1/models", timeout=2)
            return True
        except Exception:
            return False


# ── LM Studio ─────────────────────────────────────────────────────────────
class LMStudioBackend(LLMBackend):
    """LM Studio :1234 — native API (/api/v0/models) + OpenAI-compat (/v1/models)."""
    name = "lm-studio"

    def __init__(self, url="http://127.0.0.1:1234"):
        self.url = url

    def status(self) -> dict:
        loaded = []
        available = []
        try:
            # Native API: explicit load state
            lm_loaded_ids = []
            try:
                data = _http_get(self.url + "/api/v0/models", timeout=3)
                for m in data.get("data", []):
                    if m.get("type") == "embeddings":
                        continue
                    if m.get("state") == "loaded":
                        lm_loaded_ids.append(m.get("id", "unknown"))
            except Exception:
                pass  # native API unavailable

            # OpenAI-compat: full catalog
            data = _http_get(self.url + "/v1/models", timeout=3)
            for m in data.get("data", []):
                model_id = m.get("id", "unknown")
                if model_id in lm_loaded_ids:
                    loaded.append({"name": model_id, "server": self.name})
                else:
                    available.append({"name": model_id, "server": self.name})
        except Exception as e:
            return {"loaded": [], "available": [], "error": str(e)}

        return {"loaded": loaded, "available": available, "error": None}

    def unload(self, model: str) -> bool:
        """LM Studio: killt den spezifischen llama-server Prozess für das Modell.

        LM Studio hat keinen HTTP-Unload-API. Modelle laufen als separate
        llama-server Prozesse auf zufälligen Ports. Wir finden den Prozess
        dessen --model-Argument zum Ziel passt und killen ihn gezielt."""
        try:
            # Finde alle llama-server Prozesse mit --model
            result = subprocess.run(
                ["pgrep", "-a", "-f", "llama-server.*--model"],
                capture_output=True, text=True, timeout=5)
            killed = False
            for line in result.stdout.strip().split("\n"):
                if not line.strip():
                    continue
                pid_str, cmdline = line.split(None, 1)
                pid = int(pid_str)
                # --model argument extrahieren
                parts = cmdline.split("\0") if "\0" in cmdline else cmdline.split()
                model_path = ""
                for i, p in enumerate(parts):
                    if p == "--model" and i + 1 < len(parts):
                        model_path = parts[i + 1]
                        break
                if not model_path:
                    continue
                model_base = model_path.split("/")[-1].lower().replace(".gguf", "")
                # Model-ID kann sein: 'qwen/qwen3.8-27b' oder 'qwen3.8-27b'
                # Dateiname kann sein: 'qwen3.8-27b-q6_k' oder 'qwen3.5-122b-a10b-ud-q4_k_xl-00001-of-00003'
                target = model.lower().replace(".gguf", "")
                # Nimm den Teil nach dem letzten '/' (Publisher-Prefix entfernen)
                target_short = target.split("/")[-1]
                # Match: Model-ID-Teil muss im Dateinamen enthalten sein (ohne Quant-Suffix)
                if target_short in model_base or model_base.startswith(target_short):
                    subprocess.run(["kill", str(pid)], capture_output=True, timeout=10)
                    # Warte bis Prozess weg ist
                    for _ in range(10):
                        if not os.path.exists(f"/proc/{pid}"):
                            break
                        time.sleep(0.5)
                    killed = True
                    _write_error(f"LM Studio unload: killed PID {pid} ({model_base})")
                    break
            if not killed:
                _write_error(f"LM Studio unload: no llama-server found for '{model}'")
            return killed
        except Exception as e:
            _write_error(f"LM Studio unload error ({model}): {e}")
            return False

    def is_running(self) -> bool:
        try:
            _http_get(self.url + "/api/v0/models", timeout=2)
            return True
        except Exception:
            return False


# ── Ollama ────────────────────────────────────────────────────────────────
class OllamaBackend(LLMBackend):
    """Ollama :11434 — /api/tags (catalog) + /api/ps (loaded)."""
    name = "ollama"

    def __init__(self, url="http://127.0.0.1:11434"):
        self.url = url

    def status(self) -> dict:
        loaded = []
        available = []
        try:
            data = _http_get(self.url + "/api/tags", timeout=5)
            available = [
                {"name": m["name"],
                 "size_gb": round(m.get("size", 0) / (1024 ** 3), 1),
                 "server": self.name}
                for m in data.get("models", [])
            ]
        except Exception as e:
            err = str(e)
            if "Connection refused" in err or "urlopen error" in err:
                return {"loaded": [], "available": [], "error": "ollama_offline"}
            return {"loaded": [], "available": [], "error": err}

        try:
            ps_data = _http_get(self.url + "/api/ps", timeout=5)
            loaded = [
                {"name": m["name"],
                 "size_vram_gb": round(m.get("size_vram", 0) / (1024 ** 3), 1),
                 "server": self.name}
                for m in ps_data.get("models", [])
            ]
        except Exception:
            pass  # /api/ps might not be available on older versions

        return {"loaded": loaded, "available": available, "error": None}

    def unload(self, model: str) -> bool:
        """Ollama: keep_alive:0 unloads the model."""
        try:
            _http_post(self.url + "/api/generate",
                       {"name": model, "keep_alive": 0}, timeout=15)
            return True
        except Exception as e:
            _write_error(f"Ollama unload error ({model}): {e}")
            return False

    def is_running(self) -> bool:
        try:
            _http_get(self.url + "/api/tags", timeout=2)
            return True
        except Exception:
            return False


# ── llama-server (llama.cpp) ──────────────────────────────────────────────
class LlamaServerBackend(LLMBackend):
    """llama-server :PORT — /v1/models (nur geladene), /slots (Status)."""
    name = "llama-server"

    def __init__(self, url="http://127.0.0.1:8080", api_key=None):
        self.url = url
        self.api_key = api_key

    def status(self) -> dict:
        loaded = []
        try:
            data = _http_get(self.url + "/v1/models", timeout=3)
            for m in data.get("data", []):
                model_id = m.get("id", "unknown")
                meta = m.get("meta", {})
                size_bytes = meta.get("size", 0)
                size_gb = round(size_bytes / (1024 ** 3), 1) if size_bytes else None
                loaded.append({
                    "name": model_id,
                    "size_vram_gb": size_gb,
                    "server": self.name,
                })
        except Exception as e:
            return {"loaded": [], "available": [], "error": str(e)}

        return {"loaded": loaded, "available": [], "error": None}

    def live_tps(self) -> float | None:
        """Live-TPS via /slots (wenn API-Key verfügbar).
        Prüft is_processing + n_prompt_tokens_processed Delta."""
        if not self.api_key:
            return None
        try:
            req = urllib.request.Request(
                self.url + "/slots",
                headers={"Authorization": f"Bearer {self.api_key}"},
            )
            resp = urllib.request.urlopen(req, timeout=3)
            data = json.loads(resp.read().decode())
            slots = data if isinstance(data, list) else data.get("slots", [])
            for slot in slots:
                if slot.get("is_processing"):
                    # Während Processing: n_prompt_tokens_processed zeigt Prefill-Fortschritt
                    # Für Decode-TPS bräuchten wir /metrics Differenz
                    return None  # TODO: metrics-differencing
            return None
        except Exception:
            return None

    def unload(self, model: str) -> bool:
        """llama-server: kein sauberes Unload-API → fuser -k."""
        try:
            port = self.url.split(":")[-1].split("/")[0]
            subprocess.run(["fuser", "-k", f"{port}/tcp"],
                           capture_output=True, timeout=10)
            return True
        except Exception as e:
            _write_error(f"llama-server unload error ({model}): {e}")
            return False

    def is_running(self) -> bool:
        try:
            _http_get(self.url + "/v1/models", timeout=2)
            return True
        except Exception:
            return False


# ── Discovery ─────────────────────────────────────────────────────────────
def discover_backends() -> list[LLMBackend]:
    """Auto-detect which backends are running."""
    backends = []

    # LM Studio
    lm = LMStudioBackend()
    if lm.is_running():
        backends.append(lm)

    # Ollama
    ol = OllamaBackend()
    if ol.is_running():
        backends.append(ol)

    # llama-server instances (bekannte Ports)
    for port, label in [(8080, "llama-server-35b"), (8081, "llama-server-122b")]:
        ls = LlamaServerBackend(url=f"http://127.0.0.1:{port}")
        ls.name = label
        if ls.is_running():
            backends.append(ls)

    return backends


def get_all_model_stats() -> dict:
    """Combined status from all running backends.
    Returns {'loaded': [...], 'available': [...], 'error': None|str}."""
    loaded = []
    available = []
    errors = []

    backends = discover_backends()

    for be in backends:
        try:
            st = be.status()
            loaded.extend(st.get("loaded", []))
            available.extend(st.get("available", []))
            if st.get("error"):
                errors.append(f"{be.name}: {st['error']}")
        except Exception as e:
            errors.append(f"{be.name}: {e}")

    error = "; ".join(errors) if errors else None
    return {"loaded": loaded, "available": available, "error": error}
