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


def pgrep_lama_servers() -> list:
    """Gibt eine Liste von PIDs aller llama-server Prozesse mit --model zurück."""
    try:
        result = subprocess.run(
            ["pgrep", "-f", "llama-server.*--model"],
            capture_output=True, text=True, timeout=5)
        return [int(p) for p in result.stdout.split() if p.strip()]
    except Exception:
        return []


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

    def live_tps(self, prev_state=None) -> tuple:
        """Returns (tps|None, new_state) — live TPS der aktiven Generation.
        prev_state = backend-spezifischer State vom vorherigen Poll."""
        return None, {}

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

    @staticmethod
    def _norm_key(s: str) -> str:
        """Normalisiert einen Modellnamen zum Vergleich.

        'qwen3.8-27b-q6_k'        → 'qwen3.8-27b'
        'qwen3.5-122b-a10b-mtp'   → 'qwen3.5-122b-a10b-mtp'
        'qwen3.5-122b-a10b-ud-q4_k_xl-00001-of-00003' → 'qwen3.5-122b-a10b'
        'qwen3.8-27b-q6_k'        → 'qwen3.8-27b'
        Entfernt: .gguf, quant-suffixe (q4_k*, q6_k, q8_0, f16, f32),
                  packaging (ud, mtp im Dateinamen), of-*, -gguf dir-suffix.
        Aber: im API-ID und Parent-Dir bleibt 'mtp' erhalten (es ist Teil des Namens).
        """
        s = s.lower().strip().replace(".gguf", "")
        # Entferne Publisher-Prefix (z.B. 'qwen/' → '')
        s = s.split("/")[-1]
        # Entferne -gguf suffix (bei Verzeichnisnamen)
        if s.endswith("-gguf"):
            s = s[:-5]
        # Splitte nach '-', entferne Tokens die Quant/Verpackung kennzeichnen
        drop_tokens = {"q4_k", "q4_k_xl", "q4_k_m", "q4_k_s", "q6_k", "q8_0",
                       "f16", "f32", "bf16", "ud", "of", "00001", "00002", "00003",
                       "gguf", "iq4_xs", "iq3_m", "q5_k_m", "q5_k_s", "q3_k_m"}
        parts = []
        for p in s.split("-"):
            # exakte drop-tokens
            if p in drop_tokens:
                continue
            # 'of-00001-of-00003' pattern: 0000x
            if p.isdigit() and len(p) == 5:
                continue
            parts.append(p)
        return "-".join(parts)

    def unload(self, model: str) -> bool:
        """LM Studio: killt den spezifischen llama-server Prozess für das Modell.

        LM Studio hat keinen HTTP-Unload-API. Modelle laufen als separate
        llama-server Prozesse auf zufälligen Ports. Wir finden den Prozess
        dessen --model-Argument zum Ziel passt und killen ihn gezielt.

        Matching: Vergleich normalisierter Keys von API-ID, Dateiname und
        Parent-Verzeichnis. So funktioniert es für alle Modelle:
          qwen/qwen3.8-27b       → Qwen3.8-27B-Q6_K.gguf           ✅
          qwen3.5-122b-a10b-mtp  → Qwen3.5-122B-A10B-UD-Q4_K_XL-…  ✅ (via parent dir)
        """
        try:
            target_key = self._norm_key(model)
            _write_error(f"LM Studio unload: target='{model}' norm='{target_key}'")
            killed = False
            for pid in pgrep_lama_servers():
                # Lese /proc/PID/cmdline für --model argument
                try:
                    raw = open(f"/proc/{pid}/cmdline", "rb").read()
                    parts = raw.split(b"\0")
                    parts = [p.decode("utf-8", errors="replace") for p in parts if p]
                except Exception:
                    continue
                model_path = ""
                for i, p in enumerate(parts):
                    if p == "--model" and i + 1 < len(parts):
                        model_path = parts[i + 1]
                        break
                if not model_path:
                    continue
                # Drei Kandidaten: Dateiname, Parent-Dir, Parent-Parent-Dir
                path_parts = model_path.split("/")
                candidates = []
                if len(path_parts) >= 1:
                    candidates.append(path_parts[-1])                    # Dateiname
                if len(path_parts) >= 2:
                    candidates.append(path_parts[-2])                    # Parent-Dir
                if len(path_parts) >= 3:
                    candidates.append(path_parts[-3])                    # Grandparent-Dir
                for cand in candidates:
                    cand_key = self._norm_key(cand)
                    if cand_key == target_key or cand_key.startswith(target_key) or target_key.startswith(cand_key):
                        subprocess.run(["kill", str(pid)], capture_output=True, timeout=10)
                        for _ in range(20):
                            if not os.path.exists(f"/proc/{pid}"):
                                break
                            time.sleep(0.5)
                        killed = True
                        _write_error(f"LM Studio unload: killed PID {pid} ({cand} → norm='{cand_key}')")
                        break
                if killed:
                    break
            if not killed:
                _write_error(f"LM Studio unload: no llama-server found for '{model}' (norm='{target_key}')")
            return killed
        except Exception as e:
            _write_error(f"LM Studio unload error ({model}): {e}")
            return False

    def live_tps(self, prev_state=None) -> tuple:
        """Live-TPS der aktiven Generation via /slots auf internen llama-server Ports.

        LM Studio startet pro Modell einen llama-server auf einem dynamischen Port.
        Wir lesen --port und --api-key aus /proc/PID/cmdline, pollen /slots,
        und berechnen Δn_decoded / Δt zwischen zwei Polls.

        Returns: (tps|None, new_state)
        prev_state = {'slots': {slot_id: {'n_decoded': int, 't': float}}} vom vorherigen Poll.
        """
        prev_state = prev_state or {}
        prev_slots = prev_state.get("slots", {})
        new_slots = {}
        best_tps = None

        for pid in pgrep_lama_servers():
            try:
                raw = open(f"/proc/{pid}/cmdline", "rb").read()
                parts = [p.decode("utf-8", errors="replace") for p in raw.split(b"\0") if p]
            except Exception:
                continue
            port = None
            api_key = None
            for i, p in enumerate(parts):
                if p == "--port" and i + 1 < len(parts):
                    port = parts[i + 1]
                if p == "--api-key" and i + 1 < len(parts):
                    api_key = parts[i + 1]
            if not port:
                continue
            try:
                url = f"http://127.0.0.1:{port}/slots"
                headers = {}
                if api_key:
                    headers["Authorization"] = f"Bearer {api_key}"
                req = urllib.request.Request(url, headers=headers)
                resp = urllib.request.urlopen(req, timeout=3)
                data = json.loads(resp.read().decode())
                slots = data if isinstance(data, list) else data.get("slots", [])
                now = time.time()
                for slot in slots:
                    if not slot.get("is_processing"):
                        continue
                    slot_id = str(slot.get("id", 0))
                    n_decoded = slot.get("n_decoded", 0)
                    n_past = slot.get("n_past", 0)
                    # n_past = prefilled tokens, n_decoded = generated tokens
                    # Für Decode-TPS: Δn_decoded / Δt
                    # Für Prefill-TPS: Δn_past / Δt (wenn n_decoded noch 0)
                    prev = prev_slots.get(slot_id, {})
                    prev_n_decoded = prev.get("n_decoded", 0)
                    prev_t = prev.get("t", 0)
                    new_slots[slot_id] = {"n_decoded": n_decoded, "n_past": n_past, "t": now}
                    if prev_t > 0 and n_decoded > prev_n_decoded:
                        dt = now - prev_t
                        if dt > 0:
                            tps = round((n_decoded - prev_n_decoded) / dt, 1)
                            if best_tps is None or tps > best_tps:
                                best_tps = tps
            except Exception:
                continue
        return best_tps, {"slots": new_slots}

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

    def live_tps(self, prev_state=None) -> tuple:
        """Ollama hat keine Token-Zähler pro laufender Generation in /api/ps.
        /api/ps zeigt nur geladene Modelle + expires_at, nicht n_decoded.
        → Live-TPS für Ollama nicht ohne synthetische Probe möglich.
        Gibt (None, {}) zurück — Dashboard zeigt dann null für Ollama."""
        return None, {}

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

    def live_tps(self, prev_state=None) -> tuple:
        """Live-TPS via /slots mit Delta-Berechnung zwischen Polls."""
        prev_state = prev_state or {}
        prev_slots = prev_state.get("slots", {})
        new_slots = {}
        best_tps = None
        if not self.api_key:
            return None, {}
        try:
            req = urllib.request.Request(
                self.url + "/slots",
                headers={"Authorization": f"Bearer {self.api_key}"},
            )
            resp = urllib.request.urlopen(req, timeout=3)
            data = json.loads(resp.read().decode())
            slots = data if isinstance(data, list) else data.get("slots", [])
            now = time.time()
            for slot in slots:
                if not slot.get("is_processing"):
                    continue
                slot_id = str(slot.get("id", 0))
                n_decoded = slot.get("n_decoded", 0)
                prev = prev_slots.get(slot_id, {})
                prev_n = prev.get("n_decoded", 0)
                prev_t = prev.get("t", 0)
                new_slots[slot_id] = {"n_decoded": n_decoded, "t": now}
                if prev_t > 0 and n_decoded > prev_n:
                    dt = now - prev_t
                    if dt > 0:
                        tps = round((n_decoded - prev_n) / dt, 1)
                        if best_tps is None or tps > best_tps:
                            best_tps = tps
        except Exception:
            pass
        return best_tps, {"slots": new_slots}

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


