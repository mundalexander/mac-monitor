<?php
/**
 * auth.php — zentrale Zugriffsschicht des Fleet Monitor (seit 1.2.0).
 *
 * Es gibt KEINE API-Tokens mehr. Zwei Wege führen hinein:
 *
 *  1. Menschen im Browser
 *     PIN (oder Passkey) einmal eingeben -> Session. Mit "Gerät merken"
 *     erhält der Browser ein zufälliges Geräte-Cookie (90 Tage, gleitend).
 *     Das Cookie wird serverseitig nur als SHA-256-Hash gespeichert und
 *     kann in der Geräteverwaltung einzeln widerrufen werden.
 *
 *  2. Maschinen (Monitor-Client, Jarvis, Skripte)
 *     Einmalige Kopplung per PIN (pair.php). Jede Maschine erhält einen
 *     eigenen, zufälligen Geräteschlüssel, der auf dem Gerät mit Dateirechten
 *     0600 liegt. Danach läuft der Dienst dauerhaft ohne PIN.
 *     Übertragung per Header:  X-Fleet-Device: <id>   X-Fleet-Key: <schlüssel>
 *
 * Übergangsweise (nur bis alle Geräte gekoppelt sind) kann in secrets.php
 *   define('LEGACY_CLIENT_TOKEN', '...');
 * gesetzt werden. Standard: aus. Nach der Umstellung wieder entfernen.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-v2.php';

const MM_REMEMBER_COOKIE = 'mm_device';
const MM_REMEMBER_DAYS   = 90;
const MM_IP_MAX_FAILS    = 8;      // Fehlversuche je IP ...
const MM_IP_WINDOW       = 900;    // ... innerhalb von 15 Minuten

// ════════════════════════════════════════════════════════════════════
// Tabellen
// ════════════════════════════════════════════════════════════════════
function mm_auth_db(): PDO {
    static $ready = false;
    $pdo = db();
    if (!$ready) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS auth_devices (
                id         TEXT PRIMARY KEY,
                name       TEXT NOT NULL DEFAULT '',
                platform   TEXT NOT NULL DEFAULT '',
                key_hash   TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                last_seen  INTEGER,
                last_ip    TEXT,
                revoked    INTEGER NOT NULL DEFAULT 0
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS auth_browsers (
                id         TEXT PRIMARY KEY,
                key_hash   TEXT NOT NULL,
                label      TEXT NOT NULL DEFAULT '',
                created_at INTEGER NOT NULL,
                last_used  INTEGER,
                expires_at INTEGER NOT NULL,
                revoked    INTEGER NOT NULL DEFAULT 0
            )
        ");
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (ip TEXT NOT NULL, ts INTEGER NOT NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auth_attempts ON auth_attempts(ip, ts)");
        $ready = true;
    }
    return $pdo;
}

function mm_client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 64);
}

function mm_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
}

// ════════════════════════════════════════════════════════════════════
// Brute-Force-Bremse je IP (für PIN-Login und Geräte-Kopplung)
// ════════════════════════════════════════════════════════════════════
function mm_ip_allowed(): bool {
    try {
        $pdo = mm_auth_db();
        $pdo->prepare('DELETE FROM auth_attempts WHERE ts < ?')->execute([time() - MM_IP_WINDOW]);
        $st = $pdo->prepare('SELECT COUNT(*) FROM auth_attempts WHERE ip = ?');
        $st->execute([mm_client_ip()]);
        return (int)$st->fetchColumn() < MM_IP_MAX_FAILS;
    } catch (Throwable $e) {
        return true; // DB-Problem darf Login nicht dauerhaft sperren
    }
}

function mm_ip_fail(): void {
    try {
        mm_auth_db()->prepare('INSERT INTO auth_attempts(ip, ts) VALUES(?, ?)')
            ->execute([mm_client_ip(), time()]);
    } catch (Throwable $e) {}
}

function mm_ip_clear(): void {
    try {
        mm_auth_db()->prepare('DELETE FROM auth_attempts WHERE ip = ?')->execute([mm_client_ip()]);
    } catch (Throwable $e) {}
}

// ════════════════════════════════════════════════════════════════════
// "Gerät merken" für Browser
// ════════════════════════════════════════════════════════════════════
function mm_browser_label(): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = 'Gerät';
    foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android',
              'Mac OS X' => 'Mac', 'Windows' => 'Windows', 'Linux' => 'Linux'] as $k => $v) {
        if (stripos($ua, $k) !== false) { $os = $v; break; }
    }
    $br = 'Browser';
    foreach (['Edg/' => 'Edge', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $k => $v) {
        if (stripos($ua, $k) !== false) { $br = $v; break; }
    }
    return $os . ' · ' . $br;
}

function mm_set_remember_cookie(string $value, int $expires): void {
    setcookie(MM_REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => mm_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Neues Browser-Gerät anlegen und Cookie setzen. */
