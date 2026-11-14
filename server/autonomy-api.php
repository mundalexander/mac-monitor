<?php
/**
 * autonomy-api.php — JSON-API für den Autonomie-Modus (Cockpit, Karte 41).
 *
 * Endpunkte:
 *   GET                                   → {ok, enabled, journal:[...]}
 *   POST {action:"append", key?, ts, event, slot?, card?, action_text?, note?}
 *        → Journal-Eintrag anhängen; `key` (optional) = Deduplizierer —
 *          identische Keys werden ignoriert (Re-Sync des Clients ist billig).
 *   POST {action:"toggle", enabled:true|false} → Master-Schalter setzen.
 *
 * Auth: wie board-api.php — Session-Login (slot-api.php?login=1) ODER
 * Header "X-Monitor-Token: <DASHBOARD_TOKEN>".
 *
 * Speicher: SQLite (DB_PATH) — Tabellen autonomy_journal, autonomy_settings.
 */
require __DIR__ . '/mac-monitor-config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/auth-v2.php';

session_name('MACMON');
@session_set_cookie_params([
    'lifetime' => 2592000,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
@session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function autonomy_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS autonomy_journal (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            ts          INTEGER NOT NULL,
            event       TEXT    NOT NULL,
            slot        TEXT    NOT NULL DEFAULT '',
            card        TEXT    NOT NULL DEFAULT '',
            action_text TEXT    NOT NULL DEFAULT '',
            note        TEXT    NOT NULL DEFAULT '',
            dedup       TEXT    NOT NULL DEFAULT ''
        )
    ");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_autonomy_dedup ON autonomy_journal(dedup) WHERE dedup <> ''");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_autonomy_ts ON autonomy_journal(ts DESC)");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS autonomy_settings (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL
        )
    ");
    $pdo->exec("INSERT OR IGNORE INTO autonomy_settings (k, v) VALUES ('enabled', '1')");
}

function autonomy_enabled(PDO $pdo): bool {
    $stmt = $pdo->query("SELECT v FROM autonomy_settings WHERE k = 'enabled'");
    $v = $stmt->fetchColumn();
    // Achtung: '0' ist in PHP falsy — nicht mit ?: arbeiten.
    return $v === false ? true : $v === '1';
}

function autonomy_clip(string $s, int $max): string {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max);
    return substr($s, 0, $max);
}

// ── Login (identisch zu board-api.php) ─────────────────────────────────
if (isset($_GET['login']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw   = file_get_contents('php://input');
    $data  = $raw ? json_decode($raw, true) : [];
    $given = (string)($data['pin'] ?? $data['dashboard_token'] ?? $_POST['dashboard_token'] ?? '');
    if (!mm_login_allowed()) {
        http_response_code(429);
        echo json_encode(['error' => 'locked']);
        exit;
    }
    $how = mm_login_check($given);
    if ($how !== null) {
        mm_grant_session($how);
        echo json_encode(['ok' => true, 'method' => $how]);
    } else {
        mm_login_fail();
        http_response_code(401);
        echo json_encode(['error' => 'invalid token']);
    }
    exit;
}

// ── Auth: Session ODER X-Monitor-Token ─────────────────────────────────
$hdr = (string)($_SERVER['HTTP_X_MONITOR_TOKEN'] ?? '');
$token_ok = false;
if ($hdr !== '') {
    if (defined('SECRET_TOKEN') && SECRET_TOKEN !== '' && SECRET_TOKEN !== '***'
        && hash_equals(SECRET_TOKEN, $hdr)) {
        $token_ok = true;
    }
    if (!$token_ok) {
        $ds = monitor_secret();
        if ($ds !== '' && hash_equals($ds, $hdr)) $token_ok = true;
    }
}
if (!$token_ok && empty($_SESSION['planner_ok'])) {
    json_response(401, ['error' => 'unauthorized', 'login_required' => true]);
}

$pdo = db();
autonomy_tables($pdo);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $limit = min(max((int)($_GET['limit'] ?? 80), 1), 500);
    $stmt  = $pdo->prepare('SELECT ts, event, slot, card, action_text, note FROM autonomy_journal ORDER BY id DESC LIMIT ?');
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok'      => true,
        'enabled' => autonomy_enabled($pdo),
        'journal' => array_reverse($rows), // chronologisch, neueste zuletzt
    ]);
    exit;
}

if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) json_response(400, ['error' => 'invalid json']);
    $action = (string)($data['action'] ?? '');

    if ($action === 'toggle') {
        $en = !empty($data['enabled']) ? '1' : '0';
        $pdo->prepare("INSERT INTO autonomy_settings (k, v) VALUES ('enabled', ?)
                       ON CONFLICT(k) DO UPDATE SET v = excluded.v")->execute([$en]);
        echo json_encode(['ok' => true, 'enabled' => $en === '1']);
        exit;
    }

    if ($action === 'append') {
        $ts    = (int)($data['ts'] ?? time());
        $event = autonomy_clip((string)($data['event'] ?? 'event'), 40);
        $slot  = autonomy_clip((string)($data['slot'] ?? ''), 60);
        $card  = autonomy_clip((string)($data['card'] ?? ''), 120);
        $atext = autonomy_clip((string)($data['action_text'] ?? ''), 300);
        $note  = autonomy_clip((string)($data['note'] ?? ''), 500);
        $dedup = autonomy_clip((string)($data['key'] ?? ''), 160);
        try {
            $pdo->prepare('INSERT INTO autonomy_journal (ts, event, slot, card, action_text, note, dedup) VALUES (?,?,?,?,?,?,?)')
                ->execute([$ts, $event, $slot, $card, $atext, $note, $dedup]);
            echo json_encode(['ok' => true, 'inserted' => true]);
        } catch (Throwable $e) {
            // Dedup-Kollision = schon vorhanden -> still erfolgreich.
            echo json_encode(['ok' => true, 'inserted' => false]);
        }
        exit;
    }

    json_response(400, ['error' => 'unknown action']);
}

json_response(405, ['error' => 'method not allowed']);
