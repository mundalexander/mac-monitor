<?php
/**
 * slot-api.php — Session-gated proxy for slots.php.
 *
 * Authentifizierung läuft über DASHBOARD_TOKEN (Session), nicht über
 * SECRET_TOKEN im Client-JS. Der Browser bekommt SECRET_TOKEN nie.
 *
 * Endpoints:
 *   POST ?login=1           {dashboard_token: "..."} → Session setzen
 *   POST ?logout=1          → Session löschen
 *   GET  ?from=..&to=..     → slots.php proxy (Liste)
 *   POST {machine, ...}     → slots.php proxy (buchen)
 *   DELETE ?id=..           → slots.php proxy (löschen)
 *   POST ?id=..%2Fextend    → slots.php proxy (verlängern)
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

// ── Login ───────────────────────────────────────────────────────────────
if (isset($_GET['login']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw   = file_get_contents('php://input');
    $data  = $raw ? json_decode($raw, true) : [];
    $given = (string)($data['dashboard_token'] ?? $_POST['dashboard_token'] ?? '');
    $secret = monitor_secret();
    if ($secret !== '' && $given !== '' && hash_equals($secret, $given)) {
        session_regenerate_id(true);
        $_SESSION['planner_ok'] = true;
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'invalid token']);
    }
    exit;
}

// ── Logout ──────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

// ── Session-Gate ────────────────────────────────────────────────────────
if (empty($_SESSION['planner_ok'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized', 'login_required' => true]);
    exit;
}

// ── Proxy: SECRET_TOKEN setzen und slots.php includen ───────────────────
$_SERVER['HTTP_AUTHORIZATION'] = SECRET_TOKEN;

// Für GET-Requests: Token auch in Query setzen (slots.php prüft Header zuerst)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_GET['token'] = SECRET_TOKEN;
}

require __DIR__ . '/slots.php';