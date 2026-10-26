<?php
/**
 * board.php — Ideen-Board (Kanban) für mac-monitor.
 *
 * Drei Spalten: Ideen | In Arbeit | Erledigt.
 * Karte: Titel, Beschreibung, Priorität (low/med/high), Fälligkeit (due_at),
 * Maschine-Tag. Buttons: Statuswechsel, In Kalender buchen (bei due_at), Löschen.
 * Daten via board-api.php (Session-Login wie slot-api, oder X-Monitor-Token).
 * Look: dunkles Theme, CSS-Variablen identisch zu calendar.php.
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
<title>Ideen-Board — Feldermatrix</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root, [data-theme="dark"] {
  --bg: #0f1419;
  --panel: #1a2027;
  --border: #2a323d;
  --text: #e6edf3;
  --muted: #8b949e;
  --accent: #ffa657;
}
[data-theme="light"] {
  --bg: #f0f2f5;
  --panel: #ffffff;
  --border: #d0d7de;
  --text: #1c2128;
  --muted: #636c76;
  --accent: #bf5c00;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", system-ui, sans-serif; background: var(--bg); color: var(--text); }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
header {
  padding: 10px 20px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 8px; background: var(--panel);
  position: sticky; top: 0; z-index: 50;
}
header h1 { margin: 0; font-size: 15px; font-weight: 600; }
header .muted { color: var(--muted); font-size: 11px; }
main { padding: 12px 20px; }

/* ── Anlegen-Form ─────────────────────────────── */
.newcard {
  background: var(--panel); border: 1px solid var(--border); border-radius: 10px;
  padding: 12px 14px; margin-bottom: 14px;
  display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end;
}
.newcard .f { display: flex; flex-direction: column; gap: 3px; }
.newcard label { font-size: 10px; color: var(--muted); }
.newcard input, .newcard select {
  background: var(--bg); color: var(--text);
  border: 1px solid var(--border); border-radius: 6px;
  padding: 6px 10px; font-size: 13px; font-family: inherit;
}
.newcard input:focus, .newcard select:focus { outline: 1px solid var(--accent); border-color: var(--accent); }
#f-title { flex: 2 1 220px; }
#f-desc  { flex: 3 1 260px; }
.btn { border: none; border-radius: 6px; padding: 6px 12px; font-size: 12px; cursor: pointer; font-family: inherit; transition: opacity .15s; }
.btn:hover { opacity: .85; }
.btn-p { background: #0969da; color: #fff; }
.btn-g { background: transparent; color: var(--text); border: 1px solid var(--border); }
.btn-d { background: #f85149; color: #fff; }
.btn-s { background: #238636; color: #fff; }

/* ── Spalten ──────────────────────────────────── */
.cols { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; align-items: start; }
.col { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; min-height: 120px; }
.col h2 {
  margin: 0; padding: 9px 12px; font-size: 12px; font-weight: 700;
  border-bottom: 1px solid var(--border); color: var(--muted);
  display: flex; justify-content: space-between; align-items: center;
}
.col h2 .count { background: var(--border); color: var(--text); border-radius: 10px; padding: 0 8px; font-size: 11px; }
.col .cards { padding: 8px; display: flex; flex-direction: column; gap: 8px; }

.card {
  background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
  padding: 9px 11px;
}
.card .c-title { font-size: 13px; font-weight: 600; line-height: 1.3; }
.card .c-desc { font-size: 11px; color: var(--muted); margin-top: 4px; white-space: pre-wrap; word-break: break-word; }
.card .c-meta { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 7px; font-size: 10px; }
.chip { border-radius: 4px; padding: 1px 7px; border: 1px solid var(--border); color: var(--muted); }
.chip.p-low  { color: #8b949e; border-color: #30363d; }
.chip.p-med  { color: #d29922; border-color: #d2992266; }
.chip.p-high { color: #f85149; border-color: #f8514966; }
.chip.due.overdue { color: #f85149; border-color: #f8514966; }
.chip.mach { color: #58a6ff; border-color: #58a6ff55; }
.card .c-btns { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 8px; }
.card .c-btns .btn { padding: 3px 9px; font-size: 11px; }

.toast {
  position: fixed; bottom: 18px; right: 18px;
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 8px; padding: 9px 14px; font-size: 12px;
  z-index: 200; display: none; max-width: 340px;
}
.toast.show { display: block; }
.toast.ok  { border-color: #2da44e; color: #2da44e; }
.toast.err { border-color: #f85149; color: #f85149; }

@media (max-width: 860px) { .cols { grid-template-columns: 1fr; } main { padding: 8px 10px; } }
</style>
</head>
<body>

<header>
  <div style="display:flex;align-items:center;gap:14px">
    <nav style="display:flex;gap:8px">
      <a href="index.php" style="padding:6px 14px;border-radius:8px;background:#2a323d;color:#e6edf3;text-decoration:none;font-size:13px;font-weight:600">← Dashboard</a>
      <a href="calendar.php" style="padding:6px 14px;border-radius:8px;background:#2a323d;color:#e6edf3;text-decoration:none;font-size:13px;font-weight:600">📅 Kalender</a>
      <a href="board.php" style="padding:6px 14px;border-radius:8px;background:#8957e5;color:#fff;text-decoration:none;font-size:13px;font-weight:600">💡 Ideen-Board</a>
    </nav>
    <div>
      <h1>💡 Ideen-Board</h1>
      <div class="muted" id="updated"></div>
    </div>
  </div>
</header>

<main>
  <form class="newcard" id="newcard" onsubmit="return createCard(event)">
    <div class="f" id="f-title"><label>Titel *</label><input id="n-title" required maxlength="300" placeholder="Idee…"></div>
    <div class="f" id="f-desc"><label>Beschreibung</label><input id="n-desc" maxlength="2000" placeholder="optional"></div>
    <div class="f"><label>Priorität</label>
      <select id="n-prio"><option value="low">low</option><option value="med" selected>med</option><option value="high">high</option></select>
    </div>
    <div class="f"><label>Fälligkeit</label><input id="n-due" type="datetime-local"></div>
    <div class="f"><label>Maschine</label>
      <select id="n-machine">
        <option value="">—</option>
        <?php foreach (SERVERS as $sid => $m): ?>
        <option value="<?= htmlspecialchars($sid) ?>"><?= $m['icon'] ?> <?= htmlspecialchars($m['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="f"><label>Spalte</label>
      <select id="n-status"><option value="idee" selected>Ideen</option><option value="arbeit">In Arbeit</option><option value="erledigt">Erledigt</option></select>
    </div>
    <button class="btn btn-p" type="submit">+ Karte anlegen</button>
  </form>

  <div class="cols">
    <div class="col"><h2>💡 Ideen <span class="count" id="cnt-idee">0</span></h2><div class="cards" id="col-idee"></div></div>
    <div class="col"><h2>🔨 In Arbeit <span class="count" id="cnt-arbeit">0</span></h2><div class="cards" id="col-arbeit"></div></div>
    <div class="col"><h2>✅ Erledigt <span class="count" id="cnt-erledigt">0</span></h2><div class="cards" id="col-erledigt"></div></div>
  </div>
</main>

<div class="toast" id="toast"></div>

<script>
const MACHINES = <?php echo json_encode(array_map(fn($m) => ['name' => $m['name'], 'icon' => $m['icon']], SERVERS)); ?>;
const STATUS_NEXT = { idee: 'arbeit', arbeit: 'erledigt', erledigt: 'idee' };
const STATUS_LABEL = { idee: 'Ideen', arbeit: 'In Arbeit', erledigt: 'Erledigt' };
let cards = [];

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const pad = n => String(n).padStart(2,'0');
const fmtDue = u => { const d = new Date(u*1000); return `${pad(d.getDate())}.${pad(d.getMonth()+1)}. ${pad(d.getHours())}:${pad(d.getMinutes())}`; };

function toast(msg, type='ok') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = `toast show ${type}`;
  clearTimeout(t._tid); t._tid = setTimeout(() => t.classList.remove('show'), 4000);
}

// ── Login-Helfer: bei 401 einmal nach Dashboard-Token fragen ──────────
let loginTried = false;
async function tryLogin() {
  const tok = prompt('Dashboard-Token (Login):');
  if (!tok) return false;
  try {
    const r = await fetch('board-api.php?login=1', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ dashboard_token: tok }),
    });
    return r.ok;
  } catch(e) { return false; }
}

async function api(url, opts) {
  let r = await fetch(url, opts);
  if (r.status === 401 && !loginTried) {
    loginTried = true;
    if (await tryLogin()) r = await fetch(url, opts);
  }
  return r;
}

// ── Laden + Rendern ────────────────────────────────────────────────────
async function load() {
  try {
    const r = await api('board-api.php');
    const d = await r.json();
    cards = d.cards || [];
    if (r.status === 401) toast('Login erforderlich.', 'err');
  } catch(e) { cards = []; toast('Netzwerkfehler: ' + e.message, 'err'); }
  render();
  document.getElementById('updated').textContent = 'Aktualisiert: ' + new Date().toLocaleTimeString();
}

function render() {
  ['idee','arbeit','erledigt'].forEach(st => {
    const box = document.getElementById('col-' + st);
    const list = cards.filter(c => c.status === st);
    document.getElementById('cnt-' + st).textContent = list.length;
    box.innerHTML = list.map(cardHtml).join('') || '<div class="muted" style="color:var(--muted);font-size:11px;padding:4px 2px">—</div>';
  });
}

function cardHtml(c) {
  const now = Math.floor(Date.now()/1000);
  const mach = MACHINES[c.machine] ? `${MACHINES[c.machine].icon} ${esc(MACHINES[c.machine].name)}` : (c.machine ? esc(c.machine) : '');
  const due = c.due_at ? `<span class="chip due${c.due_at < now && c.status !== 'erledigt' ? ' overdue' : ''}">⏰ ${fmtDue(c.due_at)}</span>` : '';
  const next = STATUS_NEXT[c.status];
  const bookBtn = c.due_at ? `<button class="btn btn-s" onclick="bookSlot(${c.id})">📅 In Kalender buchen</button>` : '';
  return `<div class="card">
    <div class="c-title">${esc(c.title)}</div>
    ${c.description ? `<div class="c-desc">${esc(c.description)}</div>` : ''}
    <div class="c-meta">
      <span class="chip p-${esc(c.priority)}">${esc(c.priority)}</span>
      ${due}${mach ? `<span class="chip mach">${mach}</span>` : ''}
    </div>
    <div class="c-btns">
      <button class="btn btn-g" onclick="move(${c.id},'${next}')">→ ${STATUS_LABEL[next]}</button>
      ${bookBtn}
      <button class="btn btn-d" onclick="del(${c.id})">🗑</button>
    </div>
  </div>`;
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
    if (!r.ok) { const d = await r.json(); toast('Fehler: ' + (d.error || r.status), 'err'); return false; }
    document.getElementById('newcard').reset();
    toast('✅ Karte angelegt', 'ok');
    load();
  } catch(e) { toast('Netzwerkfehler: ' + e.message, 'err'); }
  return false;
}

async function move(id, status) {
  try {
    const r = await api('board-api.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id, status }) });
    if (!r.ok) { const d = await r.json(); toast('Fehler: ' + (d.error || r.status), 'err'); return; }
    load();
  } catch(e) { toast('Netzwerkfehler: ' + e.message, 'err'); }
}

async function del(id) {
  if (!confirm('Karte wirklich löschen?')) return;
  try {
    const r = await api(`board-api.php?id=${id}`, { method: 'DELETE' });
    if (!r.ok) { const d = await r.json(); toast('Fehler: ' + (d.error || r.status), 'err'); return; }
    toast('🗑 Gelöscht', 'ok');
    load();
  } catch(e) { toast('Netzwerkfehler: ' + e.message, 'err'); }
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
    if (r.status === 409) { toast('⚠ Konflikt: Slot bereits belegt', 'err'); return; }
    if (!r.ok) { toast('Buchung fehlgeschlagen: ' + (d.error || r.status), 'err'); return; }
    toast('📅 In Kalender gebucht (1h)', 'ok');
  } catch(e) { toast('Netzwerkfehler: ' + e.message, 'err'); }
}

load();
setInterval(load, 30000);
</script>
</body>
</html>
