"""Fleet Control - plattformneutrale Steuerung lokaler LLM-Engines und Agenten.

Laeuft auf macOS und Linux ohne systemd/launchd-Abhaengigkeit: Engines werden
ueber ihren Startbefehl, ihren Port und optionale Prozessmuster verwaltet.

Profile (z. B. "flash-next", "27b") gehoeren zu einer Engine-Gruppe
(z. B. "halogen"). Innerhalb einer Gruppe laeuft immer nur ein Profil.

Ablauf beim Profilwechsel
    1. Sperre setzen (nur eine Aktion gleichzeitig pro Maschine)
    2. laufende Profile derselben Engine sauber stoppen (TERM, danach KILL)
    3. Ollama- und LM-Studio-Modelle entladen (optional)
    4. Seiten-Cache freigeben (optional, nur wenn erlaubt)
    5. warten, bis genug RAM frei ist (gemessener Bedarf + Reserve)
    6. Profil starten, Health-Check abwarten, kurzer Generierungstest
    7. RAM-Bedarf messen und speichern
    8. bei Fehler: vorheriges Profil wiederherstellen

Konfiguration: Schluessel "control" in client/config.json
(Vorlage: client/control.example.json). Profile lassen sich aus einem
laufenden Prozess uebernehmen:  python3 client/fleet_control.py adopt <id> --port 8731
"""
from __future__ import annotations

import contextlib
import json
import os
import platform
import re
import shlex
import shutil
import signal
import socket
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Callable

STATE_DIR = Path(os.path.expanduser(os.environ.get("FLEET_STATE_DIR", "~/.local/share/fleet-monitor")))
GB = 1024 ** 3
IS_MAC = platform.system() == "Darwin"
ID_RE = re.compile(r"^[a-z0-9][a-z0-9_.-]{0,39}$")

PHASE_LABEL = {
    "idle": "Bereit", "stopping": "Stoppt", "unloading": "Entlädt Modelle",
    "freeing": "Gibt Speicher frei", "waiting_ram": "Wartet auf RAM",
    "starting": "Startet", "loading": "Lädt Modell", "testing": "Prüft Generierung",
    "measuring": "Misst RAM", "rollback": "Stellt vorheriges Profil her",
    "restarting": "Startet neu", "error": "Fehler",
}


class ControlError(RuntimeError):
    pass


# --------------------------------------------------------------------------
# Hilfen: Prozesse, Ports, Speicher (macOS + Linux)
# --------------------------------------------------------------------------
def _run(cmd: list[str], timeout: float = 10.0) -> tuple[int, str]:
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, check=False)
        return p.returncode, (p.stdout or "") + (p.stderr or "")
    except (OSError, subprocess.SubprocessError) as exc:
        return 127, str(exc)


def mem_available_gb() -> float:
    """Freier Arbeitsspeicher in GB (Linux MemAvailable, macOS free+inactive+speculative)."""
    if IS_MAC:
        rc, out = _run(["vm_stat"])
        page = 4096
        m = re.search(r"page size of (\d+) bytes", out)
        if m:
            page = int(m.group(1))
        stats = {k.strip().lower(): int(v) for k, v in re.findall(r"^(.*?):\s+(\d+)\.", out, re.M)}
        pages = sum(stats.get(k, 0) for k in ("pages free", "pages inactive", "pages speculative", "pages purgeable"))
        return round(pages * page / GB, 2)
    try:
        with open("/proc/meminfo", encoding="utf-8") as fh:
            for line in fh:
                if line.startswith("MemAvailable:"):
                    return round(int(line.split()[1]) * 1024 / GB, 2)
    except OSError:
        pass
    return 0.0


def mem_total_gb() -> float:
    if IS_MAC:
        rc, out = _run(["sysctl", "-n", "hw.memsize"])
        return round(int(out.strip()) / GB, 2) if out.strip().isdigit() else 0.0
    try:
        with open("/proc/meminfo", encoding="utf-8") as fh:
            for line in fh:
                if line.startswith("MemTotal:"):
                    return round(int(line.split()[1]) * 1024 / GB, 2)
    except OSError:
        pass
    return 0.0


