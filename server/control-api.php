<?php
/**
 * control-api.php — Kommandozentrale (1.3).
 *
 * GET                     -> {ok, server_time, machines:{id:{name, online, age_s, state, pending:[...], recent:[...]}}}
 * POST {machine, action, profile?|target?|agent?}
 *      action: profile | stop | free | agent_restart
 *
 * Zugriff: Browser-Session (alle Aktionen) oder Geräteschlüssel
 * (nur profile/stop/free – siehe MM_DEVICE_CONTROL_ACTIONS).
 * Ausführung erfolgt auf dem Gerät durch client/monitor.py → client/control.py.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$how = mm_require_api_access();

$pdo = db();
mm_control_table($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$now = time();

function cc_state(PDO $pdo, string $sid): ?array {
    $st = $pdo->prepare('SELECT ts, data FROM control_state WHERE server_id = ?');
    $st->execute([$sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $data = json_decode((string)$row['data'], true);
    return is_array($data) ? ['ts' => (int)$row['ts'], 'data' => $data] : null;
}

if ($method === 'GET') {
    $cmds = mm_commands_read();
    $ids = array_keys(SERVERS);
    foreach ($pdo->query('SELECT server_id FROM control_state')->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        if (!in_array($sid, $ids, true)) $ids[] = $sid;
    }
    $out = [];
    foreach ($ids as $sid) {
        $st = cc_state($pdo, $sid);
        $mine = array_values(array_filter($cmds, fn($c) => ($c['server_id'] ?? '') === $sid
            && str_starts_with((string)($c['action'] ?? ''), 'control_')));
        $out[$sid] = [
            'name'    => SERVERS[$sid]['name'] ?? $sid,
            'online'  => $st !== null && ($now - $st['ts']) < 45,
            'age_s'   => $st ? $now - $st['ts'] : null,
            'state'   => $st['data'] ?? null,
            'pending' => array_values(array_filter($mine, fn($c) => empty($c['done']))),
            'recent'  => array_slice(array_values(array_filter($mine, fn($c) => !empty($c['done']))), -10),
        ];
    }
    json_response(200, ['ok' => true, 'server_time' => $now, 'machines' => $out]);
}

if ($method !== 'POST') json_response(405, ['error' => 'method not allowed']);
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) json_response(400, ['error' => 'invalid JSON']);

$sid = strtolower(trim((string)($data['machine'] ?? '')));
if (!mm_valid_device_id($sid)) json_response(400, ['error' => 'bad machine']);
$map = ['profile' => 'control_profile', 'stop' => 'control_stop', 'free' => 'control_free',
        'agent_restart' => 'control_agent_restart'];
$action = $map[(string)($data['action'] ?? '')] ?? null;
if ($action === null) json_response(400, ['error' => 'unknown action']);
if ($how === 'device' && !in_array($action, MM_DEVICE_CONTROL_ACTIONS, true)) {
    json_response(403, ['error' => 'forbidden', 'message' => 'Diese Aktion ist nur im Dashboard erlaubt.']);
}

// Ziel gegen den vom Gerät gemeldeten Zustand prüfen – keine freien Kommandos
$st = cc_state($pdo, $sid);
if ($st === null || ($now - $st['ts']) > 120) {
    json_response(409, ['error' => 'offline', 'message' => 'Gerät meldet sich nicht – Monitor-Client läuft nicht oder ist nicht gekoppelt.']);
}
$state = $st['data'];
$params = [];
$profiles = array_column($state['profiles'] ?? [], null, 'id');
$agents = array_column($state['agents'] ?? [], null, 'id');
if ($action === 'control_profile') {
    $p = (string)($data['profile'] ?? '');
    if (!isset($profiles[$p])) json_response(400, ['error' => 'unknown profile']);
    $params['profile'] = $p;
} elseif ($action === 'control_stop') {
    $t = (string)($data['target'] ?? '');
    $engines = array_unique(array_column($state['profiles'] ?? [], 'engine'));
    if (!isset($profiles[$t]) && !in_array($t, $engines, true)) json_response(400, ['error' => 'unknown target']);
    $params['target'] = $t;
} elseif ($action === 'control_agent_restart') {
    $a = (string)($data['agent'] ?? '');
    if (!isset($agents[$a]) || empty($agents[$a]['can_restart'])) json_response(400, ['error' => 'unknown agent']);
    $params['agent'] = $a;
}

$cmd = [
    'id' => bin2hex(random_bytes(8)), 'action' => $action, 'server_id' => $sid,
    'params' => $params, 'ts' => $now, 'done' => false,
    'by' => $how === 'device' ? 'device:' . (mm_device_identity() ?? '?') : 'browser',
];
$res = mm_commands_update(function (array &$cmds) use ($cmd, $sid, $now) {
    foreach ($cmds as $c) {
        if (empty($c['done']) && ($c['server_id'] ?? '') === $sid
            && str_starts_with((string)($c['action'] ?? ''), 'control_') && $now - (int)$c['ts'] < 900) {
            return false;   // nur ein offener Steuerauftrag je Gerät
        }
    }
    $cmds[] = $cmd;
    return true;
});
if (!$res) json_response(409, ['error' => 'busy', 'message' => 'Auf diesem Gerät läuft bereits ein Steuerauftrag.']);
if (!empty($state['busy'])) {
    json_response(202, ['ok' => true, 'id' => $cmd['id'], 'queued' => true,
        'message' => 'Gerät ist beschäftigt – Auftrag wird danach ausgeführt.']);
}
json_response(200, ['ok' => true, 'id' => $cmd['id']]);
