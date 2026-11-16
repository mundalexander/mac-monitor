<?php
/**
 * pair.php — einmalige Kopplung einer Maschine per PIN.
 *
 * POST {pin, machine_id, name?, platform?}
 *   -> 200 {ok, machine_id, device_key}
 *
 * Der Geräteschlüssel wird nur in dieser Antwort im Klartext übertragen
 * und serverseitig ausschließlich als SHA-256-Hash gespeichert.
 * Erneutes Koppeln derselben machine_id ersetzt den alten Schlüssel.
 * Aufruf normalerweise über:  python3 client/monitor.py --pair
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(405, ['error' => 'POST required']);
}
// HTTPS erzwingt der Client (monitor.py --pair lehnt http:// ab, außer localhost).
if (!mm_ip_allowed()) {
    json_response(429, ['error' => 'locked', 'message' => 'Zu viele Fehlversuche – in 15 Minuten erneut versuchen.']);
}

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) json_response(400, ['error' => 'invalid JSON']);

$pin      = trim((string)($data['pin'] ?? ''));
$id       = strtolower(trim((string)($data['machine_id'] ?? '')));
$name     = trim((string)($data['name'] ?? $id));
$platform = trim((string)($data['platform'] ?? ''));

if (!mm_valid_device_id($id)) {
    json_response(400, ['error' => 'bad_machine_id',
        'message' => 'machine_id: 2-40 Zeichen, a-z, 0-9, Bindestrich, Unterstrich.']);
}
if (mm_login_check($pin) !== 'pin') {
    mm_ip_fail();
    usleep(400000);
    json_response(401, ['error' => 'invalid_pin', 'message' => 'PIN falsch.']);
}
mm_ip_clear();

$name     = substr(preg_replace('/[^\pL\pN .,_()\-]/u', '', $name) ?? $id, 0, 80) ?: $id;
$platform = substr(preg_replace('/[^a-zA-Z0-9 ._-]/', '', $platform) ?? '', 0, 40);

try {
    $key = mm_device_issue($id, $name, $platform);
} catch (Throwable $e) {
    json_response(500, ['error' => 'db_error']);
}
json_response(200, [
    'ok'         => true,
    'machine_id' => $id,
    'device_key' => $key,
    'known'      => isset(SERVERS[$id]),
]);
