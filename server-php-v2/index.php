<?php
/** Dashboard-Einstieg der PHP-Variante v2. Liefert das Frontend aus. */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
readfile(__DIR__ . '/dashboard/index.html');