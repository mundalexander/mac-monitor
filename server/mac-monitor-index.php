<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Dashboard — mac-monitor</title>
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
main { padding: 20px 24px; max-width: 1400px; margin: 0 auto; }

/* ── Server tabs ──────────────────────────────────────────────── */
.server-tabs {
  display: flex; gap: 0; margin-bottom: 20px;
  border-bottom: 2px solid var(--border);
  overflow-x: auto;
}
.server-tab {
  padding: 10px 20px; cursor: pointer; border: none;
  background: transparent; color: var(--muted);
  font-size: 15px; font-weight: 500; font-family: inherit;
  border-bottom: 3px solid transparent;
  transition: color .2s, border-color .2s;
  white-space: nowrap; display: flex; align-items: center; gap: 8px;
}
.server-tab:hover { color: var(--text); }
.server-tab.active { color: var(--text); border-bottom-color: var(--accent); }
.server-tab .tab-dot { width: 8px; height: 8px; border-radius: 50%; }
.server-tab .tab-os { font-size: 11px; color: var(--muted); margin-left: 4px; }

.host-block { margin-top: 0; }
.host-title { font-size: 16px; font-weight: 600; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px; }
.gauges { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.gauge {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 16px;
  transition: background .2s, border-color .2s;
}
.gauge:hover { border-color: var(--border-strong); }
.gauge .label { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .05em; }
.gauge .value { font-size: 32px; font-weight: 600; margin: 6px 0 8px 0; line-height: 1; font-variant-numeric: tabular-nums; }
.gauge .sub   { color: var(--muted); font-size: 12px; }
.bar { height: 6px; background: var(--bar-bg); border-radius: 3px; overflow: hidden; margin-top: 8px; }
.bar > div { height: 100%; transition: width .4s ease; }
.bar.cpu > div { background: var(--cpu); }
.bar.gpu > div { background: var(--gpu); }
.bar.ram > div { background: var(--ram); }
.bar.kv > div { background: var(--accent); }
.gauge.kv-warn { border-color: var(--red); }
.gauge.kv-warn .value { color: var(--red); }
.chart-wrap {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 16px; margin-top: 12px;
  height: 320px; position: relative;
}
.req-wrap { margin-top: 24px; }
.req-chart-wrap {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 16px; height: 200px; position: relative;
}
.req-legend { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 10px; }
.req-legend-item { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text); }
.req-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.solar-title { font-size: 16px; font-weight: 600; margin: 0 0 8px 0; color: var(--text); }
.solar-value { font-size: 14px; color: var(--muted); margin: 0; }
.solar-chart-wrap {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 16px; height: 320px; position: relative;
}
.token-title { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 8px; }
.req-stats { display: flex; gap: 24px; margin-top: 12px; flex-wrap: wrap; }
.req-stat { text-align: center; }
.req-stat b { display: block; font-size: 22px; font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; }
.req-stat span { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .4px; }

