<?php
/**
 * submit.php — receives metric POSTs from monitor clients (Mac or Linux).
 * POST body: JSON {
 *   token, host, ts, cpu, gpu, ram_percent,
 *   ram_used_gb?, ram_total_gb?,
 *   ollama?, shelly_power?,
 *   server_id?   // 'mac' | 'evo-x3' — identifies which server
 * }
 *
 * Backward compatible: if server_id is absent, the server is resolved
 * from the host name via SERVERS registry in config.php.
 */
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'POST required']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    json_response(400, ['error' => 'invalid JSON']);
}

// auth
if (!hash_equals(SECRET_TOKEN, (string)($data['token'] ?? ''))) {
    json_response(401, ['error' => 'bad token']);
}

// required fields
$required = ['host', 'ts', 'cpu', 'gpu', 'ram_percent'];
foreach ($required as $f) {
    if (!array_key_exists($f, $data)) {
        json_response(400, ['error' => "missing field: $f"]);
    }
}

$host = trim((string)$data['host']);
if (ALLOWED_HOSTS !== null && !in_array($host, ALLOWED_HOSTS, true)) {
    json_response(403, ['error' => 'host not allowed']);
}

// Resolve server_id (falls back to host-name mapping or 'mac')
$serverId = resolve_server_id(
    isset($data['server_id']) ? trim((string)$data['server_id']) : null,
    $host
);

$ts  = (int)$data['ts'];
// Sanity: reject timestamps too far in the future or before 2020.
if ($ts < 1577836800 || $ts > time() + 3600) {
    json_response(400, ['error' => 'ts out of range']);
}

$cpu = max(-1.0, min(100.0, (float)$data['cpu']));
$gpu = max(-1.0, min(100.0, (float)$data['gpu']));
$ram = max(0.0,  min(100.0, (float)$data['ram_percent']));
$ramUsed  = isset($data['ram_used_gb'])  ? (float)$data['ram_used_gb']  : null;
$ramTotal = isset($data['ram_total_gb']) ? (float)$data['ram_total_gb'] : null;
$vramUsed  = isset($data['vram_used_gb'])  ? (float)$data['vram_used_gb']  : null;
$vramTotal = isset($data['vram_total_gb']) ? (float)$data['vram_total_gb'] : null;
$ollama       = isset($data['ollama'])        ? json_encode($data['ollama'])  : null;
$shellyPower = isset($data['shelly_power'])   ? max(0.0, (float)$data['shelly_power']) : null;

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO metrics (ts, host, server_id, cpu, gpu, gpu_temp, ram_percent, ram_used_gb, ram_total_gb, vram_used_gb, vram_total_gb, ollama, shelly_power)
        VALUES (:ts, :host, :server_id, :cpu, :gpu, :gpu_temp, :ram, :used, :total, :vram_used, :vram_total, :ollama, :shelly_power)
    ");
    $stmt->execute([
        ':ts'           => $ts,
        ':host'         => $host,
        ':server_id'    => $serverId,
        ':cpu'          => $cpu,
        ':gpu'          => $gpu,
        ':gpu_temp'     => isset($data['gpu_temp']) ? (int)$data['gpu_temp'] : null,
        ':ram'          => $ram,
        ':used'         => $ramUsed,
        ':total'        => $ramTotal,
        ':vram_used'    => $vramUsed,
        ':vram_total'   => $vramTotal,
        ':ollama'       => $ollama,
        ':shelly_power' => $shellyPower,
    ]);

    // prune anything older than retention window
    $cutoff = time() - (RETENTION_HOURS * 3600);
    $pdo->prepare('DELETE FROM metrics WHERE ts < :c')
        ->execute([':c' => $cutoff]);
    $pdo->prepare('DELETE FROM requests WHERE ts < :c')
        ->execute([':c' => $cutoff]);

    json_response(200, ['ok' => true, 'server_id' => $serverId]);
} catch (Throwable $e) {
    json_response(500, ['error' => 'db error', 'detail' => $e->getMessage()]);
}
