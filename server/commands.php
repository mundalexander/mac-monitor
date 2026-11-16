<?php
/**
 * commands.php — Command-Queue für Steuerbefehle (z. B. Modell entladen).
 *
 * GET   (Gerät)   -> {commands:[...]}   nur offene Befehle für DIESES Gerät
 * POST  (Gerät)   {id, done:true, success}        -> Befehl als erledigt markieren
 * POST  (Browser) {action, server_id, model_id?}  -> neuen Befehl anlegen
 *
 * Geräte authentifizieren sich per Geräteschlüssel, Browser per Session.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $deviceId = mm_device_require(['token' => (string)($_GET['token'] ?? '')]);
    $pending = array_values(array_filter(mm_commands_read(), function ($c) use ($deviceId) {
        if (!empty($c['done'])) return false;
        return $deviceId === 'legacy' || ($c['server_id'] ?? '') === $deviceId;
    }));
    json_response(200, ['commands' => $pending]);
}

if ($method !== 'POST') json_response(405, ['error' => 'method not allowed']);

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) json_response(400, ['error' => 'invalid JSON']);

// 1) Gerät meldet Erledigung
if (isset($data['id']) && !empty($data['done'])) {
    $deviceId = mm_device_require($data);
    $id = (string)$data['id'];
    $found = mm_commands_update(function (array &$cmds) use ($id, $deviceId, $data) {
        foreach ($cmds as &$c) {
            if (($c['id'] ?? '') !== $id) continue;
            if ($deviceId !== 'legacy' && ($c['server_id'] ?? '') !== $deviceId) return false;
            $c['done']    = true;
            $c['done_at'] = time();
            $c['success'] = !empty($data['success']);
            return true;
        }
        return false;
    });
    json_response($found ? 200 : 404, ['ok' => $found, 'id' => $id]);
}

// 2) Neuer Befehl — nur aus angemeldetem Browser
mm_require_session();
$action = (string)($data['action'] ?? '');
if (!in_array($action, MM_COMMAND_ACTIONS, true)) json_response(400, ['error' => 'unknown action']);
$serverId = trim((string)($data['server_id'] ?? ''));
if (!mm_valid_device_id($serverId)) json_response(400, ['error' => 'bad server_id']);
$modelId = isset($data['model_id']) ? trim((string)$data['model_id']) : null;
if ($modelId !== null && $modelId !== '' && !preg_match('/^[A-Za-z0-9._\/:\-]{1,120}$/', $modelId)) {
    json_response(400, ['error' => 'bad model_id']);
}
$cmd = [
    'id'        => bin2hex(random_bytes(8)),
    'action'    => $action,
    'server_id' => $serverId,
    'model_id'  => $modelId ?: null,
    'ts'        => time(),
    'done'      => false,
];
mm_commands_update(function (array &$cmds) use ($cmd) { $cmds[] = $cmd; return true; });
json_response(200, ['ok' => true, 'id' => $cmd['id']]);
