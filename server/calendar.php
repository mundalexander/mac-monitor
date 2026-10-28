<?php
/**
 * calendar.php — Kalenderansicht für die Slot-Planung (Relaunch 2026-10-28).
 *
 * Wochenansicht (Mo–So) als Default, Tag/Woche-Umschalter, Stundenraster 00–24,
 * Slots als farbige Blöcke über ihre Dauer (Farbe je Maschine).
 * Buchung über Klick auf freie Zelle, Details/Löschen/Extend über Klick auf Slot.
 * Nutzt die bestehende API unverändert: slot-api.php (GET/POST/DELETE/extend).
 *
 * Bugfix 2026-10-28: slot-api.php ist session-gated (planner_ok). calendar.php
 * behandelte 401/login_required bisher nicht → Kalender blieb stumm leer.
 * Jetzt: Login-Modal (shared ui.js) + sichtbares Fehler-Banner + Leerzustand.
 *
 * Fallback: slot-planner.php (Feldermatrix) bleibt bestehen (Link "Matrix").
 */
require_once __DIR__ . '/mac-monitor-config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ── Ideen-Board: Karten mit Fälligkeit als eigene Kalender-Blöcke ─────
// Nur lesender Zugriff auf die board-Tabelle (board-api.php schreibt).
$boardCards = [];
try {
    $bdo = db();
    $bdo->exec("
        CREATE TABLE IF NOT EXISTS board (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT    NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            priority    TEXT    NOT NULL DEFAULT 'med',
            status      TEXT    NOT NULL DEFAULT 'idee',
            due_at      INTEGER,
            machine     TEXT    NOT NULL DEFAULT '',
            created_at  INTEGER NOT NULL,
            updated_at  INTEGER NOT NULL
        )
    ");
    $boardCards = $bdo->query(
        "SELECT id, title, priority, status, due_at, machine FROM board
         WHERE due_at IS NOT NULL AND status != 'erledigt' ORDER BY due_at"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $boardCards = [];
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Kalender — mac-monitor</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>
  // Pre-Paint-Theme (FOUC-frei); Details in assets/ui.js
  window.__mmTheme=(function(){try{return localStorage.getItem('mm_theme')||'auto'}catch(e){return'auto'}})();
  window.__mmResolveTheme=function(m){return(m==='light'||m==='dark')?m:(window.matchMedia('(prefers-color-scheme: light)').matches?'light':'dark')};
  document.documentElement.setAttribute('data-theme',window.__mmResolveTheme(window.__mmTheme));
  document.documentElement.setAttribute('data-theme-mode',window.__mmTheme);
</script>
<link rel="stylesheet" href="assets/ui.css">
<style>
main { padding: 12px 20px; }

.owner-bar {
  display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
  font-size: 12px; color: var(--muted); flex-wrap: wrap;
}
.owner-bar #period-label { font-weight: 600; color: var(--text); font-size: 14px; }

.legend { display: flex; gap: 12px; align-items: center; font-size: 11px; margin-bottom: 10px; flex-wrap: wrap; }
.legend-item { display: flex; align-items: center; gap: 5px; }
.legend-sq { width: 13px; height: 13px; border-radius: 3px; border: 1px solid; }

/* ── Kalender-Grundgerüst ─────────────────────────────────────────── */
.cal-outer {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  overflow: auto;
  max-height: calc(100vh - 210px);
}
.cal { min-width: 760px; }

.cal-head { display: grid; position: sticky; top: 0; z-index: 5; background: var(--panel);
           border-bottom: 1px solid var(--border); }
.cal-head .hh {
  padding: 7px 8px; text-align: center; font-size: 11px; font-weight: 600;
  border-right: 1px solid var(--border); white-space: nowrap;
  color: var(--muted);
}
.cal-head .hh .dnum { font-size: 15px; color: var(--text); margin-left: 5px; }
.cal-head .hh.today, .cal-head .hh.today .dnum { color: var(--accent); }
.cal-head .hh.gutter { border-right: 1px solid var(--border); }

.cal-body { display: grid; position: relative; }
.gutter { border-right: 1px solid var(--border); }
.hour-label {
  height: var(--hour-px);
  font-size: 10px; color: var(--muted);
  text-align: right; padding: 2px 6px 0 0;
  border-top: 1px solid var(--border);
  font-variant-numeric: tabular-nums;
}
.hour-label:first-child { border-top: none; }

.day-col { position: relative; border-right: 1px solid var(--border); }
.day-col:last-child { border-right: none; }
.day-col.today { background: color-mix(in srgb, var(--accent) 5%, transparent); }
.hour-cell {
  height: var(--hour-px);
  border-top: 1px solid var(--border);
  cursor: pointer; position: relative;
}
.hour-cell:first-child { border-top: none; }
.hour-cell:hover { background: var(--free-hover); }
.hour-cell:hover::after {
  content: '+'; position: absolute; top: 4px; right: 7px;
  color: var(--muted); font-size: 13px; font-weight: 700;
}
.hour-cell.past { cursor: default; background: var(--past-bg); }
.hour-cell.past:hover { background: var(--past-bg); }
.hour-cell.past:hover::after { content: ''; }

.now-line {
  position: absolute; left: 0; right: 0; height: 2px;
  background: var(--now-line); z-index: 4; pointer-events: none;
}
.now-line::before {
  content: ''; position: absolute; left: -4px; top: -3px;
  width: 8px; height: 8px; border-radius: 50%; background: var(--now-line);
}

/* Slot-Blöcke */
.slot {
  position: absolute; border-radius: 6px; padding: 3px 7px;
  overflow: hidden; cursor: pointer; z-index: 3;
  border: 1px solid; min-height: 20px;
  transition: filter .1s, box-shadow .1s;
  box-shadow: 0 1px 3px rgba(0,0,0,.25);
}
.slot:hover { filter: brightness(1.12); z-index: 4; box-shadow: var(--shadow); }
.slot .s-title {
  font-size: 12px; font-weight: 700; line-height: 1.25;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  color: rgba(0,0,0,.88);
}
.slot .s-sub {
  font-size: 10px; line-height: 1.3;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  color: rgba(0,0,0,.62);
}
.slot.mine { outline: 2px solid #e6c060; outline-offset: -1px; }
.slot .s-chip {
  position: absolute; top: 2px; right: 4px;
  font-size: 8px; font-weight: 700; padding: 0 3px; border-radius: 3px;
  color: #000; opacity: .75;
}
.slot.compact { padding: 1px 6px; }
.slot.compact .s-title { font-size: 10px; }
.slot.compact .s-sub { display: none; }

/* Ideen-Board-Blöcke (eigene Farbe, Klick → board.php) */
.bcard { background: #a371f7b3; border-color: #a371f7; }
.bcard .s-title { color: #1c1130; }
.bcard .s-sub { color: rgba(28,17,48,.75); }

.cal-empty { margin: 12px 0 0; }

.ext-info { font-size: 11px; color: var(--muted); margin-top: 6px; }

@media (max-width: 760px) {
  .cal { min-width: 640px; }
  .cal-outer { max-height: calc(100vh - 250px); }
  .mm-controls { width: 100%; justify-content: flex-start; }
}
</style>
</head>
<body>

<header class="mm-header">
  <div class="mm-brand">
    <nav class="mm-nav" aria-label="Hauptnavigation">
      <a href="index.php"><span class="nav-ico">📊</span>Dashboard</a>
      <a href="calendar.php" aria-current="page"><span class="nav-ico">📅</span>Kalender</a>
      <a href="board.php"><span class="nav-ico">💡</span>Ideen-Board</a>
    </nav>
    <div class="mm-title">
      <h1>📅 Kalender</h1>
      <div class="muted" id="updated"></div>
    </div>
  </div>
  <div class="mm-controls">
    <div class="mm-seg" role="group" aria-label="Zeitraum">
      <button id="prev-btn" onclick="shiftPeriod(-1)" title="Zurück">‹</button>
      <button id="today-btn" onclick="goToday()">Heute</button>
      <button id="next-btn" onclick="shiftPeriod(1)" title="Vor">›</button>
    </div>
    <input type="date" id="date-pick" onchange="pickDate(this.value)" title="Datum wählen">
    <div class="mm-seg" role="group" aria-label="Ansicht">
      <button id="view-day" onclick="setView('day')">Tag</button>
      <button id="view-week" class="active" onclick="setView('week')">Woche</button>
    </div>
    <button data-theme-toggle title="Theme wechseln">🔄 Auto</button>
    <a class="btn" href="slot-planner.php" title="Klassische Feldermatrix (Fallback)">Matrix</a>
    <button class="btn btn-a" onclick="openBookDlg()">+ Buchen</button>
  </div>
</header>

<nav class="mm-bottomnav" aria-label="Hauptnavigation mobil">
  <a href="index.php"><span class="nav-ico">📊</span>Dashboard</a>
  <a href="calendar.php" aria-current="page"><span class="nav-ico">📅</span>Kalender</a>
  <a href="board.php"><span class="nav-ico">💡</span>Board</a>
</nav>

<main>
  <div class="owner-bar">
    <label for="owner-sel">Ich:</label>
    <select id="owner-sel">
      <option value="jarvis">Jarvis</option>
      <option value="sascha">Sascha</option>
      <option value="dorian">Dorian</option>
    </select>
    <span id="period-label">–</span>
    <span id="summary"></span>
    <span id="model-status" style="color:var(--accent)"></span>
  </div>

  <div class="legend" id="legend"></div>

  <div class="cal-outer" id="cal-outer">
    <div class="cal" id="cal"></div>
  </div>
  <div class="empty cal-empty" id="cal-empty" style="display:none">
    <span class="empty-ico">🗓️</span>
    In diesem Zeitraum ist nichts gebucht.<br>
    Klick auf eine freie Stunde oder „+ Buchen“, um eine Aktivität anzulegen.
  </div>
</main>

<!-- Booking / Detail Dialog -->
<div class="dlg-ov" id="dlg">
  <div class="dlg">
    <h3 id="dlg-title">+ Slot buchen</h3>
    <div class="dlg-info" id="dlg-info"></div>

    <div class="row" id="row-machine">
      <label for="dlg-machine">Maschine</label>
      <select id="dlg-machine" onchange="onMachineChange()">
        <?php foreach (SERVERS as $sid => $m): ?>
        <option value="<?= htmlspecialchars($sid) ?>"><?= $m['icon'] ?> <?= htmlspecialchars($m['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="row" id="row-model">
      <label for="dlg-model">Modell</label>
      <select id="dlg-model">
        <option value="">— lädt… —</option>
      </select>
      <div class="muted" style="font-size:10px;margin-top:2px" id="model-hint"></div>
    </div>

    <div class="row" id="row-task">
      <label for="dlg-task">Task / Beschreibung</label>
      <input id="dlg-task" type="text" placeholder="z.B. Code Review, Rendering, Deployment…">
    </div>

    <div class="row" id="row-start">
      <label for="dlg-start">Start</label>
      <input id="dlg-start" type="datetime-local">
    </div>

    <div class="row" id="row-end">
      <label for="dlg-end">Ende</label>
      <input id="dlg-end" type="datetime-local">
    </div>

    <div id="ext-info" class="ext-info" style="display:none"></div>

    <div class="dlg-btns">
      <button class="btn" onclick="closeDlg()">Abbrechen</button>
      <button class="btn btn-d" id="dlg-del-btn" style="display:none" onclick="delSlot()">🗑 Löschen</button>
      <button class="btn btn-a" id="dlg-ext-btn" style="display:none" onclick="extSlot()">+30min</button>
      <button class="btn btn-p" id="dlg-book-btn" style="display:none" onclick="bookSlot()">Buchen</button>
    </div>
  </div>
</div>

<script src="assets/ui.js"></script>
<script>
// ── Config from PHP ────────────────────────────────────────────────────
const SERVERS  = <?php echo json_encode(SERVERS); ?>;
const HOUR_PX  = 52;          // Höhe einer Stunde in Pixel
const SNAP_MIN = 15;          // Klick-Snap auf 15-Minute-Raster
const MAX_EXT  = 3;

const BOARD_CARDS = <?php echo json_encode($boardCards); ?>;

const TAG_LABELS = {
  'ollama':    'Ollama',
  'halogen':   '⚡ Halogen',
  'lm-studio': 'LM Studio',
  'cloud':     '☁ Cloud',
};

// ── State ─────────────────────────────────────────────────────────────
let curDate  = new Date(); curDate.setHours(0,0,0,0);
let view     = 'week';       // 'week' | 'day'
let slots    = [];
let slotsError = '';         // sichtbarer Fehlerzustand (Bugfix 2026-10-28)
let machineModels = {};      // { machineId: {available:[], loaded:[]} }
let dlgSlot  = null;

// ── Helpers ────────────────────────────────────────────────────────────
const pad  = n => String(n).padStart(2,'0');
const fmtD = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
const fmtT = u => { const d = new Date(u*1000); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; };
const day0 = d => { const s = new Date(d); s.setHours(0,0,0,0); return Math.floor(s.getTime()/1000); };
const toLoc = u => { const d = new Date(u*1000); return `${fmtD(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`; };
const fromLoc = s => Math.floor(new Date(s).getTime()/1000);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const tagLabel = t => TAG_LABELS[t] || t;
const shortModel = m => m ? m.split('/').pop().split(':')[0] : '';
const MONTHS_SHORT = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
const WD_SHORT = ['So','Mo','Di','Mi','Do','Fr','Sa'];

// ISO-Wochennummer (Mo–So)
function isoWeek(d) {
  const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  const dayNum = (t.getUTCDay() + 6) % 7;
  t.setUTCDate(t.getUTCDate() - dayNum + 3);
  const firstThu = new Date(Date.UTC(t.getUTCFullYear(), 0, 4));
  return 1 + Math.round(((t - firstThu) / 86400000 - 3 + ((firstThu.getUTCDay() + 6) % 7)) / 7);
}

// Sichtbare Tage je nach View (Woche: Mo–So)
function visibleDays() {
  if (view === 'day') return [new Date(curDate)];
  const monday = new Date(curDate);
  const dow = (monday.getDay() + 6) % 7;   // Mo=0 … So=6
  monday.setDate(monday.getDate() - dow);
  return Array.from({length: 7}, (_, i) => { const d = new Date(monday); d.setDate(monday.getDate() + i); return d; });
}

// ── Modelle laden (wie Slot-Planner, via data.php) ────────────────────
async function loadModels() {
  const ids = Object.keys(SERVERS);
  await Promise.all(ids.map(async sid => {
    try {
      const r = await fetch(`data.php?host=${sid}`);
      const d = await r.json();
      const srv = d.servers?.[sid];
      const ollama = srv?.latest?.ollama;
      const loaded = ollama?.loaded || [];
      const avail  = ollama?.available || [];
      machineModels[sid] = { available: [...loaded, ...avail], loaded };
    } catch(e) {
      machineModels[sid] = { available: [], loaded: [] };
    }
  }));
  const n = Object.values(machineModels).reduce((a, m) => a + m.available.length, 0);
  document.getElementById('model-status').textContent = n + ' Modelle geladen';
}

function renderModelSelect(machineId, selectedModel) {
  const sel = document.getElementById('dlg-model');
  const hint = document.getElementById('model-hint');
  sel.innerHTML = '';
  const data = machineModels[machineId];
  if (!data || !data.available.length) {
    sel.innerHTML = '<option value="">Keine Modelle gefunden (optional)</option>';
    hint.textContent = '';
    return;
  }
  const byServer = {};
  data.available.forEach(m => {
    const srv = m.server || 'unknown';
    (byServer[srv] = byServer[srv] || []).push(m);
  });
  Object.keys(byServer).sort().forEach(srv => {
    const group = document.createElement('optgroup');
    group.label = TAG_LABELS[srv] || srv;
    byServer[srv].forEach(m => {
      const opt = document.createElement('option');
      opt.value = m.name;
      opt.textContent = `${m.name}${m.size_gb ? ` (${m.size_gb}GB)` : ''}`;
      if (m.name === selectedModel) opt.selected = true;
      group.appendChild(opt);
    });
    sel.appendChild(group);
  });
  hint.textContent = data.available.length + ' Modelle verfügbar';
}

// ── Slots laden (Sichtbereich der aktuellen Woche/des Tages) ──────────
// Bugfix 2026-10-28: slot-api.php antwortet ohne Session mit 401
// {login_required:true}. Vorher wurde das stumm als "keine Slots"
// interpretiert → Kalender zeigte keine Aktivitäten. Jetzt: Login-Modal,
// danach automatischer Reload; bei Abbruch sichtbares Banner statt Leere.
async function loadSlots() {
  const days = visibleDays();
  const from = day0(days[0]);
  const to   = day0(days[days.length - 1]) + 86400 - 1;
  try {
    const r = await fetch(`slot-api.php?from=${from}&to=${to}`);
    if (r.status === 401) {
      const d = await r.json().catch(() => ({}));
      if (d.login_required) {
        const ok = await window.mmLogin('slot-api.php');
        if (ok) return loadSlots();          // Retry nach erfolgreichem Login
        slots = [];
        slotsError = 'Login erforderlich — ohne Anmeldung sind keine Aktivitäten sichtbar.';
      } else {
        slots = [];
        slotsError = d.error || 'HTTP 401';
      }
    } else if (!r.ok) {
      const d = await r.json().catch(() => ({}));
      slots = [];
      slotsError = d.error || ('HTTP ' + r.status);
    } else {
      const d = await r.json();
      slots = d.slots || [];
      slotsError = '';
    }
  } catch(e) {
    slots = [];
    slotsError = 'Netzwerkfehler: ' + e.message;
  }
  if (slotsError) {
    window.mmBanner(slotsError, 'Erneut versuchen', () => { window.mmBanner(null); loadSlots(); });
  } else {
    window.mmBanner(null);
  }
  render();
  const upd = document.getElementById('updated');
  if (upd) upd.textContent = `Aktualisiert: ${new Date().toLocaleTimeString()}`;
}

// ── Layout: überlappende Slots nebeneinander ──────────────────────────
function layoutColumns(daySlots) {
  const sorted = [...daySlots].sort((a,b) => a.start_unix - b.start_unix || a.end_unix - b.end_unix);
  const clusters = [];
  let cur = [], curEnd = -1;
  for (const s of sorted) {
    if (cur.length && s.start_unix >= curEnd) { clusters.push(cur); cur = []; curEnd = -1; }
    cur.push(s);
    curEnd = Math.max(curEnd, s.end_unix);
  }
  if (cur.length) clusters.push(cur);

  const placed = new Map();
  for (const cl of clusters) {
    const colEnds = [];
    for (const s of cl) {
      let col = colEnds.findIndex(end => end <= s.start_unix);
      if (col === -1) { col = colEnds.length; }
      colEnds[col] = s.end_unix;
      placed.set(s.id, { col, cols: 1 });
    }
    const nCols = colEnds.length;
    for (const s of cl) placed.get(s.id).cols = nCols;
  }
  return placed;
}

// ── Render ─────────────────────────────────────────────────────────────
function render() {
  renderLegend();
  renderCalendar();
  updateLabels();
}

function renderLegend() {
  let html = '';
  Object.values(SERVERS).forEach(m => {
    html += `<div class="legend-item"><div class="legend-sq" style="background:${m.color}cc;border-color:${m.color}"></div>${m.icon} ${esc(m.name)}</div>`;
  });
  html += `<div class="legend-item"><div class="legend-sq" style="background:#555;border-color:#777"></div>Unbekannte Maschine</div>`;
  html += `<div class="legend-item"><div class="legend-sq" style="background:#888;border-color:#888;outline:2px solid #e6c060;outline-offset:-1px"></div>Mein Slot</div>`;
  html += `<div class="legend-item"><div class="legend-sq" style="background:#a371f7b3;border-color:#a371f7"></div>💡 Ideen-Board (Fälligkeit)</div>`;
  html += `<div class="legend-item"><div class="legend-sq" style="background:var(--past-bg);border-color:var(--past-border)"></div>Vergangen</div>`;
  document.getElementById('legend').innerHTML = html;
}

function renderCalendar() {
  const days = visibleDays();
  const owner = document.getElementById('owner-sel').value;
  const now = Math.floor(Date.now()/1000);
  const todayStr = fmtD(new Date());
  const cols = `60px repeat(${days.length}, 1fr)`;

  let head = `<div class="cal-head" style="grid-template-columns:${cols}">`;
  head += `<div class="hh gutter"></div>`;
  days.forEach(d => {
    const isToday = fmtD(d) === todayStr;
    head += `<div class="hh${isToday ? ' today' : ''}">${WD_SHORT[d.getDay()]}<span class="dnum">${d.getDate()}.${pad(d.getMonth()+1)}.</span></div>`;
  });
  head += `</div>`;

  let body = `<div class="cal-body" style="grid-template-columns:${cols};--hour-px:${HOUR_PX}px">`;
  body += `<div class="gutter">`;
  for (let h = 0; h < 24; h++) {
    body += `<div class="hour-label">${pad(h)}:00</div>`;
  }
  body += `</div>`;

  days.forEach(d => {
    const d0 = day0(d);
    const dEnd = d0 + 86400;
    const isToday = fmtD(d) === todayStr;
    body += `<div class="day-col${isToday ? ' today' : ''}">`;

    for (let h = 0; h < 24; h++) {
      const cellEnd = d0 + (h + 1) * 3600;
      const past = cellEnd <= now;
      body += `<div class="hour-cell${past ? ' past' : ''}" data-day="${d0}" data-hour="${h}" onclick="onCellClick(event, this)"></div>`;
    }

    const daySlots = slots.filter(s => s.start_unix < dEnd && s.end_unix > d0);
    const layout = layoutColumns(daySlots);
    daySlots.forEach(s => {
      const cs = Math.max(s.start_unix, d0);
      const ce = Math.min(s.end_unix, dEnd);
      const top = (cs - d0) / 3600 * HOUR_PX;
      const hpx = Math.max((ce - cs) / 3600 * HOUR_PX, 20);
      const { col, cols: nCols } = layout.get(s.id) || { col: 0, cols: 1 };
      const widthPct = 100 / nCols;
      const leftPct = col * widthPct;
      const mc = SERVERS[s.machine]?.color || '#888';
      const isMine = s.owner === owner;
      const bg = isMine ? mc + 'e6' : mc + '99';
      const border = isMine ? mc : mc + 'cc';
      const title = s.task ? s.task : '(ohne Task)';
      const subBits = [esc(s.owner || '—')];
      if (s.model) subBits.push(esc(shortModel(s.model)));
      subBits.push(`${fmtT(s.start_unix)}–${fmtT(s.end_unix)}`);
      const chip = (s.extended > 0)
        ? `<span class="s-chip" style="background:${border}">↻${s.extended}/${MAX_EXT}</span>`
        : (s.tags && s.tags.length ? `<span class="s-chip" style="background:${border}">${esc(s.tags.slice(0,2).map(tagLabel).join('·'))}</span>` : '');
      const compact = hpx < 38 ? ' compact' : '';
      body += `<div class="slot${isMine ? ' mine' : ''}${compact}"
        style="top:${top}px;height:${hpx - 2}px;left:calc(${leftPct}% + 2px);width:calc(${widthPct}% - 4px);background:${bg};border-color:${border}"
        data-id="${esc(s.id)}" onclick="onSlotClick(event, '${esc(s.id)}')"
        title="${esc(title)} · ${esc(s.owner || '')} · ${fmtT(s.start_unix)}–${fmtT(s.end_unix)}">
        <div class="s-title">${esc(title)}</div>
        <div class="s-sub">${subBits.join(' · ')}</div>
        ${chip}
      </div>`;
    });

    BOARD_CARDS.forEach(c => {
      const du = parseInt(c.due_at, 10);
      if (!(du >= d0 && du < dEnd)) return;
      const top = (du - d0) / 3600 * HOUR_PX;
      const pr = c.priority === 'high' ? '🔴' : (c.priority === 'low' ? '🟢' : '🟡');
      const m = SERVERS[c.machine];
      body += `<div class="slot bcard" style="top:${top}px;height:${HOUR_PX - 2}px;left:2px;width:calc(100% - 4px)"
        title="💡 ${esc(c.title)} · Prio ${esc(c.priority)} · ${fmtT(du)} — zum Ideen-Board"
        onclick="window.location='board.php'">
        <div class="s-title">💡 ${esc(c.title)}</div>
        <div class="s-sub">${pr} ${m ? esc(m.name) + ' · ' : ''}${fmtT(du)}</div>
      </div>`;
    });

    if (isToday) {
      const nowTop = (now - d0) / 3600 * HOUR_PX;
      if (nowTop >= 0 && nowTop <= 24 * HOUR_PX) {
        body += `<div class="now-line" style="top:${nowTop}px"></div>`;
      }
    }
    body += `</div>`;
  });
  body += `</div>`;

  document.getElementById('cal').innerHTML = head + body;
  // Leerzustand nur, wenn wirklich nichts zu sehen ist und kein Fehler vorliegt
  const emptyEl = document.getElementById('cal-empty');
  emptyEl.style.display = (!slotsError && slots.length === 0) ? 'block' : 'none';
}

function updateLabels() {
  const days = visibleDays();
  const lbl = document.getElementById('period-label');
  if (view === 'day') {
    const d = days[0];
    lbl.textContent = `${WD_SHORT[d.getDay()]}, ${d.getDate()}. ${MONTHS_SHORT[d.getMonth()]} ${d.getFullYear()}`;
  } else {
    const a = days[0], b = days[6];
    lbl.textContent = `KW ${isoWeek(a)} · ${a.getDate()}. ${MONTHS_SHORT[a.getMonth()]} – ${b.getDate()}. ${MONTHS_SHORT[b.getMonth()]} ${b.getFullYear()}`;
  }
  const owner = document.getElementById('owner-sel').value;
  const mine = slots.filter(s => s.owner === owner).length;
  document.getElementById('summary').textContent =
    slotsError ? '' : `${slots.length} Slots · davon ${mine} von ${owner}`;
  document.getElementById('date-pick').value = fmtD(curDate);
  document.getElementById('view-day').classList.toggle('active', view === 'day');
  document.getElementById('view-week').classList.toggle('active', view === 'week');
}

// ── Klick-Handling ─────────────────────────────────────────────────────
function onCellClick(ev, el) {
  ev.stopPropagation();
  if (el.classList.contains('past')) { window.mmToast('Liegt in der Vergangenheit.', 'err'); return; }
  const dayStart = parseInt(el.dataset.day, 10);
  const hour = parseInt(el.dataset.hour, 10);
  const rect = el.getBoundingClientRect();
  const frac = Math.max(0, Math.min(0.999, (ev.clientY - rect.top) / rect.height));
  const snapSec = SNAP_MIN * 60;
  const offset = Math.floor(frac * 3600 / snapSec) * snapSec;
  const start = dayStart + hour * 3600 + offset;
  const end = start + 3600;
  openBookDlg(start, end);
}

function onSlotClick(ev, id) {
  ev.stopPropagation();
  const s = slots.find(x => x.id === id);
  if (!s) return;
  const owner = document.getElementById('owner-sel').value;
  if (s.owner === owner) showOwn(s); else showInfo(s);
}

function showInfo(s) {
  const m = SERVERS[s.machine] || { icon: '💻', name: s.machine };
  openDlg(`⛔ ${m.icon} ${esc(m.name)} — Belegt`,
    `<strong>${m.icon} ${esc(m.name)}</strong><br>
     ${fmtT(s.start_unix)} – ${fmtT(s.end_unix)}<br>
     ${s.model ? 'Modell: <strong>' + esc(s.model) + '</strong><br>' : ''}
     Gebucht von: <strong>${esc(s.owner)}</strong><br>
     ${s.task ? 'Task: ' + esc(s.task) : ''}`,
    { book: false, del: false, ext: false });
}

function showOwn(s) {
  const m = SERVERS[s.machine] || { icon: '💻', name: s.machine };
  const maxed = s.extended >= MAX_EXT;
  openDlg(`✏ ${m.icon} ${esc(m.name)} — Mein Slot`,
    `<strong>${m.icon} ${esc(m.name)}</strong><br>
     ${fmtT(s.start_unix)} – ${fmtT(s.end_unix)}<br>
     ${s.model ? 'Modell: <strong>' + esc(s.model) + '</strong><br>' : ''}
     Task: ${esc(s.task || '—')}<br>
     Verlängerungen: ${s.extended}/${MAX_EXT}`,
    { book: false, del: true, ext: !maxed, slot: s });
  document.getElementById('dlg-machine').value = s.machine;
  document.getElementById('dlg-task').value = s.task || '';
  document.getElementById('dlg-start').value = toLoc(s.start_unix);
  document.getElementById('dlg-end').value = toLoc(s.end_unix);
  onMachineChange();
  if (s.model) document.getElementById('dlg-model').value = s.model;
  document.getElementById('ext-info').style.display = maxed ? 'block' : 'none';
  document.getElementById('ext-info').textContent = `Max. Verlängerungen (${MAX_EXT}) erreicht.`;
}

// ── Dialog ─────────────────────────────────────────────────────────────
function openBookDlg(start, end) {
  const now = Math.floor(Date.now()/1000);
  if (!start) {
    const base = Math.ceil(now / 1800) * 1800;
    start = base; end = base + 3600;
  }
  openDlg('+ Slot buchen', '', { book: true, del: false, ext: false });
  document.getElementById('dlg-machine').value = 'evo-x3';
  document.getElementById('dlg-task').value = '';
  document.getElementById('dlg-start').value = toLoc(start);
  document.getElementById('dlg-end').value = toLoc(end);
  onMachineChange();
  document.getElementById('ext-info').style.display = 'none';
  document.getElementById('dlg-task').focus();
}

function openDlg(title, info, btns) {
  document.getElementById('dlg-title').textContent = title;
  const infoEl = document.getElementById('dlg-info');
  infoEl.innerHTML = info;
  infoEl.style.display = info ? 'block' : 'none';
  document.getElementById('dlg-book-btn').style.display = btns.book ? 'inline-block' : 'none';
  document.getElementById('dlg-del-btn').style.display = btns.del ? 'inline-block' : 'none';
  document.getElementById('dlg-ext-btn').style.display = btns.ext ? 'inline-block' : 'none';
  dlgSlot = btns.slot || null;
  document.getElementById('dlg').classList.add('open');
}

function closeDlg() {
  document.getElementById('dlg').classList.remove('open');
  dlgSlot = null;
}

function onMachineChange() {
  const mId = document.getElementById('dlg-machine').value;
  renderModelSelect(mId, dlgSlot?.model || '');
}

// ── API ────────────────────────────────────────────────────────────────
async function bookSlot() {
  const machine = document.getElementById('dlg-machine').value;
  const model   = document.getElementById('dlg-model').value;
  const task    = document.getElementById('dlg-task').value.trim();
  const s       = fromLoc(document.getElementById('dlg-start').value);
  const e       = fromLoc(document.getElementById('dlg-end').value);
  const owner   = document.getElementById('owner-sel').value;

  if (!s || !e || s >= e) { window.mmToast('Start muss vor Ende liegen.', 'err'); return; }

  const body = { machine, model, task, start_unix: s, end_unix: e, owner, tags: [] };
  try {
    const r = await fetch('slot-api.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(body),
    });
    const d = await r.json();
    if (r.status === 401) {
      const ok = await window.mmLogin('slot-api.php');
      if (ok) return bookSlot();
      window.mmToast('Login erforderlich.', 'err');
      return;
    }
    if (r.status === 409) {
      window.mmToast(`⚠ Konflikt: ${d.error || 'Slot bereits belegt'} (${fmtT(s)}–${fmtT(e)})`, 'err');
      return;
    }
    if (!r.ok) { window.mmToast('Fehler: ' + (d.error || '?'), 'err'); return; }
    closeDlg();
    const m = SERVERS[machine] || { icon: '💻' };
    window.mmToast(`✅ Gebucht: ${m.icon} ${model || '—'} ${fmtT(s)}–${fmtT(e)}`, 'ok');
    loadSlots();
  } catch(err) {
    window.mmToast('Netzwerkfehler: ' + err.message, 'err');
  }
}

async function delSlot() {
  if (!dlgSlot) return;
  if (!confirm('Slot wirklich löschen?')) return;
  try {
    await fetch(`slot-api.php?id=${encodeURIComponent(dlgSlot.id)}`, { method: 'DELETE' });
    closeDlg();
    window.mmToast('🗑 Gelöscht', 'ok');
    loadSlots();
  } catch(err) {
    window.mmToast('Netzwerkfehler: ' + err.message, 'err');
  }
}

async function extSlot() {
  if (!dlgSlot) return;
  const newEnd = dlgSlot.end_unix + 1800;
  try {
    const r = await fetch(`slot-api.php?id=${encodeURIComponent(dlgSlot.id)}/extend`, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ new_end_unix: newEnd }),
    });
    const d = await r.json();
    if (!r.ok) { window.mmToast('Fehler: ' + (d.error || '?'), 'err'); return; }
    closeDlg();
    window.mmToast(`⏰ Verlängert bis ${fmtT(newEnd)}`, 'ok');
    loadSlots();
  } catch(err) {
    window.mmToast('Netzwerkfehler: ' + err.message, 'err');
  }
}

// ── Navigation ─────────────────────────────────────────────────────────
function shiftPeriod(delta) {
  curDate.setDate(curDate.getDate() + (view === 'week' ? 7 * delta : delta));
  loadSlots();
}
function goToday() {
  curDate = new Date(); curDate.setHours(0,0,0,0);
  loadSlots();
}
function pickDate(v) {
  if (!v) return;
  const [y, m, d] = v.split('-').map(Number);
  curDate = new Date(y, m - 1, d);
  loadSlots();
}
function setView(v) {
  view = v;
  loadSlots();
}

// ── Tastatur: Esc schließt Dialog ──────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeDlg();
});

// ── Init ───────────────────────────────────────────────────────────────
document.getElementById('owner-sel').addEventListener('change', render);
(async () => {
  await loadModels();
  await loadSlots();
  const outer = document.getElementById('cal-outer');
  const now = new Date();
  const target = Math.max(0, (now.getHours() - 1) * HOUR_PX);
  outer.scrollTop = target;
})();
setInterval(loadSlots, 30000);
</script>
</body>
</html>
