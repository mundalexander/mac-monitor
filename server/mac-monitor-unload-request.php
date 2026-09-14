<?php
/**
 * unload-request.php — Dashboard → Command-Queue.
 * Wird vom Frontend OHNE Token aufgerufen (Dashboard ist per Owner-Entscheidung öffentlich);
 * der Secret-Token bleibt server-seitig in config.php.
 * Schreibt einen Unload-Command in commands.json; der Monitor-Client
 * pollt commands.php und führt den Befehl lokal aus (z.B. LM Studio unload).
 *
 * POST body: JSON { "action": "lm_studio_unload", "server_id": "mac", "model_id": "..." }
 */
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'POST required']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    json_response(400, ['error' => 'invalid JSON']);
}

$action = $data['action'] ?? 'lm_studio_unload';
if (!in_array($action, ['lm_studio_unload', 'ollama_unload'], true)) {
    json_response(400, ['error' => 'unknown action']);
}

// Server-ID validieren (bekannt aus der Registry, Default 'mac')
$serverId = isset($data['server_id']) ? trim((string)$data['server_id']) : 'mac';
if (!isset(SERVERS[$serverId])) {
    $serverId = 'mac';
}

// Model-ID auf sicheres Muster prüfen (kein Müll in die Queue)
$modelId = isset($data['model_id']) ? trim((string)$data['model_id']) : null;
if ($modelId !== null && $modelId !== '' && !preg_match('/^[A-Za-z0-9._\/:\-]{1,120}$/', $modelId)) {
    json_response(400, ['error' => 'bad model_id']);
}

$COMMANDS_FILE = __DIR__ . '/commands.json';
$cmds = file_exists($COMMANDS_FILE) ? (json_decode(file_get_contents($COMMANDS_FILE), true) ?: []) : [];

// Alte erledigte Commands aufräumen (letzte 50 behalten)
$cmds = array_values(array_filter($cmds, fn($c) => !($c['done'] ?? false)));
if (count($cmds) > 50) {
    $cmds = array_slice($cmds, -50);
}

$cmds[] = [
    'id'        => bin2hex(random_bytes(8)),
    'action'    => $action,
    'server_id' => $serverId,
    'model_id'  => $modelId ?: null,
    'ts'        => time(),
    'done'      => false,
];

if (file_put_contents($COMMANDS_FILE, json_encode($cmds, JSON_PRETTY_PRINT), LOCK_EX) === false) {
    json_response(500, ['error' => 'cannot write command file']);
}

json_response(200, ['ok' => true, 'server_id' => $serverId]);