/* ── Side-by-side view ────────────────────────────────────────── */
.server-grid { display: grid; grid-template-columns: 1fr; gap: 24px; }
@media (min-width: 1100px) {
  .server-grid.side-by-side { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 760px) {
  main { padding: 12px; }
  .gauge .value { font-size: 26px; }
  .mm-controls { width: 100%; }
  .mm-controls #machine-selector { flex: 1; }
}
</style>
</head>
<body>
<header class="mm-header">
  <div class="mm-brand">
    <nav class="mm-nav" aria-label="Hauptnavigation">
      <a href="index.php" aria-current="page"><span class="nav-ico">📊</span>Dashboard</a>
      <a href="calendar.php"><span class="nav-ico">📅</span>Kalender</a>
      <a href="board.php"><span class="nav-ico">💡</span>Ideen-Board</a>
    </nav>
    <div class="mm-title">
      <h1>📊 System Monitor</h1>
      <div class="muted" id="updated">Last Update: loading…</div>
    </div>
  </div>
  <div class="mm-controls">
    <select id="machine-selector" title="Maschine waehlen">
      <option value="all">Alle Maschinen</option>
    </select>
    <div class="mm-seg" role="group" aria-label="Zeitraum">
      <button data-range="10m">10m</button>
      <button data-range="30m">30m</button>
      <button data-range="1h" class="active">1h</button>
      <button data-range="6h">6h</button>
      <button data-range="12h">12h</button>
      <button data-range="24h">24h</button>
    </div>
    <button data-theme-toggle title="Theme: Auto">🔄 Auto</button>
  </div>
</header>

<nav class="mm-bottomnav" aria-label="Hauptnavigation mobil">
  <a href="index.php" aria-current="page"><span class="nav-ico">📊</span>Dashboard</a>
  <a href="calendar.php"><span class="nav-ico">📅</span>Kalender</a>
  <a href="board.php"><span class="nav-ico">💡</span>Board</a>
</nav>

<main>
  <div class="server-tabs" id="server-tabs"></div>
  <div id="server-content"></div>
  <div class="req-wrap" id="halogen-req-wrap" style="display:none">
    <h2>Halogen Request-Counter</h2>
    <div class="req-chart-wrap"><canvas id="halogen-req-chart"></canvas></div>
    <div class="req-legend" id="halogen-req-legend"></div>
    <div class="req-stats" id="halogen-req-stats"></div>
  </div>
  <div class="req-wrap" id="token-wrap" style="display:none">
    <h2>Token-Durchsatz (Halogen)</h2>
    <div class="token-title">Prefill t/s (orange, durchgezogen, linke Achse) · Decode t/s (hellorange, gestrichelt, rechte Achse)</div>
    <div class="req-chart-wrap" style="height:240px"><canvas id="token-chart"></canvas></div>
    <div class="req-legend" id="token-legend"></div>
  </div>
  <div id="solar-section" class="solar-wrap" style="display:none;margin-top:24px;padding-bottom:120px">
    <h2 class="solar-title">Solar Power</h2>
    <div id="solar-power" class="solar-value" style="margin-bottom:10px">Current Solar Power Production: –</div>
    <div class="solar-chart-wrap"><canvas id="solar-chart"></canvas></div>
  </div>
</main>

<script src="assets/ui.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js?v=2"></script>
<script>
const REFRESH_INTERVAL = 10; // seconds — must match LaunchAgent interval
const SERVERS = {"evo-x3":{"id":"evo-x3","name":"Evo-X3","host":"sascha-EVO-X3","os":"Ubuntu 24.04","icon":"\ud83d\udfe0","color":"#ffa657"},"mac":{"id":"mac","name":"BigMac","host":"BigMac","os":"macOS","icon":"\ud83d\udda5\ufe0f","color":"#58a6ff"},"mini-pc":{"id":"mini-pc","name":"Mini-PC","host":"MINI-PC","os":"Ubuntu 24.04 (WSL2)","icon":"\ud83d\udce6","color":"#7ee787"}};

const RANGE_BUTTONS = document.querySelectorAll('[data-range]');
const TABS_EL = document.getElementById('server-tabs');
const CONTENT_EL = document.getElementById('server-content');
const UPDATED_EL = document.getElementById('updated');

let currentRange = '1h';
let activeServerId = null;  // set on first data load
let machineFilter = 'all';  // 'all' | server_id — Machine-Selector (Abnahme 2026-10-15)
const charts = {}; // serverId -> Chart instance

// ── Theme-aware chart colors (Engine in assets/ui.js) ─────────────
// Farben werden aus den CSS-Variablen gelesen → konsistente Farbwelt
// über alle Seiten (dunkel + hell), Charts reagieren auf Theme-Wechsel.
function isDarkTheme() {
  return document.documentElement.getAttribute('data-theme') !== 'light';
}
function cssVar(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}
function chartColors() {
  return {
    grid:   cssVar('--chart-grid')   || '#2a323d',
    tick:   cssVar('--chart-tick')   || '#8b949e',
    legend: cssVar('--chart-legend') || '#e6edf3',
    cpu:    cssVar('--cpu')          || '#58a6ff',
    gpu:    cssVar('--gpu')          || '#d2a8ff',
    ram:    cssVar('--ram')          || '#7ee787',
  };
}
// Chart.js-Defaults: konsistente Legende + Tooltip über alle Charts.
function chartLegend(c) {
  return { labels: { color: c.legend, usePointStyle: true, pointStyle: 'line', boxWidth: 22, padding: 14, font: { size: 12 } } };
}
function chartTooltip(extra) {
  return Object.assign({
    backgroundColor: 'rgba(12,16,22,.95)', titleColor: '#e6edf3', bodyColor: '#e6edf3',
    borderColor: '#3d4854', borderWidth: 1, padding: 10, cornerRadius: 8,
    displayColors: true, usePointStyle: true, bodySpacing: 5,
  }, extra || {});
}
function chartScale(c, opts) {
  return Object.assign({
    ticks: { color: c.tick, maxTicksLimit: 8, maxRotation: 0, font: { size: 11 } },
    grid: { color: c.grid, drawTicks: false },
    border: { display: false },
  }, opts || {});
}
function timeLabel(ts) {
  return new Date(ts * 1000).toLocaleTimeString([], { hour12: false, hour: '2-digit', minute: '2-digit' });
}
function applyThemeVars() {
  // Balkenfarben laufen rein über CSS-Variablen (.bar.* > div) — kein JS nötig.
}
function refreshChartsTheme() {
  for (const sid in charts) {
    const info = window._lastData && window._lastData.servers && window._lastData.servers[sid];
    if (info) updateChart(sid, info.series);
  }
  if (window._lastData) {
    updateSolarChart(window._lastData);
    renderTokenChart();
    renderHalogenReqChart();
  }
}
document.addEventListener('mm-theme-change', () => { applyThemeVars(); refreshChartsTheme(); });

RANGE_BUTTONS.forEach(btn => {
  btn.addEventListener('click', () => {
    RANGE_BUTTONS.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentRange = btn.dataset.range;
    refresh();
  });
});

// ── Machine-Selector (Dropdown: Alle + Maschinen) ───────────────────
const MACHINE_SEL_EL = document.getElementById('machine-selector');
if (MACHINE_SEL_EL) {
  MACHINE_SEL_EL.addEventListener('change', () => {
    machineFilter = MACHINE_SEL_EL.value || 'all';
    if (machineFilter !== 'all') activeServerId = machineFilter;
    renderServerContent();
    renderTabs(window._lastData ? (window._lastData.servers || {}) : {});
    renderTokenChart();
    renderHalogenReqChart();
  });
}

function renderMachineSelector(servers) {
  const sel = MACHINE_SEL_EL || document.getElementById('machine-selector');
  if (!sel) return;
  const ids = Object.keys(servers || {});
  if (machineFilter !== 'all' && !servers[machineFilter]) machineFilter = 'all';
  const html = '<option value="all">Alle Maschinen</option>' +
    ids.map(id => {
      const s = servers[id] || {};
      return `<option value="${id}">${s.icon || '💻'} ${s.name || id}</option>`;
    }).join('');
  if (sel.innerHTML !== html) sel.innerHTML = html;
  sel.value = machineFilter;
}

function fmtAgo(ts, now) {
  const d = now - ts;
  if (d < 60) return `${d}s ago`;
  if (d < 3600) return `${Math.floor(d/60)}m ago`;
  return `${Math.floor(d/3600)}h ${Math.floor((d%3600)/60)}m ago`;
}

function statusDotClass(ageSec) {
  if (ageSec < 180) return 'dot';
  if (ageSec < 600) return 'dot stale';
  return 'dot dead';
}

function statusDotColor(ageSec) {
  if (ageSec < 180) return '#7ee787';
  if (ageSec < 600) return '#d29922';
  return '#f85149';
}

// ── Tab rendering ────────────────────────────────────────────────────────
function renderTabs(servers) {
  const ids = Object.keys(servers);
  if (ids.length === 0) return;

  // Pick active tab: preserve selection, else first server
  if (!activeServerId || !servers[activeServerId]) {
    activeServerId = ids[0];
  }

  TABS_EL.innerHTML = ids.map(sid => {
    const s = servers[sid];
    const latest = s.latest;
    const ageSec = latest ? (window._lastData.now - latest.ts) : 999999;
    const dotColor = latest ? statusDotColor(ageSec) : '#f85149';
    const isActive = sid === activeServerId;
    return `<button class="server-tab ${isActive ? 'active' : ''}" data-server-id="${sid}">
      <span class="tab-dot" style="background:${dotColor};box-shadow:0 0 6px ${dotColor}"></span>
      ${s.icon || '💻'} ${s.name}
      <span class="tab-os">${s.os || ''}</span>
    </button>`;
  }).join('');

  TABS_EL.querySelectorAll('.server-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      activeServerId = btn.dataset.serverId;
      machineFilter = activeServerId;
      const sel = document.getElementById('machine-selector');
      if (sel) sel.value = activeServerId;
      TABS_EL.querySelectorAll('.server-tab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      renderServerContent();
      renderTokenChart();
      renderHalogenReqChart();
    });
  });
}

// ── Server content rendering ─────────────────────────────────────────────
// Track what's currently rendered so we only rebuild HTML on tab switch or layout change
let _renderedLayout = null; // 'single' | 'side-by-side' | null
let _renderedServerIds = []; // server IDs currently in DOM

