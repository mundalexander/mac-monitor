"""API-Handler. Jeder Handler gibt (status_code, dict) zurueck."""
from __future__ import annotations

import json
import time
from typing import Any

from . import db, scheduler, settings

API_VERSION = "1.0.0"
PRODUCT = "mac-monitor"


# --------------------------------------------------------------------------
# Validierung
# --------------------------------------------------------------------------
class ValidationError(Exception):
    pass


def v_str(data: dict, key: str, default: str = "", maxlen: int = 200,
          required: bool = False) -> str:
    val = data.get(key, default)
    if val is None:
        val = default
    if not isinstance(val, (str, int, float)):
        raise ValidationError(f"Feld '{key}' muss Text sein.")
    s = str(val).strip()
    if required and not s:
        raise ValidationError(f"Feld '{key}' ist erforderlich.")
    return s[:maxlen]


def v_num(data: dict, key: str, default: float | None = 0.0,
          lo: float = -1e9, hi: float = 1e9) -> float | None:
    if key not in data or data[key] is None:
        return default
    try:
        f = float(data[key])
    except (TypeError, ValueError):
        return default
    if f != f or f in (float("inf"), float("-inf")):
        return default
    return max(lo, min(hi, f))


def v_int(data: dict, key: str, default: int = 0, lo: int = -10**9,
          hi: int = 10**9) -> int:
    f = v_num(data, key, float(default), float(lo), float(hi))
    return int(f if f is not None else default)


def v_list_str(data: dict, key: str, max_items: int = 50, maxlen: int = 160) -> list[str]:
    val = data.get(key) or []
    if isinstance(val, str):
        val = [val]
    if not isinstance(val, list):
        return []
    return [str(x)[:maxlen] for x in val[:max_items]]


# --------------------------------------------------------------------------
# Meta
# --------------------------------------------------------------------------
def health() -> tuple[int, dict]:
    machines = scheduler.all_machines()
    now = int(time.time())
    online = sum(1 for m in machines
                 if now - int(m["last_seen"] or 0) <= settings.OFFLINE_AFTER)
    row = db.query_one("SELECT COUNT(*) c FROM samples")
    active = db.query_one(
        "SELECT COUNT(*) c FROM reservations WHERE state IN ('reserved','running')")
    return 200, {
        "status": "ok",
        "product": PRODUCT,
        "api_version": API_VERSION,
        "schema_version": db.SCHEMA_VERSION,
        "server_time": now,
        "public_url": settings.PUBLIC_URL,
        "api_url": settings.API_URL,
        "dashboard_url": settings.PUBLIC_URL + "/",
        "machines_total": len(machines),
        "machines_online": online,
        "samples_total": int(row["c"]) if row else 0,
        "active_reservations": int(active["c"]) if active else 0,
        "db_size_bytes": db.db_size_bytes(),
        "sample_interval": settings.SAMPLE_INTERVAL,
    }


def config_public() -> tuple[int, dict]:
    """Konfiguration fuer Dashboard und Agenten - keine Geheimnisse."""
    return 200, {
        "public_url": settings.PUBLIC_URL,
        "api_url": settings.API_URL,
        "api_prefix": settings.API_PREFIX,
        "sample_interval": settings.SAMPLE_INTERVAL,
        "offline_after": settings.OFFLINE_AFTER,
        "reservation_ttl": settings.RESERVATION_TTL,
        "heartbeat_timeout": settings.HEARTBEAT_TIMEOUT,
        "retention_raw_days": settings.RETENTION_RAW_DAYS,
    }


