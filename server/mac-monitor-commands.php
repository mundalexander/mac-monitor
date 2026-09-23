<?php
/**
 * commands.php — Command queue for remote actions.
 * EVO pollt dieses File, Client (Monitor) schreibt Befehle rein.
 *
 * GET  → gibt alle unbearbeiteten Commands zurück
 * POST → Client schreibt neuen Command (token auth)
 *
 * Command-Format: { "id": "uuid", "action": "lm_studio_unload", "server_id": "evo-x3", "ts": 1234567890, "done": false }
 */
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$COMMANDS_FILE = __DIR__ . '/commands.json';

// Load commands
function load_commands() {
    global $COMMANDS_FILE;
    if (!file_exists($COMMANDS_FILE)) return [];
    $content = file_get_contents($COMMANDS_FILE);
    return json_decode($content, true) ?: [];
}

// Save commands
function save_commands(array $cmds) {
    global $COMMANDS_FILE;
    file_put_contents($COMMANDS_FILE, json_encode($cmds, JSON_PRETTY_PRINT), LOCK_EX);
}

// GET: EVO pollt – gibt alle nicht-bearbeiteten Commands zurück
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cmds = load_commands();
    $pending = array_filter($cmds, fn($c) => !($c['done'] ?? false));
    echo json_encode(['commands' => array_values($pending)]);
    exit;
}

// POST: Client schreibt Command ODER markiert als done
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid JSON']);
        exit;
    }

    // Token auth
    if (!hash_equals(SECRET_TOKEN, (string)($data['token'] ?? ''))) {
        http_response_code(401);
        echo json_encode(['error' => 'bad token']);
        exit;
    }

    $cmds = load_commands();

    // Mark existing command as done?
    if (isset($data['id']) && isset($data['done']) && $data['done']) {
        $found = false;
        foreach ($cmds as &$cmd) {
            if ($cmd['id'] === $data['id']) {
                $cmd['done'] = true;
                $cmd['done_at'] = time();
                $found = true;
                break;
            }
        }
        unset($cmd);
        if ($found) {
            save_commands($cmds);
            http_response_code(200);
            echo json_encode(['ok' => true, 'id' => $data['id']]);
            exit;
        }
    }

    // Otherwise: create new command
    $action = $data['action'] ?? '';
    if (!in_array($action, ['lm_studio_unload', 'ollama_unload'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'unknown action']);
        exit;
    }

    $cmd = [
        'id'       => $data['id'] ?? bin2hex(random_bytes(8)),
        'action'   => $action,
        'server_id' => $data['server_id'] ?? 'evo-x3',
        'model_id'  => $data['model_id'] ?? null,
        'ts'       => time(),
        'done'     => false,
    ];
    $cmds[] = $cmd;
    save_commands($cmds);

    http_response_code(200);
    echo json_encode(['ok' => true, 'id' => $cmd['id']]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