function mm_remember_issue(): void {
    try {
        $id  = bin2hex(random_bytes(12));
        $key = bin2hex(random_bytes(32));
        $exp = time() + MM_REMEMBER_DAYS * 86400;
        mm_auth_db()->prepare(
            'INSERT INTO auth_browsers(id, key_hash, label, created_at, last_used, expires_at) VALUES(?,?,?,?,?,?)'
        )->execute([$id, hash('sha256', $key), mm_browser_label(), time(), time(), $exp]);
        mm_set_remember_cookie($id . '.' . $key, $exp);
        $_SESSION['mm_browser_id'] = $id;
    } catch (Throwable $e) {}
}

/** Prüft das Geräte-Cookie. Verlängert die Laufzeit gleitend (max. 1x pro Tag). */
function mm_remember_check(): bool {
    $raw = (string)($_COOKIE[MM_REMEMBER_COOKIE] ?? '');
    if (!preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', $raw, $m)) return false;
    try {
        $pdo = mm_auth_db();
        $st = $pdo->prepare('SELECT * FROM auth_browsers WHERE id = ? AND revoked = 0');
        $st->execute([$m[1]]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['expires_at'] < time()) return false;
        if (!hash_equals((string)$row['key_hash'], hash('sha256', $m[2]))) return false;
        if (time() - (int)$row['last_used'] > 86400) {
            $exp = time() + MM_REMEMBER_DAYS * 86400;
            $pdo->prepare('UPDATE auth_browsers SET last_used = ?, expires_at = ? WHERE id = ?')
                ->execute([time(), $exp, $m[1]]);
            mm_set_remember_cookie($raw, $exp);
        }
        $_SESSION['mm_browser_id'] = $m[1];
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Abmelden: Cookie löschen und dieses Browser-Gerät widerrufen. */
function mm_remember_clear(): void {
    $raw = (string)($_COOKIE[MM_REMEMBER_COOKIE] ?? '');
    if (preg_match('/^([a-f0-9]{24})\./', $raw, $m)) {
        try {
            mm_auth_db()->prepare('UPDATE auth_browsers SET revoked = 1 WHERE id = ?')->execute([$m[1]]);
        } catch (Throwable $e) {}
    }
    mm_set_remember_cookie('', time() - 3600);
    // altes HMAC-Cookie (bis 1.1) ebenfalls entfernen
    setcookie('mm_remember', '', ['expires' => time() - 3600, 'path' => '/']);
}

// ════════════════════════════════════════════════════════════════════
// Browser-Session
// ════════════════════════════════════════════════════════════════════
/** true, wenn eine Session besteht oder ein gemerktes Gerät sie automatisch herstellt. */
function mm_is_authed(): bool {
    mm_session_start();
    if (!empty($_SESSION['planner_ok']) && (int)($_SESSION['mm_v'] ?? 0) === 2) return true;
    // Alte Sitzung (vor 1.2, z. B. per Token) verwerfen -> einmal neu anmelden
    if (!empty($_SESSION['planner_ok'])) unset($_SESSION['planner_ok'], $_SESSION['mm_auth']);
    if (mm_remember_check()) {
        $_SESSION['planner_ok'] = true;
        $_SESSION['mm_auth']    = 'remember';
        $_SESSION['mm_v']       = 2;
        return true;
    }
    return false;
}

// ════════════════════════════════════════════════════════════════════
// Geräteschlüssel für Maschinen
// ════════════════════════════════════════════════════════════════════
function mm_valid_device_id(string $id): bool {
    return preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $id) === 1;
}

/** Neue Maschine koppeln bzw. Schlüssel neu ausstellen. Gibt den Klartext-Schlüssel EINMAL zurück. */
function mm_device_issue(string $id, string $name, string $platform): string {
    $key = bin2hex(random_bytes(32));
    $pdo = mm_auth_db();
    $pdo->prepare("
        INSERT INTO auth_devices(id, name, platform, key_hash, created_at, revoked)
        VALUES(:id, :name, :pf, :h, :t, 0)
        ON CONFLICT(id) DO UPDATE SET name = excluded.name, platform = excluded.platform,
            key_hash = excluded.key_hash, created_at = excluded.created_at, revoked = 0
    ")->execute([':id' => $id, ':name' => $name, ':pf' => $platform,
                 ':h' => hash('sha256', $key), ':t' => time()]);
    return $key;
}

/**
 * Identifiziert eine Maschine anhand von X-Fleet-Device / X-Fleet-Key.
 * @return ?string  Geräte-ID oder null.
 */
function mm_device_identity(): ?string {
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;
    $id  = strtolower(trim((string)($_SERVER['HTTP_X_FLEET_DEVICE'] ?? '')));
    $key = trim((string)($_SERVER['HTTP_X_FLEET_KEY'] ?? ''));
    if ($id === '' || !mm_valid_device_id($id) || !preg_match('/^[a-f0-9]{64}$/', $key)) return null;
    try {
        $pdo = mm_auth_db();
        $st = $pdo->prepare('SELECT key_hash, last_seen FROM auth_devices WHERE id = ? AND revoked = 0');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals((string)$row['key_hash'], hash('sha256', $key))) return null;
        if (time() - (int)$row['last_seen'] >= 60) {
            $pdo->prepare('UPDATE auth_devices SET last_seen = ?, last_ip = ? WHERE id = ?')
                ->execute([time(), mm_client_ip(), $id]);
        }
        $cached = $id;
    } catch (Throwable $e) {
        $cached = null;
    }
    return $cached;
}