function renderServerContent() {
  if (!window._lastData || !window._lastData.servers) return;
  let servers = window._lastData.servers;
  // Machine-Selector-Filter: nur die gewählte Maschine anzeigen
  if (machineFilter !== 'all') {
    if (servers[machineFilter]) {
      const only = {};
      only[machineFilter] = window._lastData.servers[machineFilter];
      servers = only;
    }
    activeServerId = machineFilter;
  }
  const sid = activeServerId;
  if (!sid || !servers[sid]) return;

  // Determine layout: side-by-side if 2+ servers and wide screen
  const sideBySide = Object.keys(servers).length >= 2 && window.innerWidth >= 1100;
  const layoutKey = sideBySide ? 'side-by-side' : 'single';

  // Which server IDs should be visible?
  const visibleIds = sideBySide ? Object.keys(servers) : [sid];

  // Rebuild HTML only if: layout changed, visible server set changed, or tab switched
  const needsRebuild = _renderedLayout !== layoutKey ||
                       JSON.stringify(_renderedServerIds) !== JSON.stringify(visibleIds);

  if (needsRebuild) {
    let html = '<div class="server-grid' + (sideBySide ? ' side-by-side' : '') + '">';
    if (sideBySide) {
      for (const [id, sv] of Object.entries(servers)) {
        html += renderServerBlock(id, sv);
      }
    } else {
      html += renderServerBlock(sid, servers[sid]);
    }
    html += '</div>';
    CONTENT_EL.innerHTML = html;
    _renderedLayout = layoutKey;
    _renderedServerIds = visibleIds;

    // Destroy old chart instances — canvases were replaced
    for (const key in charts) {
      if (!visibleIds.includes(key)) {
        try { charts[key].destroy(); } catch(e) {}
        delete charts[key];
      }
    }

    // Initialize charts for visible blocks
    for (const id of visibleIds) {
      const sv = servers[id];
      const block = document.getElementById('host-' + id);
      if (block && block.offsetParent !== null) {
        updateChart(id, sv.series);
        updateGauges(id, sv.latest, window._lastData.now);
      }
    }
  } else {
    // Same layout — just update data without rebuilding HTML
    for (const id of visibleIds) {
      const sv = servers[id];
      const block = document.getElementById('host-' + id);
      if (block && block.offsetParent !== null) {
        updateChart(id, sv.series);
        updateGauges(id, sv.latest, window._lastData.now);
      }
    }
  }
}

function renderServerBlock(sid, s) {
  const latest = s.latest;
  const now = window._lastData ? window._lastData.now : Math.floor(Date.now()/1000);
  const ageSec = latest ? (now - latest.ts) : 999999;
  const dotClass = latest ? statusDotClass(ageSec) : 'dot dead';
  const ageText = latest ? fmtAgo(latest.ts, now) : 'no data';

  return `
    <section class="host-block" id="host-${sid}">
      <h2 class="host-title">
        <span class="${dotClass}" id="dot-${sid}"></span>
        ${s.icon || '💻'} ${s.name}
        <span class="muted" id="age-${sid}" style="font-weight:400;font-size:13px;margin-left:8px">${ageText}</span>
      </h2>
      <div class="gauges">
        <div class="gauge">
          <div class="label">CPU</div>
          <div class="value" id="cpu-${sid}">–</div>
          <div class="bar cpu"><div id="cpu-bar-${sid}" style="width:0%"></div></div>
        </div>
        <div class="gauge">
          <div class="label">GPU</div>
          <div class="value" id="gpu-${sid}">–</div>
          <div class="bar gpu"><div id="gpu-bar-${sid}" style="width:0%"></div></div>
          <div class="sub" id="gpu-temp-${sid}" style="margin-top:2px">–</div>
        </div>
        <div class="gauge">
          <div class="label">RAM</div>
          <div class="value" id="ram-${sid}">–</div>
          <div class="sub" id="ram-sub-${sid}"></div>
          <div class="bar ram"><div id="ram-bar-${sid}" style="width:0%"></div></div>
        </div>
        <div class="gauge ollama-box">
          <div class="label">LOADED MODELS</div>
          <div class="value ollama-value" id="models-${sid}" style="font-size:14px;line-height:1.4;"></div>
          <div class="sub muted" id="models-sub-${sid}"></div>
          <div id="unload-wrap-${sid}" style="margin-top:8px"></div>
          <div id="available-wrap-${sid}" style="margin-top:8px;display:none">
            <details><summary style="cursor:pointer;color:var(--muted);font-size:12px">Available (not loaded)</summary>
              <div id="available-${sid}" style="font-size:12px;line-height:1.4;margin-top:6px;color:var(--muted)"></div>
            </details>
          </div>
        </div>
        <div class="gauge">
          <div class="label">TOKEN/S</div>
          <div class="value" id="tps-${sid}">–</div>
          <div class="sub muted" id="tps-sub-${sid}"></div>
        </div>
        <div class="gauge" id="kv-gauge-${sid}" style="display:none">
          <div class="label">KV-POOL</div>
          <div class="value" id="kv-${sid}">–</div>
          <div class="sub muted" id="kv-sub-${sid}"></div>
          <div class="bar kv"><div id="kv-bar-${sid}" style="width:0%"></div></div>
        </div>
      </div>
      <div class="chart-wrap"><canvas id="chart-${sid}"></canvas></div>
    </section>
  `;
}

function serverTagColor(server) {
  const colors = {
    'ollama':    '#58a6ff',
    'lm-studio': '#d2a8ff',
    'llama.cpp':  '#ffa657',
  };
  return colors[server] || 'var(--muted)';
}

