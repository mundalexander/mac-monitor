<?php
/**
 * autonomy.php — Autonomie-Cockpit für mac-monitor (Board-Karte 41).
 *
 * Ein Blick auf den Autonomie-Modus: Master-Schalter (an/aus), heutige
 * Jarvis-Slots mit Status, aktuelles Board (arbeit + fällige Ideen) und
 * das Wake-Journal (warum wurde wann geweckt und was wurde getan).
 *
 * Daten: autonomy-api.php (Journal + Schalter), slot-api.php (Slots),
 * board-api.php (Karten) — alle mit Session-Login via Login-Modal.
 * Gemeinsames Design-System: assets/ui.css + assets/ui.js.
 */
require_once __DIR__ . '/mac-monitor-config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Autonomie — Fleet Monitor</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>
  window.__mmTheme=(function(){try{return localStorage.getItem('mm_theme')||'auto'}catch(e){return'auto'}})();
  window.__mmResolveTheme=function(m){return(m==='light'||m==='dark')?m:(window.matchMedia('(prefers-color-scheme: light)').matches?'light':'dark')};
  document.documentElement.setAttribute('data-theme',window.__mmResolveTheme(window.__mmTheme));
  document.documentElement.setAttribute('data-theme-mode',window.__mmTheme);
</script>
<link rel="stylesheet" href="assets/ui.css">
<link rel="stylesheet" href="assets/glass-theme.css">
<style>
main { padding: 14px 20px; max-width: 1100px; margin: 0 auto; }

/* Master-Schalter */
.switchbar {
  background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius);
  box-shadow: var(--shadow); padding: 14px 16px; margin-bottom: 14px;
  display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
}
.switchbar h2 { margin: 0; font-size: 16px; flex: 1 1 auto; }
.switch { position: relative; display: inline-block; width: 58px; height: 30px; flex: none; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
  position: absolute; inset: 0; cursor: pointer; border-radius: 30px;
  background: var(--border); transition: .2s;
}
.slider:before {
  content: ""; position: absolute; height: 24px; width: 24px; left: 3px; top: 3px;
  background: #fff; border-radius: 50%; transition: .2s; box-shadow: 0 1px 3px rgba(0,0,0,.35);
}
.switch input:checked + .slider { background: #2ea043; }
.switch input:checked + .slider:before { transform: translateX(28px); }
#switch-state { font-weight: 600; min-width: 70px; }
#switch-state.on  { color: #2ea043; }
#switch-state.off { color: #e5534b; }

.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 860px) { .cols { grid-template-columns: 1fr; } }

