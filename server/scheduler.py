"""Option C: intelligente Maschinenplanung inkl. Reservierung.

Kernidee: Nicht die Maschine mit der niedrigsten CPU-Last gewinnt, sondern
die Maschine mit der besten *real verfuegbaren und prognostizierten*
Ausfuehrungskapazitaet. Bereits reservierte Ressourcen werden abgezogen.
"""
from __future__ import annotations

import json
import secrets
import time
from typing import Any

from . import db, settings

ACTIVE_STATES = ("reserved", "running")


# --------------------------------------------------------------------------
# Helfer
# --------------------------------------------------------------------------
def _clamp(v: float, lo: float = 0.0, hi: float = 100.0) -> float:
    return max(lo, min(hi, v))


def _num(v: Any, default: float = 0.0) -> float:
    try:
        f = float(v)
    except (TypeError, ValueError):
        return default
    if f != f or f in (float("inf"), float("-inf")):  # NaN / inf
        return default
    return f


def new_reservation_id() -> str:
    return "res_" + secrets.token_hex(8)


def expire_stale() -> int:
    """Verfallene Reservierungen und tote Jobs aufraeumen."""
    now = int(time.time())
    n = 0
    cur = db.execute(
        "UPDATE reservations SET state='expired' "
        "WHERE state='reserved' AND valid_until < ?", (now,))
    n += cur.rowcount or 0
    cur = db.execute(
        "UPDATE reservations SET state='expired' "
        "WHERE state='running' AND COALESCE(last_heartbeat, started_ts, created_ts) < ?",
        (now - settings.HEARTBEAT_TIMEOUT,))
    n += cur.rowcount or 0
    if n:
        db.log_event("info", "scheduler", f"{n} Reservierung(en) verfallen")
    return n


def latest_sample(machine_id: str):
    return db.query_one(
        "SELECT * FROM samples WHERE machine_id=? ORDER BY ts DESC LIMIT 1",
        (machine_id,))


def active_reservations(machine_id: str) -> list[dict]:
    rows = db.query(
        "SELECT * FROM reservations WHERE machine_id=? AND state IN (?,?) "
        "ORDER BY created_ts", (machine_id, *ACTIVE_STATES))
    return [dict(r) for r in rows]


def model_tps(machine_id: str, model: str | None) -> tuple[float, str]:
    """Historische TPS der letzten 24h.

    Rueckgabe: (tps, quelle) mit quelle in 'model' | 'machine' | 'none'.
    Fremdmodell-Historie wird abgewertet, sonst gewinnt eine Maschine, die
    das angefragte Modell gar nicht kennt, nur wegen eines schnellen
    kleineren Modells.
    """
    now = int(time.time())
    if model:
        row = db.query_one(
            "SELECT AVG(tps) a FROM tps_samples "
            "WHERE machine_id=? AND model=? AND ts > ? AND tps > 0",
            (machine_id, model, now - 86400))
        if row and row["a"]:
            return float(row["a"]), "model"
    row = db.query_one(
        "SELECT AVG(tps) a FROM tps_samples "
        "WHERE machine_id=? AND ts > ? AND tps > 0",
        (machine_id, now - 86400))
    if row and row["a"]:
        factor = settings.TPS_FALLBACK_DISCOUNT if model else 1.0
        return float(row["a"]) * factor, "machine"
    return 0.0, "none"


def _best_tps_overall() -> float:
    row = db.query_one(
        "SELECT MAX(tps) m FROM tps_samples WHERE ts > ? AND tps > 0",
        (int(time.time()) - 86400,))
    return float(row["m"]) if row and row["m"] else 0.0


