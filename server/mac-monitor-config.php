<?php
/**
 * Mac Monitor — shared config.
 * Edit SECRET_TOKEN to match the client's config.
 *
 * Supports multiple servers (multi-host monitoring).
 * Each server is identified by a `server_id` (e.g. 'mac', 'evo-x3').
 */

// Shared secret. Must match client config.json -> "token".
const SECRET_TOKEN = 'fseJLgBDetOAZizZjt_fv3AM-m0jUZYXZHEF7xrpOOw';

// SQLite database file.
const DB_PATH = __DIR__ . '/metrics.sqlite';

// Retention: drop rows older than this on every submit.
const RETENTION_HOURS = 25; // keep a bit > 24h for the 24h chart

// Hostname allow-list. Set to null to allow any.
const ALLOWED_HOSTS = null; // e.g. ['BigMac', 'sascha-EVO-X3']

// ── Server registry ──────────────────────────────────────────────────────
// Defines known servers and their display metadata.
// `id` matches the `server_id` POST field from the client.
const SERVERS = [
    'evo-x3' => [
        'id'      => 'evo-x3',
        'name'    => 'Evo-X3',
        'host'    => 'sascha-EVO-X3',
        'os'      => 'Ubuntu 24.04',
        'icon'    => '🟠',
        'color'   => '#ffa657',
    ],
    'mac' => [
        'id'      => 'mac',
        'name'    => 'BigMac',
        'host'    => 'BigMac',
        'os'      => 'macOS',
        'icon'    => '🖥️',
        'color'   => '#58a6ff',
    ],
    'mini-pc' => [
        'id'      => 'mini-pc',
        'name'    => 'Mini-PC',
        'host'    => 'MINI-PC',
        'os'      => 'Ubuntu 24.04 (WSL2)',
        'icon'    => '📦',
        'color'   => '#7ee787',
    ],
];

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS metrics (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                ts           INTEGER NOT NULL,
                host         TEXT    NOT NULL,
                server_id    TEXT    DEFAULT NULL,
                cpu          REAL    NOT NULL,
                gpu          REAL    NOT NULL,
                ram_percent  REAL    NOT NULL,
                ram_used_gb  REAL,
                ram_total_gb REAL,
                ollama       TEXT,
                shelly_power REAL
            )
        ");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_metrics_ts_host ON metrics(host, ts)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_metrics_server_id ON metrics(server_id, ts)');
        // Migrations for older schemas
        try { $pdo->exec('ALTER TABLE metrics ADD COLUMN ollama TEXT'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE metrics ADD COLUMN shelly_power REAL'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE metrics ADD COLUMN server_id TEXT'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE metrics ADD COLUMN vram_used_gb REAL'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE metrics ADD COLUMN vram_total_gb REAL'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE metrics ADD COLUMN gpu_temp INTEGER'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE metrics ADD COLUMN tokens_per_second REAL'); } catch (Throwable $e) {}
        // Requests table: tracks Ollama API calls per caller IP
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS requests (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                ts       INTEGER NOT NULL,
                ip       TEXT    NOT NULL,
                method   TEXT    NOT NULL,
                endpoint TEXT    NOT NULL,
                duration_ms REAL,
                status   INTEGER
            )
        ");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_requests_ts ON requests(ts)');
    }
    return $pdo;
}

function json_response(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/**
 * Resolve server_id from a POST payload.
 * Falls back to mapping by hostname, then to 'mac' for backward compat.
 */
function resolve_server_id(?string $serverId, string $host): string {
    if ($serverId && isset(SERVERS[$serverId])) {
        return $serverId;
    }
    // Fallback: match by host name
    foreach (SERVERS as $id => $cfg) {
        if (strcasecmp($cfg['host'], $host) === 0) {
            return $id;
        }
    }
    return 'mac';
}