# --------------------------------------------------------------------------
# Ingest
# --------------------------------------------------------------------------
def ingest(payload: dict) -> tuple[int, dict]:
    if not isinstance(payload, dict):
        raise ValidationError("Payload muss ein JSON-Objekt sein.")
    m = payload.get("machine") or {}
    s = payload.get("sample") or {}
    if not isinstance(m, dict) or not isinstance(s, dict):
        raise ValidationError("'machine' und 'sample' muessen Objekte sein.")

    mid = v_str(m, "id", maxlen=64, required=True)
    now = int(time.time())
    ts = v_int(s, "ts", now, now - 86400, now + 300)

    db.execute(
        """INSERT INTO machines(id, name, platform, backend_url, labels,
                ram_total_gb, vram_total_gb, cpu_model, gpu_model, max_slots,
                agent_version, first_seen, last_seen)
           VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
           ON CONFLICT(id) DO UPDATE SET
                name=excluded.name, platform=excluded.platform,
                backend_url=CASE WHEN excluded.backend_url != '' THEN excluded.backend_url
                                 ELSE machines.backend_url END,
                labels=excluded.labels,
                ram_total_gb=excluded.ram_total_gb,
                vram_total_gb=excluded.vram_total_gb,
                cpu_model=excluded.cpu_model, gpu_model=excluded.gpu_model,
                max_slots=excluded.max_slots,
                agent_version=excluded.agent_version,
                last_seen=excluded.last_seen""",
        (mid, v_str(m, "name", mid, 120), v_str(m, "platform", "unknown", 40),
         settings.normalize_base_url(v_str(m, "backend_url", "", 300)),
         json.dumps(v_list_str(m, "labels")),
         v_num(m, "ram_total_gb", 0.0, 0, 100000),
         v_num(m, "vram_total_gb", 0.0, 0, 100000),
         v_str(m, "cpu_model", "", 160), v_str(m, "gpu_model", "", 160),
         max(1, v_int(m, "max_slots", 1, 1, 64)),
         v_str(m, "agent_version", "", 40), ts, ts))

    db.execute(
        """INSERT INTO samples(machine_id, ts, cpu_pct, load1, ram_used_gb,
                ram_total_gb, gpu_pct, vram_used_gb, vram_total_gb, temp_c,
                power_w, loaded_models, tps_current, tps_avg, tps_peak, active_jobs)
           VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        (mid, ts,
         v_num(s, "cpu_pct", 0.0, 0, 100), v_num(s, "load1", 0.0, 0, 10000),
         v_num(s, "ram_used_gb", 0.0, 0, 100000),
         v_num(s, "ram_total_gb", 0.0, 0, 100000),
         v_num(s, "gpu_pct", 0.0, 0, 100),
         v_num(s, "vram_used_gb", 0.0, 0, 100000),
         v_num(s, "vram_total_gb", 0.0, 0, 100000),
         v_num(s, "temp_c", None, -50, 200), v_num(s, "power_w", None, 0, 20000),
         json.dumps(v_list_str(s, "loaded_models")),
         v_num(s, "tps_current", 0.0, 0, 100000),
         v_num(s, "tps_avg", 0.0, 0, 100000),
         v_num(s, "tps_peak", 0.0, 0, 100000),
         v_int(s, "active_jobs", 0, 0, 1000)))

    accepted = 0
    for ev in (payload.get("tps_events") or [])[:50]:
        if isinstance(ev, dict):
            ev.setdefault("machine_id", mid)
            code, _ = tps(ev)
            accepted += 1 if code < 300 else 0

    return 201, {"status": "accepted", "machine_id": mid, "ts": ts,
                 "tps_events_accepted": accepted,
                 "next_sample_in": settings.SAMPLE_INTERVAL}


def tps(payload: dict) -> tuple[int, dict]:
    """Exakte TPS-Messung.

    Akzeptiert entweder fertige Werte oder eine rohe Ollama-Antwort
    (eval_count / eval_duration in Nanosekunden).
    """
    if not isinstance(payload, dict):
        raise ValidationError("Payload muss ein JSON-Objekt sein.")
    mid = v_str(payload, "machine_id", maxlen=64, required=True)
    now = int(time.time())
    ts = v_int(payload, "ts", now, now - 86400, now + 300)

    eval_tokens = v_int(payload, "eval_count", 0, 0, 10**8)
    eval_ns = v_num(payload, "eval_duration", 0.0, 0, 1e18) or 0.0
    prompt_tokens = v_int(payload, "prompt_eval_count", 0, 0, 10**8)
    prompt_ns = v_num(payload, "prompt_eval_duration", 0.0, 0, 1e18) or 0.0

    eval_seconds = v_num(payload, "eval_seconds", eval_ns / 1e9, 0, 1e6) or 0.0
    prompt_seconds = prompt_ns / 1e9

    rate = v_num(payload, "tps", 0.0, 0, 100000) or 0.0
    if rate <= 0 and eval_tokens > 0 and eval_seconds > 0:
        rate = eval_tokens / eval_seconds
    prompt_rate = (prompt_tokens / prompt_seconds) if prompt_seconds > 0 else 0.0

    if rate <= 0:
        return 400, {"status": "error", "error": "no_tps",
                     "message": "Weder 'tps' noch eval_count/eval_duration nutzbar."}

    db.execute(
        """INSERT INTO tps_samples(machine_id, ts, model, task_id, reservation_id,
                prompt_tokens, eval_tokens, eval_seconds, prompt_tps, tps, source)
           VALUES(?,?,?,?,?,?,?,?,?,?,?)""",
        (mid, ts, v_str(payload, "model", "", 160),
         v_str(payload, "task_id", "", 120),
         v_str(payload, "reservation_id", "", 64),
         prompt_tokens, eval_tokens, round(eval_seconds, 3),
         round(prompt_rate, 2), round(rate, 2),
         v_str(payload, "source", "agent", 32)))
    return 201, {"status": "accepted", "machine_id": mid,
                 "tps": round(rate, 2), "prompt_tps": round(prompt_rate, 2)}


# --------------------------------------------------------------------------
# Lesen
# --------------------------------------------------------------------------
def machines() -> tuple[int, dict]:
    return 200, {"machines": scheduler.capacity_overview(),
                 "server_time": int(time.time())}


def capacity(q: dict) -> tuple[int, dict]:
    model = (q.get("model") or [None])[0]
    return 200, {"model": model, "machines": scheduler.capacity_overview(model),
                 "server_time": int(time.time())}


def _qf(q: dict, key: str, default: float) -> float:
    try:
        return float(q.get(key, [default])[0])
    except (TypeError, ValueError, IndexError):
        return default


def _qi(q: dict, key: str, default: int) -> int:
    return int(_qf(q, key, float(default)))


def _qs(q: dict, key: str, default: str | None = None) -> str | None:
    v = q.get(key, [default])[0]
    return v if v not in ("", None) else default


def slots(q: dict) -> tuple[int, dict]:
    window = max(5, min(1440, _qi(q, "window", 60)))
    return 200, {"window_minutes": window, "slots": scheduler.timeline(window),
                 "server_time": int(time.time())}


def discovery(q: dict) -> tuple[int, dict]:
    """Kompakter Endpunkt fuer Agenten (OpenClaw, Hermes, OpenCode, VS Code)."""
    model = _qs(q, "model")
    result = scheduler.plan(model, _qf(q, "min_ram_gb", 0.0),
                            _qf(q, "min_vram_gb", 0.0),
                            _qs(q, "require_model", "0") == "1")
    rec = result["recommended"]
    return 200, {
        "server_time": int(time.time()),
        "api_url": settings.API_URL,
        "model": model,
        "can_start_subagent": rec is not None,
        "recommended": None if not rec else {
            "machine_id": rec["machine_id"], "name": rec["name"],
            "backend_url": rec["backend_url"], "platform": rec["platform"],
            "capacity_score": rec["capacity_score"],
            "ram_free_gb": rec["ram_free_gb"], "vram_free_gb": rec["vram_free_gb"],
            "free_slots": rec["free_slots"], "model_loaded": rec["model_loaded"],
            "expected_tps": rec["tps_history_model"],
            "reserve_endpoint": settings.API_URL + "/reservations",
        },
        "candidates": [
            {"machine_id": c["machine_id"], "name": c["name"],
             "backend_url": c["backend_url"], "capacity_score": c["capacity_score"],
             "ram_free_gb": c["ram_free_gb"], "vram_free_gb": c["vram_free_gb"],
             "free_slots": c["free_slots"], "model_loaded": c["model_loaded"],
             "expected_tps": c["tps_history_model"]}
            for c in result["candidates"]],
        "unavailable": [{"machine_id": c["machine_id"], "name": c["name"],
                         "reason": c["reason"]} for c in result["rejected"]],
    }


def series(q: dict) -> tuple[int, dict]:
    minutes = max(1, min(7 * 24 * 60, _qi(q, "minutes", 60)))
    machine_id = _qs(q, "machine_id")
    since = int(time.time()) - minutes * 60
    args: list[Any] = [since]
    sql = ("SELECT machine_id, ts, cpu_pct, gpu_pct, ram_used_gb, ram_total_gb, "
           "vram_used_gb, vram_total_gb, temp_c, power_w, tps_current, tps_avg, "
           "tps_peak, active_jobs FROM samples WHERE ts > ?")
    if machine_id:
        sql += " AND machine_id=?"
        args.append(machine_id)
    sql += " ORDER BY ts ASC LIMIT 20000"
    rows = [dict(r) for r in db.query(sql, args)]

    tsql = ("SELECT machine_id, ts, model, task_id, tps, prompt_tps, eval_tokens "
            "FROM tps_samples WHERE ts > ?")
    targs: list[Any] = [since]
    if machine_id:
        tsql += " AND machine_id=?"
        targs.append(machine_id)
    tsql += " ORDER BY ts ASC LIMIT 5000"
    tps_rows = [dict(r) for r in db.query(tsql, targs)]

    return 200, {"minutes": minutes, "machine_id": machine_id,
                 "samples": rows, "tps_samples": tps_rows,
                 "server_time": int(time.time())}


def tps_summary(q: dict) -> tuple[int, dict]:
    minutes = max(1, min(7 * 24 * 60, _qi(q, "minutes", 60)))
    since = int(time.time()) - minutes * 60
    rows = db.query(
        """SELECT machine_id, model, COUNT(*) n, AVG(tps) avg_tps, MAX(tps) peak_tps,
                  SUM(eval_tokens) tokens, SUM(eval_seconds) seconds
           FROM tps_samples WHERE ts > ? AND tps > 0
           GROUP BY machine_id, model ORDER BY avg_tps DESC""", (since,))
    return 200, {"minutes": minutes, "rows": [
        {"machine_id": r["machine_id"], "model": r["model"], "samples": r["n"],
         "avg_tps": round(r["avg_tps"] or 0, 2),
         "peak_tps": round(r["peak_tps"] or 0, 2),
         "tokens": int(r["tokens"] or 0),
         "seconds": round(r["seconds"] or 0, 1)} for r in rows]}


def events(q: dict) -> tuple[int, dict]:
    limit = max(1, min(500, _qi(q, "limit", 50)))
    rows = db.query("SELECT * FROM events ORDER BY ts DESC LIMIT ?", (limit,))
    return 200, {"events": [dict(r) for r in rows]}


# --------------------------------------------------------------------------
# Reservierungen
# --------------------------------------------------------------------------
def create_reservation(payload: dict) -> tuple[int, dict]:
    if not isinstance(payload, dict):
        raise ValidationError("Payload muss ein JSON-Objekt sein.")
    result = scheduler.reserve(
        requester=v_str(payload, "requester", "unknown", 120),
        task_id=v_str(payload, "task_id", "", 120),
        model=v_str(payload, "model", "", 160) or None,
        min_ram_gb=v_num(payload, "minimum_ram_gb",
                         v_num(payload, "min_ram_gb", 0.0, 0, 10000), 0, 10000) or 0.0,
        min_vram_gb=v_num(payload, "minimum_vram_gb",
                          v_num(payload, "min_vram_gb", 0.0, 0, 10000), 0, 10000) or 0.0,
        est_duration_s=v_int(payload, "estimated_duration_seconds",
                             v_int(payload, "est_duration_s", 600, 1, 86400), 1, 86400),
        priority=v_int(payload, "priority", 50, 0, 100),
        machine_id=v_str(payload, "machine_id", "", 64) or None,
        require_model=bool(payload.get("require_model_loaded")),
        note=v_str(payload, "note", "", 500))
    if result["status"] == "reserved":
        return 201, result
    if result["status"] == "unavailable":
        return 503, result
    return 404, result


def get_reservation(rid: str) -> tuple[int, dict]:
    scheduler.expire_stale()
    row = db.query_one("SELECT * FROM reservations WHERE id=?", (rid,))
    if not row:
        return 404, {"status": "error", "error": "not_found"}
    return 200, {"status": "ok", "reservation": dict(row)}


def reservation_action(rid: str, action: str) -> tuple[int, dict]:
    res = scheduler.transition(rid, action)
    if res.get("status") == "error":
        return (404 if res["error"] == "not_found" else 409), res
    return 200, res


def list_reservations(q: dict) -> tuple[int, dict]:
    return 200, {"reservations": scheduler.list_reservations(
        _qs(q, "state"), _qs(q, "machine_id"), _qi(q, "limit", 100))}


def maintenance() -> tuple[int, dict]:
    expired = scheduler.expire_stale()
    deleted = db.run_retention()
    db.execute("VACUUM")
    return 200, {"status": "ok", "expired_reservations": expired,
                 "deleted": deleted, "db_size_bytes": db.db_size_bytes()}
