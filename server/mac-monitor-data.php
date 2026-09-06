<?php
/**
 * data.php — JSON feed for the dashboard.
 * GET params:
 *   range = 10m | 30m | 1h | 6h | 12h | 24h (default 1h)
 *   host  = optional host filter; otherwise returns all hosts grouped
 *   server_id = optional server_id filter (e.g. 'mac' or 'evo-x3')
 *
 * Response shape:
 * {
 *   "range_hours": 1,
 *   "now": 1700000000,
 *   "servers": { "mac": { ... }, "evo-x3": { ... } },   // keyed by server_id
 *   "hosts":   { ... },                                  // legacy: keyed by host name
 *   "requests": [ { ts, ip, method, endpoint, duration_ms, status }, ... ]
 * }
 *
 * For backward compat, `hosts` is still populated. New dashboards should
 * iterate `servers` instead — it preserves the server_id grouping even if
 * two servers happen to share a hostname.
 */
require __DIR__ . '/auth.php';
monitor_gate_api_json();
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Solar power: values ≤ 2W are parasitic draw from the meter itself → show as 0
function solarThreshold($w) {
    return ($w !== null && $w <= 2.0) ? 0.0 : $w;
}

$rangeMap = ['10m' => 0.167, '30m' => 0.5, '1h' => 1, '6h' => 6, '12h' => 12, '24h' => 24];
$rangeKey = $_GET['range'] ?? '1h';
$cleanup = isset($_GET['cleanup']);
$hours    = $rangeMap[$rangeKey] ?? 1;
$host     = isset($_GET['host']) ? trim((string)$_GET['host']) : null;
$serverId = isset($_GET['server_id']) ? trim((string)$_GET['server_id']) : null;
$cutoff   = time() - $hours * 3600;

// ── Maintenance: delete stale hosts ──────────────────────────────
if ($cleanup) {
    $pdo = new PDO('sqlite:' . __DIR__ . '/metrics.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $stmt = $pdo->prepare("DELETE FROM metrics WHERE host != 'BigMac'");
    $stmt->execute();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount(), 'hosts' => $pdo->query("SELECT DISTINCT host FROM metrics")->fetchAll(PDO::FETCH_COLUMN)]);
    exit;
}

$pdo = db();
$hosts = [];
$servers = [];

// ── Build server list to query ───────────────────────────────────────────
// Always include all servers from the registry, even if no data yet.
$serverIdsToQuery = [];
if ($serverId !== null) {
    $serverIdsToQuery[] = $serverId;
} else {
    $serverIdsToQuery = array_keys(SERVERS);
}

