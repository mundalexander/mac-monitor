<?php
/**
 * slot-api.php — Session-gated Zugang zu slots.php für Kalender & Planner.
 *
 *   POST ?login=1   {pin, remember?} → Session setzen (Kompatibilität; bevorzugt auth-api.php)
 *   POST ?logout=1                   → Session beenden
 *   GET/POST/DELETE                  → slots.php (Liste, buchen, löschen, verlängern)
 *
 * Seit 1.2.0 ohne Token-Proxy: slots.php prüft selbst Session bzw. Geräteschlüssel.
 */
require_once __DIR__ . '/config.php';

mm_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Login (PIN) ─────────────────────────────────────────────────────────
if (isset($_GET['login']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!mm_login_allowed()) json_response(429, ['error' => 'locked']);
    $data  = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $how   = mm_login_check((string)($data['pin'] ?? ''));
    if ($how === null) {
        mm_login_fail();
        json_response(401, ['error' => 'invalid', 'message' => 'PIN falsch.']);
    }
    mm_grant_session($how);
    if (!empty($data['remember'])) mm_remember_issue();
    json_response(200, ['ok' => true, 'method' => $how]);
}

// ── Logout ──────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    mm_remember_clear();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    json_response(200, ['ok' => true]);
}

// ── Gate + Weiterleitung ────────────────────────────────────────────────
mm_require_api_access();
require_once __DIR__ . '/slots.php';
