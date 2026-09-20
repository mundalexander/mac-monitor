#!/usr/bin/env python3
"""
tps_probe.py — TPS-Messung: Live-Probe (SSE) + Benchmark (einmalig).

Live-Probe:  Alle PROBE_INTERVAL Sekunden eine kurze Streaming-Generierung,
             TPS aus SSE-Chunks + usage (stream_options).
Benchmark:    python3 tps_probe.py --benchmark  →  Prefill/Decode/TTFT
             für 3 Kontextgrößen (kurz/mittel/groß).

Standalone ausführbar:  python3 tps_probe.py
"""

import json
import time
import uuid
import urllib.request
from datetime import datetime
import os

LOG_DIR = os.path.expanduser("~/.local/share/mac-monitor")
ERROR_LOG = os.path.join(LOG_DIR, "monitor_linux.err.log")


def _write_error(msg):
    try:
        os.makedirs(LOG_DIR, exist_ok=True)
        with open(ERROR_LOG, "a") as f:
            f.write(f"{datetime.now().isoformat()} - {msg}\n")
    except Exception:
        pass


# ── Live TPS Probe ────────────────────────────────────────────────────────
def _find_loaded_vlm_lmstudio(url):
    """Finde das erste geladene VLM-Modell in LM Studio."""
    try:
        resp = urllib.request.urlopen(url + "/api/v0/models", timeout=3)
        data = json.loads(resp.read().decode())
        for m in data.get("data", []):
            if m.get("type") == "embeddings":
                continue
            if m.get("state") == "loaded":
                return m.get("id")
    except Exception:
        pass
    return None


def _find_loaded_vlm_ollama(url):
    """Finde das erste geladene VLM-Modell in Ollama."""
    try:
        resp = urllib.request.urlopen(url + "/api/tags", timeout=3)
        data = json.loads(resp.read().decode())
        for m in data.get("models", []):
            # Ollama hat keine 'type' Info — wir nehmen alle Modelle
            return m.get("name")
    except Exception:
        pass
    return None


def _probe_openai_stream(url, model, label="unknown"):
    """Generische TPS-Probe über OpenAI-compatible /v1/chat/completions streaming.
    Returns (tps|None, probe_call|None)."""
    body = json.dumps({
        "model": model,
        "messages": [{"role": "user", "content": "Count from 1 to 20, separated by commas."}],
        "max_tokens": 48,
        "stream": True,
        "stream_options": {"include_usage": True},
    }).encode("utf-8")
    req = urllib.request.Request(
        url + "/v1/chat/completions", data=body,
        headers={"Content-Type": "application/json"}, method="POST",
    )
    t0 = time.time()
    try:
        resp = urllib.request.urlopen(req, timeout=30)
        first = last = None
        n_chunks = 0
        usage_tokens = None

        for raw in resp:
            line = raw.decode("utf-8", errors="replace").strip()
            if not line.startswith("data:"):
                continue
            payload_s = line[5:].strip()
            if payload_s == "[DONE]":
                break
            try:
                chunk = json.loads(payload_s)
            except Exception:
                continue
            if chunk.get("usage"):
                usage_tokens = chunk["usage"].get("completion_tokens")
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
            "ts": int(time.time()), "ip": "evo-x3", "method": "POST",
            "endpoint": f"{label} /v1/chat/completions",
            "duration_ms": round(t_total * 1000, 1), "status": 200,
        }
        n_tokens = usage_tokens if usage_tokens else n_chunks
        if n_tokens >= 2 and first is not None and last is not None and last > first:
            return round(n_tokens / (last - first), 1), call
        return None, call
    except Exception:
        call = {
            "ts": int(time.time()), "ip": "evo-x3", "method": "POST",
            "endpoint": f"{label} /v1/chat/completions",
            "duration_ms": round((time.time() - t0) * 1000, 1), "status": 503,
        }
        return None, call


def probe_tokens_per_second(lm_url="http://127.0.0.1:1234"):
    """Probe generation speed of the first loaded LM Studio model (streaming).
    Uses stream_options: {include_usage: true} for exact token counts.
    Returns (tps|None, probe_call|None)."""
    model = _find_loaded_vlm_lmstudio(lm_url)
    if not model:
        return None, None
    return _probe_openai_stream(lm_url, model, label="lm-studio")


def probe_ollama_tps(ollama_url="http://127.0.0.1:11434"):
    """Probe generation speed of the first loaded Ollama model.
    Returns (tps|None, probe_call|None)."""
    model = _find_loaded_vlm_ollama(ollama_url)
    if not model:
        return None, None
    return _probe_openai_stream(ollama_url, model, label="ollama")


