#!/usr/bin/env python3
"""OpenClaw-Bruecke: Wo soll der naechste Subagent laufen?

Aufruf aus OpenClaw / Hermes / OpenCode / Shell:

    python3 pick_machine.py --model qwen2.5-coder:14b --min-ram 18
    -> JSON mit machine_id, backend_url, reservation_id

Rueckgabewert 0 = Slot reserviert, 3 = keine Kapazitaet frei.

Der Slot bleibt nur kurz gueltig (Server-TTL). Der startende Prozess muss
ihn per Heartbeat halten:

    python3 pick_machine.py --hold <reservation_id>
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from agent_client import MacMonitor, MacMonitorError  # noqa: E402


def main() -> int:
    ap = argparse.ArgumentParser(description="Maschinenauswahl fuer Subagenten")
    ap.add_argument("--server", default=os.environ.get("MM_SERVER_URL", "http://127.0.0.1:8770"))
    ap.add_argument("--token", default=os.environ.get("MM_API_TOKEN", "mac-monitor-test-token"))
    ap.add_argument("--requester", default="openclaw")
    ap.add_argument("--task-id", default=f"subagent-{int(time.time())}")
    ap.add_argument("--model", default=None)
    ap.add_argument("--min-ram", type=float, default=0.0)
    ap.add_argument("--min-vram", type=float, default=0.0)
    ap.add_argument("--duration", type=int, default=900)
    ap.add_argument("--priority", type=int, default=50)
    ap.add_argument("--machine", default=None, help="feste Maschine erzwingen")
    ap.add_argument("--require-loaded", action="store_true",
                    help="nur Maschinen, auf denen das Modell bereits geladen ist")
    ap.add_argument("--wait", type=int, default=0, help="Sekunden auf freie Kapazitaet warten")
    ap.add_argument("--dry-run", action="store_true", help="nur empfehlen, nicht reservieren")
    ap.add_argument("--hold", default=None, help="Reservierung per Heartbeat halten")
    ap.add_argument("--release", default=None, help="Reservierung abschliessen")
    ap.add_argument("--env", action="store_true",
                    help="Ausgabe als Shell-Export statt JSON")
    a = ap.parse_args()

    mm = MacMonitor(a.server, a.token)

    try:
        if a.hold:
            print(f"Halte {a.hold} - STRG+C zum Beenden.", file=sys.stderr)
            while True:
                mm.heartbeat(a.hold)
                time.sleep(30)

        if a.release:
            print(json.dumps(mm.complete(a.release), indent=2))
            return 0

        if a.dry_run:
            d = mm.discovery(a.model, a.min_ram, a.min_vram, a.require_loaded)
            print(json.dumps(d, indent=2, ensure_ascii=False))
            return 0 if d.get("can_start_subagent") else 3

        kw = dict(task_id=a.task_id, model=a.model, min_ram_gb=a.min_ram,
                  min_vram_gb=a.min_vram, duration_s=a.duration,
                  priority=a.priority, machine_id=a.machine,
                  require_model_loaded=a.require_loaded)
        slot = (mm.wait_for_slot(a.requester, timeout_s=a.wait, **kw) if a.wait > 0
                else mm.reserve(a.requester, **kw))

        if not slot:
            out = {"status": "unavailable",
                   "message": "Aktuell keine Maschine mit ausreichender Kapazitaet.",
                   "machines": [{"machine_id": m["machine_id"], "online": m["online"],
                                 "ram_free_gb": m["ram_free_gb"],
                                 "free_slots": m["free_slots"],
                                 "capacity_score": m["capacity_score"]}
                                for m in mm.machines()]}
            print(json.dumps(out, indent=2, ensure_ascii=False))
            return 3

        mm.start(slot)
        if a.env:
            print(f'export OLLAMA_BASE_URL="{slot.backend_url}"')
            print(f'export MM_MACHINE_ID="{slot.machine_id}"')
            print(f'export MM_RESERVATION_ID="{slot.id}"')
            print(f'export MM_MODEL="{slot.get("model", "")}"')
        else:
            print(json.dumps(slot, indent=2, ensure_ascii=False))
        return 0

    except KeyboardInterrupt:
        if a.hold:
            mm.complete(a.hold)
        return 0
    except MacMonitorError as exc:
        print(json.dumps({"status": "error", "message": str(exc)}), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
