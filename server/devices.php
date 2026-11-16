<?php
/**
 * devices.php — Geräteverwaltung: gekoppelte Maschinen und gemerkte Browser.
 * Einzelne Geräte lassen sich hier widerrufen (z. B. verlorenes Notebook).
 */
require_once __DIR__ . '/config.php';
mm_gate_page();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$base = (mm_is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')
      . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Geräte — Fleet Monitor</title>
<script>
  window.__mmTheme=(function(){try{return localStorage.getItem('mm_theme')||'auto'}catch(e){return'auto'}})();
  window.__mmResolveTheme=function(m){return(m==='light'||m==='dark')?m:(window.matchMedia('(prefers-color-scheme: light)').matches?'light':'dark')};
  document.documentElement.setAttribute('data-theme',window.__mmResolveTheme(window.__mmTheme));
  document.documentElement.setAttribute('data-theme-mode',window.__mmTheme);
</script>
<link rel="stylesheet" href="assets/ui.css?v=130">
<link rel="stylesheet" href="assets/apple.css?v=130">
</head>
<body>
<header class="mm-header">
  <div class="mm-brand">
    <nav class="mm-nav" aria-label="Hauptnavigation">
      <a href="index.php">Dashboard</a>
      <a href="calendar.php">Kalender</a>
      <a href="board.php">Board</a>
      <a href="autonomy.php">Autonomie</a>
      <a href="devices.php" aria-current="page">Geräte</a>
    </nav>
  </div>
  <div class="mm-controls">
    <button class="btn" data-theme-toggle type="button">Auto</button>
    <button class="btn" id="logout" type="button">Abmelden</button>
  </div>
</header>
<nav class="mm-bottomnav" aria-label="Hauptnavigation mobil">
  <a href="index.php">Dashboard</a>
  <a href="calendar.php">Kalender</a>
  <a href="board.php">Board</a>
  <a href="autonomy.php">Autonomie</a>
  <a href="devices.php" aria-current="page">Geräte</a>
</nav>

<main class="fm-page">
  <h1>Geräte</h1>
  <p class="fm-lead">Wer Zugriff auf den Fleet Monitor hat. Widerrufene Geräte müssen neu mit PIN gekoppelt werden.</p>

  <h2>Maschinen</h2>
  <p class="fm-lead">Monitor-Clients und Agenten. Sie senden Messwerte und holen Steuerbefehle ab.</p>
  <div class="fm-list" id="machines" aria-live="polite"><div class="fm-row"><div class="fm-main fm-meta">Lade …</div></div></div>

  <h2>Browser</h2>
  <p class="fm-lead">Geräte, auf denen „Dieses Gerät merken" aktiv ist (90 Tage, verlängert sich bei Nutzung).</p>
  <div class="fm-list" id="browsers" aria-live="polite"></div>

  <h2>Neue Maschine koppeln</h2>
  <div class="fm-howto">
    Auf der Maschine im Projektordner ausführen und einmalig die PIN eingeben:<br>
    <code>python3 client/monitor.py --pair --server <?= htmlspecialchars($base, ENT_QUOTES) ?> --machine-id evo-x3</code><br>
    Der Geräteschlüssel wird lokal mit Dateirechten 0600 gespeichert. Danach läuft der Dienst dauerhaft ohne PIN.
  </div>
</main>

<script src="assets/ui.js?v=130"></script>
<script>
(function () {
  'use strict';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const when = ts => {
    if (!ts) return 'nie';
    const d = Math.max(0, Math.floor(Date.now() / 1000) - Number(ts));
    if (d < 90) return 'gerade eben';
    if (d < 3600) return 'vor ' + Math.round(d / 60) + ' min';
    if (d < 86400) return 'vor ' + Math.round(d / 3600) + ' h';
    return new Date(ts * 1000).toLocaleDateString('de-DE');
  };
  async function api(body) {
    const r = await fetch('devices-api.php', {
      method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined
    });
    if (r.status === 401) { location.reload(); throw new Error('login'); }
    return r.json();
  }
  function render(d) {
    const m = document.getElementById('machines');
    m.innerHTML = d.machines.length ? d.machines.map(x => {
      const online = !x.revoked && x.last_seen && (Date.now() / 1000 - x.last_seen) < 120;
      const pill = x.revoked ? '<span class="fm-pill off">widerrufen</span>'
                 : online ? '<span class="fm-pill on">aktiv</span>' : '<span class="fm-pill">inaktiv</span>';
      return '<div class="fm-row"><div class="fm-ico">' + esc((x.id || '?').slice(0, 2).toUpperCase()) + '</div>' +
        '<div class="fm-main"><div class="fm-title">' + esc(x.name || x.id) + '</div>' +
        '<div class="fm-meta">' + esc(x.id) + (x.platform ? ' · ' + esc(x.platform) : '') +
        ' · gekoppelt ' + when(x.created_at) + ' · zuletzt ' + when(x.last_seen) +
        (x.known ? '' : ' · nicht in config.php SERVERS') + '</div></div>' + pill +
        (x.revoked ? '' : '<button class="fm-link-btn" data-rm="' + esc(x.id) + '" type="button">Widerrufen</button>') +
        '</div>';
    }).join('') : '<div class="fm-row"><div class="fm-main fm-meta">Noch keine Maschine gekoppelt.</div></div>';

    const b = document.getElementById('browsers');
    const notRemembered = !d.current_browser || !d.browsers.some(x => x.id === d.current_browser);
    const rememberRow = notRemembered
      ? '<div class="fm-row"><div class="fm-ico">!</div><div class="fm-main"><div class="fm-title">Dieser Browser ist nicht gemerkt</div>' +
        '<div class="fm-meta">Ohne „Merken" fragt der Fleet Monitor nach Ablauf der Sitzung erneut nach der PIN.</div></div>' +
        '<button class="fm-ctl-btn" id="remember-this" type="button">Diesen Browser merken</button></div>'
      : '';
    b.innerHTML = rememberRow + (d.browsers.length ? d.browsers.map(x => {
      const me = x.id === d.current_browser;
      return '<div class="fm-row"><div class="fm-ico">' + (me ? '•' : '') + '</div>' +
        '<div class="fm-main"><div class="fm-title">' + esc(x.label) + (me ? ' <span class="fm-pill on">dieses Gerät</span>' : '') + '</div>' +
        '<div class="fm-meta">angemeldet ' + when(x.created_at) + ' · zuletzt ' + when(x.last_used) +
        ' · gültig bis ' + new Date(x.expires_at * 1000).toLocaleDateString('de-DE') + '</div></div>' +
        '<button class="fm-link-btn" data-rb="' + esc(x.id) + '" type="button">Abmelden</button></div>';
    }).join('') : (rememberRow ? '' : '<div class="fm-row"><div class="fm-main fm-meta">Kein Browser gemerkt.</div></div>'));
  }
  async function load() { try { render(await api()); } catch (e) {} }
  document.addEventListener('click', async e => {
    if (e.target.closest('#remember-this')) {
      const r = await api({ action: 'remember_this' });
      window.mmToast && window.mmToast(r.ok ? 'Browser für 90 Tage gemerkt' : 'Merken fehlgeschlagen', r.ok ? 'ok' : 'err');
      load(); return;
    }
    const rm = e.target.closest('[data-rm]'), rb = e.target.closest('[data-rb]');
    if (rm && confirm('Maschine „' + rm.dataset.rm + '" widerrufen? Sie kann danach keine Daten mehr senden, bis sie neu gekoppelt wird.')) {
      await api({ action: 'revoke_machine', id: rm.dataset.rm }); load();
    }
    if (rb && confirm('Diesen Browser abmelden?')) {
      await api({ action: 'revoke_browser', id: rb.dataset.rb }); load();
    }
  });
  document.getElementById('logout').addEventListener('click', async () => {
    await fetch('auth-api.php?logout=1', { method: 'POST', credentials: 'same-origin' });
    location.reload();
  });
  load();
})();
</script>
</body>
</html>
