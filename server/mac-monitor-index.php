<?php
require __DIR__ . '/config.php';
$pdo = db();
$totalRows = (int)$pdo->query('SELECT COUNT(*) FROM metrics')->fetchColumn();
$hosts = $pdo->query('SELECT DISTINCT host FROM metrics ORDER BY host')->fetchAll(PDO::FETCH_COLUMN);
$serverIds = $pdo->query('SELECT DISTINCT server_id FROM metrics WHERE server_id IS NOT NULL ORDER BY server_id')->fetchAll(PDO::FETCH_COLUMN);
// Include all registered servers even if no data yet
$allServerIds = array_unique(array_merge(array_keys(SERVERS), $serverIds));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>System Monitor</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>
  // Apply theme before paint to avoid flash
  (function(){
    var m = window.matchMedia('(prefers-color-scheme: light)');
    document.documentElement.setAttribute('data-theme', m.matches ? 'light' : 'dark');
  })();
</script>
<style>
:root, [data-theme="dark"] {
  --bg: #0f1419;
  --panel: #1a2027;
  --border: #2a323d;
  --text: #e6edf3;
  --muted: #8b949e;
  --cpu: #58a6ff;
  --gpu: #d2a8ff;
  --ram: #7ee787;
  --accent: #ffa657;
  --bar-bg: #0a0e13;
  --chart-grid: rgba(255,255,255,0.04);
  --chart-tick: #8b949e;
  --chart-legend: #e6edf3;
  --tab-active: #1a2027;
  --tab-inactive: transparent;
}
[data-theme="light"] {
  --bg: #f0f2f5;
  --panel: #ffffff;
  --border: #d0d7de;
  --text: #1c2128;
  --muted: #636c76;
  --cpu: #0969da;
  --gpu: #8250df;
  --ram: #1a7f37;
  --accent: #bf5c00;
  --bar-bg: #d0d7de;
  --chart-grid: rgba(0,0,0,0.06);
  --chart-tick: #636c76;
  --chart-legend: #1c2128;
  --tab-active: #ffffff;
  --tab-inactive: transparent;
}
[data-theme="light"] .controls button.active { background: #0969da; color: #ffffff; border-color: #0969da; }
* { box-sizing: border-box; }
html, body {
  margin: 0; padding: 0;
  font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", system-ui, sans-serif;
  background: var(--bg); color: var(--text);
  transition: background 0.2s, color 0.2s;
}
header {
  padding: 16px 24px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 12px;
  background: var(--panel);
  transition: background 0.2s, border-color 0.2s;
}
h1 { margin: 0; font-size: 18px; font-weight: 600; }
.muted { color: var(--muted); font-size: 13px; }
main { padding: 20px 24px; max-width: 1400px; margin: 0 auto; }
.controls {
  display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
}
.controls button, .controls select {
  background: var(--panel); color: var(--text);
  border: 1px solid var(--border);
  padding: 6px 14px; border-radius: 6px; cursor: pointer;
  font-size: 13px; font-family: inherit;
  transition: background 0.2s, color 0.2s, border-color 0.2s;
}
.controls button.active { background: #0969da; color: #ffffff; border-color: #0969da; }
.controls button:hover:not(.active) { background: var(--border); }

/* ── Server tabs ──────────────────────────────────────────────── */
.server-tabs {
  display: flex; gap: 0; margin-bottom: 20px;
  border-bottom: 2px solid var(--border);
  overflow-x: auto;
}
.server-tab {
  padding: 10px 20px;
  cursor: pointer;
  border: none;
  background: transparent;
  color: var(--muted);
  font-size: 15px;
  font-weight: 500;
  font-family: inherit;
  border-bottom: 3px solid transparent;
  transition: color 0.2s, border-color 0.2s;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: 8px;
}
.server-tab:hover { color: var(--text); }
.server-tab.active {
  color: var(--text);
  border-bottom-color: var(--accent);
}
.server-tab .tab-dot {
  width: 8px; height: 8px; border-radius: 50%;
  transition: background 0.2s, box-shadow 0.2s;
}
.server-tab .tab-os {
  font-size: 11px; color: var(--muted);
  margin-left: 4px;
}

.host-block { margin-top: 0; }
.host-title {
  font-size: 16px; font-weight: 600;
  margin: 0 0 12px 0;
  display: flex; align-items: center; gap: 8px;
}
.dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--ram); box-shadow: 0 0 8px var(--ram);
  transition: background 0.2s, box-shadow 0.2s;
}
.dot.stale { background: #d29922; box-shadow: 0 0 8px #d29922; }
.dot.dead  { background: #f85149; box-shadow: 0 0 8px #f85149; }
.gauges {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}
.gauge {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 10px; padding: 16px;
  transition: background 0.2s, border-color 0.2s;
}
.gauge .label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
.gauge .value { font-size: 32px; font-weight: 600; margin: 6px 0 8px 0; line-height: 1; }
.gauge .sub   { color: var(--muted); font-size: 12px; }
.bar { height: 6px; background: var(--bar-bg); border-radius: 3px; overflow: hidden; margin-top: 8px; }
.bar > div { height: 100%; transition: width 0.4s ease, background 0.2s; }
.bar.cpu > div { background: var(--cpu); }
.bar.gpu > div { background: var(--gpu); }
.bar.ram > div { background: var(--ram); }
.chart-wrap {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 10px; padding: 16px; margin-top: 12px;
  height: 320px; position: relative;
  transition: background 0.2s, border-color 0.2s;
}
.req-wrap { margin-top: 24px; }
.req-wrap h2 { font-size: 16px; font-weight: 600; margin: 0 0 12px 0; color: var(--text); }
.req-chart-wrap {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 10px; padding: 16px; height: 200px; position: relative;
  transition: background 0.2s, border-color 0.2s;
}
.req-legend { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 10px; }
.req-legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); }
.solar-title { font-size: 16px; font-weight: 600; margin: 0 0 8px 0; color: var(--text); }
.solar-value { font-size: 14px; color: var(--muted); margin: 0; }
.solar-chart-wrap {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 10px; padding: 16px; height: 320px; position: relative;
  transition: background 0.2s, border-color 0.2s;
}
.req-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.empty {
  text-align: center; color: var(--muted); padding: 48px 16px;
  background: var(--panel); border: 1px dashed var(--border); border-radius: 10px;
}

