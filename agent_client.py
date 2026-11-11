#!/usr/bin/env python3
"""mac-monitor Agent-SDK.

Fuer jeden Agenten verwendbar: OpenClaw, Hermes, OpenCode, VS Code,
eigene Python-Skripte. Der Agent bleibt austauschbar - mac-monitor ist
nur die Kapazitaets- und Reservierungsinstanz.

Typischer Ablauf:

    from agent_client import MacMonitor

    mm = MacMonitor("http://127.0.0.1:8770", "mac-monitor-test-token")

    with mm.slot(requester="openclaw", task_id="subagent-04",
                 model="qwen2.5-coder:14b", min_ram_gb=18) as slot:
        if not slot:
            print("keine Kapazitaet frei")
        else:
            answer = mm.generate(slot, "Schreibe eine Python-Funktion ...")
            print(answer["response"])

Der Kontextmanager kuemmert sich um Heartbeat, Freigabe und exakte
TPS-Meldung an den Server.
"""
from __future__ import annotations

import json
import os
import threading
import time
import urllib.error
import urllib.request
from contextlib import contextmanager
from pathlib import Path

TPS_SPOOL = Path(os.path.expanduser("~/.mac-monitor/tps.jsonl"))


class MacMonitorError(RuntimeError):
    pass


class Slot(dict):
    """Reservierung. Alle Felder der Server-Antwort sind als Keys verfuegbar."""

    @property
    def id(self) -> str:
        return self.get("reservation_id", "")

    @property
    def machine_id(self) -> str:
        return self.get("machine_id", "")

    @property
    def backend_url(self) -> str:
        return self.get("backend_url", "")


