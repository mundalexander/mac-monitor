<?php
/**
 * mac-monitor - Konfiguration fuer die PHP/Shared-Hosting-Variante.
 * Gleiche API, gleiches SQLite-Schema wie der Python-Server.
 */
declare(strict_types=1);

// --- Basis -----------------------------------------------------------------
// Leer lassen = automatische Erkennung. Nur setzen, wenn hinter Proxy/Subpfad.
const MM_PUBLIC_URL_OVERRIDE = '';

// Testbetrieb: statisches Token ist bewusst akzeptiert.
const MM_API_TOKEN = 'mac-monitor-test-token';
const MM_ALLOW_ANONYMOUS_READ = true;

// Datenbank MUSS ausserhalb des Web-Roots liegen, sonst ist sie ladbar.
define('MM_DB_PATH', __DIR__ . '/../data/mac-monitor.sqlite3');

// --- Zeiten ----------------------------------------------------------------
const MM_SAMPLE_INTERVAL   = 10;
const MM_OFFLINE_AFTER     = 45;
const MM_RESERVATION_TTL   = 120;
const MM_HEARTBEAT_TIMEOUT = 90;

const MM_RETENTION_RAW_DAYS   = 14;
const MM_RETENTION_TPS_DAYS   = 30;
const MM_RETENTION_EVENT_DAYS = 30;

// --- Scheduler (Option C) --------------------------------------------------
const MM_RAM_HEADROOM_GB  = 2.0;
const MM_VRAM_HEADROOM_GB = 0.5;

const MM_W_VRAM         = 28.0;
const MM_W_RAM          = 22.0;
const MM_W_GPU_IDLE     = 14.0;
const MM_W_CPU_IDLE     = 8.0;
const MM_W_TPS          = 16.0;
const MM_W_MODEL_LOADED = 12.0;
const MM_TPS_FALLBACK_DISCOUNT = 0.6;

/** Oeffentliche Basis-URL ermitteln - ohne hart codierte Domain. */
function mm_public_url(): string {
    if (MM_PUBLIC_URL_OVERRIDE !== '') {
        return rtrim(MM_PUBLIC_URL_OVERRIDE, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . $dir;
}

function mm_api_url(): string {
    return mm_public_url() . '/api/v1';
}

function mm_normalize_url(string $url): string {
    $url = trim($url);
    if ($url === '') { return ''; }
    if (strpos($url, '://') === false) { $url = 'http://' . $url; }
    return rtrim($url, '/');
}