/** Übergangsweise: altes Client-Token im JSON-Body (nur wenn in secrets.php aktiviert). */
function mm_legacy_token_ok(?array $data): bool {
    if (!defined('LEGACY_CLIENT_TOKEN')) return false;
    $tok = trim((string)constant('LEGACY_CLIENT_TOKEN'));
    if (strlen($tok) < 16 || !is_array($data)) return false;
    return hash_equals($tok, (string)($data['token'] ?? ''));
}

/**
 * Für Client-Endpunkte (submit, requests, commands, ingest):
 * liefert die Geräte-ID oder beendet mit 401.
 * Bei aktivem Übergangs-Token wird 'legacy' zurückgegeben.
 */
function mm_device_require(?array $data = null): string {
    $id = mm_device_identity();
    if ($id !== null) return $id;
    if (mm_legacy_token_ok($data)) return 'legacy';
    json_response(401, [
        'error'   => 'device_not_paired',
        'message' => 'Gerät nicht gekoppelt. Auf dem Gerät ausführen: python3 client/monitor.py --pair',
    ]);
}

// ════════════════════════════════════════════════════════════════════
// Gates
// ════════════════════════════════════════════════════════════════════
/** Für JSON-APIs, die Browser UND Maschinen nutzen (data, board, slots, autonomy ...). */
function mm_api_access(): ?string {
    if (mm_device_identity() !== null) return 'device';
    if (mm_is_authed()) return 'session';
    return null;
}