class MacMonitor:
    def __init__(self, server_url: str = "http://127.0.0.1:8770",
                 token: str = "mac-monitor-test-token", timeout: float = 10.0) -> None:
        url = (server_url or "").strip()
        if "://" not in url:
            url = "http://" + url
        self.base = url.rstrip("/")
        self.api = self.base + "/api/v1"
        self.token = token
        self.timeout = timeout

    # -- HTTP --------------------------------------------------------------
    def _req(self, method: str, path: str, payload: dict | None = None) -> dict:
        data = json.dumps(payload).encode("utf-8") if payload is not None else None
        req = urllib.request.Request(
            self.api + path, data=data, method=method,
            headers={"Content-Type": "application/json", "X-API-Token": self.token})
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                raw = resp.read().decode("utf-8")
                return json.loads(raw) if raw.strip() else {}
        except urllib.error.HTTPError as exc:
            try:
                return json.loads(exc.read().decode("utf-8"))
            except Exception:  # noqa: BLE001
                return {"status": "error", "error": f"http_{exc.code}"}
        except (urllib.error.URLError, OSError) as exc:
            raise MacMonitorError(f"mac-monitor nicht erreichbar: {exc}") from exc

    # -- Kapazitaet --------------------------------------------------------
    def health(self) -> dict:
        return self._req("GET", "/health")

    def machines(self) -> list[dict]:
        return self._req("GET", "/capacity").get("machines", [])

    def discovery(self, model: str | None = None, min_ram_gb: float = 0,
                  min_vram_gb: float = 0, require_model: bool = False) -> dict:
        q = (f"?model={model or ''}&min_ram_gb={min_ram_gb}"
             f"&min_vram_gb={min_vram_gb}&require_model={'1' if require_model else '0'}")
        return self._req("GET", "/agent/discovery" + q)

    def best_machine(self, model: str | None = None, **kw) -> dict | None:
        return self.discovery(model, **kw).get("recommended")

    # -- Reservierung ------------------------------------------------------
    def reserve(self, requester: str, task_id: str = "", model: str | None = None,
                min_ram_gb: float = 0, min_vram_gb: float = 0,
                duration_s: int = 900, priority: int = 50,
                machine_id: str | None = None,
                require_model_loaded: bool = False) -> Slot | None:
        payload = {
            "requester": requester, "task_id": task_id, "model": model or "",
            "minimum_ram_gb": min_ram_gb, "minimum_vram_gb": min_vram_gb,
            "estimated_duration_seconds": duration_s, "priority": priority,
            "require_model_loaded": require_model_loaded,
        }
        if machine_id:
            payload["machine_id"] = machine_id
        res = self._req("POST", "/reservations", payload)
        return Slot(res) if res.get("status") == "reserved" else None

    def wait_for_slot(self, requester: str, timeout_s: int = 300,
                      poll_s: int = 10, **kw) -> Slot | None:
        """Blockiert, bis Kapazitaet frei ist oder das Timeout greift."""
        deadline = time.time() + timeout_s
        while time.time() < deadline:
            slot = self.reserve(requester, **kw)
            if slot:
                return slot
            time.sleep(poll_s)
        return None

    def start(self, slot: Slot | str) -> dict:
        return self._req("POST", f"/reservations/{_sid(slot)}/start")

    def heartbeat(self, slot: Slot | str) -> dict:
        return self._req("POST", f"/reservations/{_sid(slot)}/heartbeat")

    def complete(self, slot: Slot | str) -> dict:
        return self._req("POST", f"/reservations/{_sid(slot)}/complete")

    def fail(self, slot: Slot | str) -> dict:
        return self._req("POST", f"/reservations/{_sid(slot)}/fail")

    def cancel(self, slot: Slot | str) -> dict:
        return self._req("DELETE", f"/reservations/{_sid(slot)}")

    # -- TPS ---------------------------------------------------------------
    def report_tps(self, machine_id: str, ollama_response: dict,
                   model: str = "", task_id: str = "",
                   reservation_id: str = "") -> dict:
        """Exakte TPS aus einer Ollama-Antwort melden."""
        payload = {
            "machine_id": machine_id, "model": model or ollama_response.get("model", ""),
            "task_id": task_id, "reservation_id": reservation_id,
            "eval_count": ollama_response.get("eval_count", 0),
            "eval_duration": ollama_response.get("eval_duration", 0),
            "prompt_eval_count": ollama_response.get("prompt_eval_count", 0),
            "prompt_eval_duration": ollama_response.get("prompt_eval_duration", 0),
            "source": "agent",
        }
        write_tps_spool(payload)  # lokal puffern, damit der Agent es auch sieht
        try:
            return self._req("POST", "/tps", payload)
        except MacMonitorError:
            return {"status": "spooled"}

    # -- Ollama ------------------------------------------------------------
    def generate(self, slot: Slot, prompt: str, model: str | None = None,
                 stream: bool = False, timeout: float = 600.0,
                 options: dict | None = None) -> dict:
        """Fuehrt eine Generierung auf der reservierten Maschine aus und
        meldet die gemessene TPS automatisch zurueck."""
        model = model or slot.get("model") or ""
        url = (slot.backend_url or "http://127.0.0.1:11434").rstrip("/") + "/api/generate"
        body = {"model": model, "prompt": prompt, "stream": stream}
        if options:
            body["options"] = options
        req = urllib.request.Request(url, data=json.dumps(body).encode("utf-8"),
                                     method="POST",
                                     headers={"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            data = json.loads(resp.read().decode("utf-8"))
        self.report_tps(slot.machine_id, data, model=model,
                        task_id=slot.get("task_id", ""), reservation_id=slot.id)
        if data.get("eval_count") and data.get("eval_duration"):
            data["measured_tps"] = round(
                data["eval_count"] / (data["eval_duration"] / 1e9), 2)
        return data

    # -- Kontextmanager ----------------------------------------------------
    @contextmanager
    def slot(self, requester: str, wait_s: int = 0, heartbeat_s: int = 30, **kw):
        """Reserviert, haelt den Slot per Heartbeat und gibt ihn sicher frei."""
        s = (self.wait_for_slot(requester, timeout_s=wait_s, **kw) if wait_s > 0
             else self.reserve(requester, **kw))
        if not s:
            yield None
            return
        self.start(s)
        stop = threading.Event()

        def beat() -> None:
            while not stop.wait(heartbeat_s):
                try:
                    self.heartbeat(s)
                except MacMonitorError:
                    pass

        t = threading.Thread(target=beat, daemon=True)
        t.start()
        try:
            yield s
        except Exception:
            stop.set()
            try:
                self.fail(s)
            except MacMonitorError:
                pass
            raise
        else:
            stop.set()
            try:
                self.complete(s)
            except MacMonitorError:
                pass


def _sid(slot: Slot | str) -> str:
    return slot if isinstance(slot, str) else slot.get("reservation_id", "")


def write_tps_spool(event: dict, path: Path = TPS_SPOOL) -> None:
    """Schreibt eine TPS-Messung lokal, damit der Monitor-Agent sie einsammelt."""
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        event = dict(event)
        event.setdefault("ts", int(time.time()))
        with path.open("a", encoding="utf-8") as fh:
            fh.write(json.dumps(event, ensure_ascii=False) + "\n")
        if path.stat().st_size > 2 * 1024 * 1024:  # einfache Rotation
            lines = path.read_text(encoding="utf-8", errors="ignore").splitlines()[-500:]
            path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    except OSError:
        pass


if __name__ == "__main__":
    import argparse

    ap = argparse.ArgumentParser(description="mac-monitor Agent-SDK (CLI)")
    ap.add_argument("--server", default=os.environ.get("MM_SERVER_URL", "http://127.0.0.1:8770"))
    ap.add_argument("--token", default=os.environ.get("MM_API_TOKEN", "mac-monitor-test-token"))
    ap.add_argument("--model", default=None)
    ap.add_argument("--min-ram", type=float, default=0)
    ap.add_argument("--min-vram", type=float, default=0)
    ap.add_argument("command", choices=["health", "machines", "best", "discovery", "reserve"])
    a = ap.parse_args()

    mm = MacMonitor(a.server, a.token)
    if a.command == "health":
        print(json.dumps(mm.health(), indent=2))
    elif a.command == "machines":
        for m in mm.machines():
            print(f"{m['capacity_score']:5.1f}  {m['machine_id']:<20} "
                  f"{'online ' if m['online'] else 'offline'} "
                  f"RAM {m['ram_free_gb']:.1f} GB frei  "
                  f"VRAM {m['vram_free_gb']:.1f} GB frei  "
                  f"Slots {m['free_slots']}/{m['max_slots']}")
    elif a.command in ("best", "discovery"):
        d = mm.discovery(a.model, a.min_ram, a.min_vram)
        print(json.dumps(d if a.command == "discovery" else d.get("recommended"), indent=2))
    elif a.command == "reserve":
        s = mm.reserve("cli", "manual", a.model, a.min_ram, a.min_vram)
        print(json.dumps(s or {"status": "unavailable"}, indent=2))