function updateGauges(sid, latest, now) {
  if (!latest) return;
  const age = now - latest.ts;
  const dotEl = document.getElementById('dot-' + sid);
  if (dotEl) dotEl.className = statusDotClass(age);
  const ageEl = document.getElementById('age-' + sid);
  if (ageEl) ageEl.textContent = fmtAgo(latest.ts, now);

  const cpu = +latest.cpu, gpu = +latest.gpu, ram = +latest.ram_percent;
  const cpuEl = document.getElementById('cpu-' + sid);
  const gpuEl = document.getElementById('gpu-' + sid);
  const ramEl = document.getElementById('ram-' + sid);
  if (cpuEl) cpuEl.textContent = cpu.toFixed(0) + '%';
  if (gpuEl) gpuEl.textContent = (gpu < 0 ? 'n/a' : gpu.toFixed(0) + '%');
  if (ramEl) ramEl.textContent = ram.toFixed(0) + '%';

  const cpuBar = document.getElementById('cpu-bar-' + sid);
  const gpuBar = document.getElementById('gpu-bar-' + sid);
  const ramBar = document.getElementById('ram-bar-' + sid);
  if (cpuBar) cpuBar.style.width = Math.max(0, cpu) + '%';
  if (gpuBar) gpuBar.style.width = Math.max(0, gpu) + '%';
  if (ramBar) ramBar.style.width = Math.max(0, ram) + '%';

  const gpuTempEl = document.getElementById('gpu-temp-' + sid);
  if (gpuTempEl) gpuTempEl.textContent = (latest.gpu_temp != null) ? latest.gpu_temp + ' °C' : '–';

  if (latest.ram_used_gb && latest.ram_total_gb) {
    const subEl = document.getElementById('ram-sub-' + sid);
    if (subEl) subEl.textContent = `${(+latest.ram_used_gb).toFixed(1)} / ${(+latest.ram_total_gb).toFixed(0)} GB`;
  }

  // Loaded models — grouped by server
  const modelsEl = document.getElementById('models-' + sid);
  const modelsSubEl = document.getElementById('models-sub-' + sid);
  const availableWrap = document.getElementById('available-wrap-' + sid);
  const availableEl = document.getElementById('available-' + sid);
  if (!modelsEl) return;
  const loaded = (latest.ollama && latest.ollama.loaded) ? latest.ollama.loaded : [];
  const available = (latest.ollama && latest.ollama.available) ? latest.ollama.available : [];
  if (loaded.length > 0) {
    // Group by server
    const groups = {};
    loaded.forEach(m => { (groups[m.server||'unknown'] = groups[m.server||'unknown']||[]).push(m); });
    modelsEl.innerHTML = Object.keys(groups).map(srv => {
      const tag = `<span style="color:${serverTagColor(srv)};font-size:11px;font-weight:600">[${srv}]</span>`;
      const items = groups[srv].map(m => {
        // Halogen: size_vram_gb ist Container-RAM (cgroup), kein GPU-Footprint.
        const unit = srv === 'halogen' ? 'GB (Container)' : 'GB';
        const sz = m.size_vram_gb != null ? `(${m.size_vram_gb} ${unit})` : '';
        return `<div style="font-size:13px;margin-left:12px">${m.name} <span style="color:var(--muted)">${sz}</span></div>`;
      }).join('');
      return `${tag}${items}`;
    }).join('<div style="height:6px"></div>');
    const totalVRAM = loaded.reduce((sum, m) => sum + (parseFloat(m.size_vram_gb) || 0), 0);
    if (modelsSubEl) modelsSubEl.textContent = `Total: ${totalVRAM.toFixed(1)} GB`;
    // Unload buttons: one per loaded LM Studio model
    const unloadWrap = document.getElementById('unload-wrap-' + sid);
    if (unloadWrap) {
      const lmModels = loaded.filter(m => m.server === 'lm-studio');
      const hgModels = loaded.filter(m => m.server === 'halogen');
      let html = '';
      // LM Studio: Start + Stop + per-model Unload
      const lmRunning = lmModels.length > 0;
      html += `<div style="margin-bottom:4px;padding:4px 0;border-bottom:1px solid #21262d">`;
      html += `<span style="font-size:11px;color:var(--muted);margin-right:8px">LM Studio:</span>`;
      html += `<span style="font-size:11px;color:${lmRunning ? '#7ee787' : '#f85149'};margin-right:8px">${lmRunning ? '● online' : '● offline'}</span>`;
      if (!lmRunning) {
        html += `<button class="backend-btn" data-sid="${sid}" data-action="lmstudio_start"
          style="margin:2px 4px;background:#7ee787;color:#161b22;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:inherit">▶ Start</button>`;
      } else {
        html += `<button class="backend-btn" data-sid="${sid}" data-action="lmstudio_stop"
          style="margin:2px 4px;background:#f85149;color:#fff;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:inherit">⏹ Stop</button>`;
      }
      html += lmModels.map(m => {
        const short = (m.name || '').split('/').pop().split(':')[0];
        return `<button class="unload-btn" data-sid="${sid}" data-model="${m.name}"
          style="margin:2px 4px;background:#d2a8ff;color:#161b22;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:inherit">
          ⏏ ${short}</button>`;
      }).join('');
      html += `</div>`;
      // Halogen: Start + Stop + Restart
      const hgRunning = hgModels.length > 0;
      html += `<div style="padding:4px 0">`;
      html += `<span style="font-size:11px;color:var(--muted);margin-right:8px">Halogen:</span>`;
      html += `<span style="font-size:11px;color:${hgRunning ? '#7ee787' : '#f85149'};margin-right:8px">${hgRunning ? '● online' : '● offline'}</span>`;
      if (hgRunning) {
        html += `<button class="backend-btn" data-sid="${sid}" data-action="halogen_restart"
          style="margin:2px 4px;background:#7ee787;color:#161b22;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:inherit">🔄 Restart</button>`;
        html += `<button class="backend-btn" data-sid="${sid}" data-action="halogen_stop"
          style="margin:2px 4px;background:#f85149;color:#fff;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:inherit">⏹ Stop</button>`;
      } else {
        html += `<button class="backend-btn" data-sid="${sid}" data-action="halogen_start"
          style="margin:2px 4px;background:#7ee787;color:#161b22;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:11px;font-family:inherit">▶ Start</button>`;
      }
      html += `</div>`;
      unloadWrap.innerHTML = html;
      unloadWrap.querySelectorAll('.unload-btn').forEach(b => {
        b.addEventListener('click', () => requestUnload(b.dataset.model, b, b.dataset.sid));
      });
      unloadWrap.querySelectorAll('.backend-btn').forEach(b => {
        b.addEventListener('click', () => requestHalogenAction(b.dataset.action, b, b.dataset.sid));
      });
    }
  } else if (latest.ollama && latest.ollama.error === 'ollama_offline') {
    modelsEl.innerHTML = '<span style="color:var(--muted)">Ollama offline</span>';
    if (modelsSubEl) modelsSubEl.textContent = '';
    const uw1 = document.getElementById('unload-wrap-' + sid);
    if (uw1) uw1.innerHTML = '';
  } else {
    modelsEl.innerHTML = '<span style="color:var(--muted)">None</span>';
    if (modelsSubEl) modelsSubEl.textContent = '';
    const uw2 = document.getElementById('unload-wrap-' + sid);
    if (uw2) uw2.innerHTML = '';
  }
  // Available (not loaded) models in collapsible section
  if (availableEl && availableWrap) {
    if (available.length > 0) {
      availableWrap.style.display = 'block';
      availableEl.innerHTML = available.map(m => {
        const srv = m.server || '';
        const tag = srv ? ` <span style="color:${serverTagColor(srv)};font-size:10px">[${srv}]</span>` : '';
        const sz = m.size_gb != null ? `(${m.size_gb} GB)` : '';
        return `<div>${m.name} <span style="color:var(--muted)">${sz}</span>${tag}</div>`;
      }).join('');
    } else {
      availableWrap.style.display = 'none';
    }
  }

  // TPS gauge — always update, independent of loaded models
  const tpsGauge = document.getElementById('tps-' + sid);
  const tpsGaugeSub = document.getElementById('tps-sub-' + sid);
  if (tpsGauge) {
    const hTps = latest.halogen_tps != null ? (+latest.halogen_tps).toFixed(1) : null;
    const hPrefill = latest.halogen_prefill_tps != null ? (+latest.halogen_prefill_tps).toFixed(1) : null;
    const hDecode = latest.halogen_decode_tps != null ? (+latest.halogen_decode_tps).toFixed(1) : null;
    const oTps = latest.tokens_per_second != null ? (+latest.tokens_per_second).toFixed(1) : null;
    const lmTps = latest.lm_studio_tps != null ? (+latest.lm_studio_tps).toFixed(1) : null;
    // Jede angezeigte Zahl traegt ihr echtes Backend-Label (kein
    // 'Halogen'-Label auf einem Ollama-Wert).
    const tpsParts = [];
    if (hPrefill != null || hDecode != null) {
      // Prefill/Decode-Split: Prompt-Verarbeitung vs. Generierung getrennt.
      if (hPrefill != null) tpsParts.push([hPrefill, 'Halogen Prefill']);
      if (hDecode != null) tpsParts.push([hDecode, 'Halogen Decode']);
    } else if (hTps != null) {
      // Fallback fuer aeltere Daten ohne Split
      tpsParts.push([hTps, 'Halogen']);
    }
    if (oTps != null) tpsParts.push([oTps, 'Ollama']);
    if (lmTps != null) tpsParts.push([lmTps, 'LM Studio']);
    if (tpsParts.length > 0) {
      tpsGauge.textContent = tpsParts.map(p => p[0]).join(' / ');
      if (tpsGaugeSub) tpsGaugeSub.textContent = 'tok/s — ' + tpsParts.map(p => p[1]).join(' / ');
    } else {
      tpsGauge.textContent = '–';
      if (tpsGaugeSub) tpsGaugeSub.textContent = '';
    }
  }

  // KV-Pool gauge — only show if data present (Halogen on EVO-X3)
  const kvGauge = document.getElementById('kv-gauge-' + sid);
  const kvEl = document.getElementById('kv-' + sid);
  const kvSubEl = document.getElementById('kv-sub-' + sid);
  const kvBar = document.getElementById('kv-bar-' + sid);
  if (kvGauge && kvEl) {
    const kvPct = latest.halogen_kv_pool_pct;
    const kvUsed = latest.halogen_kv_pool_used;
    const kvTotal = latest.halogen_kv_pool_total;
    if (kvPct != null) {
      kvGauge.style.display = 'block';
      kvEl.textContent = kvPct.toFixed(1) + '%';
      if (kvSubEl && kvUsed != null && kvTotal != null) {
        kvSubEl.textContent = kvUsed.toLocaleString() + ' / ' + kvTotal.toLocaleString() + ' pos';
      }
      if (kvBar) { kvBar.style.width = Math.min(100, kvPct) + '%'; }
      if (kvPct > 90) { kvGauge.classList.add('kv-warn'); } else { kvGauge.classList.remove('kv-warn'); }
    } else {
      kvGauge.style.display = 'none';
    }
  }
}