/* ── Side-by-side view ────────────────────────────────────────── */
.server-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 24px;
}
@media (min-width: 1100px) {
  .server-grid.side-by-side {
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 600px) {
  main { padding: 12px; }
  header { padding: 12px 16px; }
  .gauge .value { font-size: 26px; }
}
</style>
</head>
<body>
<header>
  <div style="display:flex;align-items:center;gap:14px;flex-shrink:0">
    <div>
      <h1 style="font-size:22px;font-weight:700">System Monitor</h1>
      <div class="muted" id="updated">Last Update: loading…</div>
    </div>
  </div>
  <div class="controls">
    <button data-range="10m">10m</button>
    <button data-range="30m">30m</button>
    <button data-range="1h" class="active">1h</button>
    <button data-range="6h">6h</button>
    <button data-range="12h">12h</button>
    <button data-range="24h">24h</button>
  </div>
</header>

<main>
<?php if ($totalRows === 0): ?>
  <div class="empty">
    <p>No data yet.</p>
    <p>Install a client and wait ~1 minute for the first sample to arrive.</p>
  </div>
<?php else: ?>
  <div class="server-tabs" id="server-tabs"></div>
  <div id="server-content"></div>
  <div class="req-wrap" id="req-wrap" style="display:none">
    <h2>Ollama API Requests</h2>
    <div class="req-chart-wrap"><canvas id="req-chart"></canvas></div>
    <div class="req-legend" id="req-legend"></div>
  </div>
  <div id="solar-section" class="solar-wrap" style="display:none;margin-top:24px;padding-bottom:120px">
    <h2 class="solar-title">Solar Power</h2>
    <div id="solar-power" class="solar-value" style="margin-bottom:10px">Current Solar Power Production: –</div>
    <div class="solar-chart-wrap"><canvas id="solar-chart"></canvas></div>
  </div>
<?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js?v=2"></script>
<script>
const REFRESH_INTERVAL = 10; // seconds — must match LaunchAgent interval
const SERVERS = <?php echo json_encode(SERVERS); ?>;

const RANGE_BUTTONS = document.querySelectorAll('.controls button');
const TABS_EL = document.getElementById('server-tabs');
const CONTENT_EL = document.getElementById('server-content');
const UPDATED_EL = document.getElementById('updated');

let currentRange = '1h';
let activeServerId = null;  // set on first data load
const charts = {}; // serverId -> Chart instance

// ── theme awareness ──────────────────────────────────────────────
function chartColors() {
  const isDark = !window.matchMedia('(prefers-color-scheme: light)').matches;
  return {
    grid:   isDark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.06)',
    tick:    isDark ? '#8b949e'               : '#636c76',
    legend:  isDark ? '#e6edf3'               : '#1c2128',
    cpu:     isDark ? '#58a6ff'               : '#0969da',
    gpu:     isDark ? '#d2a8ff'               : '#8250df',
    ram:     isDark ? '#7ee787'               : '#1a7f37',
  };
}