# --------------------------------------------------------------------------
# Kapazitaet
# --------------------------------------------------------------------------
def machine_capacity(machine: dict, model: str | None = None,
                     best_tps: float | None = None) -> dict:
    """Berechnet freie Kapazitaet + Score (0..100) einer Maschine."""
    now = int(time.time())
    s = latest_sample(machine["id"])
    last_seen = int(machine.get("last_seen") or 0)
    age = now - last_seen if last_seen else 10**9
    online = bool(machine.get("enabled", 1)) and age <= settings.OFFLINE_AFTER

    ram_total = _num(s["ram_total_gb"] if s else 0) or _num(machine.get("ram_total_gb"))
    ram_used = _num(s["ram_used_gb"] if s else 0)
    vram_total = _num(s["vram_total_gb"] if s else 0) or _num(machine.get("vram_total_gb"))
    vram_used = _num(s["vram_used_gb"] if s else 0)
    cpu = _clamp(_num(s["cpu_pct"] if s else 0))
    gpu = _clamp(_num(s["gpu_pct"] if s else 0))

    loaded: list[str] = []
    if s:
        try:
            loaded = [str(m) for m in json.loads(s["loaded_models"] or "[]")]
        except (json.JSONDecodeError, TypeError):
            loaded = []

    res = active_reservations(machine["id"])
    res_ram = sum(_num(r["min_ram_gb"]) for r in res)
    res_vram = sum(_num(r["min_vram_gb"]) for r in res)

    free_ram = max(0.0, ram_total - ram_used - res_ram - settings.RAM_HEADROOM_GB)
    free_vram = max(0.0, vram_total - vram_used - res_vram - settings.VRAM_HEADROOM_GB)

    max_slots = int(machine.get("max_slots") or 1)
    used_slots = len(res)
    free_slots = max(0, max_slots - used_slots)

    tps_hist, tps_source = model_tps(machine["id"], model)
    if best_tps is None:
        best_tps = _best_tps_overall()

    # --- Score-Komponenten (jeweils 0..1) ---
    c_vram = (free_vram / vram_total) if vram_total > 0 else (0.5 if free_ram > 0 else 0.0)
    c_ram = (free_ram / ram_total) if ram_total > 0 else 0.0
    c_gpu = (100.0 - gpu) / 100.0
    c_cpu = (100.0 - cpu) / 100.0
    c_tps = (tps_hist / best_tps) if best_tps > 0 else (0.5 if tps_hist > 0 else 0.0)
    c_model = 1.0 if (model and model in loaded) else 0.0

    weights = (settings.W_VRAM, settings.W_RAM, settings.W_GPU_IDLE,
               settings.W_CPU_IDLE, settings.W_TPS, settings.W_MODEL_LOADED)
    parts = (c_vram, c_ram, c_gpu, c_cpu, c_tps, c_model)
    total_w = sum(weights) or 1.0
    score = sum(w * _clamp(p, 0.0, 1.0) for w, p in zip(weights, parts)) / total_w * 100.0

    # Praeferenz-Bonus (manuelle Gewichtung je Maschine), dann Gates.
    score += _clamp(_num(machine.get("preference")), -20, 20)
    if not online:
        score = 0.0
    elif free_slots <= 0:
        score *= 0.15  # belegt, aber grundsaetzlich geeignet

    return {
        "machine_id": machine["id"],
        "name": machine.get("name") or machine["id"],
        "platform": machine.get("platform") or "unknown",
        "backend_url": machine.get("backend_url") or "",
        "online": online,
        "last_seen": last_seen,
        "seconds_since_seen": age if last_seen else None,
        "cpu_pct": round(cpu, 1),
        "gpu_pct": round(gpu, 1),
        "ram_total_gb": round(ram_total, 2),
        "ram_used_gb": round(ram_used, 2),
        "ram_free_gb": round(free_ram, 2),
        "vram_total_gb": round(vram_total, 2),
        "vram_used_gb": round(vram_used, 2),
        "vram_free_gb": round(free_vram, 2),
        "reserved_ram_gb": round(res_ram, 2),
        "reserved_vram_gb": round(res_vram, 2),
        "temp_c": round(_num(s["temp_c"] if s else 0), 1) if s and s["temp_c"] is not None else None,
        "power_w": round(_num(s["power_w"] if s else 0), 1) if s and s["power_w"] is not None else None,
        "loaded_models": loaded,
        "tps_current": round(_num(s["tps_current"] if s else 0), 2),
        "tps_avg": round(_num(s["tps_avg"] if s else 0), 2),
        "tps_peak": round(_num(s["tps_peak"] if s else 0), 2),
        "tps_history_model": round(tps_hist, 2),
        "tps_history_source": tps_source,
        "model_loaded": bool(c_model),
        "max_slots": max_slots,
        "used_slots": used_slots,
        "free_slots": free_slots,
        "active_reservations": [
            {"id": r["id"], "task_id": r["task_id"], "requester": r["requester"],
             "model": r["model"], "state": r["state"],
             "valid_until": r["valid_until"], "started_ts": r["started_ts"]}
            for r in res
        ],
        "capacity_score": round(_clamp(score), 1),
    }