# ── Halogen ───────────────────────────────────────────────────────────────
class HalogenBackend(LLMBackend):
    """Halogen flash-server :8731 — /health + /metrics (Prometheus).

    Live-TPS kommt fertig vom Engine-Gauge llamacpp:predicted_tokens_seconds;
    requests_processing > 0 signalisiert aktive Generation."""
    name = "halogen"

    def __init__(self, url="http://127.0.0.1:8731"):
        self.url = url

    def _metrics(self) -> dict:
        """GET /metrics → {gauge_name: float}."""
        req = urllib.request.Request(self.url + "/metrics", method="GET")
        with urllib.request.urlopen(req, timeout=5) as resp:
            text = resp.read().decode("utf-8", errors="replace")
        out = {}
        for line in text.splitlines():
            if not line or line.startswith("#"):
                continue
            parts = line.split()
            if len(parts) >= 2:
                try:
                    out[parts[0]] = float(parts[1])
                except ValueError:
                    pass
        return out

    def _container_mem_gb(self):
        """Echter Memory-Footprint des Containers aus cgroup v2 (Weights + KV Pool)."""
        try:
            r = subprocess.run(
                ["podman", "inspect", "-f", "{{.State.Pid}}", "halogen"],
                capture_output=True, text=True, timeout=5)
            pid = r.stdout.strip()
            if not pid or pid == "0":
                return None
            cg = open(f"/proc/{pid}/cgroup").read().strip().splitlines()[0].split(":")[-1]
            cur = int(open(f"/sys/fs/cgroup{cg}/memory.current").read())
            return round(cur / (1024 ** 3), 1)
        except Exception:
            return None

    def status(self) -> dict:
        try:
            data = _http_get(self.url + "/health", timeout=3)
            model = data.get("model", "halogen")
            entry = {"name": model, "server": self.name,
                     "size_vram_gb": self._container_mem_gb()}
            return {"loaded": [entry], "available": [entry], "error": None}
        except Exception as e:
            return {"loaded": [], "available": [], "error": str(e)}

    def live_tps(self, prev_state=None) -> tuple:
        """Decode-TPS durch Parsen der serve_api-Logzeile aus podman logs.

        Halogen loggt bei jeder Completion:
          serve_api: mtp 2055 tok in 29.83s = 68.89 t/s | ...
        Wir lesen die letzte Zeile und extrahieren die t/s-Rate.
        Der Wert bleibt gültig bis zur nächsten Completion."""
        try:
            result = subprocess.run(
                ["podman", "logs", "--tail", "200", "halogen"],
                capture_output=True, text=True, timeout=8)
            lines = (result.stdout + result.stderr).strip().split("\n")
            for line in reversed(lines):
                if "serve_api:" in line and "t/s" in line:
                    # Pattern: ... = 68.89 t/s | ...
                    import re
                    m = re.search(r'=\s*([\d.]+)\s*t/s', line)
                    if m:
                        return round(float(m.group(1)), 1), {}
            return None, {}
        except Exception:
            return None, {}

    def unload(self, model: str) -> bool:
        """Halogen entlädt nicht — Container-Neustart wäre nötig."""
        return False

    def is_running(self) -> bool:
        try:
            _http_get(self.url + "/health", timeout=2)
            return True
        except Exception:
            return False


# ── Discovery ─────────────────────────────────────────────────────────────
def discover_backends() -> list[LLMBackend]:
    """Auto-detect which backends are running."""
    backends = []

    # Halogen (eigener Container, Port 8731)
    hg = HalogenBackend()
    if hg.is_running():
        backends.append(hg)

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