function applyThemeVars() {
  const c = chartColors();
  document.querySelectorAll('.bar.cpu > div').forEach(el => el.style.background = c.cpu);
  document.querySelectorAll('.bar.gpu > div').forEach(el => el.style.background = c.gpu);
  document.querySelectorAll('.bar.ram > div').forEach(el => el.style.background = c.ram);
}

window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => {
  document.documentElement.setAttribute(
    'data-theme',
    window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark'
  );
  applyThemeVars();
  for (const sid in charts) {
    const info = window._lastData && window._lastData.servers && window._lastData.servers[sid];
    if (info) updateChart(sid, info.series);
  }
});

RANGE_BUTTONS.forEach(btn => {
  btn.addEventListener('click', () => {
    RANGE_BUTTONS.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentRange = btn.dataset.range;
    refresh();
  });
});

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
      TABS_EL.querySelectorAll('.server-tab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      renderServerContent();
    });
  });
}

// ── Server content rendering ─────────────────────────────────────────────
// Track what's currently rendered so we only rebuild HTML on tab switch or layout change
let _renderedLayout = null; // 'single' | 'side-by-side' | null
let _renderedServerIds = []; // server IDs currently in DOM

function renderServerContent() {
  if (!window._lastData || !window._lastData.servers) return;
  const servers = window._lastData.servers;
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
          <div id="available-wrap-${sid}" style="margin-top:8px;display:none">
            <details><summary style="cursor:pointer;color:var(--muted);font-size:12px">Available (not loaded)</summary>
              <div id="available-${sid}" style="font-size:12px;line-height:1.4;margin-top:6px;color:var(--muted)"></div>
            </details>
          </div>
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
        const sz = m.size_vram_gb != null ? `(${m.size_vram_gb} GB)` : '';
        return `<div style="font-size:13px;margin-left:12px">${m.name} <span style="color:var(--muted)">${sz}</span></div>`;
      }).join('');
      return `${tag}${items}`;
    }).join('<div style="height:6px"></div>');
    const totalVRAM = loaded.reduce((sum, m) => sum + (parseFloat(m.size_vram_gb) || 0), 0);
    if (modelsSubEl) modelsSubEl.textContent = `Total: ${totalVRAM.toFixed(1)} GB`;
  } else if (latest.ollama && latest.ollama.error === 'ollama_offline') {
    modelsEl.innerHTML = '<span style="color:var(--muted)">Ollama offline</span>';
    if (modelsSubEl) modelsSubEl.textContent = '';
  } else {
    modelsEl.innerHTML = '<span style="color:var(--muted)">None</span>';
    if (modelsSubEl) modelsSubEl.textContent = '';
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
}

