<?php
/**
 * devices-api.php — Geräteverwaltung (nur mit Browser-Session).
 *
 * GET                       -> {ok, machines:[...], browsers:[...], current_browser}
 * POST {action:"revoke_machine", id}
 * POST {action:"revoke_browser", id}
 * POST {action:"rename_browser", id, label}
 * POST {action:"remember_this"}          -> aktuellen Browser merken (90 Tage)
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
mm_require_session();

$pdo = mm_auth_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (empty($_SESSION['mm_browser_id'])) mm_remember_check();
    $machines = $pdo->query(
        'SELECT id, name, platform, created_at, last_seen, last_ip, revoked FROM auth_devices ORDER BY revoked, id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $browsers = $pdo->query(
        'SELECT id, label, created_at, last_used, expires_at, revoked FROM auth_browsers
         WHERE revoked = 0 AND expires_at > ' . time() . ' ORDER BY last_used DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($machines as &$m) { $m['revoked'] = (int)$m['revoked'] === 1; $m['known'] = isset(SERVERS[$m['id']]); }
    unset($m);
    json_response(200, [
        'ok'              => true,
        'machines'        => $machines,
        'browsers'        => $browsers,
        'current_browser' => $_SESSION['mm_browser_id'] ?? null,
    ]);
}

if ($method !== 'POST') json_response(405, ['error' => 'method not allowed']);

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) json_response(400, ['error' => 'invalid JSON']);
$id = (string)($data['id'] ?? '');

switch ((string)($data['action'] ?? '')) {
    case 'revoke_machine':
        $st = $pdo->prepare('UPDATE auth_devices SET revoked = 1 WHERE id = ?');
        $st->execute([$id]);
        json_response($st->rowCount() ? 200 : 404, ['ok' => $st->rowCount() > 0]);
    case 'revoke_browser':
        $st = $pdo->prepare('UPDATE auth_browsers SET revoked = 1 WHERE id = ?');
        $st->execute([$id]);
        json_response($st->rowCount() ? 200 : 404, ['ok' => $st->rowCount() > 0]);
    case 'remember_this':
        if (!empty($_SESSION['mm_browser_id']) && mm_remember_check()) {
            json_response(200, ['ok' => true, 'already' => true]);
        }
        mm_remember_issue();
        json_response(200, ['ok' => !empty($_SESSION['mm_browser_id'])]);
    case 'rename_browser':
        $label = substr(trim((string)($data['label'] ?? '')), 0, 60);
        if ($label === '') json_response(400, ['error' => 'label required']);
        $st = $pdo->prepare('UPDATE auth_browsers SET label = ? WHERE id = ?');
        $st->execute([$label, $id]);
        json_response($st->rowCount() ? 200 : 404, ['ok' => $st->rowCount() > 0]);
    default:
        json_response(400, ['error' => 'unknown action']);
}
