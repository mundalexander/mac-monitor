"""SQLite Zugriff, Schema, Migrationen, Retention."""
from __future__ import annotations

import json
import os
import sqlite3
import threading
import time
from pathlib import Path
from typing import Any, Iterable

from . import settings

_local = threading.local()
_init_lock = threading.Lock()
_initialized = False

SCHEMA_VERSION = 3

SCHEMA = """
CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS machines (
    id            TEXT PRIMARY KEY,
    name          TEXT NOT NULL,
    platform      TEXT NOT NULL DEFAULT 'unknown',
    backend_url   TEXT NOT NULL DEFAULT '',
    labels        TEXT NOT NULL DEFAULT '[]',
    ram_total_gb  REAL NOT NULL DEFAULT 0,
    vram_total_gb REAL NOT NULL DEFAULT 0,
    cpu_model     TEXT NOT NULL DEFAULT '',
    gpu_model     TEXT NOT NULL DEFAULT '',
    max_slots     INTEGER NOT NULL DEFAULT 1,
    preference    INTEGER NOT NULL DEFAULT 0,
    enabled       INTEGER NOT NULL DEFAULT 1,
    agent_version TEXT NOT NULL DEFAULT '',
    first_seen    INTEGER NOT NULL DEFAULT 0,
    last_seen     INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS samples (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    machine_id    TEXT NOT NULL,
    ts            INTEGER NOT NULL,
    cpu_pct       REAL,
    load1         REAL,
    ram_used_gb   REAL,
    ram_total_gb  REAL,
    gpu_pct       REAL,
    vram_used_gb  REAL,
    vram_total_gb REAL,
    temp_c        REAL,
    power_w       REAL,
    loaded_models TEXT NOT NULL DEFAULT '[]',
    tps_current   REAL,
    tps_avg       REAL,
    tps_peak      REAL,
    active_jobs   INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_samples_machine_ts ON samples(machine_id, ts DESC);
CREATE INDEX IF NOT EXISTS idx_samples_ts ON samples(ts);

CREATE TABLE IF NOT EXISTS tps_samples (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    machine_id    TEXT NOT NULL,
    ts            INTEGER NOT NULL,
    model         TEXT NOT NULL DEFAULT '',
    task_id       TEXT NOT NULL DEFAULT '',
    reservation_id TEXT NOT NULL DEFAULT '',
    prompt_tokens INTEGER NOT NULL DEFAULT 0,
    eval_tokens   INTEGER NOT NULL DEFAULT 0,
    eval_seconds  REAL NOT NULL DEFAULT 0,
    prompt_tps    REAL NOT NULL DEFAULT 0,
    tps           REAL NOT NULL DEFAULT 0,
    source        TEXT NOT NULL DEFAULT 'agent'
);
CREATE INDEX IF NOT EXISTS idx_tps_machine_ts ON tps_samples(machine_id, ts DESC);
CREATE INDEX IF NOT EXISTS idx_tps_model ON tps_samples(model, ts DESC);

CREATE TABLE IF NOT EXISTS reservations (
    id             TEXT PRIMARY KEY,
    machine_id     TEXT NOT NULL,
    requester      TEXT NOT NULL DEFAULT '',
    task_id        TEXT NOT NULL DEFAULT '',
    model          TEXT NOT NULL DEFAULT '',
    min_ram_gb     REAL NOT NULL DEFAULT 0,
    min_vram_gb    REAL NOT NULL DEFAULT 0,
    est_duration_s INTEGER NOT NULL DEFAULT 600,
    priority       INTEGER NOT NULL DEFAULT 50,
    state          TEXT NOT NULL DEFAULT 'reserved',
    score          REAL NOT NULL DEFAULT 0,
    created_ts     INTEGER NOT NULL,
    valid_until    INTEGER NOT NULL,
    started_ts     INTEGER,
    last_heartbeat INTEGER,
    completed_ts   INTEGER,
    note           TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_res_machine_state ON reservations(machine_id, state);
CREATE INDEX IF NOT EXISTS idx_res_created ON reservations(created_ts DESC);

CREATE TABLE IF NOT EXISTS events (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    ts      INTEGER NOT NULL,
    level   TEXT NOT NULL DEFAULT 'info',
    source  TEXT NOT NULL DEFAULT '',
    message TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts DESC);
"""