def pid_alive(pid: int) -> bool:
    if pid <= 0:
        return False
    # Eigene, bereits beendete Kinder einsammeln (sonst Zombie = scheinbar lebendig, v. a. macOS)
    try:
        done, _ = os.waitpid(pid, os.WNOHANG)
        if done == pid:
            return False
    except ChildProcessError:
        pass
    except OSError:
        pass
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    # Zombie unter Linux zaehlt nicht als laufend
    try:
        with open(f"/proc/{pid}/stat", encoding="utf-8") as fh:
            if fh.read().split(")")[-1].split()[0] == "Z":
                return False
    except OSError:
        pass
    return True


def _proc_table() -> list[tuple[int, int, int, str]]:
    """(pid, ppid, rss_kb, args) aller Prozesse - per ps, auf beiden Systemen vorhanden."""
    rc, out = _run(["ps", "-A", "-ww", "-o", "pid=,ppid=,rss=,args="])
    rows = []
    for line in out.splitlines():
        parts = line.strip().split(None, 3)
        if len(parts) >= 3 and parts[0].isdigit() and parts[1].isdigit():
            rss = int(parts[2]) if parts[2].isdigit() else 0
            rows.append((int(parts[0]), int(parts[1]), rss, parts[3] if len(parts) > 3 else ""))
    return rows


def tree_pids(root: int, table: list | None = None) -> list[int]:
    table = table or _proc_table()
    kids: dict[int, list[int]] = {}
    for pid, ppid, _, _ in table:
        kids.setdefault(ppid, []).append(pid)
    out, todo = [], [root]
    while todo:
        p = todo.pop()
        if p in out:
            continue
        out.append(p)
        todo.extend(kids.get(p, []))
    return out


def tree_rss_gb(pids: list[int]) -> float:
    table = _proc_table()
    allp: set[int] = set()
    for p in pids:
        allp.update(tree_pids(p, table))
    kb = sum(rss for pid, _, rss, _ in table if pid in allp)
    return round(kb * 1024 / GB, 2)


def pids_by_pattern(patterns: list[str]) -> list[int]:
    me = os.getpid()
    found: set[int] = set()
    for pid, _, _, args in _proc_table():
        if pid == me or "fleet_control" in args or "monitor.py" in args:
            continue
        for pat in patterns or []:
            if pat and re.search(pat, args):
                found.add(pid)
    return sorted(found)


def pids_on_port(port: int) -> list[int]:
    if not port:
        return []
    pids: set[int] = set()
    if shutil.which("lsof"):
        rc, out = _run(["lsof", "-nP", "-t", f"-iTCP:{port}", "-sTCP:LISTEN"])
        pids.update(int(x) for x in out.split() if x.isdigit())
    if not pids and shutil.which("ss"):
        rc, out = _run(["ss", "-ltnpH", f"sport = :{port}"])
        pids.update(int(x) for x in re.findall(r"pid=(\d+)", out))
    if not pids and not IS_MAC:
        pids.update(_linux_port_pids(port))
    return sorted(pids)


def _linux_port_pids(port: int) -> set[int]:
    inodes = set()
    for name in ("/proc/net/tcp", "/proc/net/tcp6"):
        try:
            with open(name, encoding="utf-8") as fh:
                next(fh)
                for line in fh:
                    f = line.split()
                    if f[3] == "0A" and int(f[1].split(":")[1], 16) == port:
                        inodes.add(f[9])
        except (OSError, StopIteration, IndexError, ValueError):
            pass
    out = set()
    if not inodes:
        return out
    for d in Path("/proc").iterdir():
        if not d.name.isdigit():
            continue
        try:
            for fd in (d / "fd").iterdir():
                link = os.readlink(fd)
                if link.startswith("socket:[") and link[8:-1] in inodes:
                    out.add(int(d.name))
                    break
        except OSError:
            continue
    return out


def port_open(port: int, host: str = "127.0.0.1", timeout: float = 1.0) -> bool:
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return True
    except OSError:
        return False


def proc_cmdline(pid: int) -> list[str]:
    if not IS_MAC:
        try:
            raw = Path(f"/proc/{pid}/cmdline").read_bytes()
            return [x.decode(errors="replace") for x in raw.split(b"\0") if x]
        except OSError:
            pass
    rc, out = _run(["ps", "-ww", "-o", "args=", "-p", str(pid)])
    try:
        return shlex.split(out.strip())
    except ValueError:
        return out.strip().split()


