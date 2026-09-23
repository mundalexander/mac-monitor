<?php
/**
 * slots.php — Slot booking REST API.
 *
 * Endpoints:
 *   GET  ?from=ts&to=ts[&machine=evo-x3]
 *   POST {machine, model, start_unix, end_unix, owner, task, tags}
 *   DELETE ?id=slot_id
 *   POST ?id=slot_id/extend {new_end_unix}
 */
require __DIR__ . '/mac-monitor-config.php';

function slots_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS slots (
                id          TEXT    PRIMARY KEY,
                machine     TEXT    NOT NULL,
                model       TEXT    NOT NULL DEFAULT '',
                start_unix  INTEGER NOT NULL,
                end_unix    INTEGER NOT NULL,
                owner       TEXT    NOT NULL,
                task        TEXT,
                tags        TEXT    NOT NULL DEFAULT '',
                extended    INTEGER DEFAULT 0,
                created_at  INTEGER NOT NULL
            )
        ");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_slots_machine_start ON slots(machine, start_unix)');
        // Migration: add model + tags if missing from older schema
        try { $pdo->exec("ALTER TABLE slots ADD COLUMN model TEXT NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE slots ADD COLUMN tags TEXT NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
    }
    return $pdo;
}

// ── Auth ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}
if (!hash_equals(SECRET_TOKEN, (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $token = $_GET['token'] ?? '';
        if (!hash_equals(SECRET_TOKEN, $token)) {
            json_response(401, ['error' => 'unauthorized']);
        }
    } else {
        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : [];
        if (!is_array($data) || !hash_equals(SECRET_TOKEN, (string)($data['token'] ?? ''))) {
            json_response(401, ['error' => 'unauthorized']);
        }
    }
}

// ── Routing ─────────────────────────────────────────────────────────────
$pdo = slots_db();
$method = $_SERVER['REQUEST_METHOD'];

// DELETE ?id=slot_id
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) { json_response(400, ['error' => 'missing id']); }
    $stmt = $pdo->prepare('DELETE FROM slots WHERE id = :id');
    $stmt->execute([':id' => $id]);
    json_response(200, ['ok' => true]);
}

// POST ?id=slot_id/extend
if ($method === 'POST' && isset($_GET['id']) && str_ends_with($_GET['id'], '/extend')) {
    $id = str_replace('/extend', '', $_GET['id']);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    $newEnd = isset($data['new_end_unix']) ? (int)$data['new_end_unix'] : 0;
    if (!$newEnd) { json_response(400, ['error' => 'missing new_end_unix']); }
    $stmt = $pdo->prepare('SELECT * FROM slots WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slot) { json_response(404, ['error' => 'slot not found']); }
    if ($slot['extended'] >= 3) { json_response(409, ['error' => 'max extensions reached (3)']); }
    $stmt2 = $pdo->prepare('SELECT id FROM slots WHERE machine = :m AND start_unix = :end AND id != :id LIMIT 1');
    $stmt2->execute([':m' => $slot['machine'], ':end' => $newEnd, ':id' => $id]);
    if ($stmt2->fetch()) { json_response(409, ['error' => 'extension collides with next slot']); }
    $stmt3 = $pdo->prepare('UPDATE slots SET end_unix = :e, extended = extended + 1 WHERE id = :id');
    $stmt3->execute([':e' => $newEnd, ':id' => $id]);
    json_response(200, ['ok' => true, 'extended' => (int)$slot['extended'] + 1]);
}

// POST — create slot
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    $machine   = trim($data['machine'] ?? '');
    $model     = trim($data['model'] ?? '');
    $startUnix = isset($data['start_unix']) ? (int)$data['start_unix'] : 0;
    $endUnix   = isset($data['end_unix'])   ? (int)$data['end_unix']   : 0;
    $owner     = trim($data['owner'] ?? '');
    $task      = trim($data['task'] ?? '');
    $tagsRaw   = isset($data['tags']) && is_array($data['tags']) ? $data['tags'] : [];
    $tags      = implode(',', array_filter(array_map('trim', $tagsRaw)));

    if (!$machine || !$startUnix || !$endUnix || !$owner) {
        json_response(400, ['error' => 'missing required fields: machine, start_unix, end_unix, owner']);
    }
    if ($startUnix >= $endUnix) {
        json_response(400, ['error' => 'start must be before end']);
    }
    $id = "{$machine}:{$startUnix}";
    $stmt = $pdo->prepare('SELECT id FROM slots WHERE machine = :m AND end_unix > :s AND start_unix < :e LIMIT 1');
    $stmt->execute([':m' => $machine, ':s' => $startUnix, ':e' => $endUnix]);
    if ($stmt->fetch()) { json_response(409, ['error' => 'slot already booked', 'conflict' => true]); }
    $insert = $pdo->prepare('INSERT INTO slots (id, machine, model, start_unix, end_unix, owner, task, tags, extended, created_at) VALUES (:id, :m, :mdl, :s, :e, :o, :t, :tg, 0, :c)');
    $insert->execute([
        ':id'  => $id,
        ':m'   => $machine,
        ':mdl' => $model,
        ':s'   => $startUnix,
        ':e'   => $endUnix,
        ':o'   => $owner,
        ':t'   => $task,
        ':tg'  => $tags,
        ':c'   => time(),
    ]);
    json_response(200, ['ok' => true, 'id' => $id]);
}

// GET — list slots
if ($method === 'GET') {
    $from    = isset($_GET['from']) ? (int)$_GET['from'] : 0;
    $to      = isset($_GET['to'])   ? (int)$_GET['to']   : time() + 86400;
    $machine = isset($_GET['machine']) ? trim($_GET['machine']) : null;

    if ($from <= 0) { json_response(400, ['error' => 'missing or invalid from timestamp']); }

    $sql = 'SELECT * FROM slots WHERE start_unix < :to AND end_unix > :from';
    $params = [':from' => $from, ':to' => $to];
    if ($machine) { $sql .= ' AND machine = :m'; $params[':m'] = $machine; }
    $sql .= ' ORDER BY machine, start_unix ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $slots = array_map(fn($r) => [
        'id'         => $r['id'],
        'machine'    => $r['machine'],
        'model'      => $r['model'] ?? '',
        'start_unix' => (int)$r['start_unix'],
        'end_unix'   => (int)$r['end_unix'],
        'owner'      => $r['owner'],
        'task'       => $r['task'],
        'tags'       => $r['tags'] ? explode(',', $r['tags']) : [],
        'extended'   => (int)($r['extended'] ?? 0),
        'created_at' => (int)$r['created_at'],
    ], $rows);
    json_response(200, ['slots' => $slots]);
}

json_response(405, ['error' => 'method not allowed']);
