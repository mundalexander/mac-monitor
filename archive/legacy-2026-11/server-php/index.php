<?php
/** Dashboard-Einstieg der PHP-Variante. Liefert das identische Frontend aus. */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/dashboard/index.html');
