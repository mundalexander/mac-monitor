<?php
/**
 * auth.php — Dashboard-Zugriffsschutz für mac-monitor.
 *
 * Verwendung:
 *   index.php:  require __DIR__ . '/auth.php'; monitor_gate();        (vor jeglicher Ausgabe)
 *   data.php:   require __DIR__ . '/auth.php'; monitor_gate_api_json();
 *   submit.php: bewusst OFFEN (Client-Auth läuft über SECRET_TOKEN im JSON-Body).
 *
 * Secret: secrets.php im selben Verzeichnis (nicht committet!) definiert
 *   define('DASHBOARD_TOKEN', '...');
 *
 * Zugriff für API-Clients (z. B. Jarvis): Header "X-Monitor-Token: <token>"
 * oder Query "?token=<token>" auf data.php.
 */

if (is_file(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
}

function monitor_secret(): string {
    return defined('DASHBOARD_TOKEN') ? trim((string)constant('DASHBOARD_TOKEN')) : '';
}

/** API-Clients mit Token-Header oder ?token= sind autorisiert. */
function monitor_api_authorized(): bool {
    $secret = monitor_secret();
    if ($secret === '') return false;
    $hdr   = (string)($_SERVER['HTTP_X_MONITOR_TOKEN'] ?? '');
    $q     = isset($_GET['token']) ? (string)$_GET['token'] : '';
    $given = $hdr !== '' ? $hdr : $q;
    return $given !== '' && hash_equals($secret, $given);
}

function monitor_session_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('MACMON');
        @session_set_cookie_params([
            'lifetime' => 2592000,   // 30 Tage
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }
}

function monitor_login_form(string $error = ''): void {
    http_response_code(401);
    $e = $error !== ''
        ? '<p style="color:#f85149;margin:0 0 12px">' . htmlspecialchars($error) . '</p>'
        : '';
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>mac-monitor – Login</title></head>';
    echo '<body style="font-family:system-ui,sans-serif;background:#0f1419;color:#e6edf3;';
    echo 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0">';
    echo '<form method="post" style="background:#1a2027;border:1px solid #2a323d;border-radius:12px;padding:28px;width:min(90vw,340px)">';
    echo '<h2 style="margin:0 0 16px;font-size:18px">mac-monitor</h2>' . $e;
    echo '<input name="dashboard_token" type="password" placeholder="Dashboard-Token" autocomplete="current-password"';
    echo ' style="width:100%;box-sizing:border-box;padding:10px;border-radius:8px;border:1px solid #2a323d;background:#0f1419;color:#e6edf3;margin-bottom:12px">';
    echo '<button type="submit" style="width:100%;padding:10px;border-radius:8px;border:0;background:#58a6ff;color:#0f1419;font-weight:600;cursor:pointer">Login</button>';
    echo '</form></body></html>';
}

/** Gate für HTML-Seiten: Login-Maske statt JSON. */
function monitor_gate(): void {
    if (monitor_api_authorized()) return;
    monitor_session_start();
    if (!empty($_SESSION['macmon_ok'])) return;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['dashboard_token'])) {
        $secret = monitor_secret();
        if ($secret !== '' && hash_equals($secret, (string)$_POST['dashboard_token'])) {
            session_regenerate_id(true);
            $_SESSION['macmon_ok'] = true;
            header('Location: ' . basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'), true, 302);
            exit;
        }
        monitor_login_form('Token falsch.');
        exit;
    }

    if (monitor_secret() === '') {
        http_response_code(500);
        echo 'Konfiguration unvollständig: secrets.php mit DASHBOARD_TOKEN fehlt.';
        exit;
    }
    monitor_login_form();
    exit;
}

/** Gate für JSON-Endpunkte: 401 statt Login-Maske. */
function monitor_gate_api_json(): void {
    if (monitor_api_authorized()) return;
    monitor_session_start();
    if (!empty($_SESSION['macmon_ok'])) return;
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}
