<?php
/**
 * secrets.example.php -> als secrets.php ins Server-Verzeichnis kopieren
 * (NICHT ins Repository einchecken, .htaccess sperrt den Direktabruf).
 *
 * Einziger geheimer Wert ist der PIN-Hash. Erzeugen mit:
 *     php tools/gen-pin.php 482913
 */
define('LOGIN_PIN_HASH', 'pbkdf2$sha256$150000$...$...');

// Optional: Passkeys (Touch ID / Face ID / Windows Hello)
// define('WEBAUTHN_RP_ID',   'mund.bplaced.net');
// define('WEBAUTHN_RP_NAME', 'Fleet Monitor');

// Optional: Datenbank außerhalb des Web-Verzeichnisses
// define('MM_DB_PATH', '/www/data/fleet-monitor.sqlite');

// NUR für die Umstellung von 1.1 auf 1.2: Clients mit altem Token werden
// noch angenommen, bis sie per PIN gekoppelt sind. Danach Zeile LÖSCHEN.
// define('LEGACY_CLIENT_TOKEN', 'alter-token-aus-client-config');
