<?php
/**
 * ingest.php — Fleet Ingest API v1 (generische Geräte-Metriken).
 *
 * Seit 1.2.0 ohne Tokens: Geräte authentifizieren sich mit ihrem
 * Geräteschlüssel (Header X-Fleet-Device / X-Fleet-Key, siehe pair.php),
 * Browser mit ihrer Session. Die frühere Admin-Registrierung per
 * MASTER-Token entfällt — Geräte werden per PIN gekoppelt.
 *
 *   POST {ts?, metrics:{...}}                 (Gerät)  -> {ok}
 *   GET  ?device=<id>&hours=<h>               (Browser oder dasselbe Gerät)
 *   GET  ?devices=1                           (Browser) -> Geräteliste
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ingest_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function ingest_tables(PDO $pdo): void {
    // Alte Token-Tabelle (bis 1.1, Klartext-Tokens) entfernen.
    $pdo->exec("DROP TABLE IF EXISTS devices");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS device_metrics (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL,
            ts        INTEGER NOT NULL,
            metrics   TEXT NOT NULL
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_device_metrics_dev_ts ON device_metrics(device_id, ts)');
}

$pdo = db();
ingest_tables($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Geräte-Liste (Browser) ──────────────────────────────────────────────
if ($method === 'GET' && isset($_GET['devices'])) {
    mm_require_session();
    $rows = $pdo->query("SELECT device_id AS id, MAX(ts) AS last_ts, COUNT(*) AS samples
                         FROM device_metrics GROUP BY device_id ORDER BY device_id")->fetchAll(PDO::FETCH_ASSOC);
    ingest_out(200, ['ok' => true, 'devices' => $rows]);
}

// ── History-Lesung ──────────────────────────────────────────────────────
if ($method === 'GET') {
    $devId = strtolower(trim((string)($_GET['device'] ?? '')));
    if (!mm_valid_device_id($devId)) ingest_out(400, ['ok' => false, 'error' => 'device required']);
    $caller = mm_device_identity();
    if ($caller !== $devId && !mm_is_authed()) {
        ingest_out(401, ['ok' => false, 'error' => 'unauthorized', 'login_required' => true]);
    }
    $hours = min(168.0, max(0.1, (float)($_GET['hours'] ?? 2)));
    $st = $pdo->prepare('SELECT ts, metrics FROM device_metrics WHERE device_id = ? AND ts >= ? ORDER BY ts ASC LIMIT 20000');
    $st->execute([$devId, time() - (int)($hours * 3600)]);
    $hist = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $hist[] = ['ts' => (int)$r['ts'], 'metrics' => json_decode($r['metrics'], true)];
    }
    ingest_out(200, ['ok' => true, 'device_id' => $devId, 'history' => $hist]);
}

// ── Metrik schreiben (Gerät) ────────────────────────────────────────────
if ($method !== 'POST') ingest_out(405, ['ok' => false, 'error' => 'POST required']);
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) ingest_out(400, ['ok' => false, 'error' => 'invalid JSON']);
$devId = mm_device_require($data);
if ($devId === 'legacy') ingest_out(401, ['ok' => false, 'error' => 'device_not_paired']);
$metrics = $data['metrics'] ?? null;
if (!is_array($metrics) || !$metrics) ingest_out(400, ['ok' => false, 'error' => 'metrics{} required']);

// Werte begrenzen: max 40 Felder, Zahlen/Strings/Booleans, String <= 120 Zeichen
$clean = [];
$i = 0;
foreach ($metrics as $k => $v) {
    if (++$i > 40) break;
    $k = preg_replace('/[^a-z0-9_]/i', '', (string)$k);
    if ($k === '') continue;
    if (is_bool($v) || is_int($v)) $clean[$k] = $v;
    elseif (is_float($v)) { if (is_finite($v)) $clean[$k] = $v; }
    elseif (is_string($v)) $clean[$k] = function_exists('mb_substr') ? mb_substr($v, 0, 120) : substr($v, 0, 120);
}
$ts = isset($data['ts']) && is_numeric($data['ts']) ? (int)$data['ts'] : time();
if ($ts < time() - 86400 || $ts > time() + 3600) $ts = time();
$pdo->prepare('INSERT INTO device_metrics (device_id, ts, metrics) VALUES (?, ?, ?)')
    ->execute([$devId, $ts, json_encode($clean)]);
// Aufblähung begrenzen: 30 Tage Rollung (nur gelegentlich ausführen)
if (random_int(1, 50) === 1) {
    $pdo->prepare('DELETE FROM device_metrics WHERE ts < ?')->execute([time() - 30 * 86400]);
}
ingest_out(200, ['ok' => true]);