// Also discover any server_ids in the DB not in the registry (forward compat)
$dbServerIds = $pdo->query("SELECT DISTINCT server_id FROM metrics WHERE server_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
foreach ($dbServerIds as $sid) {
    if (!in_array($sid, $serverIdsToQuery, true)) {
        $serverIdsToQuery[] = $sid;
    }
}

// ── Per-server data ──────────────────────────────────────────────────────
foreach ($serverIdsToQuery as $sid) {
    $cfg = SERVERS[$sid] ?? ['id' => $sid, 'name' => $sid, 'host' => $sid, 'os' => '', 'icon' => '💻', 'color' => '#8b949e'];
    $h = $cfg['host'];

    // Latest row for this server
    $latestStmt = $pdo->prepare("
        SELECT ts, host, server_id, cpu, gpu, ram_percent, ram_used_gb, ram_total_gb, vram_used_gb, vram_total_gb, ollama, shelly_power
        FROM metrics WHERE server_id = :sid ORDER BY ts DESC LIMIT 1
    ");
    $latestStmt->execute([':sid' => $sid]);
    $latest = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Fallback: if no rows with server_id, try by host name (legacy data)
    if (!$latest) {
        $latestStmt2 = $pdo->prepare("
            SELECT ts, host, server_id, cpu, gpu, ram_percent, ram_used_gb, ram_total_gb, vram_used_gb, vram_total_gb, ollama, shelly_power
            FROM metrics WHERE host = :h ORDER BY ts DESC LIMIT 1
        ");
        $latestStmt2->execute([':h' => $h]);
        $latest = $latestStmt2->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Time series
    $seriesStmt = $pdo->prepare("
        SELECT ts, cpu, gpu, ram_percent AS ram, shelly_power, vram_used_gb, vram_total_gb
        FROM metrics WHERE server_id = :sid AND ts >= :c ORDER BY ts ASC
    ");
    $seriesStmt->execute([':sid' => $sid, ':c' => $cutoff]);
    $rows = $seriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fallback to host-based series if empty
    if (count($rows) === 0) {
        $seriesStmt2 = $pdo->prepare("
            SELECT ts, cpu, gpu, ram_percent AS ram, shelly_power, vram_used_gb, vram_total_gb
            FROM metrics WHERE host = :h AND ts >= :c ORDER BY ts ASC
        ");
        $seriesStmt2->execute([':h' => $h, ':c' => $cutoff]);
        $rows = $seriesStmt2->fetchAll(PDO::FETCH_ASSOC);
    }

    $maxPoints = 360;
    if (count($rows) > $maxPoints) {
        $bucket = (int)ceil(count($rows) / $maxPoints);
        $grouped = [];
        foreach (array_chunk($rows, $bucket) as $chunk) {
            $n = count($chunk);
            $tsMid = $chunk[(int)floor($n / 2)]['ts'];
            $spVals = array_filter(array_column($chunk, 'shelly_power'), fn($v) => $v !== null);
            $spAvg  = count($spVals) > 0 ? solarThreshold(round(array_sum($spVals) / count($spVals), 1)) : null;
            $grouped[] = [
                'ts'           => (int)$tsMid,
                'cpu'          => round(array_sum(array_column($chunk, 'cpu')) / $n, 1),
                'gpu'          => round(array_sum(array_column($chunk, 'gpu')) / $n, 1),
                'ram'          => round(array_sum(array_column($chunk, 'ram')) / $n, 1),
                'shelly_power' => $spAvg,
            ];
        }
        $rows = $grouped;
    } else {
        $rows = array_map(fn($r) => [
            'ts'  => (int)$r['ts'],
            'cpu' => (float)$r['cpu'],
            'gpu' => (float)$r['gpu'],
            'ram' => (float)$r['ram'],
            'vram_used_gb'  => isset($r['vram_used_gb'])  ? (float)$r['vram_used_gb']  : null,
            'vram_total_gb' => isset($r['vram_total_gb']) ? (float)$r['vram_total_gb'] : null,
            'shelly_power' => $r['shelly_power'] !== null ? solarThreshold((float)$r['shelly_power']) : null,
        ], $rows);
    }

    $serverData = [
        'id'      => $sid,
        'name'    => $cfg['name'],
        'host'    => $cfg['host'],
        'os'      => $cfg['os'],
        'icon'    => $cfg['icon'],
        'color'   => $cfg['color'],
        'latest'  => $latest ? [
            'ts'           => (int)$latest['ts'],
            'cpu'          => (float)$latest['cpu'],
            'gpu'          => (float)$latest['gpu'],
            'ram_percent'  => (float)$latest['ram_percent'],
            'ram_used_gb'  => $latest['ram_used_gb']  ? (float)$latest['ram_used_gb']  : null,
            'ram_total_gb' => $latest['ram_total_gb'] ? (float)$latest['ram_total_gb'] : null,
            'vram_used_gb'  => $latest['vram_used_gb']  ? (float)$latest['vram_used_gb']  : null,
            'vram_total_gb' => $latest['vram_total_gb'] ? (float)$latest['vram_total_gb'] : null,
            'ollama'       => $latest['ollama'] ? json_decode($latest['ollama'], true) : null,
            'shelly_power' => $latest['shelly_power'] !== null ? solarThreshold((float)$latest['shelly_power']) : null,
        ] : null,
        'series'  => $rows,
    ];

    $servers[$sid] = $serverData;

    // Also populate legacy `hosts` map (keyed by hostname)
    $hosts[$h] = [
        'latest' => $serverData['latest'],
        'series' => $serverData['series'],
    ];
}

// ── Ollama Request Log ───────────────────────────────────────────────────
$reqStmt = $pdo->prepare("
    SELECT ts, ip, method, endpoint, duration_ms, status
    FROM   requests
    WHERE  ts >= :cutoff
    ORDER BY ts ASC
");
$reqStmt->execute([':cutoff' => $cutoff]);
$requests = array_map(fn($r) => [
    'ts'          => (int)    $r['ts'],
    'ip'          =>          $r['ip'],
    'method'      =>          $r['method'],
    'endpoint'    =>          $r['endpoint'],
    'duration_ms' => (float)  $r['duration_ms'],
    'status'      => (int)    $r['status'],
], $reqStmt->fetchAll());

echo json_encode([
    'range_hours' => $hours,
    'now'         => time(),
    'servers'     => $servers,
    'hosts'       => $hosts,  // legacy compat
    'requests'    => $requests,
]);