def all_machines() -> list[dict]:
    return [dict(r) for r in db.query("SELECT * FROM machines ORDER BY name")]


def capacity_overview(model: str | None = None) -> list[dict]:
    expire_stale()
    best = _best_tps_overall()
    out = [machine_capacity(m, model, best) for m in all_machines()]
    out.sort(key=lambda c: (-c["capacity_score"], c["name"]))
    return out


def eligible(cap: dict, min_ram: float, min_vram: float,
             require_model: bool = False) -> tuple[bool, str]:
    if not cap["online"]:
        return False, "offline"
    if cap["free_slots"] <= 0:
        return False, "keine freien Slots"
    if min_ram > 0 and cap["ram_free_gb"] < min_ram:
        return False, f"RAM zu knapp ({cap['ram_free_gb']} < {min_ram} GB)"
    if min_vram > 0 and cap["vram_free_gb"] < min_vram:
        return False, f"VRAM zu knapp ({cap['vram_free_gb']} < {min_vram} GB)"
    if require_model and not cap["model_loaded"]:
        return False, "Modell nicht geladen"
    return True, "ok"


def plan(model: str | None = None, min_ram_gb: float = 0.0,
         min_vram_gb: float = 0.0, require_model: bool = False) -> dict:
    """Liefert Ranking + Empfehlung, ohne zu reservieren."""
    caps = capacity_overview(model)
    candidates, rejected = [], []
    for c in caps:
        ok, reason = eligible(c, min_ram_gb, min_vram_gb, require_model)
        entry = dict(c)
        entry["eligible"] = ok
        entry["reason"] = reason
        (candidates if ok else rejected).append(entry)
    return {
        "recommended": candidates[0] if candidates else None,
        "candidates": candidates,
        "rejected": rejected,
        "generated_at": int(time.time()),
    }


# --------------------------------------------------------------------------
# Reservierungen
# --------------------------------------------------------------------------
def reserve(requester: str, task_id: str, model: str | None,
            min_ram_gb: float = 0.0, min_vram_gb: float = 0.0,
            est_duration_s: int = 600, priority: int = 50,
            machine_id: str | None = None, require_model: bool = False,
            note: str = "") -> dict:
    expire_stale()
    now = int(time.time())
    caps = capacity_overview(model)
    if machine_id:
        caps = [c for c in caps if c["machine_id"] == machine_id]
        if not caps:
            return {"status": "error", "error": "unknown_machine",
                    "message": f"Maschine '{machine_id}' ist nicht registriert."}

    for cap in caps:
        ok, reason = eligible(cap, min_ram_gb, min_vram_gb, require_model)
        if not ok:
            continue
        rid = new_reservation_id()
        valid_until = now + settings.RESERVATION_TTL
        db.execute(
            """INSERT INTO reservations(id, machine_id, requester, task_id, model,
                    min_ram_gb, min_vram_gb, est_duration_s, priority, state,
                    score, created_ts, valid_until, note)
               VALUES(?,?,?,?,?,?,?,?,?, 'reserved', ?,?,?,?)""",
            (rid, cap["machine_id"], requester[:120], task_id[:120],
             (model or "")[:160], min_ram_gb, min_vram_gb, est_duration_s,
             priority, cap["capacity_score"], now, valid_until, note[:500]))
        db.log_event("info", "scheduler",
                     f"{requester} -> {cap['machine_id']} ({rid}, score {cap['capacity_score']})")
        return {
            "status": "reserved",
            "reservation_id": rid,
            "machine_id": cap["machine_id"],
            "machine_name": cap["name"],
            "backend_url": cap["backend_url"],
            "model": model or "",
            "model_already_loaded": cap["model_loaded"],
            "capacity_score": cap["capacity_score"],
            "expected_tps": cap["tps_history_model"],
            "ram_free_gb": cap["ram_free_gb"],
            "vram_free_gb": cap["vram_free_gb"],
            "valid_until": valid_until,
            "ttl_seconds": settings.RESERVATION_TTL,
            "heartbeat_timeout_seconds": settings.HEARTBEAT_TIMEOUT,
        }

    detail = [{"machine_id": c["machine_id"], "reason": eligible(
        c, min_ram_gb, min_vram_gb, require_model)[1]} for c in caps]
    return {"status": "unavailable", "reason": "no_machine_available",
            "message": "Aktuell hat keine Maschine ausreichend freie Kapazitaet.",
            "details": detail, "retry_after_seconds": settings.SAMPLE_INTERVAL * 2}


