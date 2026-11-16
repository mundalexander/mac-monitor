<?php
/**
 * auth-api.php — zentraler Auth-Endpunkt für Kalender & Ideen-Board.
 *
 * Login: Passkey oder PIN. Seit 1.2.0 kein Token-Login mehr;
 * Maschinen nutzen Geräteschlüssel (pair.php).
 *
 * Endpoints (alle JSON):
 *   GET  ?status=1                       → {ok, authed, pinConfigured, passkeyCount, webauthnSupported*}
 *   POST ?login=1        {pin, remember}            → Session (+ Gerät merken)
 *   POST ?logout=1                       → Session beenden
 *   POST ?passkey-register-begin   (Session nötig)  → create()-Payload
 *   POST ?passkey-register-finish  (Session nötig)  {credential:{...}}
 *   POST ?passkey-assert-begin                      → get()-Payload
 *   POST ?passkey-assert-finish    {credential:{...}} → Session setzen
 *   POST ?passkey-list     (Session nötig)          → [{id,label,created}]
 *   POST ?passkey-delete   (Session nötig)          {id}
 *
 * * webauthnSupported ist hier serverseitig immer true, wenn PHP openssl
 *   verfügbar ist; der Browser prüft zusätzlich (window.PublicKeyCredential).
 */
require_once __DIR__ . '/config.php';

