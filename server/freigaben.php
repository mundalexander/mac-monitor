<?php
/**
 * freigaben.php — Gast-Freigaben verwalten (1.3.1).
 *
 * Nach normalem Login (PIN/Passkey) hier teilbare Gast-PINs erzeugen:
 * 8 Ziffern, 7 Tage gültig, nur als Hash gespeichert, einzeln widerrufbar.
 * Gaste melden sich mit der Freigabe-PIN ganz normal auf dem Login-Screen
 * an (auth-api.php / slot-api.php, gleiche Rate-Limit wie die Haupt-PIN).
 *
 * Zugriff: nur nach echtem Login; Gast-Sessions (mm_auth='freigabe')
 * sehen keine Verwaltung — sie sollen keine neuen Freigaben erzeugen.
 * JSON: GET ?list=1 · POST ?create=1 {label} · POST ?revoke=1 {id}
 */
require_once __DIR__ . '/config.php';
mm_gate_page();   // PIN-Anmeldung; gemerkte Geräte kommen direkt durch

$auth    = (string)($_SESSION['mm_auth'] ?? '');
$isGuest = str_starts_with($auth, 'freigabe');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ── JSON-Endpunkte (nur echte Sessions, keine Gast-Sessions) ──────────
$action = null;
foreach (['list', 'create', 'revoke'] as $a) {
    if (isset($_GET[$a])) { $action = $a; break; }
}
if ($action !== null) {
    header('Content-Type: application/json; charset=utf-8');
    if ($isGuest) json_response(403, ['error' => 'forbidden', 'message' => 'Gast-Login: kein Zugriff auf Freigaben.']);
    try {
        if ($action === 'list') {
            json_response(200, ['ok' => true, 'freigaben' => mm_freigaben_list()]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(405, ['error' => 'POST required']);
        if ($action === 'create') {
            $body  = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $res   = mm_freigabe_create((string)($body['label'] ?? ''));
            if ($res === null) json_response(409, ['error' => 'limit', 'message' => 'Maximal ' . MM_FREIGABE_MAX . ' aktive Freigaben.']);
            json_response(200, ['ok' => true] + $res);
        }
        if ($action === 'revoke') {
            $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $ok   = mm_freigabe_revoke((string)($body['id'] ?? ''));
            json_response($ok ? 200 : 404, ['ok' => $ok]);
        }
    } catch (Throwable $e) {
        error_log('fleet-monitor freigaben: ' . $e->getMessage());
        json_response(500, ['error' => 'server_error']);
    }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Gast-Freigaben — Fleet Monitor</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>
  window.__mmTheme=(function(){try{return localStorage.getItem('mm_theme')||'auto'}catch(e){return'auto'}})();
  window.__mmResolveTheme=function(m){return(m==='light'||m==='dark')?m:(window.matchMedia('(prefers-color-scheme: light)').matches?'light':'dark')};
  document.documentElement.setAttribute('data-theme',window.__mmResolveTheme(window.__mmTheme));
  document.documentElement.setAttribute('data-theme-mode',window.__mmTheme);
</script>
<link rel="stylesheet" href="assets/ui.css?v=130">
<link rel="stylesheet" href="assets/apple.css?v=130">
<style>
main { padding: 14px 20px; max-width: 900px; margin: 0 auto; }
.card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius);
  box-shadow: var(--shadow); padding: 16px; margin-bottom: 14px; }
.card h2 { margin: 0 0 10px; font-size: 16px; }
.row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
input[type=text] { flex: 1 1 180px; padding: 8px 10px; border: 1px solid var(--border);
  border-radius: 8px; background: var(--canvas); color: var(--text); font-size: 14px; }
button.act { padding: 8px 14px; border: 0; border-radius: 8px; background: var(--fm-accent, #76b900);
  color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; }
button.revoke { padding: 4px 10px; border: 1px solid var(--border); border-radius: 8px;
  background: transparent; color: var(--fm-red, #d70015); font-size: 13px; cursor: pointer; }
#new-pin { display: none; margin-top: 12px; padding: 12px; border: 1px dashed var(--fm-accent, #76b900);
  border-radius: 10px; text-align: center; }
#new-pin .pin { font-size: 30px; font-weight: 700; letter-spacing: .18em; font-variant-numeric: tabular-nums; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--border); }
th { color: var(--muted); font-weight: 600; font-size: 12px; }
.muted { color: var(--muted); }
.err { color: var(--fm-red, #d70015); }
</style>
</head>
<body>
<main>
<?php if ($isGuest): ?>
  <div class="card"><h2>Gast-Freigaben</h2>
  <p class="muted">Du bist mit einer Gast-Freigabe angemeldet. Freigaben kann nur der Besitzer mit Haupt-PIN verwalten.</p></div>
<?php else: ?>
  <div class="card">
    <h2>Neue Gast-Freigabe erzeugen</h2>
    <div class="row">
      <input type="text" id="label" maxlength="40" placeholder="Name / Zweck (z. B. &quot;Anna, 1 Woche&quot;)">
      <button class="act" id="create">Freigabe erzeugen</button>
    </div>
    <div id="new-pin">
      <div class="muted">Gast-PIN — nur jetzt sichtbar, 7 Tage gültig:</div>
      <div class="pin" id="pin-value"></div>
      <button class="act" id="copy" style="margin-top:6px">PIN kopieren</button>
    </div>
    <p class="err" id="err" role="alert" aria-live="polite"></p>
  </div>
  <div class="card">
    <h2>Aktive Freigaben</h2>
    <table>
      <thead><tr><th>Name</th><th>Erstellt</th><th>Gültig bis</th><th>Letzte Nutzung</th><th></th></tr></thead>
      <tbody id="rows"><tr><td colspan="5" class="muted">Lädt …</td></tr></tbody>
    </table>
  </div>
<?php endif; ?>
</main>
<?php if (!$isGuest): ?>
<script>
(function () {
  const rows = document.getElementById('rows');
  const err  = document.getElementById('err');
  const fmt  = ts => ts ? new Date(ts * 1000).toLocaleString('de-DE', {day:'2-digit', month:'2-digit', year:'2-digit', hour:'2-digit', minute:'2-digit'}) : '—';
  async function api(action, body) {
    const opt = { credentials: 'same-origin', headers: { 'Content-Type': 'application/json' } };
    if (action !== 'list') { opt.method = 'POST'; opt.body = JSON.stringify(body || {}); }
    const r = await fetch('freigaben.php?' + action + '=1', opt);
    let j = {}; try { j = await r.json(); } catch (e) {}
    if (!r.ok) throw new Error(j.message || j.error || ('HTTP ' + r.status));
    return j;
  }
  function esc(s) { const d = document.createElement('span'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
  async function refresh() {
    try {
      const j = await api('list');
      if (!j.freigaben.length) { rows.innerHTML = '<tr><td colspan="5" class="muted">Keine aktiven Freigaben.</td></tr>'; return; }
      rows.innerHTML = j.freigaben.map(f =>
        '<tr><td>' + esc(f.label) + '</td><td class="muted">' + fmt(f.created) + '</td><td>' + fmt(f.expires) +
        '</td><td class="muted">' + fmt(f.last_used) + '</td><td><button class="revoke" data-id="' +
        esc(f.id) + '">Widerrufen</button></td></tr>').join('');
      rows.querySelectorAll('.revoke').forEach(b => b.addEventListener('click', async () => {
        try { await api('revoke', { id: b.dataset.id }); refresh(); } catch (e) { err.textContent = e.message; }
      }));
    } catch (e) { rows.innerHTML = '<tr><td colspan="5" class="err">' + esc(e.message) + '</td></tr>'; }
  }
  document.getElementById('create').addEventListener('click', async () => {
    err.textContent = '';
    try {
      const j = await api('create', { label: document.getElementById('label').value.trim() });
      document.getElementById('pin-value').textContent = j.pin;
      document.getElementById('new-pin').style.display = 'block';
      document.getElementById('label').value = '';
      refresh();
    } catch (e) { err.textContent = e.message; }
  });
  document.getElementById('copy').addEventListener('click', function () {
    const p = document.getElementById('pin-value').textContent;
    if (navigator.clipboard) navigator.clipboard.writeText(p);
    this.textContent = 'Kopiert!';
  });
  refresh();
})();
</script>
<?php endif; ?>
</body>
</html>
