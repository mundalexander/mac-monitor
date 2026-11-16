<?php
/**
 * mac-monitor PHP - Datenbank, Validierung, Scheduler (Option C).
 * Portierung der getesteten Python-Referenz (server/db.py, server/scheduler.py).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Datenbank
// ---------------------------------------------------------------------------
function mm_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }

    $dir = dirname(MM_DB_PATH);
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }

    $pdo = new PDO('sqlite:' . MM_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA busy_timeout=15000');
    mm_migrate($pdo);
    return $pdo;
}

function mm_migrate(PDO $pdo): void {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);

    CREATE TABLE IF NOT EXISTS machines (
        id TEXT PRIMARY KEY, name TEXT NOT NULL,
        platform TEXT NOT NULL DEFAULT 'unknown',
        backend_url TEXT NOT NULL DEFAULT '',
        labels TEXT NOT NULL DEFAULT '[]',
        ram_total_gb REAL NOT NULL DEFAULT 0,
        vram_total_gb REAL NOT NULL DEFAULT 0,
        cpu_model TEXT NOT NULL DEFAULT '', gpu_model TEXT NOT NULL DEFAULT '',
        max_slots INTEGER NOT NULL DEFAULT 1, preference INTEGER NOT NULL DEFAULT 0,
        enabled INTEGER NOT NULL DEFAULT 1, agent_version TEXT NOT NULL DEFAULT '',
        first_seen INTEGER NOT NULL DEFAULT 0, last_seen INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS samples (
        id INTEGER PRIMARY KEY AUTOINCREMENT, machine_id TEXT NOT NULL,
        ts INTEGER NOT NULL, cpu_pct REAL, load1 REAL, ram_used_gb REAL,
        ram_total_gb REAL, gpu_pct REAL, vram_used_gb REAL, vram_total_gb REAL,
        temp_c REAL, power_w REAL, loaded_models TEXT NOT NULL DEFAULT '[]',
        tps_current REAL, tps_avg REAL, tps_peak REAL,
        active_jobs INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS idx_samples_machine_ts ON samples(machine_id, ts DESC);
    CREATE INDEX IF NOT EXISTS idx_samples_ts ON samples(ts);

    CREATE TABLE IF NOT EXISTS tps_samples (
        id INTEGER PRIMARY KEY AUTOINCREMENT, machine_id TEXT NOT NULL,
        ts INTEGER NOT NULL, model TEXT NOT NULL DEFAULT '',
        task_id TEXT NOT NULL DEFAULT '', reservation_id TEXT NOT NULL DEFAULT '',
        prompt_tokens INTEGER NOT NULL DEFAULT 0, eval_tokens INTEGER NOT NULL DEFAULT 0,
        eval_seconds REAL NOT NULL DEFAULT 0, prompt_tps REAL NOT NULL DEFAULT 0,
        tps REAL NOT NULL DEFAULT 0, source TEXT NOT NULL DEFAULT 'agent'
    );
    CREATE INDEX IF NOT EXISTS idx_tps_machine_ts ON tps_samples(machine_id, ts DESC);
    CREATE INDEX IF NOT EXISTS idx_tps_model ON tps_samples(model, ts DESC);

    CREATE TABLE IF NOT EXISTS reservations (
        id TEXT PRIMARY KEY, machine_id TEXT NOT NULL,
        requester TEXT NOT NULL DEFAULT '', task_id TEXT NOT NULL DEFAULT '',
        model TEXT NOT NULL DEFAULT '', min_ram_gb REAL NOT NULL DEFAULT 0,
        min_vram_gb REAL NOT NULL DEFAULT 0, est_duration_s INTEGER NOT NULL DEFAULT 600,
        priority INTEGER NOT NULL DEFAULT 50, state TEXT NOT NULL DEFAULT 'reserved',
        score REAL NOT NULL DEFAULT 0, created_ts INTEGER NOT NULL,
        valid_until INTEGER NOT NULL, started_ts INTEGER, last_heartbeat INTEGER,
        completed_ts INTEGER, note TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_res_machine_state ON reservations(machine_id, state);
    CREATE INDEX IF NOT EXISTS idx_res_created ON reservations(created_ts DESC);

    CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL,
        level TEXT NOT NULL DEFAULT 'info', source TEXT NOT NULL DEFAULT '',
        message TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts DESC);
    ");
    $st = $pdo->prepare("INSERT INTO meta(key,value) VALUES('schema_version','3')
        ON CONFLICT(key) DO UPDATE SET value=excluded.value");
    $st->execute();
}

function mm_q(string $sql, array $args = []): array {
    $st = mm_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function mm_q1(string $sql, array $args = []): ?array {
    $rows = mm_q($sql, $args);
    return $rows[0] ?? null;
}

function mm_exec(string $sql, array $args = []): int {
    $st = mm_db()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

function mm_log(string $level, string $source, string $message): void {
    try {
        mm_exec('INSERT INTO events(ts,level,source,message) VALUES(?,?,?,?)',
            [time(), $level, substr($source, 0, 64), substr($message, 0, 500)]);
    } catch (Throwable $e) { /* Logging darf nie den Request kippen */ }
}