def connect() -> sqlite3.Connection:
    conn = getattr(_local, "conn", None)
    if conn is not None:
        return conn
    Path(settings.DB_PATH).parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(settings.DB_PATH, timeout=15.0, isolation_level=None)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA synchronous=NORMAL")
    conn.execute("PRAGMA busy_timeout=15000")
    conn.execute("PRAGMA foreign_keys=ON")
    _local.conn = conn
    return conn


def init_db() -> None:
    global _initialized
    with _init_lock:
        if _initialized:
            return
        conn = connect()
        conn.executescript(SCHEMA)
        conn.execute(
            "INSERT INTO meta(key, value) VALUES('schema_version', ?) "
            "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
            (str(SCHEMA_VERSION),),
        )
        _load_machine_registry(conn)
        _initialized = True


def _load_machine_registry(conn: sqlite3.Connection) -> None:
    """Optionales Vorab-Register aus config/machines.json."""
    path = Path(settings.MACHINES_FILE)
    if not path.is_file():
        return
    try:
        entries = json.loads(path.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        return
    now = int(time.time())
    for e in entries if isinstance(entries, list) else []:
        mid = str(e.get("id") or "").strip()
        if not mid:
            continue
        conn.execute(
            """INSERT INTO machines(id, name, platform, backend_url, labels,
                    max_slots, preference, enabled, first_seen, last_seen)
               VALUES(?,?,?,?,?,?,?,?,?,0)
               ON CONFLICT(id) DO UPDATE SET
                    name=excluded.name,
                    backend_url=CASE WHEN excluded.backend_url != ''
                                     THEN excluded.backend_url
                                     ELSE machines.backend_url END,
                    labels=excluded.labels,
                    max_slots=excluded.max_slots,
                    preference=excluded.preference,
                    enabled=excluded.enabled""",
            (
                mid,
                str(e.get("name") or mid),
                str(e.get("platform") or "unknown"),
                settings.normalize_base_url(str(e.get("backend_url") or "")),
                json.dumps(e.get("labels") or []),
                int(e.get("max_slots") or 1),
                int(e.get("preference") or 0),
                1 if e.get("enabled", True) else 0,
                now,
            ),
        )


def query(sql: str, args: Iterable[Any] = ()) -> list[sqlite3.Row]:
    return connect().execute(sql, tuple(args)).fetchall()


def query_one(sql: str, args: Iterable[Any] = ()) -> sqlite3.Row | None:
    return connect().execute(sql, tuple(args)).fetchone()


def execute(sql: str, args: Iterable[Any] = ()) -> sqlite3.Cursor:
    return connect().execute(sql, tuple(args))


def log_event(level: str, source: str, message: str) -> None:
    try:
        execute(
            "INSERT INTO events(ts, level, source, message) VALUES(?,?,?,?)",
            (int(time.time()), level, source[:64], message[:500]),
        )
    except sqlite3.Error:
        pass


def run_retention() -> dict[str, int]:
    now = int(time.time())
    out = {}
    cur = execute("DELETE FROM samples WHERE ts < ?",
                  (now - settings.RETENTION_RAW_DAYS * 86400,))
    out["samples"] = cur.rowcount or 0
    cur = execute("DELETE FROM tps_samples WHERE ts < ?",
                  (now - settings.RETENTION_TPS_DAYS * 86400,))
    out["tps_samples"] = cur.rowcount or 0
    cur = execute("DELETE FROM events WHERE ts < ?",
                  (now - settings.RETENTION_EVENT_DAYS * 86400,))
    out["events"] = cur.rowcount or 0
    cur = execute(
        "DELETE FROM reservations WHERE state IN "
        "('completed','cancelled','expired','failed') AND created_ts < ?",
        (now - settings.RETENTION_EVENT_DAYS * 86400,))
    out["reservations"] = cur.rowcount or 0
    return out


def db_size_bytes() -> int:
    try:
        return os.path.getsize(settings.DB_PATH)
    except OSError:
        return 0
