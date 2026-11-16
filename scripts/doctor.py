#!/usr/bin/env python3
"""Diagnose: Was funktioniert auf dieser Maschine, was nicht."""
from __future__ import annotations

import json
import platform
import shutil
import socket
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "client"))

OK, WARN, BAD = "[ ok ]", "[warn]", "[FAIL]"


def line(status: str, label: str, detail: str = "") -> None:
    print(f"{status} {label:<34} {detail}")


def main() -> int:
    print("Fleet Monitor Doctor\n" + "=" * 62)
    print(f"Host      : {socket.gethostname()}")
    print(f"System    : {platform.system()} {platform.release()} ({platform.machine()})")
    print(f"Python    : {sys.version.split()[0]}\n")

    problems = 0
    line(OK if sys.version_info >= (3, 9) else BAD, "Python 3.9+",
         ".".join(map(str, sys.version_info[:3])))

    print("\nCollector")
    from collectors import gpu, ollama, system, temps  # noqa: E402
    cpu = system.cpu_percent()
    used, total = system.memory()
    line(OK if total > 0 else BAD, "RAM auslesbar", f"{used:.1f} / {total:.1f} GB")
    line(OK, "CPU auslesbar", f"{cpu:.1f} %")
    line(OK, "CPU-Modell", system.cpu_model()[:40])

    g = gpu.snapshot(total)
    if g.get("source") in (None, "none"):
        problems += 1
        line(WARN, "GPU-Quelle", "keine (rocm-smi / nvidia-smi / ioreg fehlen)")
    else:
        line(OK, "GPU-Quelle", f"{g['source']} · {g.get('gpu_pct', 0)} % · "
                               f"VRAM {g.get('vram_used_gb', 0)}/{g.get('vram_total_gb', 0)} GB")
    for tool in ("rocm-smi", "nvidia-smi", "sensors", "ollama"):
        line(OK if shutil.which(tool) else WARN, f"Werkzeug {tool}",
             shutil.which(tool) or "nicht installiert")

    t = temps.cpu_temperature()
    line(OK if t else WARN, "Temperatur", f"{t:.1f} °C" if t else "keine Quelle (optional)")

    print("\nOllama")
    url = "http://127.0.0.1:11434"
    if ollama.available(url):
        loaded = ollama.loaded_models(url)
        line(OK, "Ollama erreichbar", url)
        line(OK, "Modelle installiert", str(len(ollama.installed_models(url))))
        line(OK, "Modelle geladen",
             ", ".join(m["name"] for m in loaded) if loaded else "keines")
    else:
        problems += 1
        line(WARN, "Ollama erreichbar", f"nein ({url})")

    print("\nServer und Kopplung")
    import io, contextlib
    import monitor  # noqa: E402
    with contextlib.redirect_stdout(io.StringIO()):
        cfg = monitor.load_config(ROOT / "client" / "config.json")
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        rc = monitor.status(cfg)
    for ln in buf.getvalue().splitlines():
        line(OK if rc == 0 else WARN, ln.split(":", 1)[0].strip(), ln.split(":", 1)[-1].strip())
    if rc != 0:
        problems += 1

    print("\n" + "=" * 62)
    print("Alles betriebsbereit." if problems == 0
          else f"{problems} Hinweis(e) - optionale Quellen fehlen, der Agent laeuft trotzdem.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
