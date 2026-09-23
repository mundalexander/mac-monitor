<?php
/**
 * slot-planner.php — Feldermatrix Slot Planner.
 * Maschinen-Tags → verfügbare Modelle filtern.
 * Buchung mit Modell-Auswahl.
 */
require __DIR__ . '/mac-monitor-config.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Slot Planner — Feldermatrix</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root, [data-theme="dark"] {
  --bg: #0f1419;
  --panel: #1a2027;
  --border: #2a323d;
  --text: #e6edf3;
  --muted: #8b949e;
  --accent: #ffa657;
  --past-bg: #15181d;
  --past-border: #1e242c;
}
[data-theme="light"] {
  --bg: #f0f2f5;
  --panel: #ffffff;
  --border: #d0d7de;
  --text: #1c2128;
  --muted: #636c76;
  --accent: #bf5c00;
  --past-bg: #e8eaed;
  --past-border: #c4c8cc;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", system-ui, sans-serif; background: var(--bg); color: var(--text); height: 100%; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
header {
  padding: 10px 20px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 8px; background: var(--panel);
}
header h1 { margin: 0; font-size: 15px; font-weight: 600; }
header .muted { color: var(--muted); font-size: 11px; }
.controls { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
.controls button {
  background: var(--panel); color: var(--text);
  border: 1px solid var(--border); padding: 4px 10px;
  border-radius: 6px; cursor: pointer; font-size: 12px; font-family: inherit;
  transition: background 0.15s;
}
.controls button:hover { background: var(--border); }
.controls button.active { background: #0969da; color: #fff; border-color: #0969da; }
#day-label { min-width: 110px; font-weight: 600; }
main { padding: 10px 20px; }

.owner-bar {
  display: flex; align-items: center; gap: 8px; margin-bottom: 8px;
  font-size: 12px; color: var(--muted); flex-wrap: wrap;
}
.owner-bar select, .owner-bar input {
  background: var(--panel); color: var(--text);
  border: 1px solid var(--border); border-radius: 6px;
  padding: 3px 8px; font-size: 12px; font-family: inherit;
}

.legend {
  display: flex; gap: 10px; align-items: center; font-size: 11px;
  margin-bottom: 8px; flex-wrap: wrap;
}
.legend-item { display: flex; align-items: center; gap: 4px; }
.legend-sq { width: 13px; height: 13px; border-radius: 3px; border: 1px solid; }

.grid-outer {
  overflow-x: auto;
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 10px;
}
.grid { display: grid; min-width: 1100px; }

.ghc {
  padding: 4px 0;
  text-align: center;
  font-size: 9px; font-weight: 600;
  color: var(--muted);
  border-bottom: 1px solid var(--border);
  border-right: 1px solid var(--border);
  position: sticky; top: 0; background: var(--panel); z-index: 2;
  white-space: nowrap; overflow: hidden;
}
.ghc.lbl {
  position: sticky; left: 0; z-index: 3;
  background: var(--panel); min-width: 96px;
}
.ghc.time { min-width: 34px; max-width: 34px; }
.ghc.hour { color: var(--text); font-size: 10px; border-top: 2px solid var(--accent); }

.gm {
  padding: 6px 10px;
  font-size: 12px; font-weight: 600;
  border-bottom: 1px solid var(--border);
  border-right: 1px solid var(--border);
  position: sticky; left: 0; z-index: 2;
  background: var(--panel);
  display: flex; align-items: center; gap: 5px;
  white-space: nowrap;
}
.gm-tags { font-size: 9px; font-weight: 400; color: var(--muted); }

.sq {
  border-bottom: 1px solid var(--border);
  border-right: 1px solid var(--border);
  height: 42px;
  cursor: pointer;
  position: relative;
  transition: filter 0.1s, transform 0.08s;
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
  padding: 2px;
}
.sq:hover { filter: brightness(1.2); transform: scale(1.04); z-index: 1; }
.sq.past { background: var(--past-bg) !important; border-color: var(--past-border) !important; cursor: default; filter: none; transform: none; }
.sq.past:hover { filter: none; transform: none; }
.sq-inner {
  width: 100%; height: 100%;
  border-radius: 4px;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 1px;
  overflow: hidden;
}
.sq-model { font-size: 8px; font-weight: 700; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; width: 100%; text-align: center; color: rgba(0,0,0,0.85); }
.sq-owner { font-size: 7px; opacity: 0.7; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; width: 100%; text-align: center; color: rgba(0,0,0,0.6); }
.sq-task  { font-size: 7px; opacity: 0.6; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; width: 100%; text-align: center; color: rgba(0,0,0,0.5); }

/* Mine overlay */
.sq.mine .sq-inner { outline: 2px solid #e6c060; outline-offset: -1px; }

/* Tag chips on booked slots */
.tag-chip {
  position: absolute; top: 1px; right: 1px;
  font-size: 7px; padding: 0 2px;
  border-radius: 2px; opacity: 0.75;
  color: #000; font-weight: 700;
}

/* ── Dialog ─────────────────────────────────── */
.dlg-ov {
  position: fixed; inset: 0; background: rgba(0,0,0,0.6);
  display: flex; align-items: center; justify-content: center; z-index: 100;
  display: none;
}
.dlg-ov.open { display: flex; }
.dlg {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 12px; padding: 22px; min-width: 300px; max-width: 440px;
}
.dlg h3 { margin: 0 0 12px 0; font-size: 14px; display: flex; align-items: center; gap: 6px; }
.dlg-info { font-size: 11px; color: var(--muted); margin-bottom: 12px; line-height: 1.5; }
.dlg-info strong { color: var(--text); }
.row { display: flex; flex-direction: column; gap: 3px; margin-bottom: 8px; }
.row label { font-size: 10px; color: var(--muted); }
.row input, .row select {
  background: var(--bg); color: var(--text);
  border: 1px solid var(--border); border-radius: 6px;
  padding: 6px 10px; font-size: 13px; font-family: inherit;
  width: 100%;
}
.row input:focus, .row select:focus { outline: 1px solid var(--accent); border-color: var(--accent); }
select option { background: var(--panel); }
.dlg-btns { display: flex; gap: 5px; justify-content: flex-end; margin-top: 14px; }
.btn { border: none; border-radius: 6px; padding: 5px 12px; font-size: 12px; cursor: pointer; font-family: inherit; transition: opacity 0.2s; }
.btn:hover { opacity: 0.85; }
.btn-p { background: #0969da; color: #fff; }
.btn-d { background: #f85149; color: #fff; }
.btn-g { background: transparent; color: var(--text); border: 1px solid var(--border); }
.btn-e { background: #d29922; color: #fff; }
#dlg-del-btn, #dlg-ext-btn { display: none; }
.ext-info { font-size: 10px; color: var(--muted); margin-top: 6px; }

.toast {
  position: fixed; bottom: 18px; right: 18px;
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 8px; padding: 9px 14px; font-size: 12px;
  z-index: 200; display: none;
}
.toast.show { display: block; }
.toast.ok { border-color: #2da44e; color: #2da44e; }
.toast.err { border-color: #f85149; color: #f85149; }
</style>
</head>
<body>

<header>
  <div style="display:flex;align-items:center;gap:14px">
    <nav style="display:flex;gap:8px">
      <a href="index.php" style="padding:6px 14px;border-radius:8px;background:#2a323d;color:#e6edf3;text-decoration:none;font-size:13px;font-weight:600">← Dashboard</a>
      <a href="slot-planner.php" style="padding:6px 14px;border-radius:8px;background:#238636;color:#fff;text-decoration:none;font-size:13px;font-weight:600">⏱ Slot-Planung</a>
    </nav>
    <div>
      <h1>⏱ Slot Planner — Feldermatrix</h1>
      <div class="muted" id="updated"></div>
    </div>
  </div>
  <div class="controls">
    <button id="prev-btn" onclick="shiftDay(-1)">◀</button>
    <button id="day-btn" class="active">–</button>
    <button id="next-btn" onclick="shiftDay(1)">▶</button>
    <button onclick="showBookDlg()">+ Buchen</button>
  </div>
</header>

<main>
  <div class="owner-bar">
    <span>Ich:</span>
    <select id="owner-sel">
      <option value="jarvis">Jarvis</option>
      <option value="sascha">Sascha</option>
      <option value="dorian">Dorian</option>
    </select>
    <span>· Klick auf freies Feld → buchen · 48× 30min</span>
    <span id="model-status" style="color:var(--accent);font-size:11px"></span>
  </div>

  <div class="legend" id="legend"></div>
  <div class="grid-outer">
    <div class="grid" id="grid"></div>
  </div>
</main>

<!-- Booking Dialog -->
<div class="dlg-ov" id="dlg">
  <div class="dialog">
    <h3 id="dlg-title">+ Buchen</h3>
    <div class="dlg-info" id="dlg-info"></div>

    <div class="row">
      <label>Maschine</label>
      <select id="dlg-machine" onchange="onMachineChange()">
        <option value="evo-x3">🟠 Evo-X3</option>
        <option value="mac">🖥️ BigMac</option>
        <option value="mini-pc">📦 Mini-PC</option>
      </select>
    </div>

    <div class="row">
      <label>Modell</label>
      <select id="dlg-model">
        <option value="">— lädt… —</option>
      </select>
      <div class="muted" style="font-size:10px;margin-top:2px" id="model-hint"></div>
    </div>

    <div class="row">
      <label>Task / Beschreibung</label>
      <input id="dlg-task" type="text" placeholder="z.B. Code Review, Rendering, Deployment…">
    </div>

    <div class="row">
      <label>Start</label>
      <input id="dlg-start" type="datetime-local">
    </div>

    <div class="row">
      <label>Ende</label>
      <input id="dlg-end" type="datetime-local">
    </div>

    <div id="ext-info" class="ext-info" style="display:none"></div>

    <div class="dlg-btns">
      <button class="btn btn-g" onclick="closeDlg()">Abbrechen</button>
      <button class="btn btn-d" id="dlg-del-btn" onclick="delSlot()">🗑 Löschen</button>
      <button class="btn btn-e" id="dlg-ext-btn" onclick="extSlot()">+30min</button>
      <button class="btn btn-p" id="dlg-book-btn" onclick="bookSlot()">Buchen</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<!-- Login Overlay -->
<div class="dialog-overlay" id="login-overlay" style="display:none">
  <div class="dialog" style="max-width:340px">
    <h3>🔐 Slot Planner Login</h3>
    <div class="dialog-row">
      <label>Dashboard-Token</label>
      <input id="login-token" type="password" placeholder="Token" autocomplete="current-password"
        onkeydown="if(event.key==='Enter')doLogin()">
    </div>
    <div class="dlg-btns">
      <button class="btn btn-p" onclick="doLogin()">Login</button>
    </div>
  </div>
</div>

<script>
// ── Config from PHP ────────────────────────────────────────────────────
const SERVERS  = <?php echo json_encode(SERVERS); ?>;
const SLOT_MIN = 30;
let loggedIn   = false;
const MAX_EXT  = 3;

// Server tag → display name
const TAG_LABELS = {
  'ollama':   'Ollama',
  'lm-studio':'LM Studio',
  'cloud':    '☁ Cloud',
};

// ── State ─────────────────────────────────────────────────────────────
let curDate   = new Date();
let slots     = [];
let machineModels = {};   // { machineId: [{name, server, size_gb}] }
let dlgSlot  = null;

// ── Theme ──────────────────────────────────────────────────────────────
(function(){
  const m = window.matchMedia('(prefers-color-scheme: light)');
  document.documentElement.setAttribute('data-theme', m.matches ? 'light' : 'dark');
  m.addEventListener('change', e => document.documentElement.setAttribute('data-theme', e.matches ? 'light' : 'dark'));
})();

// ── Helpers ────────────────────────────────────────────────────────────
const pad  = n => String(n).padStart(2,'0');
const fmtD = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
const fmtT = u => { const d = new Date(u*1000); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; };
const day0 = d => { const s = new Date(d); s.setHours(0,0,0,0); return Math.floor(s.getTime()/1000); };
const toLoc = u => { const d = new Date(u*1000); return `${fmtD(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`; };
const fromLoc = s => Math.floor(new Date(s).getTime()/1000);
const tagLabel = t => TAG_LABELS[t] || t;
const modelHint = m => m ? `${m.size_gb ? m.size_gb+'GB' : '?'} · ${tagLabel(m.server||'')}` : '';

// ── Load models for all machines ───────────────────────────────────────
async function loadModels() {
  const ids = Object.keys(SERVERS);
  await Promise.all(ids.map(async sid => {
    try {
      const r = await fetch(`data.php?host=${sid}`);
      if (r.status === 401) { showLogin(); return; }
      const d = await r.json();
      const srv = d.servers?.[sid];
      const ollama = srv?.latest?.ollama;
      machineModels[sid] = {
        available: ollama?.available || [],
        loaded:   ollama?.loaded   || [],
      };
    } catch(e) {
      machineModels[sid] = { available: [], loaded: [] };
    }
  }));
  document.getElementById('model-status').textContent =
    Object.values(machineModels).reduce((acc, m) => acc + m.available.length, 0) +
    ' Modelle geladen';
}

// ── Render model selector ───────────────────────────────────────────────
function renderModelSelect(machineId, selectedModel) {
  const sel = document.getElementById('dlg-model');
  const hint = document.getElementById('model-hint');
  sel.innerHTML = '';

  const data = machineModels[machineId];
  if (!data || !data.available.length) {
    sel.innerHTML = '<option value="">Keine Modelle gefunden</option>';
    hint.textContent = '';
    return;
  }

  // Group by server
  const byServer = {};
  data.available.forEach(m => {
    const srv = m.server || 'unknown';
    if (!byServer[srv]) byServer[srv] = [];
    byServer[srv].push(m);
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

  const firstModel = data.available[0];
  hint.textContent = data.available.length + ' Modelle · ' + modelHint(firstModel);
}

// ── Load slots ─────────────────────────────────────────────────────────
async function loadSlots() {
  const from = day0(curDate);
  const to   = from + 86400 - 1;
  try {
    const r = await fetch(`slot-api.php?from=${from}&to=${to}`);
    if (r.status === 401) { showLogin(); return; }
    const d = await r.json();
    slots = d.slots || [];
  } catch(e) { slots = []; }
  render();
  const upd = document.getElementById('updated');
  if (upd) upd.textContent = `Aktualisiert: ${new Date().toLocaleTimeString()}`;
}

// ── Render ─────────────────────────────────────────────────────────────
function render() {
  renderLegend();
  renderGrid();
  updateDayBtn();
}

function renderLegend() {
  const owner = document.getElementById('owner-sel').value;
  let html = `<div class="legend-item"><div class="legend-sq" style="background:#1a2a1a;border-color:#2a4a2a"></div> Frei</div>`;
  Object.values(SERVERS).forEach(m => {
    html += `<div class="legend-item">
      <div class="legend-sq" style="background:${m.color}55;border-color:${m.color}aa"></div>
      ${m.icon} ${m.name}
    </div>`;
  });
  html += `<div class="legend-item"><div class="legend-sq" style="background:#444;border-color:#666"></div> Andere</div>`;
  html += `<div class="legend-item"><div class="legend-sq" style="background:#3a3a10;border-color:#6a6a10;outline:1px solid #e6c060"></div> Mein Slot</div>`;
  html += `<div class="legend-item"><div class="legend-sq" style="background:var(--past-bg);border-color:var(--past-border)"></div> Vergangen</div>`;
  document.getElementById('legend').innerHTML = html;
}

function renderGrid() {
  const from = day0(curDate);
  const now  = Math.floor(Date.now()/1000);
  const owner = document.getElementById('owner-sel').value;
  const nSlots = 48;
  const times = Array.from({length: nSlots}, (_, i) => from + i * SLOT_MIN * 60);

  // Header
  let html = `<div class="gh">`;
  html += `<div class="ghc lbl">Maschine</div>`;
  times.forEach((t, i) => {
    const isHour = t % 3600 === 0;
    html += `<div class="ghc time${isHour ? ' hour' : ''}">${fmtT(t)}</div>`;
  });
  html += `</div>`;

  // Machine rows
  Object.entries(SERVERS).forEach(([sid, m]) => {
    const tags = machineModels[sid]?.available || [];
    const servers = [...new Set(tags.map(t => t.server || 'ollama'))];
    const tagStr = servers.map(tagLabel).join(' · ');

    html += `<div class="gm">${m.icon} ${m.name}
      <span class="gm-tags">${tagStr}</span>
    </div>`;

    times.forEach(ts => {
      const te = ts + SLOT_MIN * 60 - 1;
      const slot = slots.find(s =>
        s.machine === sid && s.start_unix < te && s.end_unix > ts
      );
      const isPast = te < now;
      const isMine = slot && slot.owner === owner;

      let sqClass = 'sq';
      let bg = ''; let border = ''; let inner = '';
      let chip = '';

      if (isPast) {
        sqClass += ' past';
      } else if (slot) {
        const mc = SERVERS[slot.machine]?.color || '#888';
        if (isMine) {
          bg = mc + 'cc'; border = mc; sqClass += ' mine';
        } else {
          bg = mc + '66'; border = mc + 'aa'; sqClass += ' booked';
        }
        const modelName = slot.model
          ? slot.model.split('/').pop().split(':')[0]
          : '';
        inner = `<div class="sq-inner" style="background:${bg};border:1px solid ${border}">
          ${modelName ? `<div class="sq-model">${modelName}</div>` : ''}
          ${slot.owner ? `<div class="sq-owner">${slot.owner}</div>` : ''}
          ${slot.task  ? `<div class="sq-task">${slot.task}</div>`  : ''}
        </div>`;
        if (slot.tags && slot.tags.length) {
          chip = `<span class="tag-chip" style="background:${border}">${slot.tags.slice(0,2).map(t => tagLabel(t)).join('·')}</span>`;
        }
      } else {
        sqClass += ' free';
        inner = `<div class="sq-inner" style="background:#1a2a1a;border:1px solid #2a4a2a"></div>`;
      }

      const slotJson = slot ? JSON.stringify(slot).replace(/'/g,'&#39;') : '';
      html += `<div class="${sqClass}" ${bg ? `style="background:${bg}"` : ''}
        data-slot='${slotJson}'
        data-start="${ts}" data-end="${te}" data-m="${sid}"
        onclick="onSq(this)">
        ${inner}${chip}
      </div>`;
    });
  });

  const gridEl = document.getElementById('grid');
  gridEl.innerHTML = html;
  gridEl.style.gridTemplateColumns = `96px repeat(${nSlots}, minmax(20px, 1fr))`;
}

function updateDayBtn() {
  const today = new Date(); today.setHours(0,0,0,0);
  const d = new Date(curDate); d.setHours(0,0,0,0);
  const isToday = d.getTime() === today.getTime();
  const btn = document.getElementById('day-btn');
  btn.textContent = isToday ? `Heute · ${fmtD(curDate)}` : fmtD(curDate);
  btn.classList.toggle('active', isToday);
}

// ── Click ──────────────────────────────────────────────────────────────
function onSq(el) {
  const slot = el.dataset.slot ? JSON.parse(el.dataset.slot) : null;
  const start = parseInt(el.dataset.start);
  const end   = parseInt(el.dataset.end);
  const mId   = el.dataset.m;
  const owner = document.getElementById('owner-sel').value;
  const now   = Math.floor(Date.now()/1000);

  if (slot) {
    if (slot.owner !== owner) { showInfo(slot); return; }
    showOwn(slot);
  } else {
    if (end < now) { toast('Vergangen.', 'err'); return; }
    showBook(start, end, mId);
  }
}

function showInfo(slot) {
  const m = SERVERS[slot.machine];
  openDlg(`⛔ ${m.icon} ${m.name} — Belegt`,
    `<strong>${m.icon} ${m.name}</strong><br>
     ${fmtT(slot.start_unix)} – ${fmtT(slot.end_unix)}<br>
     ${slot.model ? `Modell: <strong>${slot.model}</strong><br>` : ''}
     Gebucht von: <strong>${slot.owner}</strong><br>
     ${slot.task ? 'Task: ' + slot.task : ''}`,
    false, null);
  document.getElementById('dlg-book-btn').style.display = 'none';
  document.getElementById('dlg-del-btn').style.display = 'none';
  document.getElementById('dlg-ext-btn').style.display = 'none';
  lockDlg(true);
}

function showOwn(slot) {
  const m = SERVERS[slot.machine];
  const maxed = slot.extended >= MAX_EXT;
  openDlg(`✏ ${m.icon} ${m.name} — Mein Slot`,
    `<strong>${m.icon} ${m.name}</strong><br>
     ${fmtT(slot.start_unix)} – ${fmtT(slot.end_unix)}<br>
     ${slot.model ? `Modell: <strong>${slot.model}</strong><br>` : ''}
     Verlängerungen: ${slot.extended}/${MAX_EXT}`,
    false, slot);
  document.getElementById('dlg-machine').value = slot.machine;
  document.getElementById('dlg-task').value = slot.task || '';
  document.getElementById('dlg-start').value = toLoc(slot.start_unix);
  document.getElementById('dlg-end').value = toLoc(slot.end_unix);
  onMachineChange(); // re-render model select with current machine
  if (slot.model) document.getElementById('dlg-model').value = slot.model;
  lockDlg(true);
  document.getElementById('dlg-book-btn').style.display = 'none';
  document.getElementById('dlg-del-btn').style.display = 'inline-block';
  document.getElementById('dlg-ext-btn').style.display = maxed ? 'none' : 'inline-block';
  document.getElementById('ext-info').style.display = maxed ? 'block' : 'none';
  document.getElementById('ext-info').textContent = `Max. Verlängerungen (${MAX_EXT}) erreicht.`;
}

function showBook(start, end, mId) {
  const defS = new Date(start * 1000);
  const defE = new Date(end   * 1000);
  openDlg('+ Slot buchen', '', true, null);
  document.getElementById('dlg-machine').value = mId || 'evo-x3';
  document.getElementById('dlg-task').value = '';
  document.getElementById('dlg-start').value = toLoc(Math.floor(defS.getTime()/1000));
  document.getElementById('dlg-end').value   = toLoc(Math.floor(defE.getTime()/1000));
  onMachineChange();
  lockDlg(false);
  document.getElementById('dlg-book-btn').style.display = 'inline-block';
  document.getElementById('dlg-del-btn').style.display = 'none';
  document.getElementById('dlg-ext-btn').style.display = 'none';
  document.getElementById('ext-info').style.display = 'none';
  document.getElementById('dlg-task').focus();
}

function lockDlg(lock) {
  ['dlg-machine','dlg-model','dlg-task','dlg-start','dlg-end'].forEach(id => {
    document.getElementById(id).disabled = lock;
  });
}

function openDlg(title, info, isBook, slot) {
  document.getElementById('dlg-title').textContent = title;
  document.getElementById('dlg-info').innerHTML = info;
  document.getElementById('dlg-info').style.display = info ? 'block' : 'none';
  dlgSlot = slot;
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

  if (!s || !e || s >= e) { toast('Start muss vor Ende liegen.', 'err'); return; }

  const body = { machine, model, task, start_unix: s, end_unix: e, owner, tags: [] };
  const r = await fetch('slot-api.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(body),
  });
  const d = await r.json();
  if (r.status === 401) { showLogin(); return; }
  if (!r.ok) { toast('Fehler: ' + (d.error||'?'), 'err'); return; }
  closeDlg();
  toast(`✅ Gebucht: ${SERVERS[machine].icon} ${model||'—'} ${fmtT(s)}–${fmtT(e)}`, 'ok');
  loadSlots();
}

async function delSlot() {
  if (!dlgSlot) return;
  if (!confirm('Slot wirklich löschen?')) return;
  const r = await fetch(`slot-api.php?id=${encodeURIComponent(dlgSlot.id)}`, {method:'DELETE'});
  if (r.status === 401) { showLogin(); return; }
  closeDlg();
  toast('🗑 Gelöscht', 'ok');
  loadSlots();
}

async function extSlot() {
  if (!dlgSlot) return;
  const newEnd = dlgSlot.end_unix + SLOT_MIN * 60;
  const r = await fetch(`slot-api.php?id=${encodeURIComponent(dlgSlot.id)}/extend`, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({new_end_unix: newEnd}),
  });
  const d = await r.json();
  if (r.status === 401) { showLogin(); return; }
  if (!r.ok) { toast('Fehler: ' + (d.error||'?'), 'err'); return; }
  closeDlg();
  toast(`⏰ Verlängert bis ${fmtT(newEnd)}`, 'ok');
  loadSlots();
}

// ── Nav ────────────────────────────────────────────────────────────────
function shiftDay(delta) {
  curDate.setDate(curDate.getDate() + delta);
  loadSlots();
}

// ── Login ─────────────────────────────────────────────────────────────
async function showLogin() {
  document.getElementById('login-overlay').style.display = 'flex';
  document.getElementById('login-token').focus();
}

async function doLogin() {
  const token = document.getElementById('login-token').value.trim();
  if (!token) return;
  const r = await fetch('slot-api.php?login=1', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({dashboard_token: token}),
  });
  if (r.ok) {
    document.getElementById('login-overlay').style.display = 'none';
    document.getElementById('login-token').value = '';
    await loadModels();
    await loadSlots();
  } else {
    toast('Token falsch.', 'err');
  }
}

function toast(msg, type='ok') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = `toast show ${type}`;
  clearTimeout(t._tid);
  t._tid = setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Init ───────────────────────────────────────────────────────────────
document.getElementById('owner-sel').addEventListener('change', render);
(async () => {
  await loadModels();
  await loadSlots();
})();
setInterval(loadSlots, 30000);
</script>
</body>
</html>