function updateChart(sid, series) {
  const ctx = document.getElementById('chart-' + sid);
  if (!ctx) return;
  const c = chartColors();
  const labels = series.map(p => {
    const d = new Date(p.ts * 1000);
    return d.toLocaleTimeString([], { hour12: false, hour: '2-digit', minute: '2-digit' });
  });
  const datasets = [
    { label: 'CPU', data: series.map(p => p.cpu),      borderColor: c.cpu, backgroundColor: c.cpu + '1a', tension: 0.3, pointRadius: 0, borderWidth: 2 },
    { label: 'GPU', data: series.map(p => Math.max(0, p.gpu)), borderColor: c.gpu, backgroundColor: c.gpu + '1a', tension: 0.3, pointRadius: 0, borderWidth: 2 },
    { label: 'RAM', data: series.map(p => p.ram),     borderColor: c.ram, backgroundColor: c.ram + '1a', tension: 0.3, pointRadius: 0, borderWidth: 2 },
  ];

  if (charts[sid]) {
    charts[sid].data.labels = labels;
    charts[sid].data.datasets.forEach((ds, i) => {
      ds.data = datasets[i].data;
      ds.borderColor = datasets[i].borderColor;
      ds.backgroundColor = datasets[i].backgroundColor;
    });
    charts[sid].options.scales.x.ticks.color = c.tick;
    charts[sid].options.scales.x.grid.color = c.grid;
    charts[sid].options.scales.y.ticks.color = c.tick;
    charts[sid].options.scales.y.grid.color = c.grid;
    charts[sid].options.plugins.legend.labels.color = c.legend;
    charts[sid].update('none');
    return;
  }

  charts[sid] = new Chart(ctx, {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true, maintainAspectRatio: false,
      animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: c.legend } },
        tooltip: {
          callbacks: {
            title: (items) => items[0].label,
            label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(1)}%`,
          },
        },
      },
      scales: {
        x: { ticks: { color: c.tick, maxTicksLimit: 8, maxRotation: 0 }, grid: { color: c.grid } },
        y: { beginAtZero: true, max: 100, ticks: { color: c.tick, callback: v => v + '%' }, grid: { color: c.grid } },
      },
    },
  });
}

// ── Request chart ────────────────────────────────────────────────────────
const IP_LABELS = {
  '127.0.0.1':   'Bernd',
  '192.168.178.112': 'Bernd',
  '100.67.189.1': 'Dorian',
  '100.91.16.62': 'Sascha',
  '100.77.241.124': 'Sascha',
};
const IP_COLOR_DARK = {
  '127.0.0.1': '#58a6ff', '192.168.178.112': '#58a6ff',
  '100.67.189.1': '#ffa657', '100.91.16.62': '#f0883e', '100.77.241.124': '#f0883e',
};
const IP_COLOR_LIGHT = {
  '127.0.0.1': '#0969da', '192.168.178.112': '#0969da',
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
    solarChart.options.scales.x.ticks.color = c.tick;
    solarChart.options.scales.x.grid.color  = c.grid;
    solarChart.options.scales.y.ticks.color = c.tick;
    solarChart.options.scales.y.grid.color   = c.grid;
    solarChart.options.plugins.legend.labels.color = c.legend;
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
          legend: { labels: { color: c.legend } },
          tooltip: { callbacks: { label: ctx => ctx.parsed.y + ' W' } },
        },
        scales: {
          x: { ticks: { color: c.tick, maxTicksLimit: 8, maxRotation: 0 }, grid: { color: c.grid } },
          y: { beginAtZero: true, ticks: { color: c.tick, stepSize: 50 }, grid: { color: c.grid },
               title: { display: true, text: 'W', color: c.tick } },
        },
      },
    });
  }
}

const IGNORE_ENDPOINTS = new Set(['/api/ps', '/api/tags']);

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
  const isDark = !window.matchMedia('(prefers-color-scheme: light)').matches;
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
    reqChart.options.scales.x.ticks.color = c.tick;
    reqChart.options.scales.x.grid.color = c.grid;
    reqChart.options.scales.y.ticks.color = c.tick;
    reqChart.options.scales.y.grid.color = c.grid;
    reqChart.options.plugins.legend.labels.color = c.legend;
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
        legend: { labels: { color: c.legend } },
        tooltip: { callbacks: {
          title: (items) => items[0].label,
          label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y} req${ctx.parsed.y !== 1 ? 's' : ''}`,
        }},
      },
      scales: {
        x: { ticks: { color: c.tick, maxTicksLimit: 8, maxRotation: 0 }, grid: { color: c.grid } },
        y: { beginAtZero: true, ticks: { color: c.tick, stepSize: 1 }, grid: { color: c.grid } },
      },
    },
  });

  const legendEl = document.getElementById('req-legend');
  const isDarkL = !window.matchMedia('(prefers-color-scheme: light)').matches;
  legendEl.innerHTML = ownerList.map(owner => {
    const firstIp = Object.entries(IP_LABELS).find(([ip, l]) => l === owner)?.[0] || owner;
    return `<div class="req-legend-item">
       <span class="req-dot" style="background:${ipColor(firstIp, isDarkL)}"></span>
       <span>${owner}</span>
     </div>`;
  }).join('');
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
    renderTabs(data.servers || {});
    renderServerContent();

    updateReqChart(data.requests, currentRange);
    updateSolarChart(data);
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