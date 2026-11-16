<?php
/**
 * unload-request.php — Dashboard -> Command-Queue.
 *
 * Seit 1.2.0 nur noch mit angemeldetem Browser (PIN / gemerktes Gerät).
 * Vorher war dieser Endpunkt offen: jeder konnte Modelle entladen oder
 * Halogen stoppen.
 *
 * POST {action, server_id, model_id?}
 */
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(405, ['error' => 'POST required']);
mm_require_session();

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) json_response(400, ['error' => 'invalid JSON']);

$action = (string)($data['action'] ?? 'lm_studio_unload');
if (!in_array($action, MM_COMMAND_ACTIONS, true)) json_response(400, ['error' => 'unknown action']);

$serverId = trim((string)($data['server_id'] ?? 'mac'));
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
try {
    mm_commands_update(function (array &$cmds) use ($cmd) { $cmds[] = $cmd; return true; });
} catch (Throwable $e) {
    json_response(500, ['error' => 'cannot write command file']);
}
json_response(200, ['ok' => true, 'id' => $cmd['id'], 'action' => $action, 'server_id' => $serverId]);
