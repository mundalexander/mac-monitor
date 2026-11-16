<?php
/**
 * requests.php — receives LLM API call records from monitor clients.
 * POST body: JSON { token, calls: [ { ts, ip, method, endpoint, duration_ms, status }, ... ] }
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'POST required']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    json_response(400, ['error' => 'invalid JSON']);
}

$deviceId = mm_device_require($data);

$calls = $data['calls'] ?? [];
if (!is_array($calls)) {
    json_response(400, ['error' => 'calls must be an array']);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO requests (ts, ip, method, endpoint, duration_ms, status, server_id)
        VALUES (:ts, :ip, :method, :endpoint, :duration, :status, :server_id)
    ");
    $inserted = 0;
    $now = time();
    $pdo->beginTransaction();
    foreach (array_slice($calls, 0, 500) as $c) {
        if (!is_array($c)) continue;
        $ts = (int)($c['ts'] ?? $now);
        if ($ts < 1577836800 || $ts > $now + 3600) continue;
        $ip       = substr(trim((string)($c['ip'] ?? 'unknown')), 0, 64);
        $method   = substr(trim((string)($c['method'] ?? 'POST')), 0, 8);
        $endpoint = substr(trim((string)($c['endpoint'] ?? '')), 0, 255);
        $duration = isset($c['duration_ms']) && is_numeric($c['duration_ms']) ? round((float)$c['duration_ms'], 1) : null;
        $status   = (int)($c['status'] ?? 0);
        // Monitoring-Polls (Bernds Client ruft alle 10s auf) nicht speichern
        if (in_array($endpoint, ['/api/ps', '/api/tags'], true)) continue;
        $stmt->execute([
            ':ts' => $ts, ':ip' => $ip, ':method' => $method,
            ':endpoint' => $endpoint, ':duration' => $duration, ':status' => $status,
            ':server_id' => $deviceId === 'legacy' ? null : $deviceId,
        ]);
        $inserted++;
    }
    $pdo->commit();
    $cutoff = time() - (RETENTION_HOURS * 3600);
    $pdo->prepare('DELETE FROM requests WHERE ts < :c')->execute([':c' => $cutoff]);
    json_response(200, ['ok' => true, 'inserted' => $inserted]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('fleet-monitor requests: ' . $e->getMessage());
    json_response(500, ['error' => 'db error']);
}
