<?php
/**
 * slot-planner.php — Standalone slot planning UI.
 * Also usable as tab in mac-monitor dashboard via iframe.
 * URL: /mac-monitor/slot-planner.php
 */
require __DIR__ . '/mac-monitor-config.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Slot Planner</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root, [data-theme="dark"] {
  --bg: #0f1419;
  --panel: #1a2027;
  --border: #2a323d;
  --text: #e6edf3;
  --muted: #8b949e;
  --accent: #ffa657;
  --free-bg: #0d1f12;
  --free-border: #1a3a20;
  --booked-bg: #2d1520;
  --booked-border: #5a2035;
  --mine-bg: #2a2510;
  --mine-border: #5a4a10;
  --past-bg: #15181d;
  --past-border: #1e242c;
  --chart-grid: rgba(255,255,255,0.04);
  --chart-tick: #8b949e;
  --chart-legend: #e6edf3;
}
[data-theme="light"] {
  --bg: #f0f2f5;
  --panel: #ffffff;
  --border: #d0d7de;
  --text: #1c2128;
  --muted: #636c76;
  --accent: #bf5c00;
  --free-bg: #e6f4ea;
  --free-border: #a8d5b8;
  --booked-bg: #fce8e8;
  --booked-border: #f0a0a0;
  --mine-bg: #fff3cd;
  --mine-border: #e6c060;
  --past-bg: #e8eaed;
  --past-border: #c4c8cc;
  --chart-grid: rgba(0,0,0,0.06);
  --chart-tick: #636c76;
  --chart-legend: #1c2128;
}
* { box-sizing: border-box; }
html, body {
  margin: 0; padding: 0;
  font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", system-ui, sans-serif;
  background: var(--bg); color: var(--text);
  transition: background 0.2s, color 0.2s;
  height: 100%;
}
[data-theme="light"] .controls button.active { background: #0969da; color: #fff; border-color: #0969da; }
header {
  padding: 14px 20px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 10px;
  background: var(--panel);
}
h1 { margin: 0; font-size: 18px; font-weight: 600; }
.muted { color: var(--muted); font-size: 13px; }
.controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.controls button, .controls select {
  background: var(--panel); color: var(--text);
  border: 1px solid var(--border);
  padding: 5px 12px; border-radius: 6px; cursor: pointer;
  font-size: 13px; font-family: inherit;
  transition: background 0.2s, border-color 0.2s;
}
.controls button.active { background: #0969da; color: #fff; border-color: #0969da; }
.controls button:hover:not(.active) { background: var(--border); }
main { padding: 16px 20px; max-width: 1400px; margin: 0 auto; }

/* ── Owner identity selector ─────────────────── */
.owner-bar {
  display: flex; align-items: center; gap: 10px; margin-bottom: 14px;
  font-size: 13px;
}
.owner-bar label { color: var(--muted); }
.owner-bar select, .owner-bar input {
  background: var(--panel); color: var(--text);
  border: 1px solid var(--border); border-radius: 6px;
  padding: 4px 10px; font-size: 13px; font-family: inherit;
}

/* ── Grid ────────────────────────────────────── */
.grid-wrap {
  overflow-x: auto;
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 10px;
}
.grid {
  display: grid;
  min-width: 900px;
}
.grid-header {
  display: contents;
}
.grid-header-cell {
  padding: 8px 4px;
  text-align: center;
  font-size: 11px;
  font-weight: 600;
  color: var(--muted);
  border-bottom: 1px solid var(--border);
  border-right: 1px solid var(--border);
  position: sticky; top: 0; background: var(--panel);
  z-index: 2;
}
.grid-header-cell:first-child {
  position: sticky; left: 0; z-index: 3;
  background: var(--panel);
  min-width: 90px;
}
.machine-row {
  display: contents;
}
.machine-label {
  padding: 6px 10px;
  font-size: 13px; font-weight: 600;
  color: var(--text);
  border-bottom: 1px solid var(--border);
  border-right: 1px solid var(--border);
  position: sticky; left: 0;
  background: var(--panel);
  z-index: 1;
  display: flex; align-items: center; gap: 6px;
}
.slot-cell {
  border-bottom: 1px solid var(--border);
  border-right: 1px solid var(--border);
  height: 42px;
  cursor: pointer;
  position: relative;
  transition: background 0.15s;
  padding: 3px 2px;
}
.slot-cell:hover { filter: brightness(1.2); cursor: pointer; }
.slot-cell.booked { background: var(--booked-bg); border-color: var(--booked-border); cursor: default; }
.slot-cell.free   { background: var(--free-bg); border-color: var(--free-border); }
.slot-cell.mine   { background: var(--mine-bg); border-color: var(--mine-border); }
.slot-cell.past   { background: var(--past-bg); border-color: var(--past-border); cursor: default; }
.slot-cell .slot-label {
  font-size: 10px; line-height: 1.3;
  color: var(--muted); overflow: hidden; white-space: nowrap;
  text-overflow: ellipsis; pointer-events: none;
}
.slot-cell.booked .slot-label { color: #f85149; }
.slot-cell.mine .slot-label   { color: #e6c060; }
.slot-cell.booked .owner-tag { font-size: 9px; color: #f85149; display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.slot-cell.mine .owner-tag   { font-size: 9px; color: #e6c060; display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }

/* ── Time markers ─────────────────────────────── */
.time-row {
  display: contents;
}
.time-cell {
  height: 28px;
  border-bottom: 1px solid var(--border);
  border-right: 1px solid var(--border);
  padding: 0 4px;
  display: flex; align-items: center;
}
.time-marker {
  font-size: 10px; color: var(--muted);
  white-space: nowrap;
}

/* ── Dialog ──────────────────────────────────── */
.dialog-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.5);
  display: flex; align-items: center; justify-content: center;
  z-index: 100;
  display: none;
}
.dialog-overlay.open { display: flex; }
.dialog {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 24px;
  min-width: 300px;
  max-width: 400px;
}
.dialog h3 { margin: 0 0 16px 0; font-size: 16px; }
.dialog-row {
  display: flex; flex-direction: column; gap: 6px;
  margin-bottom: 12px;
}
.dialog-row label { font-size: 12px; color: var(--muted); }
.dialog-row input, .dialog-row select {
  background: var(--bg); color: var(--text);
  border: 1px solid var(--border);
  border-radius: 6px; padding: 7px 10px;
  font-size: 13px; font-family: inherit;
}
.dialog-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
.btn {
  border: none; border-radius: 6px; padding: 7px 16px;
  font-size: 13px; cursor: pointer; font-family: inherit;
  transition: opacity 0.2s;
}
.btn:hover { opacity: 0.85; }
.btn-primary { background: #0969da; color: #fff; }
.btn-danger  { background: #f85149; color: #fff; }
.btn-ghost  { background: transparent; color: var(--text); border: 1px solid var(--border); }
.btn-extend { background: #d29922; color: #fff; }
.slot-info { font-size: 12px; color: var(--muted); margin-bottom: 12px; line-height: 1.5; }
.slot-info strong { color: var(--text); }

/* ── Extend info ─────────────────────────────── */
.extend-count { font-size: 10px; color: var(--muted); margin-top: 2px; display: block; }

/* ── Responsive ──────────────────────────────── */
@media (max-width: 600px) {
  main { padding: 10px; }
  h1 { font-size: 15px; }
}
</style>
</head>
<body>

<header>
  <div>
    <h1>⏱ Slot Planner</h1>
    <div class="muted" id="updated">–</div>
  </div>
  <div class="controls">
    <button id="prev-day" onclick="shiftDay(-1)">◀</button>
    <button id="day-label" class="active" style="min-width:100px">–</button>
    <button id="next-day" onclick="shiftDay(1)">▶</button>
    <button onclick="showBookDialog()">+ Slot buchen</button>
  </div>
</header>

<main>
  <div class="owner-bar">
    <label>Ich bin:</label>
    <select id="owner-select">
      <option value="jarvis">Jarvis</option>
      <option value="sascha">Sascha</option>
      <option value="dorian">Dorian</option>
    </select>
    <span class="muted" style="font-size:12px">
      Slot: 30min · Klick auf freies Feld → buchen · Klick auf eigenen Slot → verlängern
    </span>
  </div>
  <div class="grid-wrap" id="grid-wrap">
    <div id="grid"></div>
  </div>
</main>

<!-- Book Dialog -->
<div class="dialog-overlay" id="book-dialog">
  <div class="dialog">
    <h3 id="dialog-title">Slot buchen</h3>
    <div class="slot-info" id="dialog-info"></div>
    <div class="dialog-row">
      <label>Machine</label>
      <select id="dialog-machine">
        <option value="evo-x3">Evo-X3 🟠</option>
        <option value="mac">BigMac 🖥️</option>
        <option value="mini-pc">Mini-PC 📦</option>
      </select>
    </div>
    <div class="dialog-row">
      <label>Task / Beschreibung</label>
      <input id="dialog-task" type="text" placeholder="z.B. 3D-Konfigurator Rendering">
    </div>
    <div class="dialog-row">
      <label>Start</label>
      <input id="dialog-start" type="datetime-local">
    </div>
    <div class="dialog-row">
      <label>Ende</label>
      <input id="dialog-end" type="datetime-local">
    </div>
    <div class="dialog-actions">
      <button class="btn btn-ghost" onclick="closeDialog()">Abbrechen</button>
      <button class="btn btn-primary" id="dialog-book-btn" onclick="bookSlot()">Buchen</button>
      <button class="btn btn-danger" id="dialog-delete-btn" onclick="deleteSlot()" style="display:none">Löschen</button>
      <button class="btn btn-extend" id="dialog-extend-btn" onclick="extendSlot()" style="display:none">Verlängern (+30min)</button>
    </div>
  </div>
</div>

<script>
const SERVERS_RAW = <?php echo json_encode(SERVERS); ?>;
const SECRET_TOKEN = "<?php echo SECRET_TOKEN; ?>";
const SLOTS_URL = "slots.php";
const MACHINES = [
  { id: "evo-x3",  name: "Evo-X3",  icon: "🟠", color: "#ffa657" },
  { id: "mac",     name: "BigMac",  icon: "🖥️", color: "#58a6ff" },
  { id: "mini-pc", name: "Mini-PC", icon: "📦", color: "#7ee787" },
];
const SLOT_MINUTES = 30;
const MAX_EXTEND = 3;

let currentDate = new Date();
let currentSlots = []; // {id, machine, start_unix, end_unix, owner, task, extended}
let dialogMode = 'book'; // 'book' | 'mine'
let dialogSlot = null;

// ── Theme ───────────────────────────────────────────────────────────────
(function(){
  var m = window.matchMedia('(prefers-color-scheme: light)');
  document.documentElement.setAttribute('data-theme', m.matches ? 'light' : 'dark');
})();
window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', (e) => {
  document.documentElement.setAttribute('data-theme', e.matches ? 'light' : 'dark');
});

// ── Helpers ────────────────────────────────────────────────────────────
function unixToLocal(unix, dt) {
  const d = new Date(unix * 1000);
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
function localToUnix(local) {
  return Math.floor(new Date(local).getTime() / 1000);
}
function dayStart(d) {
  const s = new Date(d);
  s.setHours(0,0,0,0);
  return Math.floor(s.getTime() / 1000);
}
function fmtDate(d) {
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
}
function fmtTime(unix) {
  const d = new Date(unix * 1000);
  const pad = n => String(n).padStart(2, '0');
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// ── Load slots ─────────────────────────────────────────────────────────
async function loadSlots() {
  const from = dayStart(currentDate);
  const to   = from + 86400 - 1;
  const url = `${SLOTS_URL}?from=${from}&to=${to}&token=${encodeURIComponent(SECRET_TOKEN)}`;
  try {
    const resp = await fetch(url);
    const data = await resp.json();
    currentSlots = data.slots || [];
  } catch(e) {
    currentSlots = [];
  }
  renderGrid();
  document.getElementById('updated').textContent = 'Aktualisiert: ' + new Date().toLocaleTimeString();
}

// ── Render grid ─────────────────────────────────────────────────────────
function renderGrid() {
  const from = dayStart(currentDate);
  const slots24 = [];
  for (let t = from; t < from + 86400; t += SLOT_MINUTES * 60) {
    slots24.push(t);
  }
  const owner = document.getElementById('owner-select').value;
  const now = Math.floor(Date.now() / 1000);

  // Header row
  let headerHTML = `<div class="grid-header">
    <div class="grid-header-cell" style="position:sticky;left:0;z-index:3;background:var(--panel)">Machine</div>`;
  slots24.forEach(ts => {
    const label = fmtTime(ts);
    headerHTML += `<div class="grid-header-cell">${label}</div>`;
  });
  headerHTML += '</div>';

  // Machine rows
  let rowsHTML = '';
  MACHINES.forEach(m => {
    rowsHTML += `<div class="machine-row">`;
    rowsHTML += `<div class="machine-label" style="position:sticky;left:0;z-index:2;background:var(--panel)">${m.icon} ${m.name}</div>`;
    slots24.forEach(ts => {
      const end = ts + SLOT_MINUTES * 60 - 1;
      const slot = currentSlots.find(s =>
        s.machine === m.id &&
        s.start_unix < end &&
        s.end_unix > ts
      );
      const isPast = end < now;
      const isMine = slot && slot.owner === owner;
      let cls = 'slot-cell';
      if (isPast)     cls += ' past';
      else if (slot && isMine) cls += ' mine';
      else if (slot)           cls += ' booked';
      else                    cls += ' free';

      const label = slot ? (slot.task || slot.owner) : fmtTime(ts);
      const ownerTag = slot ? slot.owner : '';
      const extendInfo = slot && slot.extended > 0 ? `${slot.extended}× verlängert` : '';
      rowsHTML += `<div class="${cls}"
        data-slot='${slot ? JSON.stringify(slot).replace(/'/g, '&#39;') : ''}'
        data-start="${ts}"
        data-end="${end}"
        data-machine="${m.id}"
        onclick="onCellClick(this)">
        <span class="owner-tag">${ownerTag}</span>
        <span class="slot-label">${label}</span>
        ${extendInfo ? `<span class="extend-count">${extendInfo}</span>` : ''}
      </div>`;
    });
    rowsHTML += '</div>';
  });

  document.getElementById('grid').innerHTML = headerHTML + rowsHTML;

  // Set grid columns: 90px for label + 48px per slot (30min in a day = 48 slots)
  const slotCount = slots24.length;
  const gridEl = document.getElementById('grid');
  gridEl.style.gridTemplateColumns = `90px repeat(${slotCount}, minmax(42px, 1fr))`;
}

// ── Cell click ──────────────────────────────────────────────────────────
function onCellClick(el) {
  const slot = el.dataset.slot ? JSON.parse(el.dataset.slot) : null;
  const start = parseInt(el.dataset.start);
  const end   = parseInt(el.dataset.end);
  const machine = el.dataset.machine;
  const owner = document.getElementById('owner-select').value;
  const now = Math.floor(Date.now() / 1000);

  if (slot) {
    // Existing slot
    if (slot.owner !== owner) {
      // Booked by someone else — show info only
      showInfoDialog(slot);
      return;
    }
    // Own slot — show extend/delete
    showOwnSlotDialog(slot);
  } else {
    // Free slot — book
    showBookDialog(start, end, machine);
  }
}

function showInfoDialog(slot) {
  const machine = MACHINES.find(m => m.id === slot.machine);
  document.getElementById('dialog-title').textContent = '⛔ Belegt';
  document.getElementById('dialog-info').innerHTML =
    `<strong>${machine.icon} ${machine.name}</strong><br>
     ${fmtTime(slot.start_unix)} – ${fmtTime(slot.end_unix)}<br>
     Gebucht von: <strong>${slot.owner}</strong><br>
     ${slot.task ? 'Task: ' + slot.task : ''}`;
  document.getElementById('dialog-machine').value = slot.machine;
  document.getElementById('dialog-task').value = slot.task || '';
  document.getElementById('dialog-start').value = unixToLocal(slot.start_unix);
  document.getElementById('dialog-end').value = unixToLocal(slot.end_unix);
  document.getElementById('dialog-book-btn').style.display = 'none';
  document.getElementById('dialog-delete-btn').style.display = 'none';
  document.getElementById('dialog-extend-btn').style.display = 'none';
  document.getElementById('dialog-task').readOnly = true;
  document.getElementById('dialog-machine').disabled = true;
  document.getElementById('dialog-start').disabled = true;
  document.getElementById('dialog-end').disabled = true;
  document.getElementById('book-dialog').classList.add('open');
}

function showOwnSlotDialog(slot) {
  const machine = MACHINES.find(m => m.id === slot.machine);
  const extendLabel = slot.extended >= MAX_EXTEND
    ? `Max. Verlängerungen erreicht (${MAX_EXTEND}×)`
    : `Verlängern um +${SLOT_MINUTES}min`;
  document.getElementById('dialog-title').textContent = '✏ Dein Slot';
  document.getElementById('dialog-info').innerHTML =
    `<strong>${machine.icon} ${machine.name}</strong><br>
     ${fmtTime(slot.start_unix)} – ${fmtTime(slot.end_unix)}`;
  document.getElementById('dialog-task').value = slot.task || '';
  document.getElementById('dialog-task').readOnly = false;
  document.getElementById('dialog-start').value = unixToLocal(slot.start_unix);
  document.getElementById('dialog-end').value = unixToLocal(slot.end_unix);
  document.getElementById('dialog-machine').value = slot.machine;
  document.getElementById('dialog-machine').disabled = true;
  document.getElementById('dialog-start').disabled = true;
  document.getElementById('dialog-end').disabled = true;
  document.getElementById('dialog-book-btn').style.display = 'none';
  document.getElementById('dialog-delete-btn').style.display = slot.extended >= MAX_EXTEND ? 'none' : 'inline-block';
  document.getElementById('dialog-extend-btn').style.display = slot.extended >= MAX_EXTEND ? 'none' : 'inline-block';
  dialogSlot = slot;
  document.getElementById('book-dialog').classList.add('open');
}

function showBookDialog(start, end, machine) {
  const owner = document.getElementById('owner-select').value;
  const now = new Date();
  const defStart = start ? new Date(start * 1000) : new Date(now.getTime() + 60000);
  const defEnd   = end   ? new Date(end   * 1000) : new Date(defStart.getTime() + SLOT_MINUTES * 60000);
  defEnd.setMinutes(Math.ceil(defEnd.getMinutes() / SLOT_MINUTES) * SLOT_MINUTES);

  document.getElementById('dialog-title').textContent = '+ Slot buchen';
  document.getElementById('dialog-info').innerHTML = '';
  document.getElementById('dialog-task').value = '';
  document.getElementById('dialog-task').readOnly = false;
  document.getElementById('dialog-start').value = unixToLocal(Math.floor(defStart.getTime()/1000));
  document.getElementById('dialog-end').value   = unixToLocal(Math.floor(defEnd.getTime()/1000));
  document.getElementById('dialog-machine').value = machine || 'evo-x3';
  document.getElementById('dialog-machine').disabled = false;
  document.getElementById('dialog-start').disabled = false;
  document.getElementById('dialog-end').disabled = false;
  document.getElementById('dialog-book-btn').style.display = 'inline-block';
  document.getElementById('dialog-delete-btn').style.display = 'none';
  document.getElementById('dialog-extend-btn').style.display = 'none';
  dialogSlot = null;
  document.getElementById('book-dialog').classList.add('open');
  document.getElementById('dialog-task').focus();
}

function closeDialog() {
  document.getElementById('book-dialog').classList.remove('open');
  dialogSlot = null;
}

// ── API actions ─────────────────────────────────────────────────────────
async function bookSlot() {
  const machine = document.getElementById('dialog-machine').value;
  const task    = document.getElementById('dialog-task').value.trim();
  const start   = localToUnix(document.getElementById('dialog-start').value);
  const end     = localToUnix(document.getElementById('dialog-end').value);
  const owner   = document.getElementById('owner-select').value;
  if (!machine || !start || !end) return alert('Start und Ende müssen angegeben werden.');
  if (start >= end) return alert('Start muss vor Ende sein.');

  const body = { token: SECRET_TOKEN, machine, start_unix: start, end_unix: end, owner, task };
  const resp = await fetch(SLOTS_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await resp.json();
  if (!resp.ok) {
    alert('Fehler: ' + (data.error || 'Unbekannt'));
    return;
  }
  closeDialog();
  loadSlots();
}

async function deleteSlot() {
  if (!dialogSlot) return;
  if (!confirm('Slot wirklich löschen?')) return;
  const id = encodeURIComponent(dialogSlot.id);
  const resp = await fetch(`${SLOTS_URL}?id=${id}&token=${encodeURIComponent(SECRET_TOKEN)}`, {
    method: 'DELETE',
  });
  closeDialog();
  loadSlots();
}

async function extendSlot() {
  if (!dialogSlot) return;
  const newEnd = dialogSlot.end_unix + SLOT_MINUTES * 60;
  const id = encodeURIComponent(dialogSlot.id);
  const resp = await fetch(`${SLOTS_URL}?id=${id}/extend&token=${encodeURIComponent(SECRET_TOKEN)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token: SECRET_TOKEN, new_end_unix: newEnd }),
  });
  const data = await resp.json();
  if (!resp.ok) {
    alert('Fehler: ' + (data.error || 'Nicht möglich'));
    return;
  }
  closeDialog();
  loadSlots();
}

// ── Day navigation ──────────────────────────────────────────────────────
function shiftDay(delta) {
  currentDate.setDate(currentDate.getDate() + delta);
  updateDayLabel();
  loadSlots();
}
function updateDayLabel() {
  const today = new Date();
  today.setHours(0,0,0,0);
  const d = new Date(currentDate);
  d.setHours(0,0,0,0);
  const label = fmtDate(currentDate);
  const isToday = d.getTime() === today.getTime();
  document.getElementById('day-label').textContent = isToday ? 'Heute' : label;
}

// ── Init ────────────────────────────────────────────────────────────────
updateDayLabel();
loadSlots();
setInterval(loadSlots, 30000); // refresh every 30s
</script>
</body>
</html>