// ---------------------------------------------------------------------------
// Validierung
// ---------------------------------------------------------------------------
class MmValidationError extends RuntimeException {}

function mm_str(array $d, string $k, string $def = '', int $max = 200, bool $req = false): string {
    $v = $d[$k] ?? $def;
    if (is_array($v) || is_object($v)) { throw new MmValidationError("Feld '$k' muss Text sein."); }
    $s = trim((string)($v ?? ''));
    if ($req && $s === '') { throw new MmValidationError("Feld '$k' ist erforderlich."); }
    return mb_substr($s, 0, $max);
}

function mm_num(array $d, string $k, ?float $def = 0.0, float $lo = -1e9, float $hi = 1e9): ?float {
    if (!array_key_exists($k, $d) || $d[$k] === null || !is_numeric($d[$k])) { return $def; }
    $f = (float)$d[$k];
    if (is_nan($f) || is_infinite($f)) { return $def; }
    return max($lo, min($hi, $f));
}

function mm_int(array $d, string $k, int $def = 0, int $lo = -1000000000, int $hi = 1000000000): int {
    $f = mm_num($d, $k, (float)$def, (float)$lo, (float)$hi);
    return (int)($f ?? $def);
}

function mm_list(array $d, string $k, int $maxItems = 50, int $max = 160): array {
    $v = $d[$k] ?? [];
    if (is_string($v)) { $v = [$v]; }
    if (!is_array($v)) { return []; }
    $out = [];
    foreach (array_slice($v, 0, $maxItems) as $x) {
        if (!is_array($x) && !is_object($x)) { $out[] = mb_substr((string)$x, 0, $max); }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Scheduler
// ---------------------------------------------------------------------------
function mm_expire_stale(): int {
    $now = time();
    $n  = mm_exec("UPDATE reservations SET state='expired'
                   WHERE state='reserved' AND valid_until < ?", [$now]);
    $n += mm_exec("UPDATE reservations SET state='expired'
                   WHERE state='running'
                     AND COALESCE(last_heartbeat, started_ts, created_ts) < ?",
                  [$now - MM_HEARTBEAT_TIMEOUT]);
    return $n;
}

function mm_best_tps_overall(): float {
    $r = mm_q1('SELECT MAX(tps) m FROM tps_samples WHERE ts > ? AND tps > 0',
               [time() - 86400]);
    return (float)($r['m'] ?? 0.0);
}

/** @return array{0: float, 1: string} [tps, quelle] */
function mm_model_tps(string $machineId, ?string $model): array {
    $since = time() - 86400;
    if ($model) {
        $r = mm_q1('SELECT AVG(tps) a FROM tps_samples
                    WHERE machine_id=? AND model=? AND ts > ? AND tps > 0',
                   [$machineId, $model, $since]);
        if (!empty($r['a'])) { return [(float)$r['a'], 'model']; }
    }
    $r = mm_q1('SELECT AVG(tps) a FROM tps_samples
                WHERE machine_id=? AND ts > ? AND tps > 0', [$machineId, $since]);
    if (!empty($r['a'])) {
        $factor = $model ? MM_TPS_FALLBACK_DISCOUNT : 1.0;
        return [(float)$r['a'] * $factor, 'machine'];
    }
    return [0.0, 'none'];
}

function mm_active_reservations(string $machineId): array {
    return mm_q("SELECT * FROM reservations WHERE machine_id=?
                 AND state IN ('reserved','running') ORDER BY created_ts", [$machineId]);
}

function mm_machine_capacity(array $m, ?string $model = null, ?float $bestTps = null): array {
    $now  = time();
    $s    = mm_q1('SELECT * FROM samples WHERE machine_id=? ORDER BY ts DESC LIMIT 1', [$m['id']]);
    $last = (int)($m['last_seen'] ?? 0);
    $age  = $last ? $now - $last : 1000000000;
    $online = ((int)($m['enabled'] ?? 1) === 1) && $age <= MM_OFFLINE_AFTER;

    $ramTotal  = (float)($s['ram_total_gb'] ?? 0) ?: (float)($m['ram_total_gb'] ?? 0);
    $ramUsed   = (float)($s['ram_used_gb'] ?? 0);
    $vramTotal = (float)($s['vram_total_gb'] ?? 0) ?: (float)($m['vram_total_gb'] ?? 0);
    $vramUsed  = (float)($s['vram_used_gb'] ?? 0);
    $cpu = max(0.0, min(100.0, (float)($s['cpu_pct'] ?? 0)));
    $gpu = max(0.0, min(100.0, (float)($s['gpu_pct'] ?? 0)));

    $loaded = json_decode((string)($s['loaded_models'] ?? '[]'), true);
    if (!is_array($loaded)) { $loaded = []; }

    $res = mm_active_reservations((string)$m['id']);
    $resRam = $resVram = 0.0;
    foreach ($res as $r) {
        $resRam  += (float)$r['min_ram_gb'];
        $resVram += (float)$r['min_vram_gb'];
    }

    $freeRam  = max(0.0, $ramTotal - $ramUsed - $resRam - MM_RAM_HEADROOM_GB);
    $freeVram = max(0.0, $vramTotal - $vramUsed - $resVram - MM_VRAM_HEADROOM_GB);

    $maxSlots  = max(1, (int)($m['max_slots'] ?? 1));
    $usedSlots = count($res);
    $freeSlots = max(0, $maxSlots - $usedSlots);

    [$tpsHist, $tpsSource] = mm_model_tps((string)$m['id'], $model);
    if ($bestTps === null) { $bestTps = mm_best_tps_overall(); }

    $cVram  = $vramTotal > 0 ? $freeVram / $vramTotal : ($freeRam > 0 ? 0.5 : 0.0);
    $cRam   = $ramTotal  > 0 ? $freeRam / $ramTotal : 0.0;
    $cGpu   = (100.0 - $gpu) / 100.0;
    $cCpu   = (100.0 - $cpu) / 100.0;
    $cTps   = $bestTps > 0 ? $tpsHist / $bestTps : ($tpsHist > 0 ? 0.5 : 0.0);
    $cModel = ($model && in_array($model, $loaded, true)) ? 1.0 : 0.0;

    $w     = [MM_W_VRAM, MM_W_RAM, MM_W_GPU_IDLE, MM_W_CPU_IDLE, MM_W_TPS, MM_W_MODEL_LOADED];
    $parts = [$cVram, $cRam, $cGpu, $cCpu, $cTps, $cModel];
    $total = array_sum($w) ?: 1.0;
    $score = 0.0;
    foreach ($w as $i => $weight) {
        $score += $weight * max(0.0, min(1.0, $parts[$i]));
    }
    $score = $score / $total * 100.0;
    $score += max(-20.0, min(20.0, (float)($m['preference'] ?? 0)));

    if (!$online)            { $score = 0.0; }
    elseif ($freeSlots <= 0) { $score *= 0.15; }

    return [
        'machine_id' => $m['id'],
        'name' => $m['name'] ?: $m['id'],
        'platform' => $m['platform'] ?? 'unknown',
        'backend_url' => $m['backend_url'] ?? '',
        'online' => $online,
        'last_seen' => $last,
        'seconds_since_seen' => $last ? $age : null,
        'cpu_pct' => round($cpu, 1),
        'gpu_pct' => round($gpu, 1),
        'ram_total_gb' => round($ramTotal, 2),
        'ram_used_gb' => round($ramUsed, 2),
        'ram_free_gb' => round($freeRam, 2),
        'vram_total_gb' => round($vramTotal, 2),
        'vram_used_gb' => round($vramUsed, 2),
        'vram_free_gb' => round($freeVram, 2),
        'reserved_ram_gb' => round($resRam, 2),
        'reserved_vram_gb' => round($resVram, 2),
        'temp_c' => isset($s['temp_c']) && $s['temp_c'] !== null ? round((float)$s['temp_c'], 1) : null,
        'power_w' => isset($s['power_w']) && $s['power_w'] !== null ? round((float)$s['power_w'], 1) : null,
        'loaded_models' => $loaded,
        'tps_current' => round((float)($s['tps_current'] ?? 0), 2),
        'tps_avg' => round((float)($s['tps_avg'] ?? 0), 2),
        'tps_peak' => round((float)($s['tps_peak'] ?? 0), 2),
        'tps_history_model' => round($tpsHist, 2),
        'tps_history_source' => $tpsSource,
        'model_loaded' => (bool)$cModel,
        'max_slots' => $maxSlots,
        'used_slots' => $usedSlots,
        'free_slots' => $freeSlots,
        'active_reservations' => array_map(static fn($r) => [
            'id' => $r['id'], 'task_id' => $r['task_id'], 'requester' => $r['requester'],
            'model' => $r['model'], 'state' => $r['state'],
            'valid_until' => (int)$r['valid_until'],
            'started_ts' => $r['started_ts'] !== null ? (int)$r['started_ts'] : null,
        ], $res),
        'capacity_score' => round(max(0.0, min(100.0, $score)), 1),
    ];
}

function mm_capacity_overview(?string $model = null): array {
    mm_expire_stale();
    $best = mm_best_tps_overall();
    $out  = [];
    foreach (mm_q('SELECT * FROM machines ORDER BY name') as $m) {
        $out[] = mm_machine_capacity($m, $model, $best);
    }
    usort($out, static fn($a, $b) => $b['capacity_score'] <=> $a['capacity_score']
        ?: strcmp((string)$a['name'], (string)$b['name']));
    return $out;
}

/** @return array{0: bool, 1: string} */
function mm_eligible(array $c, float $minRam, float $minVram, bool $requireModel): array {
    if (!$c['online'])          { return [false, 'offline']; }
    if ($c['free_slots'] <= 0)  { return [false, 'keine freien Slots']; }
    if ($minRam > 0 && $c['ram_free_gb'] < $minRam) {
        return [false, "RAM zu knapp ({$c['ram_free_gb']} < $minRam GB)"];
    }
    if ($minVram > 0 && $c['vram_free_gb'] < $minVram) {
        return [false, "VRAM zu knapp ({$c['vram_free_gb']} < $minVram GB)"];
    }
    if ($requireModel && !$c['model_loaded']) { return [false, 'Modell nicht geladen']; }
    return [true, 'ok'];
}

function mm_plan(?string $model, float $minRam, float $minVram, bool $requireModel): array {
    $cands = $rejected = [];
    foreach (mm_capacity_overview($model) as $c) {
        [$ok, $reason] = mm_eligible($c, $minRam, $minVram, $requireModel);
        $c['eligible'] = $ok;
        $c['reason']   = $reason;
        if ($ok) { $cands[] = $c; } else { $rejected[] = $c; }
    }
    return ['recommended' => $cands[0] ?? null, 'candidates' => $cands,
            'rejected' => $rejected, 'generated_at' => time()];
}

function mm_reserve(array $p): array {
    mm_expire_stale();
    $now   = time();
    $model = mm_str($p, 'model', '', 160) ?: null;
    $minRam  = (float)(mm_num($p, 'minimum_ram_gb', mm_num($p, 'min_ram_gb', 0.0, 0, 10000), 0, 10000) ?? 0);
    $minVram = (float)(mm_num($p, 'minimum_vram_gb', mm_num($p, 'min_vram_gb', 0.0, 0, 10000), 0, 10000) ?? 0);
    $dur     = mm_int($p, 'estimated_duration_seconds', mm_int($p, 'est_duration_s', 600, 1, 86400), 1, 86400);
    $prio    = mm_int($p, 'priority', 50, 0, 100);
    $reqModel = !empty($p['require_model_loaded']);
    $forced   = mm_str($p, 'machine_id', '', 64);

    $caps = mm_capacity_overview($model);
    if ($forced !== '') {
        $caps = array_values(array_filter($caps, static fn($c) => $c['machine_id'] === $forced));
        if (!$caps) {
            return ['_code' => 404, 'status' => 'error', 'error' => 'unknown_machine',
                    'message' => "Maschine '$forced' ist nicht registriert."];
        }
    }

    foreach ($caps as $cap) {
        [$ok] = mm_eligible($cap, $minRam, $minVram, $reqModel);
        if (!$ok) { continue; }
        $rid   = 'res_' . bin2hex(random_bytes(8));
        $valid = $now + MM_RESERVATION_TTL;
        mm_exec("INSERT INTO reservations(id,machine_id,requester,task_id,model,
                    min_ram_gb,min_vram_gb,est_duration_s,priority,state,score,
                    created_ts,valid_until,note)
                 VALUES(?,?,?,?,?,?,?,?,?,'reserved',?,?,?,?)",
            [$rid, $cap['machine_id'], mm_str($p, 'requester', 'unknown', 120),
             mm_str($p, 'task_id', '', 120), $model ?? '', $minRam, $minVram, $dur,
             $prio, $cap['capacity_score'], $now, $valid, mm_str($p, 'note', '', 500)]);
        mm_log('info', 'scheduler', "Reservierung $rid auf {$cap['machine_id']}");
        return ['_code' => 201, 'status' => 'reserved', 'reservation_id' => $rid,
                'machine_id' => $cap['machine_id'], 'machine_name' => $cap['name'],
                'backend_url' => $cap['backend_url'], 'model' => $model ?? '',
                'model_already_loaded' => $cap['model_loaded'],
                'capacity_score' => $cap['capacity_score'],
                'expected_tps' => $cap['tps_history_model'],
                'ram_free_gb' => $cap['ram_free_gb'], 'vram_free_gb' => $cap['vram_free_gb'],
                'valid_until' => $valid, 'ttl_seconds' => MM_RESERVATION_TTL,
                'heartbeat_timeout_seconds' => MM_HEARTBEAT_TIMEOUT];
    }

    $details = [];
    foreach ($caps as $c) {
        [, $reason] = mm_eligible($c, $minRam, $minVram, $reqModel);
        $details[] = ['machine_id' => $c['machine_id'], 'reason' => $reason];
    }
    return ['_code' => 503, 'status' => 'unavailable', 'reason' => 'no_machine_available',
            'message' => 'Aktuell hat keine Maschine ausreichend freie Kapazitaet.',
            'details' => $details, 'retry_after_seconds' => MM_SAMPLE_INTERVAL * 2];
}

function mm_transition(string $rid, string $action): array {
    $row = mm_q1('SELECT * FROM reservations WHERE id=?', [$rid]);
    if (!$row) { return ['_code' => 404, 'status' => 'error', 'error' => 'not_found']; }
    $now   = time();
    $state = $row['state'];

    if ($action === 'start' || $action === 'heartbeat') {
        if (!in_array($state, ['reserved', 'running'], true)) {
            return ['_code' => 409, 'status' => 'error', 'error' => 'invalid_state',
                    'state' => $state];
        }
        mm_exec("UPDATE reservations SET state='running',
                    started_ts=COALESCE(started_ts,?), last_heartbeat=?, valid_until=?
                 WHERE id=?", [$now, $now, $now + MM_HEARTBEAT_TIMEOUT, $rid]);
    } elseif (in_array($action, ['complete', 'fail', 'cancel'], true)) {
        $map = ['complete' => 'completed', 'fail' => 'failed', 'cancel' => 'cancelled'];
        mm_exec('UPDATE reservations SET state=?, completed_ts=? WHERE id=?',
                [$map[$action], $now, $rid]);
    } else {
        return ['_code' => 400, 'status' => 'error', 'error' => 'unknown_action'];
    }
    return ['_code' => 200, 'status' => 'ok',
            'reservation' => mm_q1('SELECT * FROM reservations WHERE id=?', [$rid])];
}

function mm_timeline(int $windowMinutes = 60): array {
    $now  = time();
    $rows = mm_q('SELECT * FROM reservations WHERE created_ts > ? ORDER BY created_ts',
                 [$now - $windowMinutes * 60]);
    $out = [];
    foreach ($rows as $r) {
        $start = (int)($r['started_ts'] ?: $r['created_ts']);
        if ($r['completed_ts']) {
            $end = (int)$r['completed_ts'];
        } elseif (in_array($r['state'], ['reserved', 'running'], true)) {
            $end = $start + (int)($r['est_duration_s'] ?: 600);
        } else {
            $end = (int)$r['valid_until'];
        }
        $out[] = ['id' => $r['id'], 'machine_id' => $r['machine_id'],
                  'task_id' => $r['task_id'], 'requester' => $r['requester'],
                  'model' => $r['model'], 'state' => $r['state'],
                  'start' => $start, 'end' => max($end, $start + 30),
                  'priority' => (int)$r['priority']];
    }
    return $out;
}

function mm_retention(): array {
    $now = time();
    return [
        'samples' => mm_exec('DELETE FROM samples WHERE ts < ?',
                             [$now - MM_RETENTION_RAW_DAYS * 86400]),
        'tps_samples' => mm_exec('DELETE FROM tps_samples WHERE ts < ?',
                                 [$now - MM_RETENTION_TPS_DAYS * 86400]),
        'events' => mm_exec('DELETE FROM events WHERE ts < ?',
                            [$now - MM_RETENTION_EVENT_DAYS * 86400]),
        'reservations' => mm_exec("DELETE FROM reservations
            WHERE state IN ('completed','cancelled','expired','failed') AND created_ts < ?",
            [$now - MM_RETENTION_EVENT_DAYS * 86400]),
    ];
}