function updateChart(sid, series) {
  const ctx = document.getElementById('chart-' + sid);
  if (!ctx) return;
  const c = chartColors();
  const labels = series.map(p => {
    const d = new Date(p.ts * 1000);
    return d.toLocaleTimeString([], { hour12: false, hour: '2-digit', minute: '2-digit' });
  });

  // Token-TPS (Prefill/Decode/Ollama/LM Studio) wurde ins separate
  // Token-Durchsatz-Panel ausgelagert — hier nur noch Systemlast in %.
  // KV-Pool series (0-100% — gleiche y-Achse wie CPU/GPU/RAM)
  const kvPoolSeries = series.map(p => p.kv_pool_pct != null ? p.kv_pool_pct : null);
  const hasKv = kvPoolSeries.some(v => v != null);
  const kvDatasets = [];
  if (hasKv) {
    kvDatasets.push({
      label: 'KV-Pool %', data: kvPoolSeries,
      borderColor: '#f85149', backgroundColor: 'transparent',
      tension: 0.3, pointRadius: 0, borderWidth: 1.5,
      yAxisID: 'y', order: -2,
    });
  }
  const datasets = [
    { label: 'CPU', data: series.map(p => p.cpu),      borderColor: c.cpu, backgroundColor: c.cpu + '1a', tension: 0.3, pointRadius: 0, borderWidth: 2 },
    { label: 'GPU', data: series.map(p => Math.max(0, p.gpu)), borderColor: c.gpu, backgroundColor: c.gpu + '1a', tension: 0.3, pointRadius: 0, borderWidth: 2 },
    { label: 'RAM', data: series.map(p => p.ram),     borderColor: c.ram, backgroundColor: c.ram + '1a', tension: 0.3, pointRadius: 0, borderWidth: 2 },
    ...kvDatasets,
  ];

  // Destroy and recreate so TPS datasets (which change per update) are properly applied
  if (charts[sid]) { try { charts[sid].destroy(); } catch(e) {} }
  charts[sid] = new Chart(ctx, {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true, maintainAspectRatio: false, animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: chartLegend(c),
        tooltip: chartTooltip({ callbacks: {
          title: (items) => items[0].label,
          label: (ctx) => ' ' + ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(1) + '%',
        } }),
      },
      scales: {
        x: chartScale(c),
        y: chartScale(c, { beginAtZero: true, max: 100, ticks: { color: c.tick, stepSize: 25, callback: v => v + '%', font: { size: 11 } } }),
      },
    },
  });
}

// ── Unload: POST to server queue; monitor client polls & executes ──
// Kein Secret-Token im Frontend — unload-request.php arbeitet server-seitig.
function requestHalogenAction(action, btn, sid) {
  btn.disabled = true;
  const origText = btn.textContent;
  btn.textContent = '⏳ ...';
  fetch('unload-request.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ action: action, server_id: sid, model_id: 'backend' }),
  }).then(r => r.json()).then(d => {
    if (d.ok) { btn.textContent = '✅ Sent'; setTimeout(() => { btn.textContent = origText; btn.disabled = false; }, 3000); }
    else { btn.textContent = '❌ Error'; btn.disabled = false; }
  }).catch(() => { btn.textContent = '❌ Error'; btn.disabled = false; });
}

function requestUnload(modelId, btn, sid) {
  if (!modelId) return;
  fetch('unload-request.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'lm_studio_unload', server_id: sid, model_id: modelId }),
  }).then(r => r.json()).then(d => {
    if (!btn) return;
    if (d.ok) {
      btn.textContent = '✓ Befehl in Queue';
      btn.style.background = '#7ee787';
      btn.disabled = true;
    } else {
      btn.textContent = '✗ Fehler';
      btn.style.background = '#f85149';
    }
  }).catch(() => {
    if (btn) {
      btn.textContent = '✗ Fehler';
      btn.style.background = '#f85149';
    }
  });
}

