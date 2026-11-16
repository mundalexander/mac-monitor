<?php
/**
 * Fleet Monitor — gemeinsame Konfiguration (enthält KEINE Geheimnisse).
 *
 * Seit 1.2.0 gibt es keine API-Tokens mehr:
 *   - Browser melden sich einmalig mit PIN an ("Gerät merken", 90 Tage).
 *   - Clients (BigMac, Evo-X3, ...) werden einmalig per PIN gekoppelt
 *     (client/monitor.py --pair) und erhalten einen eigenen Geräteschlüssel.
 * Der einzige geheime Wert ist der PIN-Hash in secrets.php (nicht im Repo).
 *
 * Diese Datei darf unverändert deployt werden.
 */

// SQLite-Datenbank. Bei bplaced: Ordner per .htaccess gesperrt.
// Optional in secrets.php überschreibbar: define('MM_DB_PATH', '/pfad/ausserhalb/webroot/metrics.sqlite');
if (is_file(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
}
define('DB_PATH', defined('MM_DB_PATH') ? (string)constant('MM_DB_PATH') : __DIR__ . '/metrics.sqlite');

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
        'icon'    => '',
        'color'   => '#76b900',
    ],
    'mac' => [
        'id'      => 'mac',
        'name'    => 'BigMac',
        'host'    => 'BigMac',
        'os'      => 'macOS',
        'icon'    => '',
        'color'   => '#8e8e93',
    ],
    'mini-pc' => [
        'id'      => 'mini-pc',
        'name'    => 'Mini-PC',
        'host'    => 'MINI-PC',
        'os'      => 'Ubuntu 24.04 (WSL2)',
        'icon'    => '',
        'color'   => '#6e6e73',
    ],
];

const MM_SCHEMA_VERSION = 120;

// Erlaubte Steuerbefehle (Dashboard -> Command-Queue -> Client)
const MM_COMMAND_ACTIONS = ['lm_studio_unload', 'ollama_unload', 'halogen_start', 'halogen_stop',
                            'halogen_restart', 'lmstudio_start', 'lmstudio_stop',
                            // 1.3 Kommandozentrale (Ausführung: client/control.py)
                            'control_profile', 'control_stop', 'control_free', 'control_agent_restart'];
// Steueraktionen, die ein Gerät (z. B. Jarvis über den Geräteschlüssel) selbst auslösen darf.
// Agent-Neustarts bleiben dem angemeldeten Menschen im Browser vorbehalten.
const MM_DEVICE_CONTROL_ACTIONS = ['control_profile', 'control_stop', 'control_free'];

/** Letzten Steuerzustand je Maschine speichern/lesen (Tabelle control_state). */
function mm_control_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS control_state (
        server_id TEXT PRIMARY KEY, ts INTEGER NOT NULL, data TEXT NOT NULL)");
}

// Spalten, die seit der ersten Version hinzugekommen sind (Migration).
const MM_METRIC_COLUMNS = [
    'ollama TEXT',
    'shelly_power REAL',
    'server_id TEXT',
    'vram_used_gb REAL',
    'vram_total_gb REAL',
    'gpu_temp INTEGER',
    'tokens_per_second REAL',
    'ollama_tps REAL',
    'lm_studio_tps REAL',
    'ollama_req_count INTEGER',
    'ollama_req_dur_ms INTEGER',
    'lms_req_count INTEGER',
    'lms_req_dur_ms INTEGER',
    'halogen_kv_pool_used INTEGER',
    'halogen_kv_pool_total INTEGER',
    'halogen_kv_pool_pct REAL',
    'halogen_tps REAL',
    'halogen_prefill_tps REAL',
    'halogen_decode_tps REAL',
    'halogen_requests_total INTEGER',
    'brain TEXT',
    'egpu_percent REAL',
    'egpu_vram_used_mb REAL',
    'egpu_vram_total_mb REAL',
    'egpu_watt REAL',
    'gaming_pc_watt REAL',
    'nvidia_alive INTEGER',
    'nvidia_kv_pct REAL',
    'nvidia_kv_tokens INTEGER',
    'nvidia_decode_tps REAL',
    'nvidia_prompt_tps REAL',
    'nvidia_requests_busy INTEGER',
    'gpu_watt REAL',
    'egpu_temp INTEGER',
];

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
        $pdo->exec('PRAGMA busy_timeout=10000');
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
        // Schema-Migrationen nur einmal ausführen (PRAGMA user_version),
        // statt bei jedem Request ~40 ALTER-Versuche abzusetzen.
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
        $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($ver < MM_SCHEMA_VERSION) {
            foreach (MM_METRIC_COLUMNS as $col) {
                try { $pdo->exec('ALTER TABLE metrics ADD COLUMN ' . $col); } catch (Throwable $e) {}
            }
            // 1.2.0: Anfragen je Maschine zuordenbar
            try { $pdo->exec('ALTER TABLE requests ADD COLUMN server_id TEXT'); } catch (Throwable $e) {}
            $pdo->exec("DELETE FROM requests WHERE endpoint IN ('/api/ps', '/api/tags')");
            $pdo->exec('PRAGMA user_version = ' . MM_SCHEMA_VERSION);
        }
    }
    return $pdo;
}

function json_response(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
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

// ── Command-Queue (commands.json, mit Dateisperre und atomarem Schreiben) ──
const MM_COMMANDS_FILE = __DIR__ . '/commands.json';

/**
 * Liest die Queue unter exklusiver Sperre, wendet $fn an und schreibt atomar zurück.
 * $fn erhält die Liste per Referenz und darf einen Rückgabewert liefern.
 */
function mm_commands_update(callable $fn) {
    $lock = fopen(MM_COMMANDS_FILE . '.lock', 'c');
    if ($lock === false) throw new RuntimeException('lock failed');
    try {
        flock($lock, LOCK_EX);
        $cmds = [];
        if (is_file(MM_COMMANDS_FILE)) {
            $cmds = json_decode((string)file_get_contents(MM_COMMANDS_FILE), true) ?: [];
        }
        $result = $fn($cmds);
        // Aufräumen: erledigte Befehle > 24 h und alles > 7 Tage entfernen, max. 200 Einträge
        $now = time();
        $cmds = array_values(array_filter($cmds, function ($c) use ($now) {
            $ts = (int)($c['ts'] ?? 0);
            if (!empty($c['done']) && $now - (int)($c['done_at'] ?? $ts) > 86400) return false;
            return $now - $ts < 7 * 86400;
        }));
        $cmds = array_slice($cmds, -200);
        $tmp = MM_COMMANDS_FILE . '.tmp';
        file_put_contents($tmp, json_encode($cmds, JSON_PRETTY_PRINT));
        rename($tmp, MM_COMMANDS_FILE);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function mm_commands_read(): array {
    if (!is_file(MM_COMMANDS_FILE)) return [];
    return json_decode((string)file_get_contents(MM_COMMANDS_FILE), true) ?: [];
}

// Auth-Schicht (PIN-Sessions, "Gerät merken", Geräteschlüssel) steht
// allen Endpunkten automatisch zur Verfügung.
require_once __DIR__ . '/auth.php';
