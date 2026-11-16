#!/usr/bin/env python3
"""End-to-End-Test ohne Fremdpakete: python3 tests/test_end_to_end.py"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PORT = int(os.environ.get("MM_TEST_PORT", "8791"))
BASE = f"http://127.0.0.1:{PORT}"
API = BASE + "/api/v1"
TOKEN = "mac-monitor-test-token"

passed = failed = 0


def check(name: str, cond: bool, detail: str = "") -> None:
    global passed, failed
    if cond:
        passed += 1
        print(f"  [ok]   {name}")
    else:
        failed += 1
        print(f"  [FAIL] {name} {detail}")


def req(method: str, path: str, payload=None, token: str | None = TOKEN):
    data = json.dumps(payload).encode() if payload is not None else None
    headers = {"Content-Type": "application/json"}
    if token:
        headers["X-API-Token"] = token
    r = urllib.request.Request(API + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(r, timeout=10) as resp:
            raw = resp.read().decode()
            return resp.status, (json.loads(raw) if raw.strip() else {})
    except urllib.error.HTTPError as exc:
        try:
            return exc.code, json.loads(exc.read().decode())
        except Exception:  # noqa: BLE001
            return exc.code, {}


def sample(machine_id: str, name: str, ram_total: float, ram_used: float,
           vram_total: float, vram_used: float, cpu: float, gpu: float,
           models=None, slots: int = 2, tps: float = 0.0) -> dict:
    return {
        "machine": {"id": machine_id, "name": name, "platform": "linux",
                    "backend_url": f"http://{machine_id}:11434",
                    "ram_total_gb": ram_total, "vram_total_gb": vram_total,
                    "max_slots": slots, "agent_version": "1.0.0"},
        "sample": {"ts": int(time.time()), "cpu_pct": cpu, "gpu_pct": gpu,
                   "ram_used_gb": ram_used, "ram_total_gb": ram_total,
                   "vram_used_gb": vram_used, "vram_total_gb": vram_total,
                   "loaded_models": models or [], "tps_current": tps,
                   "tps_avg": tps, "tps_peak": tps, "temp_c": 51.0, "power_w": 95.0},
    }


def main() -> int:
    tmp = tempfile.mkdtemp(prefix="mm-test-")
    env = dict(os.environ, MM_PORT=str(PORT), MM_DB_PATH=str(Path(tmp) / "test.sqlite3"),
               MM_PUBLIC_URL=BASE, MM_API_TOKEN=TOKEN, MM_ENV_FILE="/nonexistent",
               PYTHONPATH=str(ROOT))
    proc = subprocess.Popen([sys.executable, "-m", "server.app"], cwd=str(ROOT), env=env,
                            stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    try:
        for _ in range(50):
            try:
                urllib.request.urlopen(API + "/health", timeout=1)
                break
            except (urllib.error.URLError, OSError):
                time.sleep(0.2)
        else:
            err = proc.stderr.read().decode()[-800:] if proc.stderr else ""
            print("Server startet nicht:\n" + err)
            return 1

        print("\n1. Basis")
        code, body = req("GET", "/health")
        check("health erreichbar", code == 200 and body.get("status") == "ok", str(code))
        check("URLs korrekt aufgeloest", body.get("api_url") == API, body.get("api_url", ""))
        code, _ = req("GET", "/gibtsnicht")
        check("unbekannte Route -> 404", code == 404)
        code, _ = req("POST", "/ingest", {"machine": {"id": "x"}}, token="falsch")
        check("falsches Token -> 401", code == 401)
        code, _ = req("POST", "/ingest", {"machine": {}, "sample": {}})
        check("fehlende machine.id -> 400", code == 400)

        print("\n2. Ingest")
        code, body = req("POST", "/ingest", sample(
            "evo-x3", "Evo X3", 128, 40, 64, 12, 25, 30, ["qwen2.5-coder:14b"], 3))
        check("Ingest Maschine 1", code == 201 and body.get("status") == "accepted")
        code, _ = req("POST", "/ingest", sample(
            "thinkpad", "ThinkPad", 30.67, 26, 1, 0.9, 88, 95, [], 1))
        check("Ingest Maschine 2", code == 201)
        code, _ = req("POST", "/ingest", sample(
            "mac-studio", "Mac Studio", 64, 20, 64, 20, 10, 5, ["llama3:8b"], 2))
        check("Ingest Maschine 3", code == 201)

        print("\n3. TPS")
        code, body = req("POST", "/tps", {
            "machine_id": "evo-x3", "model": "qwen2.5-coder:14b",
            "eval_count": 512, "eval_duration": 20_000_000_000,
            "prompt_eval_count": 120, "prompt_eval_duration": 1_000_000_000})
        check("TPS aus eval_count/eval_duration", code == 201 and body.get("tps") == 25.6,
              str(body))
        check("prompt_tps berechnet", body.get("prompt_tps") == 120.0, str(body))
        code, body = req("POST", "/tps", {"machine_id": "mac-studio",
                                          "model": "llama3:8b", "tps": 42.5})
        check("TPS direkt", code == 201 and body.get("tps") == 42.5)
        code, body = req("POST", "/tps", {"machine_id": "evo-x3"})
        check("TPS ohne Daten -> 400", code == 400)
        code, body = req("GET", "/tps/summary?minutes=60")
        check("TPS-Summary gruppiert", len(body.get("rows", [])) == 2, str(body))

        print("\n4. Kapazitaet und Scoring")
        code, body = req("GET", "/capacity")
        ms = {m["machine_id"]: m for m in body["machines"]}
        check("3 Maschinen", len(ms) == 3)
        check("alle online", all(m["online"] for m in ms.values()))
        check("Evo X3 vor ThinkPad", ms["evo-x3"]["capacity_score"] > ms["thinkpad"]["capacity_score"],
              f"{ms['evo-x3']['capacity_score']} vs {ms['thinkpad']['capacity_score']}")
        check("freier RAM abzueglich Headroom",
              abs(ms["evo-x3"]["ram_free_gb"] - 86.0) < 0.01, str(ms["evo-x3"]["ram_free_gb"]))
        code, body = req("GET", "/capacity?model=qwen2.5-coder:14b")
        ms2 = {m["machine_id"]: m for m in body["machines"]}
        check("Modell geladen erkannt", ms2["evo-x3"]["model_loaded"] is True)
        check("historische TPS zugeordnet", ms2["evo-x3"]["tps_history_model"] == 25.6,
              str(ms2["evo-x3"]["tps_history_model"]))

        print("\n5. Agent-Discovery")
        code, body = req("GET", "/agent/discovery?model=qwen2.5-coder:14b&min_ram_gb=18")
        check("Subagent startbar", body.get("can_start_subagent") is True)
        check("Empfehlung = evo-x3", body["recommended"]["machine_id"] == "evo-x3",
              str(body.get("recommended")))
        check("Backend-URL geliefert",
              body["recommended"]["backend_url"] == "http://evo-x3:11434")
        code, body = req("GET", "/agent/discovery?min_ram_gb=500")
        check("unrealistischer Bedarf -> keine Maschine",
              body.get("can_start_subagent") is False)
        check("Ablehnungsgrund genannt", all("RAM" in u["reason"] for u in body["unavailable"]),
              str(body.get("unavailable")))

        print("\n6. Reservierung")
        code, r1 = req("POST", "/reservations", {
            "requester": "openclaw", "task_id": "sub-01",
            "model": "qwen2.5-coder:14b", "minimum_ram_gb": 18,
            "estimated_duration_seconds": 600})
        check("Reservierung erstellt", code == 201 and r1.get("status") == "reserved", str(code))
        check("auf bester Maschine", r1.get("machine_id") == "evo-x3")
        check("erwartete TPS mitgeliefert", r1.get("expected_tps") == 25.6)
        rid = r1["reservation_id"]

        code, body = req("GET", "/capacity?model=qwen2.5-coder:14b")
        evo = [m for m in body["machines"] if m["machine_id"] == "evo-x3"][0]
        check("RAM jetzt reserviert", evo["reserved_ram_gb"] == 18.0, str(evo["reserved_ram_gb"]))
        check("Slot belegt", evo["free_slots"] == 2, str(evo["free_slots"]))

        code, body = req("POST", f"/reservations/{rid}/start")
        check("start -> running", body["reservation"]["state"] == "running")
        code, body = req("POST", f"/reservations/{rid}/heartbeat")
        check("heartbeat verlaengert", body["reservation"]["last_heartbeat"] is not None)
        code, body = req("POST", f"/reservations/{rid}/complete")
        check("complete", body["reservation"]["state"] == "completed")
        code, body = req("POST", f"/reservations/{rid}/start")
        check("abgeschlossener Slot nicht startbar", code == 409, str(code))

        print("\n7. Ueberbuchungsschutz")
        _, a = req("POST", "/reservations", {"requester": "a", "minimum_ram_gb": 60})
        _, b = req("POST", "/reservations", {"requester": "b", "minimum_ram_gb": 60})
        check("erste grosse Reservierung ok", a.get("status") == "reserved")
        check("zweite weicht aus oder lehnt ab",
              b.get("status") in ("reserved", "unavailable")
              and b.get("machine_id") != a.get("machine_id"), str(b)[:120])
        code, c = req("POST", "/reservations", {"requester": "c", "minimum_ram_gb": 60})
        check("dritte -> 503 unavailable", code == 503 and c.get("status") == "unavailable",
              str(code))
        check("Begruendung je Maschine", len(c.get("details", [])) == 3, str(c.get("details")))

        print("\n8. Feste Maschine und Stornierung")
        code, d = req("POST", "/reservations", {"requester": "d", "machine_id": "nope"})
        check("unbekannte Maschine -> 404", code == 404 and d.get("error") == "unknown_machine")
        code, e = req("POST", "/reservations", {"requester": "e", "machine_id": "mac-studio",
                                                "minimum_ram_gb": 1})
        check("feste Maschine erzwingbar", e.get("machine_id") == "mac-studio", str(e)[:120])
        code, _ = req("DELETE", f"/reservations/{e['reservation_id']}")
        check("Stornierung", code == 200)
        code, body = req("GET", f"/reservations/{e['reservation_id']}")
        check("Status cancelled", body["reservation"]["state"] == "cancelled")

        print("\n9. Verlauf, Slots, Wartung")
        code, body = req("GET", "/series?minutes=60")
        check("Serien geliefert", len(body.get("samples", [])) >= 3)
        check("TPS-Serie geliefert", len(body.get("tps_samples", [])) >= 2)
        code, body = req("GET", "/slots?window=60")
        slots_tl = body.get("slots", [])
        _, rl = req("GET", "/reservations?limit=100")
        check("Slot-Zeitachse vollstaendig",
              {s["id"] for s in slots_tl} == {r["id"] for r in rl["reservations"]},
              f"{len(slots_tl)} vs {len(rl['reservations'])}")
        check("Slots haben Start und Ende", all(s["end"] > s["start"] for s in slots_tl))
        check("Zustaende in Zeitachse",
              {s["state"] for s in slots_tl} >= {"completed", "cancelled"},
              str({s["state"] for s in slots_tl}))
        code, body = req("GET", "/reservations?state=active")
        check("aktive Reservierungen filterbar",
              all(r["state"] in ("reserved", "running") for r in body["reservations"]))
        code, body = req("POST", "/maintenance")
        check("Wartung laeuft", code == 200 and body.get("status") == "ok")

        print("\n10. Dashboard")
        for f in ("/", "/app.js", "/style.css"):
            try:
                with urllib.request.urlopen(BASE + f, timeout=5) as r:
                    ok = r.status == 200 and len(r.read()) > 500
            except (urllib.error.URLError, OSError):
                ok = False
            check(f"Dashboard {f}", ok)
        try:
            urllib.request.urlopen(BASE + "/../server/settings.py", timeout=5)
            trav = False
        except urllib.error.HTTPError as exc:
            trav = exc.code == 404
        except (urllib.error.URLError, OSError):
            trav = True
        check("kein Path Traversal", trav)

        print("\n11. Client-Collector")
        out = subprocess.run([sys.executable, "client/monitor.py", "--print-payload"],
                             cwd=str(ROOT), capture_output=True, text=True, timeout=60)
        check("Client sammelt Payload", out.returncode == 0 and '"sample"' in out.stdout)
        payload = json.loads(out.stdout)
        check("RAM plausibel", payload["sample"]["ram_total_gb"] > 0)
        check("TPS ohne Last = 0", payload["sample"]["tps_current"] == 0.0)

        env2 = dict(env, MM_SERVER_URL=BASE, MM_MACHINE_ID="testclient")
        out = subprocess.run([sys.executable, "client/monitor.py", "--once"],
                             cwd=str(ROOT), env=env2, capture_output=True, text=True, timeout=60)
        check("Client sendet an Server", out.returncode == 0 and "[error]" not in out.stdout,
              out.stdout[-160:])
        code, body = req("GET", "/capacity")
        check("Client registriert",
              any(m["machine_id"] == "testclient" for m in body["machines"]))

        print("\n12. Agent-SDK")
        sdk = subprocess.run(
            [sys.executable, "integrations/agent_client.py", "--server", BASE,
             "--token", TOKEN, "machines"],
            cwd=str(ROOT), capture_output=True, text=True, timeout=30)
        check("SDK listet Maschinen", sdk.returncode == 0 and "evo-x3" in sdk.stdout,
              sdk.stderr[-160:])
        pick = subprocess.run(
            [sys.executable, "integrations/openclaw/pick_machine.py", "--server", BASE,
             "--token", TOKEN, "--model", "qwen2.5-coder:14b", "--min-ram", "8", "--env"],
            cwd=str(ROOT), capture_output=True, text=True, timeout=30)
        check("OpenClaw-Bruecke liefert Env",
              pick.returncode == 0 and "OLLAMA_BASE_URL" in pick.stdout, pick.stdout[-160:])

        print(f"\n{'=' * 52}\nErgebnis: {passed} bestanden, {failed} fehlgeschlagen\n{'=' * 52}")
        return 0 if failed == 0 else 1
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()


if __name__ == "__main__":
    raise SystemExit(main())