def _get(rid: str):
    return db.query_one("SELECT * FROM reservations WHERE id=?", (rid,))


def transition(rid: str, action: str) -> dict:
    row = _get(rid)
    if not row:
        return {"status": "error", "error": "not_found"}
    now = int(time.time())
    state = row["state"]

    if action == "start":
        if state not in ("reserved", "running"):
            return {"status": "error", "error": "invalid_state", "state": state}
        db.execute("UPDATE reservations SET state='running', started_ts=COALESCE(started_ts,?),"
                   " last_heartbeat=?, valid_until=? WHERE id=?",
                   (now, now, now + settings.HEARTBEAT_TIMEOUT, rid))
    elif action == "heartbeat":
        if state not in ("reserved", "running"):
            return {"status": "error", "error": "invalid_state", "state": state}
        db.execute("UPDATE reservations SET state='running', last_heartbeat=?,"
                   " started_ts=COALESCE(started_ts,?), valid_until=? WHERE id=?",
                   (now, now, now + settings.HEARTBEAT_TIMEOUT, rid))
    elif action == "complete":
        db.execute("UPDATE reservations SET state='completed', completed_ts=? WHERE id=?",
                   (now, rid))
    elif action == "fail":
        db.execute("UPDATE reservations SET state='failed', completed_ts=? WHERE id=?",
                   (now, rid))
    elif action == "cancel":
        db.execute("UPDATE reservations SET state='cancelled', completed_ts=? WHERE id=?",
                   (now, rid))
    else:
        return {"status": "error", "error": "unknown_action"}

    return {"status": "ok", "reservation": dict(_get(rid))}


def list_reservations(state: str | None = None, machine_id: str | None = None,
                      limit: int = 100) -> list[dict]:
    expire_stale()
    sql = "SELECT * FROM reservations WHERE 1=1"
    args: list[Any] = []
    if state == "active":
        sql += " AND state IN (?,?)"
        args += list(ACTIVE_STATES)
    elif state:
        sql += " AND state=?"
        args.append(state)
    if machine_id:
        sql += " AND machine_id=?"
        args.append(machine_id)
    sql += " ORDER BY created_ts DESC LIMIT ?"
    args.append(max(1, min(500, limit)))
    return [dict(r) for r in db.query(sql, args)]


def timeline(window_minutes: int = 60) -> list[dict]:
    """Slot-Zeitachse fuer das Dashboard (laufend + geplantes Ende)."""
    now = int(time.time())
    rows = db.query(
        "SELECT * FROM reservations WHERE created_ts > ? ORDER BY created_ts",
        (now - window_minutes * 60,))
    out = []
    for r in rows:
        start = r["started_ts"] or r["created_ts"]
        if r["completed_ts"]:
            end = r["completed_ts"]
        elif r["state"] in ACTIVE_STATES:
            end = start + int(r["est_duration_s"] or 600)
        else:
            end = r["valid_until"]
        out.append({"id": r["id"], "machine_id": r["machine_id"],
                    "task_id": r["task_id"], "requester": r["requester"],
                    "model": r["model"], "state": r["state"],
                    "start": start, "end": max(end, start + 30),
                    "priority": r["priority"]})
    return out
