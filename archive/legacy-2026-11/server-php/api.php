<?php
/**
 * mac-monitor PHP - Front Controller fuer /api/v1/*.
 * Gleiche Routen und Antworten wie der Python-Server.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Token');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

function mm_send(int $code, array $body): void {
    unset($body['_code']);
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mm_route(): string {
    $r = $_GET['route'] ?? '';                       // via .htaccess
    if ($r === '') {                                  // Fallback ohne Rewrite
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $pos  = strpos($path, '/api/v1');
        $r    = $pos !== false ? substr($path, $pos + 7) : '';
    }
    $r = '/' . trim((string)$r, '/');
    return $r === '/' ? '/' : rtrim($r, '/');
}

function mm_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') { return []; }
    if (strlen($raw) > 512 * 1024) { throw new MmValidationError('Payload zu gross.'); }
    $data = json_decode($raw, true);
    if (!is_array($data)) { throw new MmValidationError('Ungueltiges JSON.'); }
    return $data;
}

function mm_token_ok(): bool {
    $t = $_SERVER['HTTP_X_API_TOKEN']
        ?? str_replace('Bearer ', '', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($t === '') { $t = (string)($_GET['token'] ?? ''); }
    return hash_equals(MM_API_TOKEN, (string)$t);
}

function mm_qf(string $k, float $def): float {
    return isset($_GET[$k]) && is_numeric($_GET[$k]) ? (float)$_GET[$k] : $def;
}
function mm_qi(string $k, int $def): int { return (int)mm_qf($k, (float)$def); }
function mm_qs(string $k, ?string $def = null): ?string {
    $v = $_GET[$k] ?? null;
    return ($v === null || $v === '') ? $def : mb_substr((string)$v, 0, 200);
}

$route = mm_route();
$write = in_array($method, ['POST', 'DELETE'], true);

try {
    if (($write || !MM_ALLOW_ANONYMOUS_READ) && $route !== '/health' && !mm_token_ok()) {
        mm_send(401, ['status' => 'error', 'error' => 'unauthorized',
                      'message' => "Header 'X-API-Token' fehlt oder ist falsch."]);
    }

    // ---------------- GET ----------------
    if ($method === 'GET') {
        if ($route === '/health' || $route === '/') {
            $ms = mm_q('SELECT * FROM machines');
            $online = 0;
            foreach ($ms as $m) {
                if (time() - (int)$m['last_seen'] <= MM_OFFLINE_AFTER) { $online++; }
            }
            mm_send(200, [
                'status' => 'ok', 'product' => 'mac-monitor', 'api_version' => '1.0.0',
                'runtime' => 'php ' . PHP_VERSION, 'schema_version' => 3,
                'server_time' => time(), 'public_url' => mm_public_url(),
                'api_url' => mm_api_url(), 'dashboard_url' => mm_public_url() . '/',
                'machines_total' => count($ms), 'machines_online' => $online,
                'samples_total' => (int)(mm_q1('SELECT COUNT(*) c FROM samples')['c'] ?? 0),
                'active_reservations' => (int)(mm_q1("SELECT COUNT(*) c FROM reservations
                    WHERE state IN ('reserved','running')")['c'] ?? 0),
                'db_size_bytes' => is_file(MM_DB_PATH) ? filesize(MM_DB_PATH) : 0,
                'sample_interval' => MM_SAMPLE_INTERVAL,
            ]);
        }
        if ($route === '/config') {
            mm_send(200, ['public_url' => mm_public_url(), 'api_url' => mm_api_url(),
                'api_prefix' => '/api/v1', 'sample_interval' => MM_SAMPLE_INTERVAL,
                'offline_after' => MM_OFFLINE_AFTER, 'reservation_ttl' => MM_RESERVATION_TTL,
                'heartbeat_timeout' => MM_HEARTBEAT_TIMEOUT,
                'retention_raw_days' => MM_RETENTION_RAW_DAYS]);
        }
        if ($route === '/machines' || $route === '/capacity') {
            $model = mm_qs('model');
            mm_send(200, ['model' => $model, 'machines' => mm_capacity_overview($model),
                          'server_time' => time()]);
        }
        if ($route === '/agent/discovery' || $route === '/discovery') {
            $model = mm_qs('model');
            $plan  = mm_plan($model, mm_qf('min_ram_gb', 0), mm_qf('min_vram_gb', 0),
                             mm_qs('require_model', '0') === '1');
            $rec   = $plan['recommended'];
            mm_send(200, [
                'server_time' => time(), 'api_url' => mm_api_url(), 'model' => $model,
                'can_start_subagent' => $rec !== null,
                'recommended' => $rec === null ? null : [
                    'machine_id' => $rec['machine_id'], 'name' => $rec['name'],
                    'backend_url' => $rec['backend_url'], 'platform' => $rec['platform'],
                    'capacity_score' => $rec['capacity_score'],
                    'ram_free_gb' => $rec['ram_free_gb'], 'vram_free_gb' => $rec['vram_free_gb'],
                    'free_slots' => $rec['free_slots'], 'model_loaded' => $rec['model_loaded'],
                    'expected_tps' => $rec['tps_history_model'],
                    'reserve_endpoint' => mm_api_url() . '/reservations',
                ],
                'candidates' => array_map(static fn($c) => [
                    'machine_id' => $c['machine_id'], 'name' => $c['name'],
                    'backend_url' => $c['backend_url'], 'capacity_score' => $c['capacity_score'],
                    'ram_free_gb' => $c['ram_free_gb'], 'vram_free_gb' => $c['vram_free_gb'],
                    'free_slots' => $c['free_slots'], 'model_loaded' => $c['model_loaded'],
                    'expected_tps' => $c['tps_history_model'],
                ], $plan['candidates']),
                'unavailable' => array_map(static fn($c) => [
                    'machine_id' => $c['machine_id'], 'name' => $c['name'],
                    'reason' => $c['reason'],
                ], $plan['rejected']),
            ]);
        }
        if ($route === '/slots') {
            $w = max(5, min(1440, mm_qi('window', 60)));
            mm_send(200, ['window_minutes' => $w, 'slots' => mm_timeline($w),
                          'server_time' => time()]);
        }
        if ($route === '/series') {
            $minutes = max(1, min(10080, mm_qi('minutes', 60)));
            $since   = time() - $minutes * 60;
            $mid     = mm_qs('machine_id');
            $sql  = 'SELECT machine_id, ts, cpu_pct, gpu_pct, ram_used_gb, ram_total_gb,
                     vram_used_gb, vram_total_gb, temp_c, power_w, tps_current, tps_avg,
                     tps_peak, active_jobs FROM samples WHERE ts > ?';
            $args = [$since];
            if ($mid) { $sql .= ' AND machine_id=?'; $args[] = $mid; }
            $sql .= ' ORDER BY ts ASC LIMIT 20000';

            $tsql  = 'SELECT machine_id, ts, model, task_id, tps, prompt_tps, eval_tokens
                      FROM tps_samples WHERE ts > ?';
            $targs = [$since];
            if ($mid) { $tsql .= ' AND machine_id=?'; $targs[] = $mid; }
            $tsql .= ' ORDER BY ts ASC LIMIT 5000';

            mm_send(200, ['minutes' => $minutes, 'machine_id' => $mid,
                'samples' => mm_q($sql, $args), 'tps_samples' => mm_q($tsql, $targs),
                'server_time' => time()]);
        }
        if ($route === '/tps/summary') {
            $minutes = max(1, min(10080, mm_qi('minutes', 60)));
            $rows = mm_q('SELECT machine_id, model, COUNT(*) n, AVG(tps) avg_tps,
                                 MAX(tps) peak_tps, SUM(eval_tokens) tokens,
                                 SUM(eval_seconds) seconds
                          FROM tps_samples WHERE ts > ? AND tps > 0
                          GROUP BY machine_id, model ORDER BY avg_tps DESC',
                         [time() - $minutes * 60]);
            mm_send(200, ['minutes' => $minutes, 'rows' => array_map(static fn($r) => [
                'machine_id' => $r['machine_id'], 'model' => $r['model'],
                'samples' => (int)$r['n'], 'avg_tps' => round((float)$r['avg_tps'], 2),
                'peak_tps' => round((float)$r['peak_tps'], 2),
                'tokens' => (int)$r['tokens'], 'seconds' => round((float)$r['seconds'], 1),
            ], $rows)]);
        }
        if ($route === '/events') {
            mm_send(200, ['events' => mm_q('SELECT * FROM events ORDER BY ts DESC LIMIT ?',
                                           [max(1, min(500, mm_qi('limit', 50)))])]);
        }
        if ($route === '/reservations') {
            mm_expire_stale();
            $sql = 'SELECT * FROM reservations WHERE 1=1';
            $args = [];
            $state = mm_qs('state');
            if ($state === 'active') {
                $sql .= " AND state IN ('reserved','running')";
            } elseif ($state) {
                $sql .= ' AND state=?'; $args[] = $state;
            }
            if ($mid = mm_qs('machine_id')) { $sql .= ' AND machine_id=?'; $args[] = $mid; }
            $sql .= ' ORDER BY created_ts DESC LIMIT ?';
            $args[] = max(1, min(500, mm_qi('limit', 100)));
            mm_send(200, ['reservations' => mm_q($sql, $args)]);
        }
        if (preg_match('#^/reservations/([A-Za-z0-9_\-]{1,64})$#', $route, $m)) {
            mm_expire_stale();
            $row = mm_q1('SELECT * FROM reservations WHERE id=?', [$m[1]]);
            if (!$row) { mm_send(404, ['status' => 'error', 'error' => 'not_found']); }
            mm_send(200, ['status' => 'ok', 'reservation' => $row]);
        }
    }

    // ---------------- POST ----------------
    if ($method === 'POST') {
        if ($route === '/ingest') {
            $p = mm_body();
            $m = is_array($p['machine'] ?? null) ? $p['machine'] : [];
            $s = is_array($p['sample'] ?? null) ? $p['sample'] : [];
            $mid = mm_str($m, 'id', '', 64, true);
            $now = time();
            $ts  = mm_int($s, 'ts', $now, $now - 86400, $now + 300);

            mm_exec("INSERT INTO machines(id,name,platform,backend_url,labels,ram_total_gb,
                        vram_total_gb,cpu_model,gpu_model,max_slots,agent_version,
                        first_seen,last_seen)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
                     ON CONFLICT(id) DO UPDATE SET
                        name=excluded.name, platform=excluded.platform,
                        backend_url=CASE WHEN excluded.backend_url != ''
                                         THEN excluded.backend_url ELSE machines.backend_url END,
                        labels=excluded.labels, ram_total_gb=excluded.ram_total_gb,
                        vram_total_gb=excluded.vram_total_gb, cpu_model=excluded.cpu_model,
                        gpu_model=excluded.gpu_model, max_slots=excluded.max_slots,
                        agent_version=excluded.agent_version, last_seen=excluded.last_seen",
                [$mid, mm_str($m, 'name', $mid, 120), mm_str($m, 'platform', 'unknown', 40),
                 mm_normalize_url(mm_str($m, 'backend_url', '', 300)),
                 json_encode(mm_list($m, 'labels')),
                 mm_num($m, 'ram_total_gb', 0.0, 0, 100000),
                 mm_num($m, 'vram_total_gb', 0.0, 0, 100000),
                 mm_str($m, 'cpu_model', '', 160), mm_str($m, 'gpu_model', '', 160),
                 max(1, mm_int($m, 'max_slots', 1, 1, 64)),
                 mm_str($m, 'agent_version', '', 40), $ts, $ts]);

            mm_exec('INSERT INTO samples(machine_id,ts,cpu_pct,load1,ram_used_gb,ram_total_gb,
                        gpu_pct,vram_used_gb,vram_total_gb,temp_c,power_w,loaded_models,
                        tps_current,tps_avg,tps_peak,active_jobs)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$mid, $ts, mm_num($s, 'cpu_pct', 0.0, 0, 100),
                 mm_num($s, 'load1', 0.0, 0, 10000),
                 mm_num($s, 'ram_used_gb', 0.0, 0, 100000),
                 mm_num($s, 'ram_total_gb', 0.0, 0, 100000),
                 mm_num($s, 'gpu_pct', 0.0, 0, 100),
                 mm_num($s, 'vram_used_gb', 0.0, 0, 100000),
                 mm_num($s, 'vram_total_gb', 0.0, 0, 100000),
                 mm_num($s, 'temp_c', null, -50, 200), mm_num($s, 'power_w', null, 0, 20000),
                 json_encode(mm_list($s, 'loaded_models')),
                 mm_num($s, 'tps_current', 0.0, 0, 100000),
                 mm_num($s, 'tps_avg', 0.0, 0, 100000),
                 mm_num($s, 'tps_peak', 0.0, 0, 100000),
                 mm_int($s, 'active_jobs', 0, 0, 1000)]);

            $accepted = 0;
            foreach (array_slice((array)($p['tps_events'] ?? []), 0, 50) as $ev) {
                if (!is_array($ev)) { continue; }
                $ev['machine_id'] = $ev['machine_id'] ?? $mid;
                if (mm_store_tps($ev) !== null) { $accepted++; }
            }
            mm_send(201, ['status' => 'accepted', 'machine_id' => $mid, 'ts' => $ts,
                          'tps_events_accepted' => $accepted,
                          'next_sample_in' => MM_SAMPLE_INTERVAL]);
        }
        if ($route === '/tps') {
            $res = mm_store_tps(mm_body());
            if ($res === null) {
                mm_send(400, ['status' => 'error', 'error' => 'no_tps',
                    'message' => "Weder 'tps' noch eval_count/eval_duration nutzbar."]);
            }
            mm_send(201, $res);
        }
        if ($route === '/reservations') {
            $r = mm_reserve(mm_body());
            mm_send((int)($r['_code'] ?? 201), $r);
        }
        if ($route === '/maintenance') {
            $expired = mm_expire_stale();
            $deleted = mm_retention();
            mm_db()->exec('VACUUM');
            mm_send(200, ['status' => 'ok', 'expired_reservations' => $expired,
                'deleted' => $deleted,
                'db_size_bytes' => is_file(MM_DB_PATH) ? filesize(MM_DB_PATH) : 0]);
        }
        if (preg_match('#^/reservations/([A-Za-z0-9_\-]{1,64})/(start|heartbeat|complete|fail|cancel)$#',
                       $route, $m)) {
            $r = mm_transition($m[1], $m[2]);
            mm_send((int)($r['_code'] ?? 200), $r);
        }
    }

    // ---------------- DELETE ----------------
    if ($method === 'DELETE'
        && preg_match('#^/reservations/([A-Za-z0-9_\-]{1,64})$#', $route, $m)) {
        $r = mm_transition($m[1], 'cancel');
        mm_send((int)($r['_code'] ?? 200), $r);
    }

    mm_send(404, ['status' => 'error', 'error' => 'not_found',
                  'message' => "Route $route existiert nicht.", 'api_url' => mm_api_url()]);

} catch (MmValidationError $e) {
    mm_send(400, ['status' => 'error', 'error' => 'invalid_request',
                  'message' => $e->getMessage()]);
} catch (Throwable $e) {
    mm_log('error', 'http', $route . ': ' . $e->getMessage());
    mm_send(500, ['status' => 'error', 'error' => 'internal_error',
                  'message' => 'Interner Fehler, Details im Serverlog.']);
}

/** Speichert eine TPS-Messung. Gibt null zurueck, wenn keine Rate ableitbar ist. */
function mm_store_tps(array $p): ?array {
    $mid = mm_str($p, 'machine_id', '', 64, true);
    $now = time();
    $ts  = mm_int($p, 'ts', $now, $now - 86400, $now + 300);

    $evalTokens = mm_int($p, 'eval_count', 0, 0, 100000000);
    $evalNs     = (float)(mm_num($p, 'eval_duration', 0.0, 0, 1e18) ?? 0);
    $promptTok  = mm_int($p, 'prompt_eval_count', 0, 0, 100000000);
    $promptNs   = (float)(mm_num($p, 'prompt_eval_duration', 0.0, 0, 1e18) ?? 0);

    $evalSeconds   = (float)(mm_num($p, 'eval_seconds', $evalNs / 1e9, 0, 1e6) ?? 0);
    $promptSeconds = $promptNs / 1e9;

    $rate = (float)(mm_num($p, 'tps', 0.0, 0, 100000) ?? 0);
    if ($rate <= 0 && $evalTokens > 0 && $evalSeconds > 0) {
        $rate = $evalTokens / $evalSeconds;
    }
    if ($rate <= 0) { return null; }
    $promptRate = $promptSeconds > 0 ? $promptTok / $promptSeconds : 0.0;

    mm_exec('INSERT INTO tps_samples(machine_id,ts,model,task_id,reservation_id,
                prompt_tokens,eval_tokens,eval_seconds,prompt_tps,tps,source)
             VALUES(?,?,?,?,?,?,?,?,?,?,?)',
        [$mid, $ts, mm_str($p, 'model', '', 160), mm_str($p, 'task_id', '', 120),
         mm_str($p, 'reservation_id', '', 64), $promptTok, $evalTokens,
         round($evalSeconds, 3), round($promptRate, 2), round($rate, 2),
         mm_str($p, 'source', 'agent', 32)]);

    return ['status' => 'accepted', 'machine_id' => $mid,
            'tps' => round($rate, 2), 'prompt_tps' => round($promptRate, 2)];
}
