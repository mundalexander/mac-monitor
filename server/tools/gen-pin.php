<?php
/**
 * gen-pin.php — CLI-Helfer: PIN-Hash für secrets.php erzeugen.
 *
 * Usage:  php tools/gen-pin.php 4711
 * Output: define-Zeile zum Einfügen in secrets.php (live NICHT committen!).
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur im CLI ausfuehren.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/auth-v2.php';

$pin = $argv[1] ?? '';
if ($pin === '' || preg_match('/^\d{6,8}$/', $pin) !== 1) {
    fwrite(STDERR, "Usage: php tools/gen-pin.php <PIN>\n");
    fwrite(STDERR, "PIN muss 6-8 Ziffern haben (nur numerisch).\n");
    exit(1);
}
$hash = mm_pin_hash($pin);
fwrite(STDOUT, "PIN-Hash erzeugt. In secrets.php einfügen (Vorlage: secrets.example.php):\n\n");
fwrite(STDOUT, "define('LOGIN_PIN_HASH', '{$hash}');\n");