def proc_cwd(pid: int) -> str:
    if not IS_MAC:
        try:
            return os.readlink(f"/proc/{pid}/cwd")
        except OSError:
            return ""
    rc, out = _run(["lsof", "-a", "-p", str(pid), "-d", "cwd", "-Fn"])
    for line in out.splitlines():
        if line.startswith("n"):
            return line[1:]
    return ""


def http_get(url: str, timeout: float = 3.0) -> tuple[int, str]:
    try:
        with urllib.request.urlopen(url, timeout=timeout) as r:
            return r.status, r.read(65536).decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        return exc.code, ""
    except (urllib.error.URLError, OSError, ValueError):
        return 0, ""


def http_post_json(url: str, body: dict, timeout: float = 60.0) -> tuple[int, dict]:
    req = urllib.request.Request(url, data=json.dumps(body).encode("utf-8"),
                                 headers={"Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, json.loads(r.read().decode("utf-8") or "{}")
    except urllib.error.HTTPError as exc:
        return exc.code, {}
    except (urllib.error.URLError, OSError, ValueError):
        return 0, {}


# --------------------------------------------------------------------------
# Konfiguration
# --------------------------------------------------------------------------
DEFAULTS = {
    "profiles": {},
    "agents": {},
    "unload_ollama": True,
    "unload_lm_studio": True,
    "free_cache": True,
    "cache_helper": "/usr/local/sbin/fleet-dropcache",
    "ram_headroom_gb": 4.0,
    "ram_wait_seconds": 90,
    "stop_timeout": 25,
    "allow_force_kill": True,
    "rollback": True,
    "ollama_url": "http://127.0.0.1:11434",
    "lm_studio_url": "http://127.0.0.1:1234",
    # Unter welchem Namen andere Rechner diese Engines erreichen (LAN/Tailscale),
    # z. B. "evo-x3.local" oder "100.91.16.62". Leer = nicht von außen nutzbar.
    "advertise_host": "",
}
PROFILE_DEFAULTS = {
    "label": "", "engine": "default", "port": 0, "health": "", "start": [], "cwd": "",
    "env": {}, "process_patterns": [], "start_timeout": 900, "smoke_test": True,
    "smoke_model": "", "ram_gb": None,
}
AGENT_DEFAULTS = {"label": "", "restart": [], "start": [], "stop": [], "process_patterns": [],
                  "health": "", "cwd": "", "env": {}, "start_timeout": 60}


def normalize(cfg: dict | None) -> dict:
    c = dict(DEFAULTS)
    c.update(cfg or {})
    profiles, agents = {}, {}
    for pid, p in (c.get("profiles") or {}).items():
        if not ID_RE.match(str(pid)):
            continue
        q = dict(PROFILE_DEFAULTS)
        q.update(p or {})
        q["label"] = q["label"] or pid
        q["port"] = int(q.get("port") or 0)
        if not q["health"] and q["port"]:
            q["health"] = f"http://127.0.0.1:{q['port']}/health"
        profiles[pid] = q
    for aid, a in (c.get("agents") or {}).items():
        if not ID_RE.match(str(aid)):
            continue
        q = dict(AGENT_DEFAULTS)
        q.update(a or {})
        q["label"] = q["label"] or aid
        agents[aid] = q
    c["profiles"], c["agents"] = profiles, agents
    return c


# --------------------------------------------------------------------------
# Controller
# --------------------------------------------------------------------------
class Controller:
    """Fuehrt Steueraktionen aus und haelt einen beobachtbaren Zustand."""

    def __init__(self, cfg: dict | None, state_dir: Path | None = None,
                 unloaders: dict[str, Callable[[], bool]] | None = None) -> None:
        self.cfg = normalize(cfg)
        self.dir = Path(state_dir or STATE_DIR)
        self.dir.mkdir(parents=True, exist_ok=True)
        self.state_file = self.dir / "control_state.json"
        self.measure_file = self.dir / "profiles_measured.json"
        self.log_dir = self.dir / "logs"
        self.log_dir.mkdir(exist_ok=True)
        self.unloaders = unloaders or {}
        self._mem = threading.Lock()
        self.state = self._load(self.state_file, {"phase": "idle", "message": "", "busy": False,
                                                  "action": None, "desired": None, "events": [],
                                                  "pids": {}, "last_profile": {}})
        self.state["busy"] = False  # nach Neustart nie "haengend"
        self.measured = self._load(self.measure_file, {})

    # -- Persistenz --------------------------------------------------------
    @staticmethod
    def _load(path: Path, default: dict) -> dict:
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            if isinstance(data, dict):
                out = dict(default)
                out.update(data)
                return out
        except (OSError, ValueError):
            pass
        return dict(default)

    def _save(self, path: Path, data: dict) -> None:
        fd, tmp = tempfile.mkstemp(dir=str(self.dir), suffix=".tmp")
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(data, fh, indent=2)
        os.replace(tmp, path)

    def _persist(self) -> None:
        with self._mem:
            self._save(self.state_file, self.state)

    def event(self, message: str, level: str = "info") -> None:
        with self._mem:
            ev = self.state.setdefault("events", [])
            ev.append({"ts": int(time.time()), "level": level, "message": message[:300]})
            del ev[:-60]
        self._persist()

    def phase(self, phase: str, message: str = "") -> None:
        with self._mem:
            self.state["phase"] = phase
            self.state["message"] = message or PHASE_LABEL.get(phase, phase)
            self.state["phase_ts"] = int(time.time())
        self._persist()

    # -- Sperre ------------------------------------------------------------
    @contextlib.contextmanager
    def lock(self, action: str):
        lockfile = self.dir / "control.lock"
        fh = open(lockfile, "a+")
        try:
            import fcntl
            try:
                fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except OSError:
                raise ControlError("Es läuft bereits eine Steueraktion auf diesem Gerät.")
            with self._mem:
                self.state.update(busy=True, action=action, action_ts=int(time.time()))
            self._persist()
            try:
                yield
            finally:
                with self._mem:
                    self.state.update(busy=False, action=None)
                self._persist()
                fcntl.flock(fh, fcntl.LOCK_UN)
        finally:
            fh.close()

    # -- Status ------------------------------------------------------------
    def profile_pids(self, pid_: str) -> list[int]:
        p = self.cfg["profiles"][pid_]
        pids: set[int] = set()
        known = int(self.state.get("pids", {}).get(pid_) or 0)
        if known and pid_alive(known):
            pids.add(known)
        pids.update(pids_on_port(p["port"]))
        if p["process_patterns"]:
            pids.update(pids_by_pattern(p["process_patterns"]))
        return sorted(x for x in pids if pid_alive(x))

    def profile_running(self, pid_: str) -> bool:
        p = self.cfg["profiles"][pid_]
        if not self.profile_pids(pid_):
            return False
        # Port gehoert evtl. einem anderen Profil derselben Engine -> Startbefehl vergleichen
        if p["port"] and p["start"]:
            for pid in pids_on_port(p["port"]):
                cmd = " ".join(proc_cmdline(pid))
                if cmd and not self._cmd_matches(p, cmd):
                    return False
        return True

    @staticmethod
    def _cmd_matches(p: dict, cmd: str) -> bool:
        """Grobe Zuordnung: alle Argumente, die nach Modell/Pfad aussehen, muessen vorkommen."""
        keys = [a for a in p["start"][1:] if ("/" in a or "." in a) and not a.startswith("-")][:3]
        return all(k in cmd for k in keys) if keys else True

    def agent_running(self, aid: str) -> bool:
        a = self.cfg["agents"][aid]
        return bool(a["process_patterns"] and pids_by_pattern(a["process_patterns"]))

    def required_ram(self, pid_: str) -> float | None:
        m = self.measured.get(pid_) or {}
        hist = [h.get("ram_gb") for h in m.get("history", []) if h.get("ram_gb")]
        if hist:
            return round(max(hist[-5:]), 1)
        cfg_val = self.cfg["profiles"][pid_].get("ram_gb")
        return float(cfg_val) if cfg_val else None

    def snapshot(self) -> dict:
        """Kompakter Zustand fuer das Dashboard (wird mit jedem Messwert gesendet)."""
        profiles = []
        for pid_, p in self.cfg["profiles"].items():
            running = self.profile_running(pid_)
            m = self.measured.get(pid_) or {}
            last = (m.get("history") or [{}])[-1]
            host = self.cfg.get("advertise_host") or ""
            profiles.append({
                "id": pid_, "label": p["label"], "engine": p["engine"], "port": p["port"],
                "endpoint": f"http://{host}:{p['port']}/v1" if host and p["port"] else None,
                "running": running, "ram_gb": self.required_ram(pid_),
                "load_s": last.get("load_s"), "tps": last.get("tps"),
                "measured_at": last.get("ts"),
            })
        agents = [{"id": aid, "label": a["label"], "running": self.agent_running(aid),
                   "can_restart": bool(a["restart"] or a["start"])}
                  for aid, a in self.cfg["agents"].items()]
        with self._mem:
            st = {k: self.state.get(k) for k in ("phase", "message", "busy", "action", "desired", "phase_ts")}
            st["events"] = list(self.state.get("events", []))[-25:]
        st.update({
            "ram_available_gb": mem_available_gb(), "ram_total_gb": mem_total_gb(),
            "profiles": profiles, "agents": agents,
            "can_free_cache": self._cache_method() is not None,
            "platform": platform.system(),
        })
        return st

    # -- Bausteine ---------------------------------------------------------
    def _kill(self, pids: list[int], label: str) -> None:
        if not pids:
            return
        table = _proc_table()
        allp: list[int] = []
        for p in pids:
            for q in tree_pids(p, table):
                if q not in allp and q != os.getpid():
                    allp.append(q)
        for p in allp:
            with contextlib.suppress(OSError):
                os.kill(p, signal.SIGTERM)
        deadline = time.time() + float(self.cfg["stop_timeout"])
        while time.time() < deadline and any(pid_alive(p) for p in allp):
            time.sleep(0.5)
        left = [p for p in allp if pid_alive(p)]
        if left:
            if not self.cfg["allow_force_kill"]:
                raise ControlError(f"{label} reagiert nicht auf Stopp (PIDs {left}).")
            self.event(f"{label}: erzwinge Beenden ({len(left)} Prozesse)", "warn")
            for p in left:
                with contextlib.suppress(OSError):
                    os.kill(p, signal.SIGKILL)
            time.sleep(1.0)
        for p in allp:  # Zombies einsammeln, falls eigene Kinder
            with contextlib.suppress(ChildProcessError, OSError):
                os.waitpid(p, os.WNOHANG)

    def stop_profile(self, pid_: str) -> None:
        p = self.cfg["profiles"][pid_]
        pids = self.profile_pids(pid_)
        if pids:
            self.event(f"Stoppe {p['label']}")
            self._kill(pids, p["label"])
        with self._mem:
            self.state.setdefault("pids", {}).pop(pid_, None)
        self._persist()

    def stop_engine(self, engine: str, except_id: str | None = None) -> list[str]:
        stopped = []
        for pid_, p in self.cfg["profiles"].items():
            if p["engine"] == engine and pid_ != except_id and self.profile_pids(pid_):
                self.stop_profile(pid_)
                stopped.append(pid_)
        # Port eines Ziel-Profils frei machen, falls ein fremder Prozess ihn belegt
        if except_id:
            port = self.cfg["profiles"][except_id]["port"]
            foreign = pids_on_port(port)
            if foreign:
                self.event(f"Port {port} belegt – beende alte Instanz", "warn")
                self._kill(foreign, f"Port {port}")
        return stopped

    def unload_models(self) -> None:
        for name, fn in self.unloaders.items():
            if not self.cfg.get(f"unload_{name}", True):
                continue
            try:
                if fn():
                    self.event(f"{name.replace('_', ' ').title()}: Modelle entladen")
            except Exception as exc:  # noqa: BLE001
                self.event(f"{name}: Entladen fehlgeschlagen ({exc})", "warn")

    def _cache_method(self) -> list[str] | None:
        if not self.cfg["free_cache"]:
            return None
        if IS_MAC:
            if shutil.which("purge"):
                return ["purge"] if os.geteuid() == 0 else ["sudo", "-n", "/usr/sbin/purge"]
            return None
        if os.geteuid() == 0:
            return ["sh", "-c", "sync; echo 3 > /proc/sys/vm/drop_caches"]
        helper = self.cfg["cache_helper"]
        if helper and Path(helper).exists():
            return ["sudo", "-n", helper]
        return None

    def free_cache(self) -> bool:
        cmd = self._cache_method()
        if not cmd:
            self.event("Cache freigeben übersprungen (nicht eingerichtet, siehe docs/CONTROL.md)")
            return False
        before = mem_available_gb()
        rc, out = _run(["sync"]) if not IS_MAC else (0, "")
        rc, out = _run(cmd, timeout=60)
        if rc != 0:
            self.event(f"Cache freigeben fehlgeschlagen: {out.strip()[:120]}", "warn")
            return False
        after = mem_available_gb()
        self.event(f"Cache freigegeben: {before:.1f} → {after:.1f} GB verfügbar")
        return True

    def wait_ram(self, need_gb: float | None) -> float:
        avail = mem_available_gb()
        if not need_gb:
            return avail
        target = need_gb + float(self.cfg["ram_headroom_gb"])
        deadline = time.time() + float(self.cfg["ram_wait_seconds"])
        while avail < target and time.time() < deadline:
            self.phase("waiting_ram", f"Warte auf RAM: {avail:.1f} / {target:.1f} GB")
            time.sleep(3)
            avail = mem_available_gb()
        if avail < target:
            raise ControlError(f"Zu wenig RAM: {avail:.1f} GB frei, benötigt {target:.1f} GB "
                               f"({need_gb:.1f} GB Modell + Reserve).")
        return avail

    def _spawn(self, item_id: str, spec: dict) -> int:
        if not spec["start"]:
            raise ControlError(f"Für '{item_id}' ist kein Startbefehl hinterlegt "
                               f"(fleet_control.py adopt {item_id} --port …).")
        env = dict(os.environ)
        env.update({k: str(v) for k, v in (spec.get("env") or {}).items()})
        log = open(self.log_dir / f"{item_id}.log", "ab")
        log.write(f"\n--- start {time.strftime('%Y-%m-%d %H:%M:%S')} ---\n".encode())
        log.flush()
        try:
            proc = subprocess.Popen([os.path.expanduser(a) for a in spec["start"]],
                                    cwd=os.path.expanduser(spec.get("cwd") or "") or None, env=env,
                                    stdout=log, stderr=subprocess.STDOUT, stdin=subprocess.DEVNULL,
                                    start_new_session=True)
        except OSError as exc:
            raise ControlError(f"Start fehlgeschlagen: {exc}")
        finally:
            log.close()
        proc.returncode = 0   # Prozess laeuft bewusst weiter; Reaping uebernimmt pid_alive()
        return proc.pid

    def _log_tail(self, item_id: str, lines: int = 3) -> str:
        try:
            data = (self.log_dir / f"{item_id}.log").read_text(errors="replace").strip().splitlines()
            return " | ".join(x.strip() for x in data[-lines:])[:240]
        except OSError:
            return ""

    def _wait_healthy(self, pid_: str, proc_pid: int) -> float:
        p = self.cfg["profiles"][pid_]
        t0 = time.time()
        deadline = t0 + float(p["start_timeout"])
        while time.time() < deadline:
            if proc_pid and not pid_alive(proc_pid) and not pids_on_port(p["port"]):
                raise ControlError(f"{p['label']} hat sich beendet. Log: {self._log_tail(pid_)}")
            if p["health"]:
                code, _ = http_get(p["health"], timeout=3)
                if 200 <= code < 300:
                    return time.time() - t0
            elif p["port"] and port_open(p["port"]):
                return time.time() - t0
            elif not p["port"] and time.time() - t0 > 5 and pid_alive(proc_pid):
                return time.time() - t0
            self.phase("loading", f"Lädt {p['label']} … {int(time.time() - t0)} s")
            time.sleep(2)
        raise ControlError(f"{p['label']} nicht bereit nach {int(p['start_timeout'])} s.")

    def smoke_test(self, pid_: str) -> float | None:
        p = self.cfg["profiles"][pid_]
        if not p["smoke_test"] or not p["port"]:
            return None
        base = f"http://127.0.0.1:{p['port']}"
        model = p["smoke_model"]
        if not model:
            code, body = http_get(base + "/v1/models", timeout=5)
            try:
                model = (json.loads(body).get("data") or [{}])[0].get("id", "")
            except (ValueError, AttributeError):
                model = ""
        t0 = time.time()
        code, data = http_post_json(base + "/v1/chat/completions", {
            "model": model or "default", "max_tokens": 32, "temperature": 0,
            "messages": [{"role": "user", "content": "Zähle von 1 bis 10."}]}, timeout=180)
        dt = time.time() - t0
        if code != 200:
            raise ControlError(f"Generierungstest fehlgeschlagen (HTTP {code}).")
        n = int(((data.get("usage") or {}).get("completion_tokens")) or 0)
        timings = data.get("timings") or {}
        tps = timings.get("predicted_per_second") or (n / dt if n and dt > 0 else None)
        return round(float(tps), 1) if tps else None

    def _record_measure(self, pid_: str, entry: dict) -> None:
        m = self.measured.setdefault(pid_, {"history": []})
        m["history"].append(entry)
        m["history"] = m["history"][-10:]
        self._save(self.measure_file, self.measured)

    def measure(self, pid_: str, avail_before: float | None = None, load_s: float | None = None,
                tps: float | None = None) -> dict:
        pids = self.profile_pids(pid_)
        rss = tree_rss_gb(pids) if pids else 0.0
        delta = round(avail_before - mem_available_gb(), 2) if avail_before is not None else 0.0
        # Unified Memory (Strix Halo, Apple): GPU-Puffer erscheinen nicht im RSS -> Maximum
        ram = round(max(rss, delta), 2)
        entry = {"ts": int(time.time()), "ram_gb": ram, "rss_gb": rss, "avail_delta_gb": delta,
                 "load_s": round(load_s, 1) if load_s else None, "tps": tps}
        if ram > 0:
            self._record_measure(pid_, entry)
        return entry

    # -- Aktionen ----------------------------------------------------------
    def start_profile(self, pid_: str) -> dict:
        if pid_ not in self.cfg["profiles"]:
            raise ControlError(f"Unbekanntes Profil '{pid_}'.")
        p = self.cfg["profiles"][pid_]
        with self.lock(f"profile:{pid_}"):
            with self._mem:
                self.state["desired"] = pid_
            prev = next((q for q, qp in self.cfg["profiles"].items()
                         if qp["engine"] == p["engine"] and q != pid_ and self.profile_running(q)), None)
            self.event(f"Auftrag: {p['label']} starten" + (f" (statt {self.cfg['profiles'][prev]['label']})" if prev else ""))
            try:
                return self._do_start(pid_, prev)
            except Exception as exc:
                msg = str(exc)
                self.event(msg, "error")
                if prev and self.cfg["rollback"]:
                    self.phase("rollback")
                    try:
                        self._do_start(prev, None, rollback=True)
                        self.event(f"Rückfall auf {self.cfg['profiles'][prev]['label']} erfolgreich", "warn")
                    except Exception as exc2:  # noqa: BLE001
                        self.event(f"Rückfall fehlgeschlagen: {exc2}", "error")
                self.phase("error", msg)
                raise ControlError(msg) from exc

    def _do_start(self, pid_: str, prev: str | None, rollback: bool = False) -> dict:
        p = self.cfg["profiles"][pid_]
        self.phase("stopping", f"Stoppe andere {p['engine']}-Instanzen")
        self.stop_engine(p["engine"], except_id=pid_)
        if self.profile_pids(pid_) and not rollback:
            self.event(f"{p['label']} lief noch – Neustart für sauberen Zustand")
            self.stop_profile(pid_)
        if not rollback:
            self.phase("unloading")
            self.unload_models()
            self.phase("freeing")
            self.free_cache()
        need = self.required_ram(pid_)
        avail = self.wait_ram(need)
        self.event(f"RAM verfügbar: {avail:.1f} GB" + (f" (Bedarf {need:.1f} GB)" if need else " (Bedarf noch unbekannt)"))
        self.phase("starting", f"Starte {p['label']}")
        proc_pid = self._spawn(pid_, p)
        with self._mem:
            self.state.setdefault("pids", {})[pid_] = proc_pid
            self.state["last_profile"][p["engine"]] = pid_
        self._persist()
        load_s = self._wait_healthy(pid_, proc_pid)
        self.event(f"{p['label']} bereit nach {load_s:.0f} s")
        tps = None
        if p["smoke_test"]:
            self.phase("testing")
            tps = self.smoke_test(pid_)
            if tps:
                self.event(f"Generierungstest ok: {tps} tok/s")
        self.phase("measuring")
        m = self.measure(pid_, avail, load_s, tps)
        self.event(f"RAM-Bedarf gemessen: {m['ram_gb']:.1f} GB (RSS {m['rss_gb']:.1f}, Δ frei {m['avail_delta_gb']:.1f})", "ok")
        self.phase("idle", f"{p['label']} läuft")
        return {"profile": pid_, "load_s": round(load_s, 1), "tps": tps, "ram_gb": m["ram_gb"]}

    def stop(self, engine_or_profile: str) -> dict:
        with self.lock(f"stop:{engine_or_profile}"):
            if engine_or_profile in self.cfg["profiles"]:
                self.phase("stopping")
                self.stop_profile(engine_or_profile)
                stopped = [engine_or_profile]
            else:
                self.phase("stopping", f"Stoppe Engine {engine_or_profile}")
                stopped = self.stop_engine(engine_or_profile)
            with self._mem:
                if self.state.get("desired") in stopped:
                    self.state["desired"] = None
            self.event("Gestoppt: " + (", ".join(stopped) if stopped else "nichts lief"), "ok")
            self.phase("idle", "Engine gestoppt")
            return {"stopped": stopped}

    def free_memory(self) -> dict:
        with self.lock("free"):
            before = mem_available_gb()
            self.phase("unloading")
            self.unload_models()
            self.phase("freeing")
            self.free_cache()
            after = mem_available_gb()
            self.event(f"Speicher freigegeben: {before:.1f} → {after:.1f} GB", "ok")
            self.phase("idle")
            return {"before_gb": before, "after_gb": after}

    def restart_agent(self, aid: str) -> dict:
        if aid not in self.cfg["agents"]:
            raise ControlError(f"Unbekannter Agent '{aid}'.")
        a = self.cfg["agents"][aid]
        with self.lock(f"agent:{aid}"):
            self.phase("restarting", f"Starte {a['label']} neu")
            self.event(f"Auftrag: {a['label']} neu starten")
            try:
                if a["restart"]:
                    rc, out = _run([os.path.expanduser(x) for x in a["restart"]], timeout=120)
                    if rc != 0:
                        raise ControlError(f"Neustart fehlgeschlagen: {out.strip()[:200]}")
                else:
                    if a["stop"]:
                        _run([os.path.expanduser(x) for x in a["stop"]], timeout=60)
                    pids = pids_by_pattern(a["process_patterns"])
                    if pids:
                        self._kill(pids, a["label"])
                    self._spawn(f"agent-{aid}", {**a, "start": a["start"]})
                t0 = time.time()
                while time.time() - t0 < float(a["start_timeout"]):
                    if a["health"]:
                        code, _ = http_get(a["health"], timeout=3)
                        if 200 <= code < 300:
                            break
                    elif not a["process_patterns"] or self.agent_running(aid):
                        break
                    time.sleep(2)
                else:
                    raise ControlError(f"{a['label']} meldet sich nach dem Neustart nicht.")
                self.event(f"{a['label']} läuft wieder", "ok")
                self.phase("idle")
                return {"agent": aid, "running": True}
            except Exception as exc:
                self.event(str(exc), "error")
                self.phase("error", str(exc))
                raise

    # -- Profil aus laufendem Prozess uebernehmen ---------------------------
    @staticmethod
    def adopt(port: int, pattern: str = "") -> dict:
        pids = pids_on_port(port) if port else []
        if not pids and pattern:
            pids = pids_by_pattern([pattern])
        if not pids:
            raise ControlError(f"Kein laufender Prozess auf Port {port} gefunden.")
        # Elternprozess bevorzugen (z. B. Wrapper-Skript statt Worker)
        table = _proc_table()
        parent = {pid: ppid for pid, ppid, _, _ in table}
        root = pids[0]
        while parent.get(root) in pids:
            root = parent[root]
        cmd = proc_cmdline(root)
        if not cmd:
            raise ControlError("Startbefehl des Prozesses nicht lesbar.")
        return {"start": cmd, "cwd": proc_cwd(root), "pid": root, "rss_gb": tree_rss_gb([root])}