def send_probe_record(call, requests_url, api_token):
    """Report the token/s probe as an LLM request record (Anfragen section)."""
    try:
        body = json.dumps({"token": api_token, "calls": [call]}).encode("utf-8")
        req = urllib.request.Request(
            requests_url, data=body,
            headers={"Content-Type": "application/json"}, method="POST",
        )
        urllib.request.urlopen(req, timeout=10).read()
    except Exception as e:
        _write_error(f"Probe report error: {e}")


# ── Benchmark (einmalig) ──────────────────────────────────────────────────
def run_benchmark(lm_url="http://127.0.0.1:1234", model=None):
    """Prefill/Decode/TTFT Benchmark für 3 Kontextgrößen.
    Fülltext wird pro Größe variiert (uuid4) um Prompt-Caching zu vermeiden."""
    if not model:
        try:
            resp = urllib.request.urlopen(lm_url + "/api/v0/models", timeout=3)
            data = json.loads(resp.read().decode())
            for m in data.get("data", []):
                if m.get("type") == "embeddings":
                    continue
                if m.get("state") == "loaded":
                    model = m.get("id")
                    break
        except Exception:
            pass
    if not model:
        print("Kein geladenes Modell gefunden.")
        return

    sizes = [
        ("kurz",   128),
        ("mittel", 2048),
        ("groß",   8192),
    ]

    print(f"\n{'='*60}")
    print(f"  TPS Benchmark — {model}")
    print(f"  {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"{'='*60}\n")

    for label, target_tokens in sizes:
        # Variabler Fülltext (Cache-Vermeidung)
        salt = uuid.uuid4().hex
        fill = f"Token {salt[:8]}: " * (target_tokens // 10)
        # Auf Zielgröße zuschneiden
        while len(fill.split()) < target_tokens:
            fill += f" extra {uuid.uuid4().hex[:6]}"
        fill = " ".join(fill.split()[:target_tokens])

        body = json.dumps({
            "model": model,
            "messages": [{"role": "user", "content": fill + "\n\nSag nur: OK"}],
            "max_tokens": 3,
            "temperature": 0,
            "stream": True,
            "stream_options": {"include_usage": True},
        }).encode("utf-8")
        req = urllib.request.Request(
            lm_url + "/v1/chat/completions", data=body,
            headers={"Content-Type": "application/json"}, method="POST",
        )

        t0 = time.time()
        ttft = None
        first_token_t = None
        last_token_t = None
        n_out = 0
        usage = None

        try:
            resp = urllib.request.urlopen(req, timeout=120)
            for raw in resp:
                line = raw.decode("utf-8", errors="replace").strip()
                if not line.startswith("data:"):
                    continue
                payload_s = line[5:].strip()
                if payload_s == "[DONE]":
                    break
                try:
                    chunk = json.loads(payload_s)
                except Exception:
                    continue
                if chunk.get("usage"):
                    usage = chunk["usage"]
                delta = (chunk.get("choices") or [{}])[0].get("delta", {})
                txt = delta.get("content") or delta.get("reasoning_content") or ""
                if txt:
                    t = time.time()
                    if first_token_t is None:
                        first_token_t = t
                        ttft = t - t0
                    last_token_t = t
                    n_out += 1
        except Exception as e:
            print(f"  {label}: FEHLER — {e}")
            continue

        t_total = time.time() - t0
        in_tokens = usage.get("prompt_tokens", len(fill.split())) if usage else len(fill.split())
        out_tokens = usage.get("completion_tokens", n_out) if usage else n_out

        prefill_tps = round(in_tokens / ttft, 1) if ttft and ttft > 0 else 0
        decode_time = (last_token_t - first_token_t) if (first_token_t and last_token_t) else 0
        decode_tps = round(out_tokens / decode_time, 1) if decode_time > 0 and out_tokens > 1 else 0

        print(f"  {label:6s} (in={in_tokens:5d} tok): "
              f"Prefill {prefill_tps:8.1f} t/s | "
              f"Decode {decode_tps:5.1f} t/s | "
              f"TTFT {ttft*1000:6.0f} ms | "
              f"out={out_tokens} tok | "
              f"total {t_total:.1f}s")

    print(f"\n{'='*60}\n")


# ── Standalone ────────────────────────────────────────────────────────────
if __name__ == "__main__":
    import sys
    if "--benchmark" in sys.argv:
        run_benchmark()
    else:
        tps, call = probe_tokens_per_second()
        if tps:
            print(f"Live TPS: {tps} t/s")
            print(f"Call: {json.dumps(call, indent=2)}")
        else:
            print("Kein geladenes Modell oder Fehler.")