const IP_LABELS = {
  'evo-x3':      'Evo-X3',
  '127.0.0.1':   'Bernd',
  '192.168.178.112': 'Bernd',
  '100.67.189.1': 'Dorian',
  '100.91.16.62': 'Sascha',
  '100.77.241.124': 'Sascha',
};
const IP_COLOR_DARK = {
  'evo-x3': '#7ee787', '127.0.0.1': '#58a6ff', '192.168.178.112': '#58a6ff',
  '100.67.189.1': '#ffa657', '100.91.16.62': '#f0883e', '100.77.241.124': '#f0883e',
};
const IP_COLOR_LIGHT = {
  'evo-x3': '#1a7f37', '127.0.0.1': '#0969da', '192.168.178.112': '#0969da',
  '100.67.189.1': '#bf5c00', '100.91.16.62': '#9a4600', '100.77.241.124': '#9a4600',
};

let reqChart = null;
let solarChart = null;

function ipColor(ip, isDark) {
  const pal = isDark ? IP_COLOR_DARK : IP_COLOR_LIGHT;
  return pal[ip] || (isDark ? '#8b949e' : '#636c76');
}

function updateSolarChart(data) {
  const servers = data.servers || {};
  // Find the first server with shelly_power
  let solarServer = null, latestW = null, series = [];
  for (const [sid, info] of Object.entries(servers)) {
    const w = info.latest && info.latest.shelly_power;
    if (w !== null && w !== undefined) {
      solarServer = sid;
      latestW = w;
      series = info.series || [];
      break;
    }
  }
  const solarSection = document.getElementById('solar-section');
  const solarPower   = document.getElementById('solar-power');
  if (!solarSection || !solarPower) return;

  if (latestW !== null && latestW !== undefined) {
    solarSection.style.display = 'block';
    solarPower.textContent = 'Current Solar Power Production: ' + (latestW > 0 ? latestW.toFixed(0) : '0') + ' W';
  } else {
    solarSection.style.display = 'none';
    return;
  }

  const solarSeries = series
    .filter(p => p.shelly_power !== null && p.shelly_power !== undefined)
    .map(p => ({
      ts:    p.ts,
      label: new Date(p.ts * 1000).toLocaleTimeString([], {hour12: false, hour:'2-digit', minute:'2-digit'}),
      w:     p.shelly_power,
    }));

  const c = chartColors();
  const solarCtx = document.getElementById('solar-chart');
  const isHigh    = latestW >= 150;
  const solarLabel = isHigh ? '🌿 Solar' : 'Solar';

  if (solarChart) {
    solarChart.data.labels = solarSeries.map(p => p.label);
    solarChart.data.datasets[0].data   = solarSeries.map(p => p.w);
    solarChart.data.datasets[0].label  = solarLabel;
    solarChart.options.scales.x = chartScale(c);
    solarChart.options.scales.y = chartScale(c, { beginAtZero: true, ticks: { color: c.tick, font: { size: 11 } }, title: { display: true, text: 'W', color: c.tick } });
    solarChart.options.plugins.legend = chartLegend(c);
    solarChart.update('none');
  } else {
    solarChart = new Chart(solarCtx, {
      type: 'line',
      data: {
        labels: solarSeries.map(p => p.label),
        datasets: [{
          label: solarLabel, data: solarSeries.map(p => p.w),
          borderColor: '#7ee787', backgroundColor: '#7ee78722',
          tension: 0.3, pointRadius: 1, borderWidth: 2, fill: true, spanGaps: true,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: chartLegend(c),
          tooltip: chartTooltip({ callbacks: { label: ctx => ' Solar: ' + ctx.parsed.y + ' W' } }),
        },
        scales: {
          x: chartScale(c),
          y: chartScale(c, { beginAtZero: true, ticks: { color: c.tick, stepSize: 50, font: { size: 11 } }, title: { display: true, text: 'W', color: c.tick } }),
        },
      },
    });
  }
}

const IGNORE_ENDPOINTS = new Set(['/api/ps', '/api/tags', '/health', '/metrics']);

function updateReqChart(requests, range) {
  requests = (requests || []).filter(r => !IGNORE_ENDPOINTS.has(r.endpoint));
  const reqWrap = document.getElementById('req-wrap');
  // Keep req-wrap visible even when empty — show "No requests" placeholder
  if (reqWrap) reqWrap.style.display = 'block';
  if (requests.length === 0) {
    if (reqChart) { reqChart.destroy(); reqChart = null; }
    const legendEl = document.getElementById('req-legend');
    if (legendEl) legendEl.innerHTML = '<span class="muted">No API requests in this time range.</span>';
    return;
  }

  const bucketMap = { '10m': 10, '30m': 30, '1h': 30, '6h': 60, '12h': 120, '24h': 300 };
  const bucketSec = bucketMap[range] || 30;
  const timeBuckets = {};
  requests.forEach(r => {
    const bucketTs = Math.floor(r.ts / bucketSec) * bucketSec;
    const owner = IP_LABELS[r.ip] || r.ip;
    if (!timeBuckets[bucketTs]) timeBuckets[bucketTs] = { ts: bucketTs };
    timeBuckets[bucketTs][owner] = (timeBuckets[bucketTs][owner] || 0) + 1;
  });

  const sorted = Object.values(timeBuckets).sort((a, b) => a.ts - b.ts);
  const labels = sorted.map(b => {
    const d = new Date(b.ts * 1000);
    return d.toLocaleTimeString([], { hour12: false, hour: '2-digit', minute: '2-digit' });
  });

  const ownerList = [...new Set(requests.map(r => IP_LABELS[r.ip] || r.ip))];
  const isDark = isDarkTheme();
  const datasets = ownerList.map(owner => ({
    label: owner,
    data: sorted.map(b => b[owner] || 0),
    borderColor: ipColor(Object.entries(IP_LABELS).find(([ip, l]) => l === owner)?.[0] || owner, isDark),
    backgroundColor: ipColor(Object.entries(IP_LABELS).find(([ip, l]) => l === owner)?.[0] || owner, isDark) + '22',
    tension: 0.3, pointRadius: 2, borderWidth: 1.5,
  }));

  const c = chartColors();
  reqWrap.style.display = 'block';

  if (reqChart) {
    reqChart.data.labels = labels;
    reqChart.data.datasets = datasets;
    reqChart.options.scales.x = chartScale(c);
    reqChart.options.scales.y = chartScale(c, { beginAtZero: true, ticks: { color: c.tick, stepSize: 1, precision: 0, font: { size: 11 } } });
    reqChart.options.plugins.legend = chartLegend(c);
    reqChart.update('none');
    return;
  }

  reqChart = new Chart(document.getElementById('req-chart'), {
    type: 'bar',
    data: { labels, datasets },
    options: {
      responsive: true, maintainAspectRatio: false, animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: chartLegend(c),
        tooltip: chartTooltip({ callbacks: {
          title: (items) => items[0].label,
          label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.y} req${ctx.parsed.y !== 1 ? 's' : ''}`,
        }}),
      },
      scales: {
        x: chartScale(c),
        y: chartScale(c, { beginAtZero: true, ticks: { color: c.tick, stepSize: 1, precision: 0, font: { size: 11 } } }),
      },
    },
  });

  const legendEl = document.getElementById('req-legend');
  const isDarkL = isDarkTheme();
  legendEl.innerHTML = ownerList.map(owner => {
    const firstIp = Object.entries(IP_LABELS).find(([ip, l]) => l === owner)?.[0] || owner;
    return `<div class="req-legend-item">
       <span class="req-dot" style="background:${ipColor(firstIp, isDarkL)}"></span>
       <span>${owner}</span>
     </div>`;
  }).join('');
}

// ── Token-Durchsatz (Halogen) — separates Diagramm, eigene t/s-Skala ──
let tokenChart = null;
function lightenHex(hex, amt) {
  // Mischt Hex mit Weiss fuer die hellere Decode-Linie.
  const m = /^#?([0-9a-f]{6})$/i.exec(hex);
  if (!m) return hex;
  const n = parseInt(m[1], 16);
  const r = Math.min(255, ((n >> 16) & 255) + Math.round(255 * amt));
  const g = Math.min(255, ((n >> 8) & 255) + Math.round(255 * amt));
  const b = Math.min(255, (n & 255) + Math.round(255 * amt));
  return '#' + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0');
}
function renderTokenChart() {
  const wrap = document.getElementById('token-wrap');
  if (!wrap) return;
  const servers = (window._lastData && window._lastData.servers) || {};
  const ids = (machineFilter === 'all' ? Object.keys(servers) : [machineFilter]).filter(id => servers[id]);
  const perMachine = {};
  const tsSet = new Set();
  ids.forEach(id => {
    const series = (servers[id].series || []).filter(p => p.halogen_prefill_tps != null || p.halogen_decode_tps != null);
    if (series.length) { perMachine[id] = series; series.forEach(p => tsSet.add(p.ts)); }
  });
  const allTs = [...tsSet].sort((a, b) => a - b);
  if (!allTs.length) {
    wrap.style.display = 'none';
    if (tokenChart) { try { tokenChart.destroy(); } catch(e) {} tokenChart = null; }
    return;
  }
  wrap.style.display = 'block';
  const labels = allTs.map(ts => new Date(ts * 1000).toLocaleTimeString([], { hour12: false, hour: '2-digit', minute: '2-digit' }));
  const multi = Object.keys(perMachine).length > 1;
  const PREFILL = '#f0883e';   // orange
  const DECODE  = '#ffc98f';   // hellorange
  const datasets = [];
  Object.keys(perMachine).forEach(id => {
    const byTs = {};
    perMachine[id].forEach(p => { byTs[p.ts] = p; });
    const prefill = allTs.map(ts => (byTs[ts] && byTs[ts].halogen_prefill_tps != null) ? byTs[ts].halogen_prefill_tps : null);
    const decode  = allTs.map(ts => (byTs[ts] && byTs[ts].halogen_decode_tps != null) ? byTs[ts].halogen_decode_tps : null);
    const suffix = multi ? ' (' + (servers[id].name || id) + ')' : '';
    const prefillColor = multi ? (servers[id].color || PREFILL) : PREFILL;
    const decodeColor  = multi ? lightenHex(prefillColor, 0.35) : DECODE;
    if (prefill.some(v => v != null)) {
      datasets.push({ label: 'Prefill' + suffix, data: prefill, borderColor: prefillColor, yAxisID: 'yPrefill',
        backgroundColor: prefillColor + 'cc', borderWidth: 0, borderRadius: 2,
        barPercentage: 0.95, categoryPercentage: 0.8 });
    }
    if (decode.some(v => v != null)) {
      datasets.push({ label: 'Decode' + suffix, data: decode, borderColor: decodeColor, yAxisID: 'yDecode',
        backgroundColor: decodeColor + 'cc', borderWidth: 0, borderRadius: 2,
        barPercentage: 0.95, categoryPercentage: 0.8 });
    }
  });
  const c = chartColors();
  const legendEl = document.getElementById('token-legend');
  if (legendEl) {
    legendEl.innerHTML = datasets.map(ds =>
      `<div class="req-legend-item"><span class="req-dot" style="background:${ds.borderColor}"></span><span>${ds.label} t/s${ds.yAxisID === 'yDecode' ? ' (rechts)' : ' (links)'}</span></div>`
    ).join('');
  }
  if (tokenChart) {
    tokenChart.data.labels = labels;
    tokenChart.data.datasets = datasets;
    tokenChart.options.scales.x = chartScale(c);
    tokenChart.options.scales.yPrefill = chartScale(c, { position: 'left', beginAtZero: true, ticks: { color: PREFILL, callback: v => v + ' t/s', font: { size: 11 } }, grid: { color: c.grid, drawTicks: false }, title: { display: true, text: 'Prefill t/s', color: PREFILL } });
    tokenChart.options.scales.yDecode = chartScale(c, { position: 'right', beginAtZero: true, ticks: { color: DECODE, callback: v => v + ' t/s', font: { size: 11 } }, grid: { drawOnChartArea: false }, title: { display: true, text: 'Decode t/s', color: DECODE } });
    tokenChart.update('none');
    return;
  }
  tokenChart = new Chart(document.getElementById('token-chart'), {
    type: 'bar',
    data: { labels, datasets },
    options: {
      responsive: true, maintainAspectRatio: false, animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: chartTooltip({ callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + (ctx.parsed.y == null ? '–' : ctx.parsed.y.toFixed(1)) + ' t/s' } }),
      },
      scales: {
        x: chartScale(c),
        yPrefill: chartScale(c, { position: 'left', beginAtZero: true, ticks: { color: PREFILL, callback: v => v + ' t/s', font: { size: 11 } }, grid: { color: c.grid, drawTicks: false }, title: { display: true, text: 'Prefill t/s', color: PREFILL } }),
        yDecode: chartScale(c, { position: 'right', beginAtZero: true, ticks: { color: DECODE, callback: v => v + ' t/s', font: { size: 11 } }, grid: { drawOnChartArea: false }, title: { display: true, text: 'Decode t/s', color: DECODE } }),
      },
    },
  });
}

// ── Halogen Request-Counter: kumuliert + Requests/min ────────────────
let halogenReqChart = null;
function renderHalogenReqChart() {
  const wrap = document.getElementById('halogen-req-wrap');
  if (!wrap) return;
  const servers = (window._lastData && window._lastData.servers) || {};
  const ids = (machineFilter === 'all' ? Object.keys(servers) : [machineFilter]).filter(id => servers[id]);
  const perMachine = {};
  const tsSet = new Set();
  ids.forEach(id => {
    const series = (servers[id].series || []).filter(p => p.halogen_requests_total != null);
    if (series.length) { perMachine[id] = series; series.forEach(p => tsSet.add(p.ts)); }
  });
  const allTs = [...tsSet].sort((a, b) => a - b);
  if (!allTs.length) {
    wrap.style.display = 'none';
    if (halogenReqChart) { try { halogenReqChart.destroy(); } catch(e) {} halogenReqChart = null; }
    return;
  }
  wrap.style.display = 'block';
  const labels = allTs.map(ts => new Date(ts * 1000).toLocaleTimeString([], { hour12: false, hour: '2-digit', minute: '2-digit' }));
  const multi = Object.keys(perMachine).length > 1;
  const datasets = [];
  let latestTotal = 0, lastRate = 0, peakRate = 0;
  Object.keys(perMachine).forEach(id => {
    const series = perMachine[id];
    const byTs = {};
    series.forEach(p => { byTs[p.ts] = p; });
    const cum = allTs.map(ts => (byTs[ts] && byTs[ts].halogen_requests_total != null) ? byTs[ts].halogen_requests_total : null);
    const rate = allTs.map((ts, i) => {
      if (i === 0) return null;
      const prevTs = allTs[i - 1];
      const a = byTs[prevTs] && byTs[prevTs].halogen_requests_total;
      const b = byTs[ts] && byTs[ts].halogen_requests_total;
      if (a == null || b == null) return null;
      const dt = (ts - prevTs) / 60;
      if (dt <= 0 || b < a) return null;  // Counter-Reset oder keine Zeit verstrichen
      return (b - a) / dt;
    });
    const suffix = multi ? ' (' + (servers[id].name || id) + ')' : '';
    const col = '#39c5cf';
    if (rate.some(v => v != null)) {
      datasets.push({ type: 'bar', label: 'Requests/min' + suffix, data: rate, borderColor: lightenHex(col, 0.25),
        backgroundColor: lightenHex(col, 0.25) + '99', borderWidth: 0, borderRadius: 2,
        barPercentage: 0.95, categoryPercentage: 0.8, yAxisID: 'y1' });
    }
    if (cum.some(v => v != null)) {
      datasets.push({ type: 'line', label: 'Requests kumuliert' + suffix, data: cum, borderColor: col,
        backgroundColor: 'transparent', tension: 0.2, pointRadius: 1.5, borderWidth: 2, yAxisID: 'y', spanGaps: true });
    }
    const lastCum = [...cum].reverse().find(v => v != null);
    if (lastCum != null) latestTotal += lastCum;
    const rates = rate.filter(v => v != null);
    if (rates.length) {
      lastRate = Math.max(lastRate, rates[rates.length - 1]);
      peakRate = Math.max(peakRate, ...rates);
    }
  });
  const c = chartColors();
  if (halogenReqChart) {
    halogenReqChart.data.labels = labels;
    halogenReqChart.data.datasets = datasets;
    halogenReqChart.options.scales.x = chartScale(c);
    halogenReqChart.options.scales.y = chartScale(c, { beginAtZero: true, position: 'left', title: { display: true, text: 'Requests (kumuliert)', color: c.tick } });
    halogenReqChart.options.scales.y1 = chartScale(c, { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false, drawTicks: false }, title: { display: true, text: 'req/min', color: c.tick } });
    halogenReqChart.options.plugins.legend = chartLegend(c);
    halogenReqChart.update('none');
  } else {
    halogenReqChart = new Chart(document.getElementById('halogen-req-chart'), {
      type: 'bar',
      data: { labels, datasets },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: chartLegend(c),
          tooltip: chartTooltip({ callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + (ctx.parsed.y == null ? '–' : ctx.parsed.y.toFixed(1)) } }),
        },
        scales: {
          x: chartScale(c),
          y: chartScale(c, { beginAtZero: true, position: 'left', title: { display: true, text: 'Requests (kumuliert)', color: c.tick } }),
          y1: chartScale(c, { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false, drawTicks: false }, title: { display: true, text: 'req/min', color: c.tick } }),
        },
      },
    });
  }
  const statsEl = document.getElementById('halogen-req-stats');
  if (statsEl) {
    statsEl.innerHTML =
      `<div class="req-stat"><b>${latestTotal.toLocaleString('de-DE')}</b><span>Gesamt (kumuliert)</span></div>` +
      `<div class="req-stat"><b>${lastRate > 0 ? lastRate.toFixed(1) : '–'}</b><span>Requests/min</span></div>` +
      `<div class="req-stat"><b>${peakRate > 0 ? peakRate.toFixed(1) : '–'}</b><span>Peak/min</span></div>`;
  }
  const legendEl = document.getElementById('halogen-req-legend');
  if (legendEl) {
    legendEl.innerHTML = '<span class="muted">Kumulierter Halogen-Request-Counter (halogen:requests_total, alle Caller) + Rate pro Minute</span>';
  }
}

async function refresh() {
  if (!CONTENT_EL) return;
  try {
    const res = await fetch('data.php?range=' + currentRange + '&_=' + Date.now(), { cache: 'no-store' });
    const data = await res.json();
    window._lastData = data;
    applyThemeVars();
    UPDATED_EL.textContent = 'Last Update: ' + new Date(data.now * 1000).toLocaleTimeString([], { hour12: false }) + ' (every ' + REFRESH_INTERVAL + ' seconds)';

    // Solar leaf indicator
    for (const [sid, info] of Object.entries(data.servers || {})) {
      const w = info.latest && info.latest.shelly_power;
      const h1 = document.querySelector('h1');
      if (w !== null && w > 0) {
        if (h1 && !h1.querySelector('.leaf')) {
          const leaf = document.createElement('span');
          leaf.className = 'leaf';
          leaf.textContent = ' 🌿';
          leaf.style.cursor = 'help';
          leaf.title = 'Running on sustainable energy — solar production above 150W';
          h1.appendChild(leaf);
        }
      } else {
        if (h1) { const l = h1.querySelector('.leaf'); if (l) l.remove(); }
      }
      break;
    }

    // Render tabs and content
    renderMachineSelector(data.servers || {});
    renderTabs(data.servers || {});
    renderServerContent();

    updateSolarChart(data);
    renderTokenChart();
    renderHalogenReqChart();
  } catch (e) {
    UPDATED_EL.textContent = 'error: ' + e.message;
  }
}

// Re-render on window resize (for side-by-side toggle)
let resizeTimer;
window.addEventListener('resize', () => {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(() => {
    if (window._lastData) renderServerContent();
  }, 200);
});

if (CONTENT_EL) {
  applyThemeVars();
  refresh();
  setInterval(refresh, REFRESH_INTERVAL * 1000);
}
</script>
</body>
</html>