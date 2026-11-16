#!/usr/bin/env python3
"""Tests für die Kommandozentrale (client/control.py) mit echten Prozessen.

Eine Test-Engine (tests/fixtures/fake_engine.py) spielt Halogen/llama.cpp:
Health-Endpunkt mit Ladezeit, /v1/models, /v1/chat/completions.
Geprüft werden Profilwechsel, Stoppen, Rollback, Sperre, Messung,
Übernahme laufender Prozesse und Agent-Neustart.

    python3 -m unittest tests.test_control -v
"""
from __future__ import annotations

import json
import os
import socket
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "client"))

import control  # noqa: E402

FAKE = str(ROOT / "tests" / "fixtures" / "fake_engine.py")
AGENT = str(ROOT / "tests" / "fixtures" / "fake_agent.py")


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class ControlTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.port = free_port()
        self.unloaded = []
        self.tag = f"fmtest{os.getpid()}x{int(time.time() * 1000) % 100000}"
        self.cfg = {
            "free_cache": False, "ram_headroom_gb": 0, "stop_timeout": 5, "ram_wait_seconds": 2,
            "profiles": {
                "flash": {"label": "Flash", "engine": "halogen", "port": self.port,
                          "start": [sys.executable, FAKE, str(self.port), "--load-seconds", "1",
                                    "--name", "flash.model", "--alloc-mb", "40"],
                          "start_timeout": 30},
                "big": {"label": "Big", "engine": "halogen", "port": self.port,
                        "start": [sys.executable, FAKE, str(self.port), "--name", "big.model"],
                        "start_timeout": 30},
                "broken": {"label": "Broken", "engine": "halogen", "port": self.port,
                           "start": [sys.executable, FAKE, str(self.port), "--fail", "--name", "broken.model"],
                           "start_timeout": 10},
            },
            "agents": {
                "bot": {"label": "Bot", "process_patterns": [f"fake_agent.py {self.tag}"],
                        "start": [sys.executable, AGENT, self.tag], "start_timeout": 10},
            },
        }
        self.ctl = self.make()

    def make(self) -> control.Controller:
        return control.Controller(self.cfg, state_dir=Path(self.tmp.name),
                                  unloaders={"ollama": lambda: self.unloaded.append("ollama") or True})

    def tearDown(self) -> None:
        for pid in control.pids_on_port(self.port) + control.pids_by_pattern([self.tag]):
            try:
                os.kill(pid, 9)
            except OSError:
                pass
        self.tmp.cleanup()

    # ------------------------------------------------------------------
    def test_start_switch_and_measure(self) -> None:
        r = self.ctl.start_profile("flash")
        self.assertEqual(r["profile"], "flash")
        self.assertTrue(self.ctl.profile_running("flash"))
        self.assertFalse(self.ctl.profile_running("big"))
        self.assertEqual(r["tps"], 42.5)
        self.assertGreater(r["ram_gb"], 0.03)            # 40 MB Ballast + Python
        self.assertIn("ollama", self.unloaded)           # Modelle wurden entladen
        self.assertIsNotNone(self.ctl.required_ram("flash"))
        # Wechsel auf "big": alte Instanz muss beendet sein
        self.ctl.start_profile("big")
        self.assertTrue(self.ctl.profile_running("big"))
        self.assertFalse(self.ctl.profile_running("flash"))
        self.assertEqual(len(control.pids_on_port(self.port)), 1)
        snap = self.ctl.snapshot()
        running = [p["id"] for p in snap["profiles"] if p["running"]]
        self.assertEqual(running, ["big"])
        self.assertEqual(snap["phase"], "idle")
        self.assertFalse(snap["busy"])

    def test_rollback_on_failed_start(self) -> None:
        self.ctl.start_profile("big")
        with self.assertRaises(control.ControlError):
            self.ctl.start_profile("broken")
        self.assertTrue(self.ctl.profile_running("big"), "vorheriges Profil wieder aktiv")
        msgs = " ".join(e["message"] for e in self.ctl.state["events"])
        self.assertIn("Rückfall", msgs)
        self.assertIn("simulierter Absturz", msgs)        # Log-Auszug im Fehler
        self.assertEqual(self.ctl.state["phase"], "error")

    def test_stop_engine(self) -> None:
        self.ctl.start_profile("flash")
        res = self.ctl.stop("halogen")
        self.assertEqual(res["stopped"], ["flash"])
        self.assertEqual(control.pids_on_port(self.port), [])
        self.assertFalse(control.port_open(self.port))

    def test_foreign_process_on_port_is_replaced(self) -> None:
        foreign = subprocess.Popen([sys.executable, FAKE, str(self.port), "--name", "fremd"],
                                   stdout=subprocess.DEVNULL, start_new_session=True)
        for _ in range(50):
            if control.port_open(self.port):
                break
            time.sleep(0.1)
        self.ctl.start_profile("flash")
        foreign.wait(timeout=10)
        self.assertIsNotNone(foreign.returncode, "fremde Instanz beendet")
        self.assertTrue(self.ctl.profile_running("flash"))

    def test_lock_prevents_parallel_actions(self) -> None:
        errors = []
        cfg2 = json.loads(json.dumps(self.cfg))
        cfg2["profiles"]["flash"]["start"][4] = "3"       # 3 s Ladezeit
        slow = control.Controller(cfg2, state_dir=Path(self.tmp.name))
        t = threading.Thread(target=slow.start_profile, args=("flash",))
        t.start()
        time.sleep(0.8)
        try:
            self.make().free_memory()
        except control.ControlError as exc:
            errors.append(str(exc))
        t.join(timeout=40)
        self.assertTrue(errors and "bereits eine Steueraktion" in errors[0])

    def test_ram_requirement_blocks_start(self) -> None:
        self.ctl.measured["big"] = {"history": [{"ts": 1, "ram_gb": 100000}]}
        with self.assertRaises(control.ControlError) as cm:
            self.ctl.start_profile("big")
        self.assertIn("Zu wenig RAM", str(cm.exception))
        self.assertFalse(control.port_open(self.port))

    def test_adopt_running_process(self) -> None:
        p = subprocess.Popen([sys.executable, FAKE, str(self.port), "--name", "adoptme"],
                             stdout=subprocess.DEVNULL, start_new_session=True)
        try:
            for _ in range(50):
                if control.port_open(self.port):
                    break
                time.sleep(0.1)
            info = control.Controller.adopt(self.port)
            self.assertEqual(info["pid"], p.pid)
            self.assertIn(FAKE, info["start"])
            self.assertIn("adoptme", info["start"])
        finally:
            p.kill()
            p.wait()

    def test_agent_restart(self) -> None:
        first = subprocess.Popen([sys.executable, AGENT, self.tag], stdout=subprocess.DEVNULL,
                                 start_new_session=True)
        time.sleep(0.5)
        self.assertTrue(self.ctl.agent_running("bot"))
        self.ctl.restart_agent("bot")
        first.wait(timeout=10)
        pids = control.pids_by_pattern([self.tag])
        self.assertEqual(len(pids), 1)
        self.assertNotEqual(pids[0], first.pid)

    def test_unknown_targets(self) -> None:
        with self.assertRaises(control.ControlError):
            self.ctl.start_profile("gibtsnicht")
        with self.assertRaises(control.ControlError):
            self.ctl.restart_agent("gibtsnicht")

    def test_state_survives_restart_and_busy_is_reset(self) -> None:
        self.ctl.state["busy"] = True
        self.ctl.event("vor Neustart")
        again = self.make()
        self.assertFalse(again.state["busy"])
        self.assertTrue(any(e["message"] == "vor Neustart" for e in again.state["events"]))

    def test_monitor_executes_control_commands(self) -> None:
        import monitor
        agent = monitor.Agent.__new__(monitor.Agent)
        agent.cfg, agent.ctl, agent._inflight = {"machine_id": "evo-x3"}, self.ctl, {"c1", "c2", "c3"}
        done = []
        agent._mark_command_done = lambda cid, ok, note="": done.append((cid, ok, note))
        agent._run_control({"id": "c1", "action": "control_profile", "params": {"profile": "flash"}})
        agent._run_control({"id": "c2", "action": "halogen_stop"})
        agent._run_control({"id": "c3", "action": "control_profile", "params": {"profile": "nope"}})
        self.assertEqual([d[:2] for d in done], [("c1", True), ("c2", True), ("c3", False)])
        self.assertIn("Unbekanntes Profil", done[2][2])
        self.assertEqual(agent._inflight, set())

    def test_cli_status_json(self) -> None:
        cfgfile = Path(self.tmp.name) / "config.json"
        cfgfile.write_text(json.dumps({"control": self.cfg}))
        env = dict(os.environ, FLEET_STATE_DIR=self.tmp.name)
        out = subprocess.run([sys.executable, str(ROOT / "client" / "fleet_control.py"),
                              "--config", str(cfgfile), "--json", "status"],
                             capture_output=True, text=True, env=env, timeout=60)
        self.assertEqual(out.returncode, 0, out.stderr)
        data = json.loads(out.stdout)
        self.assertEqual({p["id"] for p in data["profiles"]}, {"flash", "big", "broken"})
        self.assertIn("ram_available_gb", data)


if __name__ == "__main__":
    unittest.main(verbosity=2)