mm_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mm_auth_out(int $code, array $payload): never {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function mm_auth_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function mm_auth_require_session(): void {
    if (!mm_is_authed()) {
        mm_auth_out(401, ['error' => 'unauthorized', 'login_required' => true]);
    }
}

/**
 * Browser-Response (navigator.credentials.create/get) → Felder.
 * clientDataJSON/attestationObject kommen als base64url-Strings an
 * (Frontend konvertiert ArrayBuffer → base64url, siehe ui.js).
 */
function mm_auth_credential_fields(array $data): array {
    $cred = $data['credential'] ?? [];
    if (!is_array($cred)) return [];
    $resp = $cred['response'] ?? [];
    if (!is_array($resp)) return [];
    return [
        'id'         => (string)($cred['id'] ?? ''),
        'clientData' => (string)($resp['clientDataJSON'] ?? ''),
        'attObj'     => (string)($resp['attestationObject'] ?? ''),
        'authData'   => (string)($resp['authenticatorData'] ?? ''),
        'sig'        => (string)($resp['signature'] ?? ''),
    ];
}

$cfg    = mm_auth_config();
$action = ($_GET['status'] ?? null) !== null ? 'status' : '';
foreach (['login', 'logout', 'passkey-register-begin', 'passkey-register-finish',
          'passkey-assert-begin', 'passkey-assert-finish', 'passkey-list', 'passkey-delete'] as $a) {
    if (isset($_GET[$a])) { $action = $a; break; }
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {

        case 'status':
            mm_auth_out(200, [
                'ok'              => true,
                'authed'          => mm_is_authed(),
                'method'          => $_SESSION['mm_auth'] ?? null,
                'pinConfigured'   => $cfg['pin_hash'] !== '',
                'passkeyCount'    => count(MMWebAuthn::loadCreds($cfg)),
                'webauthnSupported' => function_exists('openssl_verify'),
                'rpId'            => $cfg['rp_id'],
            ]);

        case 'login': {
            if ($method !== 'POST') mm_auth_out(405, ['error' => 'POST required']);
            if (!mm_login_allowed()) {
                mm_auth_out(429, ['error' => 'locked', 'message' => 'Zu viele Fehlversuche – später erneut versuchen.']);
            }
            $data  = mm_auth_body();
            $given = (string)($data['pin'] ?? '');
            $how   = mm_login_check($given);
            if ($how === null) {
                mm_login_fail();
                mm_auth_out(401, ['error' => 'invalid', 'message' => 'PIN falsch.']);
            }
            mm_grant_session($how);
            // "Gerät merken": HMAC-Cookie (90 Tage) nur nach ausdrücklicher Wahl.
            if (!empty($data['remember'])) mm_remember_issue();
            mm_ip_clear();
            mm_auth_out(200, ['ok' => true, 'method' => $how]);
        }

        case 'logout':
            mm_remember_clear();
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
            mm_auth_out(200, ['ok' => true]);

        case 'passkey-register-begin':
            if ($method !== 'POST') mm_auth_out(405, ['error' => 'POST required']);
            mm_auth_require_session();
            mm_auth_out(200, MMWebAuthn::registerBegin($cfg));

        case 'passkey-register-finish': {
            if ($method !== 'POST') mm_auth_out(405, ['error' => 'POST required']);
            mm_auth_require_session();
            $data = mm_auth_body();
            $f    = mm_auth_credential_fields($data);
            if ($f['id'] === '' || $f['clientData'] === '' || $f['attObj'] === '') {
                mm_auth_out(400, ['error' => 'credential incomplete']);
            }
            $info = MMWebAuthn::registerFinish($cfg, $f['clientData'], $f['attObj'], (string)($data['label'] ?? ''));
            mm_auth_out(200, ['ok' => true] + $info);
        }

        case 'passkey-assert-begin':
            if ($method !== 'POST') mm_auth_out(405, ['error' => 'POST required']);
            if (!mm_login_allowed()) {
                mm_auth_out(429, ['error' => 'locked', 'message' => 'Zu viele Fehlversuche – später erneut versuchen.']);
            }
            mm_auth_out(200, MMWebAuthn::assertBegin($cfg));

        case 'passkey-assert-finish': {
            if ($method !== 'POST') mm_auth_out(405, ['error' => 'POST required']);
            if (!mm_login_allowed()) {
                mm_auth_out(429, ['error' => 'locked', 'message' => 'Zu viele Fehlversuche – später erneut versuchen.']);
            }
            $data = mm_auth_body();
            $f    = mm_auth_credential_fields($data);
            if ($f['id'] === '' || $f['clientData'] === '' || $f['authData'] === '' || $f['sig'] === '') {
                mm_auth_out(400, ['error' => 'credential incomplete']);
            }
            try {
                $ok = MMWebAuthn::assertFinish($cfg, $f['id'], $f['clientData'], $f['authData'], $f['sig']);
            } catch (Throwable $e) {
                mm_login_fail();
                error_log('fleet-monitor passkey: ' . $e->getMessage());
                mm_auth_out(401, ['error' => 'assertion_failed']);
            }
            if (!$ok) mm_auth_out(401, ['error' => 'assertion_failed']);
            mm_grant_session('passkey');
            if (!empty($data['remember'] ?? 1)) mm_remember_issue();
            mm_auth_out(200, ['ok' => true, 'method' => 'passkey']);
        }

        case 'passkey-list':
            if ($method !== 'POST') mm_auth_out(405, ['error' => 'POST required']);
            mm_auth_require_session();
            $list = array_map(
                fn($c) => ['id' => $c['id'], 'label' => $c['label'] ?? 'Passkey', 'created' => $c['created'] ?? 0],
                MMWebAuthn::loadCreds($cfg)
            );
            mm_auth_out(200, ['ok' => true, 'passkeys' => $list]);

        case 'passkey-delete': {
            if ($method !== 'POST') mm_auth_out(405, ['error' => 'POST required']);
            mm_auth_require_session();
            $data = mm_auth_body();
            $id   = (string)($data['id'] ?? '');
            $gone = $id !== '' && MMWebAuthn::deleteCred($cfg, $id);
            mm_auth_out($gone ? 200 : 404, ['ok' => $gone]);
        }

        default:
            mm_auth_out(404, ['error' => 'unknown action']);
    }
} catch (Throwable $e) {
    error_log('fleet-monitor auth: ' . $e->getMessage());
    mm_auth_out(500, ['error' => 'server_error']);
}