.panel {
  background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius);
  box-shadow: var(--shadow); padding: 12px 14px; margin-bottom: 14px;
}
.panel h3 { margin: 0 0 8px; font-size: 14px; }
.row { padding: 7px 4px; border-bottom: 1px dashed var(--border); font-size: 13px; }
.row:last-child { border-bottom: 0; }
.row .t { font-weight: 600; }
.row .m { color: var(--muted); font-size: 12px; }
.badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.badge.now  { background: #1f6feb33; color: #58a6ff; }
.badge.done { background: #2ea04333; color: #3fb950; }
.badge.next { background: #8957e533; color: #bc8cff; }
.badge.past { background: var(--border); color: var(--muted); }
.badge.arbeit { background: #d2992233; color: #d29922; }
.badge.idee   { background: #8957e533; color: #bc8cff; }

/* Journal */
.jrow { display: flex; gap: 8px; align-items: baseline; padding: 6px 4px; border-bottom: 1px dashed var(--border); font-size: 12.5px; }
.jrow:last-child { border-bottom: 0; }
.jrow .ts { color: var(--muted); font-variant-numeric: tabular-nums; flex: none; width: 86px; }
.jrow .ev { font-weight: 600; flex: none; }
.jrow .note { color: var(--muted); }
.ev.wake    { color: #58a6ff; }
.ev.pull    { color: #bc8cff; }
.ev.start   { color: #d29922; }
.ev.done    { color: #3fb950; }
.ev.blocked { color: #e5534b; }
.ev.toggle  { color: #f0883e; }
.ev.info    { color: var(--muted); }
.empty { color: var(--muted); font-size: 13px; padding: 8px 4px; }
.toolbar { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; }
.toolbar button { font: inherit; padding: 4px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--panel); color: var(--fg); cursor: pointer; }
</style>
</head>
<body>
<script>if(new URLSearchParams(location.search).has('embedded'))document.documentElement.classList.add('embedded');</script>
<style>.embedded .mm-header,.embedded .mm-bottomnav{display:none!important}</style>

<header class="mm-header">
  <div class="mm-brand">
    <nav class="mm-nav" aria-label="Hauptnavigation">
      <a href="index.php"><span class="nav-ico">📊</span>Dashboard</a>
      <a href="calendar.php"><span class="nav-ico">📅</span>Kalender</a>
      <a href="board.php"><span class="nav-ico">💡</span>Ideen-Board</a>
      <a href="autonomy.php" aria-current="page"><span class="nav-ico">🤖</span>Autonomie</a>
    </nav>
    <div class="mm-title">
      <h1>🤖 Autonomie-Cockpit</h1>
      <div class="muted" id="updated"></div>
    </div>
  </div>
  <div class="mm-controls">
    <button data-theme-toggle title="Theme wechseln">🔄 Auto</button>
  </div>
</header>

<nav class="mm-bottomnav" aria-label="Hauptnavigation mobil">
  <a href="index.php"><span class="nav-ico">📊</span>Dashboard</a>
  <a href="calendar.php"><span class="nav-ico">📅</span>Kalender</a>
  <a href="board.php"><span class="nav-ico">💡</span>Board</a>
  <a href="autonomy.php" aria-current="page"><span class="nav-ico">🤖</span>Autonomie</a>
</nav>

<main>
  <div class="switchbar">
    <h2>Autonomie-Modus</h2>
    <span id="switch-state">…</span>
    <label class="switch">
      <input type="checkbox" id="master" onchange="toggleMaster(this.checked)">
      <span class="slider"></span>
    </label>
  </div>

  <div class="cols">
    <div>
      <div class="panel">
        <h3>Heutige Jarvis-Slots</h3>
        <div id="slots"><div class="empty">lade…</div></div>
      </div>
      <div class="panel">
        <h3>Board — Arbeit &amp; fällige Ideen</h3>
        <div id="cards"><div class="empty">lade…</div></div>
      </div>
    </div>
    <div>
      <div class="panel">
        <h3>Wake-Journal (heute)</h3>
        <div class="toolbar">
          <button onclick="load()">↻ Neu laden</button>
          <span class="muted" style="font-size:12px">warum geweckt → was getan</span>
        </div>
        <div id="journal"><div class="empty">lade…</div></div>
      </div>
    </div>
  </div>
</main>

<script src="assets/ui.js"></script>
<script>
const pad = n => String(n).padStart(2,'0');
const fmtTime = u => { const d = new Date(u*1000); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; };
const esc = s => (s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

let loginBusy = false;
async function api(url, opts) {
  let r = await fetch(url, opts);
  if (r.status === 401 && !loginBusy) {
    loginBusy = true;
    try {
      const ok = await window.mmLogin('autonomy-api.php');
      if (ok) r = await fetch(url, opts);
    } finally { loginBusy = false; }
  }
  return r;
}

// ── Master-Schalter ────────────────────────────────────────────────────
let enabled = true;
function paintSwitch() {
  const el = document.getElementById('master');
  el.checked = enabled;
  const s = document.getElementById('switch-state');
  s.textContent = enabled ? 'AKTIV' : 'PAUSIERT';
  s.className = enabled ? 'on' : 'off';
}
async function toggleMaster(on) {
  try {
    const r = await api('autonomy-api.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'toggle', enabled: on})
    });
    const d = await r.json();
    enabled = !!d.enabled;
    // Journal-Eintrag nicht hier — der Check schreibt 'blocked' bei Aus.
  } catch(e) { window.mmBanner('Schalter fehlgeschlagen: ' + e.message); }
  paintSwitch();
}

// ── Slots heute ────────────────────────────────────────────────────────
async function loadSlots() {
  const now = Math.floor(Date.now()/1000);
  const d0 = new Date(); d0.setHours(0,0,0,0);
  const from = Math.floor(d0.getTime()/1000), to = from + 86400;
  try {
    const r = await api(`slot-api.php?from=${from}&to=${to}`);
    if (!r.ok) { document.getElementById('slots').innerHTML = '<div class="empty">keine Slot-Daten (Login?)</div>'; return; }
    const d = await r.json();
    const mine = (d.slots||[]).filter(s => s.owner === 'jarvis').sort((a,b)=>a.start_unix-b.start_unix);
    if (!mine.length) { document.getElementById('slots').innerHTML = '<div class="empty">Heute keine Jarvis-Slots gebucht.</div>'; return; }
    document.getElementById('slots').innerHTML = mine.map(s => {
      let cls = 'past', lbl = 'vorbei';
      if (s.start_unix <= now && s.end_unix > now) { cls = 'now'; lbl = 'LÄUFT'; }
      else if (s.start_unix > now) { cls = 'next'; lbl = 'geplant'; }
      return `<div class="row"><span class="t">${fmtTime(s.start_unix)}–${fmtTime(s.end_unix)}</span>
        <span class="badge ${cls}">${lbl}</span><br><span class="m">${esc(s.task||'')}</span></div>`;
    }).join('');
  } catch(e) { document.getElementById('slots').innerHTML = '<div class="empty">Netzwerkfehler</div>'; }
}

// ── Board: arbeit + fällige Ideen ─────────────────────────────────────
async function loadCards() {
  try {
    const r = await api('board-api.php');
    if (!r.ok) { document.getElementById('cards').innerHTML = '<div class="empty">keine Board-Daten (Login?)</div>'; return; }
    const d = await r.json();
    const now = Math.floor(Date.now()/1000);
    const due = (d.cards||[]).filter(c => c.status === 'arbeit' || (c.status === 'idee' && c.due_at && c.due_at <= now));
    if (!due.length) { document.getElementById('cards').innerHTML = '<div class="empty">Nichts fällig — Board leer im Arbeitsfenster.</div>'; return; }
    document.getElementById('cards').innerHTML = due.map(c =>
      `<div class="row"><span class="badge ${c.status}">${c.status === 'arbeit' ? 'in Arbeit' : 'Idee'}</span>
       <span class="t">#${c.id} ${esc(c.title)}</span><br>
       <span class="m">prio ${esc(c.priority||'-')}${c.due_at ? ' · fällig ' + fmtTime(c.due_at) : ''}</span></div>`
    ).join('');
  } catch(e) { document.getElementById('cards').innerHTML = '<div class="empty">Netzwerkfehler</div>'; }
}

// ── Journal ────────────────────────────────────────────────────────────
async function loadJournal() {
  try {
    const r = await api('autonomy-api.php?limit=120');
    if (!r.ok) { document.getElementById('journal').innerHTML = '<div class="empty">kein Journal (Login?)</div>'; return; }
    const d = await r.json();
    enabled = !!d.enabled; paintSwitch();
    const day0 = new Date(); day0.setHours(0,0,0,0);
    const rows = (d.journal||[]).filter(j => j.ts >= Math.floor(day0.getTime()/1000)).reverse();
    if (!rows.length) { document.getElementById('journal').innerHTML = '<div class="empty">Heute noch keine Autonomie-Ereignisse.</div>'; return; }
    document.getElementById('journal').innerHTML = rows.map(j => {
      const parts = [j.slot && `Slot ${esc(j.slot)}`, j.card && esc(j.card), j.action_text && esc(j.action_text), j.note && esc(j.note)].filter(Boolean).join(' · ');
      return `<div class="jrow"><span class="ts">${fmtTime(j.ts)}</span><span class="ev ${esc(j.event)}">${esc(j.event)}</span><span class="note">${parts}</span></div>`;
    }).join('');
  } catch(e) { document.getElementById('journal').innerHTML = '<div class="empty">Netzwerkfehler</div>'; }
}

async function load() {
  await Promise.all([loadJournal(), loadSlots(), loadCards()]);
  document.getElementById('updated').textContent = 'Aktualisiert: ' + new Date().toLocaleTimeString();
}
load();
setInterval(load, 60000);
</script>
</body>
</html>
