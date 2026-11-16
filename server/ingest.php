<?php
/**
 * ingest.php — Fleet Ingest API v1 (WP5).
 *
 * Standardisierte Geräte-Anbindung: jedes Gerät (macOS, Linux, NVIDIA-Host,
 * LM Studio, Ollama, künftige) schreibt Metriken im selben Format.
 *
 * Endpunkte (alle JSON):
 *   POST {action:"register", token:<MASTER>, device_id, name?, os?}
 *        -> {ok, device_id, device_token}   (Gerät anlegen/Token rotieren)
 *   POST {action:"delete", token:<MASTER>, device_id}
 *        -> {ok}
 *   POST {device_id, token:<DEVICE_TOKEN>, ts?, metrics:{...}}
 *        -> {ok}   Metrik schreiben; Werte frei, Zahlen oder Strings.
 *   GET  ?device=<id>&token=<DEVICE|MASTER>&hours=<h>
 *        -> {ok, device_id, history:[{ts, metrics:{...}}, ...]}
 *   GET  ?devices=1&token=<MASTER>
 *        -> {ok, devices:[{id,name,os,created_at,last_ts}]}
 *
 * Geräte-Token: pro Gerät ein eigenes Secret — das MASTER-Geheimnis
 * (SECRET_TOKEN aus config.php) verlässt das Admin-Skript nie.
 */
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ingest_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function ingest_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS devices (
            id         TEXT PRIMARY KEY,
            token      TEXT NOT NULL,
            name       TEXT NOT NULL DEFAULT '',
            os         TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )
    ");
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

$method = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input');
$data = $raw ? json_decode($raw, true) : [];
if (!is_array($data)) $data = [];

// ── Registrierung / Verwaltung (nur MASTER-Token) ─────────────────────
$action = (string)($data['action'] ?? '');
if ($action === 'register' || $action === 'delete') {
    if (!hash_equals(SECRET_TOKEN, (string)($data['token'] ?? ''))) {
        ingest_out(401, ['ok' => false, 'error' => 'master token required']);
    }
    $devId = preg_replace('/[^a-z0-9_-]/i', '', (string)($data['device_id'] ?? ''));
    if ($devId === '' || strlen($devId) > 40) {
        ingest_out(400, ['ok' => false, 'error' => 'invalid device_id']);
    }
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM devices WHERE id = ?')->execute([$devId]);
        $pdo->prepare('DELETE FROM device_metrics WHERE device_id = ?')->execute([$devId]);
        ingest_out(200, ['ok' => true, 'deleted' => $devId]);
    }
    $token = bin2hex(random_bytes(24));
    $name  = mb_substr((string)($data['name'] ?? $devId), 0, 60);
    $os    = mb_substr((string)($data['os'] ?? ''), 0, 40);
    $pdo->prepare('INSERT INTO devices (id, token, name, os, created_at) VALUES (?, ?, ?, ?, ?)
                   ON CONFLICT(id) DO UPDATE SET token=excluded.token, name=excluded.name, os=excluded.os')
        ->execute([$devId, $token, $name, $os, time()]);
    ingest_out(200, ['ok' => true, 'device_id' => $devId, 'device_token' => $token]);
}

// ── Geräte-Liste (Admin) ────────────────────────────────────────────────
if ($method === 'GET' && isset($_GET['devices'])) {
    if (!hash_equals(SECRET_TOKEN, (string)($_GET['token'] ?? ''))) {
        ingest_out(401, ['ok' => false, 'error' => 'master token required']);
    }
    $rows = $pdo->query("SELECT d.id, d.name, d.os, d.created_at,
                                (SELECT MAX(ts) FROM device_metrics m WHERE m.device_id = d.id) AS last_ts
                         FROM devices d ORDER BY d.id")->fetchAll(PDO::FETCH_ASSOC);
    ingest_out(200, ['ok' => true, 'devices' => $rows]);
}

// ── History-Lesung ──────────────────────────────────────────────────────
if ($method === 'GET') {
    $devId = (string)($_GET['device'] ?? '');
    $tok   = (string)($_GET['token'] ?? '');
    if ($devId === '' || $tok === '') ingest_out(400, ['ok' => false, 'error' => 'device and token required']);
    $row = $pdo->prepare('SELECT token FROM devices WHERE id = ?');
    $row->execute([$devId]);
    $dev = $row->fetch(PDO::FETCH_ASSOC);
    if (!$dev || (!hash_equals($dev['token'], $tok) && !hash_equals(SECRET_TOKEN, $tok))) {
        ingest_out(401, ['ok' => false, 'error' => 'unauthorized']);
    }
    $hours = min(168.0, max(0.1, (float)($_GET['hours'] ?? 2)));
    $st = $pdo->prepare('SELECT ts, metrics FROM device_metrics WHERE device_id = ? AND ts >= ? ORDER BY ts ASC');
    $st->execute([$devId, time() - (int)($hours * 3600)]);
    $hist = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $hist[] = ['ts' => (int)$r['ts'], 'metrics' => json_decode($r['metrics'], true)];
    }
    ingest_out(200, ['ok' => true, 'device_id' => $devId, 'history' => $hist]);
}

// ── Metrik-Schreiben (Geräte-Token) ─────────────────────────────────────
if ($method !== 'POST') ingest_out(405, ['ok' => false, 'error' => 'POST required']);
$devId = (string)($data['device_id'] ?? '');
$tok   = (string)($data['token'] ?? '');
$metrics = $data['metrics'] ?? null;
if ($devId === '' || $tok === '' || !is_array($metrics) || !$metrics) {
    ingest_out(400, ['ok' => false, 'error' => 'device_id, token and metrics{} required']);
}
$dev = $pdo->prepare('SELECT token FROM devices WHERE id = ?');
$dev->execute([$devId]);
$dev = $dev->fetch(PDO::FETCH_ASSOC);
if (!$dev || !hash_equals($dev['token'], $tok)) {
    ingest_out(401, ['ok' => false, 'error' => 'unauthorized']);
}
// Werte begrenzen: max 40 Felder, Zahlen/Strings/Booleans, String <= 120 Zeichen
$clean = [];
$i = 0;
foreach ($metrics as $k => $v) {
    if (++$i > 40) break;
    $k = preg_replace('/[^a-z0-9_]/i', '', (string)$k);
    if ($k === '') continue;
    if (is_bool($v)) $clean[$k] = $v;
    elseif (is_int($v) || is_float($v)) $clean[$k] = $v;
    elseif (is_string($v)) $clean[$k] = mb_substr($v, 0, 120);
}
$ts = isset($data['ts']) && is_numeric($data['ts']) ? (int)$data['ts'] : time();
$pdo->prepare('INSERT INTO device_metrics (device_id, ts, metrics) VALUES (?, ?, ?)')
    ->execute([$devId, $ts, json_encode($clean)]);
// Aufblähung begrenzen: 30 Tage Rollung pro Gerät
$pdo->prepare('DELETE FROM device_metrics WHERE device_id = ? AND ts < ?')
    ->execute([$devId, time() - 30 * 86400]);
ingest_out(200, ['ok' => true]);
