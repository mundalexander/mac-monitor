<?php
/**
 * board-api.php — JSON-API für das Ideen-Board (Kanban).
 *
 * Endpoints:
 *   GET    ?status=idee|arbeit|erledigt   → {ok:true, cards:[...]} (optional gefiltert)
 *   POST   {title, description?, priority?, due_at?, machine?, status?}  → anlegen
 *   POST   {id, ...gleiche Felder}        → teilweises Update
 *   DELETE ?id=N                          → löschen
 *
 * Auth: wie slot-api.php (Session-Login via slot-api.php?login=1),
 * ZUSÄTZLICH Header "X-Monitor-Token: <SECRET_TOKEN>" für Agent-/Skript-Zugriff.
 *
 * Speicher: SQLite-Tabelle `board` in DB_PATH (CREATE TABLE IF NOT EXISTS idempotent).
 */
require __DIR__ . '/mac-monitor-config.php';
require __DIR__ . '/auth.php';

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

// ── Tabelle idempotent anlegen ─────────────────────────────────────────
function board_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS board (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT    NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            priority    TEXT    NOT NULL DEFAULT 'med',
            status      TEXT    NOT NULL DEFAULT 'idee',
            due_at      INTEGER,
            machine     TEXT    NOT NULL DEFAULT '',
            created_at  INTEGER NOT NULL,
            updated_at  INTEGER NOT NULL
        )
    ");
}

/** due_at akzeptiert Unix-Timestamp (int) oder Datums-/Datumzeit-String. */
function parse_due_at($v): ?int {
    if ($v === null || $v === '' || $v === false) return null;
    if (is_int($v) || (is_string($v) && ctype_digit($v))) return (int)$v;
    $t = strtotime((string)$v);
    return $t === false ? null : $t;
}

/** String auf max Länge kürzen (mbstring-frei, UTF-8-sicher über iconv). */
function board_clip(string $s, int $max): string {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max);
    if (function_exists('iconv_strimwidth')) return iconv_strimwidth($s, 0, $max, 'UTF-8');
    return substr($s, 0, $max);
}

// ── Login: Dashboard-Token gegen secrets.php (DASHBOARD_TOKEN) ────────
// Live-Deploy hat keinen slot-api.php-Login (alte Live-Version), daher hier.
if (isset($_GET['login']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw   = file_get_contents('php://input');
    $data  = $raw ? json_decode($raw, true) : [];
    $given = (string)($data['dashboard_token'] ?? $_POST['dashboard_token'] ?? '');
    $ds    = monitor_secret();
    if ($ds !== '' && $given !== '' && hash_equals($ds, $given)) {
        session_regenerate_id(true);
        $_SESSION['planner_ok'] = true;
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'invalid token']);
    }
    exit;
}

// ── Auth: Session ODER X-Monitor-Token ────────────────────────────────
// Akzeptiert SECRET_TOKEN (lokal) ODER DASHBOARD_TOKEN via monitor_secret()
// (live: secrets.php; SECRET_TOKEN in mac-monitor-config.php ist dort '***').
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
board_table($pdo);

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Karten liste ──────────────────────────────────────────────────
if ($method === 'GET') {
    $status = isset($_GET['status']) ? (string)$_GET['status'] : '';
    if ($status !== '') {
        if (!in_array($status, ['idee', 'arbeit', 'erledigt'], true)) {
            json_response(400, ['error' => 'invalid status']);
        }
        $stmt = $pdo->prepare('SELECT * FROM board WHERE status = ? ORDER BY due_at IS NULL, due_at, id DESC');
        $stmt->execute([$status]);
    } else {
        $stmt = $pdo->query('SELECT * FROM board ORDER BY due_at IS NULL, due_at, id DESC');
    }
    json_response(200, ['ok' => true, 'cards' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── POST: anlegen oder aktualisieren ───────────────────────────────────
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        json_response(400, ['error' => 'invalid JSON body']);
    }

    $now  = time();
    $id   = isset($data['id']) ? (int)$data['id'] : 0;
    $fields = [];
    $params = [];

    if (isset($data['title'])) {
        $title = trim((string)$data['title']);
        if ($title === '') json_response(400, ['error' => 'title must not be empty']);
        $fields[] = 'title = ?';       $params[] = board_clip($title, 300);
    }
    if (array_key_exists('description', $data)) {
        $fields[] = 'description = ?'; $params[] = board_clip((string)$data['description'], 4000);
    }
    if (isset($data['priority'])) {
        $p = (string)$data['priority'];
        if (!in_array($p, ['low', 'med', 'high'], true)) json_response(400, ['error' => 'invalid priority']);
        $fields[] = 'priority = ?';    $params[] = $p;
    }
    if (isset($data['status'])) {
        $s = (string)$data['status'];
        if (!in_array($s, ['idee', 'arbeit', 'erledigt'], true)) json_response(400, ['error' => 'invalid status']);
        $fields[] = 'status = ?';      $params[] = $s;
    }
    if (array_key_exists('due_at', $data)) {
        $fields[] = 'due_at = ?';      $params[] = parse_due_at($data['due_at']);
    }
    if (isset($data['machine'])) {
        $fields[] = 'machine = ?';     $params[] = board_clip((string)$data['machine'], 40);
    }

    if ($id > 0) {
        // Update
        if (!$fields) json_response(400, ['error' => 'no fields to update']);
        $fields[] = 'updated_at = ?';  $params[] = $now;
        $params[] = $id;
        $stmt = $pdo->prepare('UPDATE board SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) json_response(404, ['error' => 'card not found']);
        $stmt = $pdo->prepare('SELECT * FROM board WHERE id = ?');
        $stmt->execute([$id]);
        json_response(200, ['ok' => true, 'card' => $stmt->fetch(PDO::FETCH_ASSOC)]);
    }

    // Insert
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') json_response(400, ['error' => 'title is required']);
    $stmt = $pdo->prepare('
        INSERT INTO board (title, description, priority, status, due_at, machine, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $insPriority = in_array($p = (string)($data['priority'] ?? 'med'), ['low','med','high'], true) ? $p : 'med';
    $insStatus   = in_array($s = (string)($data['status'] ?? 'idee'), ['idee','arbeit','erledigt'], true) ? $s : 'idee';
    $stmt->execute([
        board_clip($title, 300),
        board_clip((string)($data['description'] ?? ''), 4000),
        $insPriority,
        $insStatus,
        parse_due_at($data['due_at'] ?? null),
        board_clip((string)($data['machine'] ?? ''), 40),
        $now,
        $now,
    ]);
    $newId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM board WHERE id = ?');
    $stmt->execute([$newId]);
    json_response(201, ['ok' => true, 'card' => $stmt->fetch(PDO::FETCH_ASSOC)]);
}

// ── DELETE ─────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) json_response(400, ['error' => 'id required']);
    $stmt = $pdo->prepare('DELETE FROM board WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_response(404, ['error' => 'card not found']);
    json_response(200, ['ok' => true]);
}

json_response(405, ['error' => 'method not allowed']);
