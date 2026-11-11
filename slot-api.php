<?php
/**
 * slot-api.php — Proxy für slots.php.
 *
 * Kein Login mehr (Owner-Entscheidung 2026-10-03): Der Browser bekommt das
 * SECRET_TOKEN nie zu sehen. Der Proxy setzt den Token serverseitig und
 * reicht die Anfrage an slots.php weiter.
 *
 * Endpoints (alle offen):
 *   GET    ?from=..&to=..        → Slot-Liste
 *   POST   {machine, ...}        → buchen
 *   DELETE ?id=..                → löschen
 *   POST   ?id=..%2Fextend      → verlängern
 */
require_once __DIR__ . '/mac-monitor-config.php';

header('Content-Type: application/json; charset=utf-8');

// ── Proxy: SECRET_TOKEN serverseitig setzen und slots.php includen ───────
$_SERVER['HTTP_AUTHORIZATION'] = SECRET_TOKEN;

// Für GET-Requests: Token auch in Query setzen (slots.php prüft Header zuerst)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_GET['token'] = SECRET_TOKEN;
}

require __DIR__ . '/slots.php';