function mm_require_api_access(): string {
    $how = mm_api_access();
    if ($how === null) {
        json_response(401, ['error' => 'unauthorized', 'login_required' => true]);
    }
    return $how;
}

/** Nur Browser-Session (Steuerbefehle aus dem Dashboard, Geräteverwaltung). */
function mm_require_session(): void {
    if (!mm_is_authed()) {
        json_response(401, ['error' => 'unauthorized', 'login_required' => true]);
    }
}

/**
 * Gate für HTML-Seiten. Gemerkte Geräte kommen ohne PIN durch,
 * alle anderen sehen eine schlichte PIN-Anmeldung.
 */
function mm_gate_page(): void {
    if (mm_is_authed()) return;
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $pinReady = mm_auth_config()['pin_hash'] !== '';
    ?><!doctype html>
<html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title>Fleet Monitor · Anmelden</title>
<link rel="stylesheet" href="assets/apple.css?v=121">
</head><body class="fm-login-body">
<main class="fm-login">
  <div class="fm-login-card">
    <div class="fm-logo" aria-hidden="true">m.</div>
    <h1>Fleet Monitor</h1>
    <?php if (!$pinReady): ?>
      <p class="fm-sub">Noch kein PIN eingerichtet. Bitte <code>secrets.php</code> mit
      <code>LOGIN_PIN_HASH</code> anlegen (siehe docs/INSTALLATION.md).</p>
    <?php else: ?>
      <p class="fm-sub">PIN eingeben. Dieses Gerät bleibt danach 90 Tage angemeldet.</p>
      <form id="fm-login-form" autocomplete="off">
        <input id="fm-pin" class="fm-pin" type="password" inputmode="numeric" pattern="[0-9]*"
               maxlength="8" placeholder="PIN" aria-label="PIN" autofocus required>
        <label class="fm-check"><input type="checkbox" id="fm-remember" checked> Dieses Gerät merken</label>
        <button type="submit" class="fm-btn fm-btn-primary">Anmelden</button>
        <button type="button" class="fm-btn fm-btn-plain" id="fm-passkey" hidden>Mit Passkey anmelden</button>
        <p class="fm-err" id="fm-err" role="alert" aria-live="polite"></p>
      </form>
    <?php endif; ?>
  </div>
</main>
<?php if ($pinReady): ?>
<script>
(function () {
  const f = document.getElementById('fm-login-form');
  const pin = document.getElementById('fm-pin');
  const err = document.getElementById('fm-err');
  async function api(action, body) {
    const r = await fetch('auth-api.php?' + action + '=1', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {})
    });
    let j = {}; try { j = await r.json(); } catch (e) {}
    return { ok: r.ok, json: j };
  }
  f.addEventListener('submit', async function (e) {
    e.preventDefault();
    err.textContent = '';
    const r = await api('login', { pin: pin.value.trim(),
      remember: document.getElementById('fm-remember').checked ? 1 : 0 });
    if (r.ok) { location.reload(); return; }
    err.textContent = (r.json && r.json.message) || 'PIN falsch.';
    pin.select();
  });
  const pk = document.getElementById('fm-passkey');
  if (window.PublicKeyCredential && navigator.credentials) {
    fetch('auth-api.php?status=1', { credentials: 'same-origin' }).then(r => r.json()).then(s => {
      if (s && s.passkeyCount > 0 && window.mmPasskeyLogin) pk.hidden = false;
    }).catch(function () {});
  }
  pk.addEventListener('click', async function () {
    try { if (await window.mmPasskeyLogin()) location.reload(); else err.textContent = 'Passkey abgelehnt.'; }
    catch (e) { err.textContent = 'Passkey abgebrochen.'; }
  });
})();
</script>
<script src="assets/ui.js?v=121"></script>
<?php endif; ?>
</body></html><?php
    exit;
}
