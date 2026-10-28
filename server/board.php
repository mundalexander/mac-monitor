<?php
/**
 * board.php — Ideen-Board (Kanban) für mac-monitor (Relaunch 2026-10-28).
 *
 * Drei Spalten: Ideen | In Arbeit | Erledigt.
 * Karte: Titel, Beschreibung, Priorität (low/med/high), Fälligkeit (due_at),
 * Maschine-Tag. Statuswechsel per Drag-and-drop ODER Buttons,
 * In-Kalender-buchen (bei due_at), Löschen.
 * Filter (Priorität/Maschine) + Sortierung (Fälligkeit/Priorität/Neueste).
 * Daten via board-api.php (Session-Login via Login-Modal, oder X-Monitor-Token).
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
<title>Ideen-Board — mac-monitor</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>
  window.__mmTheme=(function(){try{return localStorage.getItem('mm_theme')||'auto'}catch(e){return'auto'}})();
  window.__mmResolveTheme=function(m){return(m==='light'||m==='dark')?m:(window.matchMedia('(prefers-color-scheme: light)').matches?'light':'dark')};
  document.documentElement.setAttribute('data-theme',window.__mmResolveTheme(window.__mmTheme));
  document.documentElement.setAttribute('data-theme-mode',window.__mmTheme);
</script>
<link rel="stylesheet" href="assets/ui.css">
<style>
main { padding: 14px 20px; }

/* ── Anlegen (schnell, eine Zeile) ──────────────────────────────── */
.newcard {
  background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius);
  box-shadow: var(--shadow);
  padding: 12px 14px; margin-bottom: 14px;
  display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end;
}
.newcard .f { display: flex; flex-direction: column; gap: 3px; }
#f-title { flex: 2 1 220px; }
#f-title input { min-width: 200px; }
#f-desc  { flex: 3 1 240px; }
#f-desc input { min-width: 180px; }
.newcard details.adv { flex-basis: 100%; }
.newcard details.adv summary {
  cursor: pointer; font-size: 12px; color: var(--muted);
  padding: 2px 0; user-select: none;
}
.newcard details.adv .adv-grid { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.newcard details.adv .f { min-width: 130px; }

/* ── Toolbar: Filter + Sortierung ───────────────────────────────── */
.board-toolbar {
  display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
  margin-bottom: 12px; font-size: 12px; color: var(--muted);
}
.board-toolbar .filters { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.board-toolbar select { padding: 4px 8px; font-size: 12px; }
.board-toolbar .clear-filter { display: none; }
.board-toolbar .clear-filter.show { display: inline-flex; }
.board-toolbar .hint { margin-left: auto; font-size: 11px; }

/* ── Spalten ────────────────────────────────────────────────────── */
.cols { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; align-items: start; }
.col {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: var(--radius); box-shadow: var(--shadow);
  min-height: 140px;
  transition: border-color .15s, background .15s;
}
.col.drag-over { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 6%, var(--panel)); }
.col h2 {
  margin: 0; padding: 10px 13px; font-size: 12px; font-weight: 700;
  border-bottom: 1px solid var(--border); color: var(--muted);
  display: flex; justify-content: space-between; align-items: center;
  text-transform: uppercase; letter-spacing: .04em;
}
.col h2 .count { background: var(--panel-2); border: 1px solid var(--border); color: var(--text); border-radius: 10px; padding: 0 8px; font-size: 11px; }
.col .cards { padding: 9px; display: flex; flex-direction: column; gap: 9px; }

.card {
  background: var(--bg); border: 1px solid var(--border); border-radius: 9px;
  padding: 10px 12px; cursor: grab;
  transition: border-color .15s, box-shadow .15s, transform .1s;
}
.card:hover { border-color: var(--border-strong); box-shadow: var(--shadow); }
.card.dragging { opacity: .45; transform: rotate(1deg); }
.card .c-title { font-size: 14px; font-weight: 600; line-height: 1.35; }
.card .c-desc { font-size: 12px; color: var(--muted); margin-top: 4px; white-space: pre-wrap; word-break: break-word; }
.card .c-meta { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; font-size: 10px; }
.card .c-btns { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 9px; }

/* Prioritäts-Akzent links an der Karte */
.card.p-high { border-left: 3px solid var(--red); }
.card.p-med  { border-left: 3px solid var(--yellow); }
.card.p-low  { border-left: 3px solid var(--border-strong); }
.card.done .c-title { text-decoration: line-through; color: var(--muted); }

@media (max-width: 860px) {
  .cols { grid-template-columns: 1fr; }
  .board-toolbar .hint { display: none; }
  .newcard .f { flex: 1 1 45%; }
}
</style>
</head>
<body>

<header class="mm-header">
  <div class="mm-brand">
    <nav class="mm-nav" aria-label="Hauptnavigation">
      <a href="index.php"><span class="nav-ico">📊</span>Dashboard</a>
      <a href="calendar.php"><span class="nav-ico">📅</span>Kalender</a>
      <a href="board.php" aria-current="page"><span class="nav-ico">💡</span>Ideen-Board</a>
    </nav>
    <div class="mm-title">
      <h1>💡 Ideen-Board</h1>
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
  <a href="board.php" aria-current="page"><span class="nav-ico">💡</span>Board</a>
</nav>

<main>
  <form class="newcard" id="newcard" onsubmit="return createCard(event)">
    <div class="f" id="f-title"><label for="n-title">Titel *</label>
      <input id="n-title" required maxlength="300" placeholder="Neue Idee… (Enter = anlegen)"></div>
    <div class="f" id="f-desc"><label for="n-desc">Beschreibung</label>
      <input id="n-desc" maxlength="2000" placeholder="optional"></div>
    <button class="btn btn-a" type="submit">+ Anlegen</button>
    <details class="adv">
      <summary>Mehr Optionen (Priorität · Fälligkeit · Maschine · Spalte)</summary>
      <div class="adv-grid">
        <div class="f"><label for="n-prio">Priorität</label>
          <select id="n-prio"><option value="low">🟢 low</option><option value="med" selected>🟡 med</option><option value="high">🔴 high</option></select>
        </div>
        <div class="f"><label for="n-due">Fälligkeit</label><input id="n-due" type="datetime-local"></div>
        <div class="f"><label for="n-machine">Maschine</label>
          <select id="n-machine">
            <option value="">—</option>
            <?php foreach (SERVERS as $sid => $m): ?>
            <option value="<?= htmlspecialchars($sid) ?>"><?= $m['icon'] ?> <?= htmlspecialchars($m['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="f"><label for="n-status">Spalte</label>
          <select id="n-status"><option value="idee" selected>Ideen</option><option value="arbeit">In Arbeit</option><option value="erledigt">Erledigt</option></select>
        </div>
      </div>
    </details>
  </form>

  <div class="board-toolbar">
    <div class="filters">
      <label for="flt-prio">Filter:</label>
      <select id="flt-prio" onchange="render()">
        <option value="">Alle Prioritäten</option>
        <option value="high">🔴 high</option>
        <option value="med">🟡 med</option>
        <option value="low">🟢 low</option>
      </select>
      <select id="flt-machine" onchange="render()">
        <option value="">Alle Maschinen</option>
        <?php foreach (SERVERS as $sid => $m): ?>
        <option value="<?= htmlspecialchars($sid) ?>"><?= $m['icon'] ?> <?= htmlspecialchars($m['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm clear-filter" id="flt-clear" onclick="clearFilters()">✕ Zurücksetzen</button>
    </div>
    <div class="filters">
      <label for="sort-by">Sortieren:</label>
      <select id="sort-by" onchange="render()">
        <option value="due">Fälligkeit</option>
        <option value="prio">Priorität</option>
        <option value="created">Neueste</option>
      </select>
    </div>
    <span class="hint">Tipp: Karten per Drag &amp; drop zwischen Spalten ziehen</span>
  </div>

  <div class="cols">
    <div class="col" data-status="idee"><h2>💡 Ideen <span class="count" id="cnt-idee">0</span></h2><div class="cards" id="col-idee"></div></div>
    <div class="col" data-status="arbeit"><h2>🔨 In Arbeit <span class="count" id="cnt-arbeit">0</span></h2><div class="cards" id="col-arbeit"></div></div>
    <div class="col" data-status="erledigt"><h2>✅ Erledigt <span class="count" id="cnt-erledigt">0</span></h2><div class="cards" id="col-erledigt"></div></div>
  </div>
</main>

<script src="assets/ui.js"></script>
<script>
const MACHINES = <?php echo json_encode(array_map(fn($m) => ['name' => $m['name'], 'icon' => $m['icon']], SERVERS)); ?>;
const STATUS_NEXT = { idee: 'arbeit', arbeit: 'erledigt', erledigt: 'idee' };
const STATUS_LABEL = { idee: 'Ideen', arbeit: 'In Arbeit', erledigt: 'Erledigt' };
const PRIO_ORDER = { high: 0, med: 1, low: 2 };
const PRIO_LABEL = { high: '🔴 high', med: '🟡 med', low: '🟢 low' };
let cards = [];

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const pad = n => String(n).padStart(2,'0');
const fmtDue = u => { const d = new Date(u*1000); return `${pad(d.getDate())}.${pad(d.getMonth()+1)}. ${pad(d.getHours())}:${pad(d.getMinutes())}`; };

// ── API mit Login-Modal (bei 401) ─────────────────────────────────────
let loginBusy = false;
async function api(url, opts) {
  let r = await fetch(url, opts);
  if (r.status === 401 && !loginBusy) {
    loginBusy = true;
    try {
      const ok = await window.mmLogin('board-api.php');
      if (ok) r = await fetch(url, opts);
    } finally { loginBusy = false; }
  }
  return r;
}

// ── Laden + Rendern ────────────────────────────────────────────────────
async function load() {
  try {
    const r = await api('board-api.php');
    if (r.status === 401) {
      window.mmBanner('Login erforderlich — Board-Daten sind gesperrt.', 'Anmelden', () => window.mmLogin('board-api.php').then(ok => { if (ok) { window.mmBanner(null); load(); } }));
      cards = [];
    } else if (!r.ok) {
      const d = await r.json().catch(() => ({}));
      window.mmBanner(d.error || ('HTTP ' + r.status));
      cards = [];
    } else {
      const d = await r.json();
      cards = d.cards || [];
      window.mmBanner(null);
    }
  } catch(e) { cards = []; window.mmBanner('Netzwerkfehler: ' + e.message); }
  render();
  document.getElementById('updated').textContent = 'Aktualisiert: ' + new Date().toLocaleTimeString();
}

function filteredSorted() {
  const fp = document.getElementById('flt-prio').value;
  const fm = document.getElementById('flt-machine').value;
  const sort = document.getElementById('sort-by').value;
  document.getElementById('flt-clear').classList.toggle('show', !!(fp || fm));
  let list = cards.filter(c => (!fp || c.priority === fp) && (!fm || c.machine === fm));
  const cmp = {
    due:     (a,b) => (a.due_at ?? 9e14) - (b.due_at ?? 9e14),
    prio:    (a,b) => (PRIO_ORDER[a.priority] ?? 1) - (PRIO_ORDER[b.priority] ?? 1),
    created: (a,b) => (b.created_at ?? 0) - (a.created_at ?? 0),
  }[sort];
  return [...list].sort(cmp);
}

function clearFilters() {
  document.getElementById('flt-prio').value = '';
  document.getElementById('flt-machine').value = '';
  render();
}

function render() {
  const list = filteredSorted();
  ['idee','arbeit','erledigt'].forEach(st => {
    const box = document.getElementById('col-' + st);
    const mine = list.filter(c => c.status === st);
    const total = cards.filter(c => c.status === st).length;
    document.getElementById('cnt-' + st).textContent = (mine.length === total) ? total : `${mine.length}/${total}`;
    box.innerHTML = mine.map(cardHtml).join('') || emptyHtml(st);
  });
  bindDnd();
}

function emptyHtml(st) {
  const hints = {
    idee:    ['💭', 'Noch keine Ideen.', 'Trage oben deine erste Idee ein.'],
    arbeit:  ['🚀', 'Nichts in Arbeit.', 'Zieh eine Idee hierher oder nutze „→ In Arbeit“.'],
    erledigt:['🏁', 'Noch nichts erledigt.', 'Abgeschlossene Karten landen hier.'],
  }[st];
  return `<div class="empty"><span class="empty-ico">${hints[0]}</span><strong>${hints[1]}</strong><br>${hints[2]}</div>`;
}

function cardHtml(c) {
  const now = Math.floor(Date.now()/1000);
  const mach = MACHINES[c.machine] ? `${MACHINES[c.machine].icon} ${esc(MACHINES[c.machine].name)}` : (c.machine ? esc(c.machine) : '');
  const due = c.due_at ? `<span class="chip due${c.due_at < now && c.status !== 'erledigt' ? ' overdue' : ''}">⏰ ${fmtDue(c.due_at)}</span>` : '';
  const next = STATUS_NEXT[c.status];
  const bookBtn = c.due_at ? `<button class="btn btn-sm btn-s" onclick="bookSlot(${c.id})">📅 Buchen</button>` : '';
  return `<div class="card p-${esc(c.priority)}${c.status === 'erledigt' ? ' done' : ''}" draggable="true" data-id="${c.id}">
    <div class="c-title">${esc(c.title)}</div>
    ${c.description ? `<div class="c-desc">${esc(c.description)}</div>` : ''}
    <div class="c-meta">
      <span class="chip p-${esc(c.priority)}">${PRIO_LABEL[c.priority] || esc(c.priority)}</span>
      ${due}${mach ? `<span class="chip mach">${mach}</span>` : ''}
    </div>
    <div class="c-btns">
      <button class="btn btn-sm" onclick="move(${c.id},'${next}')">→ ${STATUS_LABEL[next]}</button>
      ${bookBtn}
      <button class="btn btn-sm btn-d" onclick="del(${c.id})">🗑</button>
    </div>
  </div>`;
}

// ── Drag & Drop (HTML5, mit Button-Fallback) ──────────────────────────
function bindDnd() {
  document.querySelectorAll('.card[draggable]').forEach(el => {
    el.addEventListener('dragstart', ev => {
      ev.dataTransfer.setData('text/plain', el.dataset.id);
      ev.dataTransfer.effectAllowed = 'move';
      el.classList.add('dragging');
    });
    el.addEventListener('dragend', () => el.classList.remove('dragging'));
  });
  document.querySelectorAll('.col').forEach(col => {
    col.addEventListener('dragover', ev => { ev.preventDefault(); col.classList.add('drag-over'); ev.dataTransfer.dropEffect = 'move'; });
    col.addEventListener('dragleave', () => col.classList.remove('drag-over'));
    col.addEventListener('drop', ev => {
      ev.preventDefault();
      col.classList.remove('drag-over');
      const id = parseInt(ev.dataTransfer.getData('text/plain'), 10);
      const status = col.dataset.status;
      const c = cards.find(x => x.id === id);
      if (c && c.status !== status) move(id, status);
    });
  });
}

// ── Aktionen ───────────────────────────────────────────────────────────
async function createCard(ev) {
  ev.preventDefault();
  const dueVal = document.getElementById('n-due').value;
  const body = {
    title: document.getElementById('n-title').value.trim(),
    description: document.getElementById('n-desc').value.trim(),
    priority: document.getElementById('n-prio').value,
    status: document.getElementById('n-status').value,
    machine: document.getElementById('n-machine').value,
    due_at: dueVal ? Math.floor(new Date(dueVal).getTime()/1000) : null,
  };
  if (!body.title) return false;
  try {
    const r = await api('board-api.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
    if (!r.ok) { const d = await r.json().catch(() => ({})); window.mmToast('Fehler: ' + (d.error || r.status), 'err'); return false; }
    document.getElementById('newcard').reset();
    window.mmToast('✅ Karte angelegt', 'ok');
    load();
  } catch(e) { window.mmToast('Netzwerkfehler: ' + e.message, 'err'); }
  return false;
}

async function move(id, status) {
  try {
    const r = await api('board-api.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id, status }) });
    if (!r.ok) { const d = await r.json().catch(() => ({})); window.mmToast('Fehler: ' + (d.error || r.status), 'err'); return; }
    load();
  } catch(e) { window.mmToast('Netzwerkfehler: ' + e.message, 'err'); }
}

async function del(id) {
  if (!confirm('Karte wirklich löschen?')) return;
  try {
    const r = await api(`board-api.php?id=${id}`, { method: 'DELETE' });
    if (!r.ok) { const d = await r.json().catch(() => ({})); window.mmToast('Fehler: ' + (d.error || r.status), 'err'); return; }
    window.mmToast('🗑 Gelöscht', 'ok');
    load();
  } catch(e) { window.mmToast('Netzwerkfehler: ' + e.message, 'err'); }
}

// Karte mit Fälligkeit → 1h-Slot auf der Maschine buchen (via slot-api)
async function bookSlot(id) {
  const c = cards.find(x => x.id === id || x.id === String(id));
  if (!c || !c.due_at) return;
  const machine = c.machine || 'evo-x3';
  const start = parseInt(c.due_at, 10);
  const body = { machine, model: '', task: '💡 ' + c.title, start_unix: start, end_unix: start + 3600, owner: 'sascha', tags: [] };
  try {
    let r = await api('slot-api.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({}));
    if (r.status === 409) { window.mmToast('⚠ Konflikt: Slot bereits belegt', 'err'); return; }
    if (!r.ok) { window.mmToast('Buchung fehlgeschlagen: ' + (d.error || r.status), 'err'); return; }
    window.mmToast('📅 In Kalender gebucht (1h)', 'ok');
  } catch(e) { window.mmToast('Netzwerkfehler: ' + e.message, 'err'); }
}

load();
setInterval(load, 30000);
</script>
</body>
</html>
