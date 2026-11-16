<?php
require_once __DIR__ . '/config.php';
mm_gate_page();   // PIN-Anmeldung; gemerkte Geräte kommen direkt durch
// bplaced ignoriert .htaccess mod_headers — Cache-Header MUSS in PHP gesetzt
// werden, sonst zeigen Browser tagelang die alte Seite (Skill-Regel 03.10.).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Fleet Monitor</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>
  // Pre-Paint-Theme (FOUC-frei); Details in assets/ui.js
  window.__mmTheme=(function(){try{return localStorage.getItem('mm_theme')||'auto'}catch(e){return'auto'}})();
  window.__mmResolveTheme=function(m){return(m==='light'||m==='dark')?m:(window.matchMedia('(prefers-color-scheme: light)').matches?'light':'dark')};
  document.documentElement.setAttribute('data-theme',window.__mmResolveTheme(window.__mmTheme));
  document.documentElement.setAttribute('data-theme-mode',window.__mmTheme);
</script>
<link rel="stylesheet" href="assets/ui.css?v=121">
<link rel="stylesheet" href="assets/apple.css?v=121">
<style>
main { padding: 20px; max-width: 1400px; margin: 0 auto; }

/* ── Server tabs ──────────────────────────────────────────────── */
.server-tabs {
  display: flex; gap: 4px; margin-bottom: 20px;
  padding: 3px; max-width: 100%;
  flex-wrap: wrap;
  background: var(--frosted-soft);
  border: 1px solid var(--border); border-radius: var(--ring);
}
.server-tab {
  padding: 8px 18px; cursor: pointer; border: none;
  background: transparent; color: var(--text-m);
  font-size: 14px; font-weight: 600; font-family: inherit; letter-spacing: .02em;
  border-radius: var(--ring);
  transition: color .2s, background .2s;
  white-space: nowrap; display: flex; align-items: center; gap: 8px;
}
.server-tab:hover { color: var(--text); background: var(--frosted); }
/* ---- Gehirn-CT (07.11.) ---- */
.brain-wrap {
  position: relative; height: 460px; border-radius: var(--r-tile); overflow: hidden;
  background: #030305; border: 1px solid var(--border); margin-top: 8px;
  box-shadow: var(--shadow-1);
}
.brain-wrap canvas { width: 100%; height: 100%; display: block; }
/* Legende Gehirn-CT: Bedeutung ohne Erklärung (07.11.) */
.brain-legend { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 10px; font-size: 12.5px; color: var(--text); }
.brain-legend .bl-item { display: inline-flex; align-items: center; gap: 7px; }
.brain-legend .bl-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex: none; }
.brain-legend .bl-fire { background: #76b900; box-shadow: 0 0 8px 2px rgba(118,185,0,.75); }
.brain-legend .bl-after { background: rgba(10,132,255,.38); box-shadow: 0 0 5px 1px rgba(10,132,255,.25); }
.brain-legend .bl-ready { background: rgba(10,132,255,.85); }
.brain-legend .bl-idle { background: transparent; border: 1px solid rgba(140,160,180,.6); }
.brain-legend .bl-down { background: rgba(120,60,60,.6); border: 1px solid rgba(180,80,80,.7); position: relative; }
.brain-legend .bl-hint { color: var(--muted, var(--text)); opacity: .75; font-style: italic; }
/* Reiter-Umschaltung: im Gehirn-Modus verschwinden Dashboard/Charts */
body.view-brain #server-content,
body.view-brain #halogen-req-wrap,
body.view-brain #token-wrap,
body.view-brain #solar-section { display: none !important; }
#brain-section { display: none; }
body.view-brain #brain-section { display: block !important; }
.mm-logo { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 14px;
  background: var(--frosted); border: 1px solid var(--border); font-size: 20px; font-weight: 650;
  color: var(--text); flex-shrink: 0; }
/* Integrierte Ansichten: Engine Room nativ, Rest als Voll-Embed */
#er-section { display: none; }
#ctl-section { display: none; }
body.view-ctl #server-content,
body.view-ctl #halogen-req-wrap,
body.view-ctl #token-wrap,
body.view-ctl #solar-section,
body.view-ctl #brain-section,
body.view-ctl #er-section { display: none !important; }
body.view-ctl #ctl-section { display: block !important; }
.ctl-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
@media (max-width: 900px) { .ctl-grid { grid-template-columns: 1fr; } }
.ctl-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-tile); padding: 18px; box-shadow: var(--shadow-1); }
.ctl-card h3 { margin: 0 0 10px; font-size: 15px; }
.ctl-stat { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.ctl-stat:last-child { border-bottom: 0; }
.ctl-stat b { font-variant-numeric: tabular-nums; }
.ctl-btns { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.ctl-btns button { padding: 9px 16px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface); color: var(--text); font-weight: 600; cursor: pointer; }
.ctl-btns button.primary { background: var(--text); color: var(--canvas); border-color: var(--text); }
.ctl-btns button.danger { color: var(--red); border-color: var(--red); }
.ctl-btns button:disabled { opacity: .45; cursor: wait; }
.ctl-events { max-height: 240px; overflow-y: auto; font-size: 12px; line-height: 1.7; }
.ctl-events time { color: var(--muted); margin-right: 8px; font-variant-numeric: tabular-nums; }
.ctl-cc-link { display: flex; align-items: baseline; gap: 12px; margin-bottom: 12px; }
.ctl-cc-link a { color: var(--accent); font-weight: 600; text-decoration: none; font-size: 13px; }
.ctl-token { display: flex; gap: 8px; margin-bottom: 12px; }
.ctl-token input { flex: 1; padding: 9px 12px; border-radius: 10px; border: 1px solid var(--border); background: var(--canvas); color: var(--text); }
.ctl-badge { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: var(--frosted); color: var(--text-m); }
.ctl-badge.on { background: color-mix(in srgb, var(--success) 18%, transparent); color: var(--success); }
.ctl-badge.off { background: color-mix(in srgb, var(--red) 14%, transparent); color: var(--red); }
#embed-wrap { display: none; }
body.view-er #server-content,
body.view-er #halogen-req-wrap,
body.view-er #token-wrap,
body.view-er #solar-section,
body.view-er #brain-section { display: none !important; }
body.view-er #er-section { display: block !important; }
body.view-embed #server-content,
body.view-embed #halogen-req-wrap,
body.view-embed #token-wrap,
body.view-embed #solar-section,
body.view-embed #brain-section { display: none !important; }
body.view-embed #embed-wrap { display: block !important; }
#embed-frame { width: 100%; height: calc(100vh - 120px); border: 1px solid var(--border); border-radius: var(--r-tile); background: var(--canvas); box-shadow: var(--shadow-1); }
/* Engine-Room-Kacheln im Dashboard-System */
.er-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
.er-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-tile); padding: 18px; box-shadow: var(--shadow-1); }
.er-card .er-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.er-card .er-name { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .12em; color: var(--text-m); }
.er-card .er-name .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; background: #ff453a; }
.er-card .er-name .dot.on { background: var(--success); box-shadow: 0 0 6px var(--success); }
.er-card .er-name .dot.idle { background: var(--warning); }
.er-big { font-size: 40px; font-weight: 600; letter-spacing: -.02em; font-variant-numeric: tabular-nums; line-height: 1.1; }
.er-big small { font-size: 13px; color: var(--text-m); font-weight: 400; margin-left: 6px; }
.er-mini-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
@media (max-width: 760px) { .er-grid, .er-mini-grid { grid-template-columns: 1fr; } }
.server-tab.active { color: var(--canvas); background: var(--text); }
.server-tab.active:hover { background: var(--text); color: var(--canvas); }
.server-tab .tab-dot { width: 8px; height: 8px; border-radius: 50%; }
.server-tab .tab-os { font-size: 11px; color: var(--muted); margin-left: 4px; }

.host-block { margin-top: 0; }
.host-title { font-size: 16px; font-weight: 600; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px; letter-spacing: .01em; }
.gauges { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.gauge {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r-tile); padding: 16px;
  box-shadow: var(--shadow-1);
  transition: background .2s, border-color .2s, transform .15s, box-shadow .15s;
}
.gauge:hover { border-color: var(--border-strong); transform: translateY(-1px); box-shadow: var(--shadow-2); }
.gauge .label { color: var(--text-m); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; }
.gauge .value { font-size: 32px; font-weight: 600; margin: 6px 0 8px 0; line-height: 1; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
.gauge .sub   { color: var(--muted); font-size: 12px; }
.bar { height: 6px; background: var(--bar-bg); border-radius: var(--ring); overflow: hidden; margin-top: 8px; }
.bar > div { height: 100%; transition: width .4s ease; }
.bar.cpu > div { background: var(--cpu); }
.bar.gpu > div { background: var(--gpu); }
.bar.ram > div { background: var(--ram); }
.bar.kv > div { background: var(--accent); }
.bar.nv > div { background: #76b900; }
.dual-head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.dual-head span { color: var(--text-m); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; }
.dual-head .nv-tag { color: #76b900; }
.dual-head b { font-size: 24px; font-weight: 600; line-height: 1; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
.nvidia-gauge { border-left: 3px solid #76b90066; }
.nvidia-gauge .label { color: #76b900; }
.nvidia-gauge.kv-warn { border-color: var(--red); }
.nvidia-gauge.kv-warn .value { color: var(--red); }
.gauge.kv-warn { border-color: var(--red); }
.gauge.kv-warn .value { color: var(--red); }
.chart-wrap {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r-tile); padding: 16px; margin-top: 12px;
  height: 320px; position: relative; box-shadow: var(--shadow-1);
}
.req-wrap { margin-top: 24px; }
.req-wrap h2 { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text-m); margin-bottom: 10px; }
.req-chart-wrap {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r-tile); padding: 16px; height: 200px; position: relative;
  box-shadow: var(--shadow-1);
}
.req-legend { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 10px; }
.req-legend-item { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text); }
.req-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.solar-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; margin: 0 0 8px 0; color: var(--text-m); }
.solar-value { font-size: 14px; color: var(--muted); margin: 0; }
.solar-chart-wrap {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r-tile); padding: 16px; height: 320px; position: relative;
  box-shadow: var(--shadow-1);
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
    <span class="mm-logo">m.</span>
    <nav class="mm-nav" aria-label="Hauptnavigation">
      <a href="index.php" aria-current="page">Dashboard</a>
      <a href="calendar.php">Kalender</a>
      <a href="board.php">Board</a>
      <a href="autonomy.php">Autonomie</a>
      <a href="devices.php">Geräte</a>
    </nav>
    <div class="mm-title">
      <h1>Fleet Monitor</h1>
      <div class="muted" id="updated">Lädt …</div>
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
    <button data-theme-toggle title="Theme: Auto">Auto</button>
  </div>
</header>

<nav class="mm-bottomnav" aria-label="Hauptnavigation mobil">
  <a href="index.php" aria-current="page">Dashboard</a>
  <a href="calendar.php">Kalender</a>
  <a href="board.php">Board</a>
  <a href="autonomy.php">Autonomie</a>
  <a href="devices.php">Geräte</a>
</nav>

<main>
  <div class="server-tabs" id="server-tabs"></div>
  <div id="server-content"></div>
  <section id="er-section">
    <div class="er-grid">
      <div class="er-card">
        <div class="er-head"><span class="er-name"><span class="dot" id="er-halo-dot"></span>Halogen · iGPU + NPU</span><span class="muted" id="er-halo-badge">–</span></div>
        <div class="er-big" id="er-halo-tps">–<small>tok/s Decode</small></div>
        <div class="meter" style="margin-top:12px"><div class="dual-head"><span>KV-Pool</span><b id="er-halo-kv">–</b></div><div class="bar kv"><div id="er-halo-kv-bar" style="width:0%"></div></div></div>
        <div class="meter" style="margin-top:10px"><div class="dual-head"><span>RAM</span><b id="er-halo-ram">–</b></div><div class="bar ram"><div id="er-halo-ram-bar" style="width:0%"></div></div></div>
        <div class="sub muted" style="margin-top:10px" id="er-halo-foot">–</div>
      </div>
      <div class="er-card">
        <div class="er-head"><span class="er-name"><span class="dot" id="er-nvi-dot"></span>NVIDIA · RTX 4070 SUPER</span><span class="muted" id="er-nvi-badge">–</span></div>
        <div class="er-big" id="er-nvi-tps">–<small>tok/s Decode</small></div>
        <div class="meter" style="margin-top:12px"><div class="dual-head"><span>KV-Cache</span><b id="er-nvi-kv">–</b></div><div class="bar nv"><div id="er-nvi-kv-bar" style="width:0%"></div></div></div>
        <div class="meter" style="margin-top:10px"><div class="dual-head"><span>VRAM</span><b id="er-nvi-vram">–</b></div><div class="bar nv"><div id="er-nvi-vram-bar" style="width:0%"></div></div></div>
        <div class="sub muted" style="margin-top:10px" id="er-nvi-foot">–</div>
      </div>
    </div>
    <div class="er-mini-grid">
      <div class="er-card"><span class="er-name">Gaming-PC · Shunk</span><div class="er-big" id="er-gaming">–<small>W</small></div></div>
      <div class="er-card"><span class="er-name">eGPU · Verbrauch</span><div class="er-big" id="er-watt">–<small>W</small></div></div>
      <div class="er-card"><span class="er-name">Autonomie</span><div class="er-big" id="er-autonomy">–</div></div>
    </div>
  </section>
  <div id="embed-wrap"><iframe id="embed-frame" title="Ansicht" src="about:blank"></iframe></div>
  <section id="ctl-section">
    <div class="ctl-token" id="ctl-token-wrap" hidden>
      <span class="muted" id="ctl-hint">Recovery-API antwortet nicht. Bitte recovery-api.php auf Session-Anmeldung umstellen (docs/RECOVERY-API.md).</span>
    </div>
    <div class="ctl-grid">
      <div class="ctl-card">
        <h3>Halogen Control <span class="ctl-badge" id="ctl-conn">Nicht verbunden</span></h3>
        <div class="ctl-stat"><span>Phase</span><b id="ctl-phase">–</b></div>
        <div class="ctl-stat"><span>Meldung</span><b id="ctl-msg" style="text-align:right;max-width:60%">–</b></div>
        <div class="ctl-stat"><span>RAM verfügbar</span><b id="ctl-ram">–</b></div>
        <div class="ctl-stat"><span>Ziel / freigegeben</span><b id="ctl-target">–</b></div>
        <div class="ctl-stat"><span>Gewünscht / Ist</span><b id="ctl-desired">–</b></div>
        <div class="ctl-btns">
          <button class="primary" data-profile="flash" disabled>Flash Next</button>
          <button data-profile="27b" disabled>27B</button>
          <button class="danger" data-profile="off" disabled>Ausschalten</button>
        </div>
      </div>
      <div class="ctl-card">
        <h3>Autonomie <span class="ctl-badge" id="ctl-auto-badge">–</span></h3>
        <div class="ctl-stat"><span>Master-Schalter</span><b id="ctl-auto-state">–</b></div>
        <div class="ctl-stat"><span>Letztes Ereignis</span><b id="ctl-auto-last" style="text-align:right;max-width:60%">–</b></div>
        <div class="ctl-btns">
          <button class="primary" id="ctl-auto-on">Einschalten</button>
          <button class="danger" id="ctl-auto-off">Ausschalten</button>
        </div>
      </div>
    </div>
    <div class="ctl-grid">
      <div class="ctl-card"><h3>Wiederherstellungsprotokoll</h3><div class="ctl-events" id="ctl-events">Noch keine Client-Daten.</div></div>
      <div class="ctl-card"><h3>Autonomie-Journal</h3><div class="ctl-events" id="ctl-journal">–</div></div>
    </div>
  </section>
  <div class="req-wrap" id="halogen-req-wrap" style="display:none">
    <h2>Halogen Request-Counter</h2>
    <div class="req-chart-wrap"><canvas id="halogen-req-chart"></canvas></div>
    <div class="req-legend" id="halogen-req-legend"></div>
    <div class="req-stats" id="halogen-req-stats"></div>
  </div>
  <div class="req-wrap" id="token-wrap" style="display:none">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <h2 style="margin:0">Token-Durchsatz (Halogen)</h2>
      <div class="mm-seg" role="group" aria-label="Zoom Token-Chart">
        <button data-trange="600" class="active">10m</button>
        <button data-trange="3600">1h</button>
        <button data-trange="21600">6h</button>
        <button data-trange="0">Alle</button>
      </div>
    </div>
    <div class="token-title">Prefill t/s (Orange, linke Achse) · Decode t/s (Gelb, rechte Achse)</div>
    <div class="req-chart-wrap" style="height:240px"><canvas id="token-chart"></canvas></div>
    <div class="req-legend" id="token-legend"></div>
  </div>
  <div id="brain-section" class="req-wrap">
    <h2>Gehirn-CT</h2>
    <div class="brain-wrap"><canvas id="brainCanvas"></canvas></div>
    <div class="brain-legend" id="brainLegend">
      <span class="bl-item"><i class="bl-dot bl-fire"></i>feuert jetzt (hell, Puls)</span>
      <span class="bl-item"><i class="bl-dot bl-after"></i>zuletzt aktiv &lt; 10&nbsp;min — matter Nachglow, klingt ab</span>
      <span class="bl-item"><i class="bl-dot bl-ready"></i>bereit — Bewegung nur bei echter Aktivität</span>
      <span class="bl-item"><i class="bl-dot bl-idle"></i>entladen (hohl)</span>
      <span class="bl-item"><i class="bl-dot bl-down"></i>offline</span>
      <span class="bl-item bl-hint">Balken am Areal-Label = Feuer-Intensität · Hover zeigt Modell-Meta (tok/min, bereit bis)</span>
    </div>
    <div class="muted" id="brainNote" style="margin-top:8px">Live-Signale aus dem Collector (10-s-Takt): Halogen-NPU · Ollama · TTS · STT</div>
  </div>
  <div id="solar-section" class="solar-wrap" style="display:none;margin-top:24px;padding-bottom:120px">
    <h2 class="solar-title">Solarleistung</h2>
    <div id="solar-power" class="solar-value" style="margin-bottom:10px">Aktuell: –</div>
    <div class="solar-chart-wrap"><canvas id="solar-chart"></canvas></div>
  </div>
</main>

<script src="assets/ui.js?v=121"></script>
<script src="assets/brainct.js"></script>
<?php if (is_file(__DIR__ . '/assets/chart.umd.min.js')): ?>
<script src="assets/chart.umd.min.js?v=4.4.4"></script>
<?php else: /* Fallback CDN – lokal ablegen: assets/chart.umd.min.js (siehe docs/INSTALLATION.md) */ ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script>
const REFRESH_INTERVAL = 10; // seconds — must match LaunchAgent interval
const SERVERS = {"evo-x3":{"id":"evo-x3","name":"Evo-X3","host":"sascha-EVO-X3","os":"Ubuntu 24.04","icon":"\ud83d\udfe0","color":"#76b900"},"mac":{"id":"mac","name":"BigMac","host":"BigMac","os":"macOS","icon":"\ud83d\udda5\ufe0f","color":"#8e8e93"},"mini-pc":{"id":"mini-pc","name":"Mini-PC","host":"MINI-PC","os":"Ubuntu 24.04 (WSL2)","icon":"\ud83d\udce6","color":"#6e6e73"}};

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
    legend: cssVar('--chart-legend') || '#f2f3f5',
    // 1.2.1: feste, klar unterscheidbare Serienfarben (Neutral + Akzent)
    cpu:    isDarkTheme() ? '#e5e5ea' : '#3a3a3c',   // CPU: Graphit (kräftig)
    gpu:    '#76b900',                                 // GPU: Akzent (durchgezogen)
    ram:    isDarkTheme() ? '#636366' : '#c7c7cc',   // RAM: Hellgrau
    kv:     '#76b900',                                 // KV-Pool: Akzent gestrichelt
  };
}
// Chart.js-Defaults: konsistente Legende + Tooltip über alle Charts.
function chartLegend(c) {
  return { labels: { color: c.legend, usePointStyle: true, pointStyle: 'line', boxWidth: 22, padding: 14, font: { size: 12 } } };
}
function chartTooltip(extra) {
  return Object.assign({
    backgroundColor: 'rgba(28,28,30,.94)', titleColor: '#f5f5f7', bodyColor: '#f5f5f7',
    borderColor: 'rgba(255,255,255,.08)', borderWidth: 1, padding: 12, cornerRadius: 12,
    displayColors: true, usePointStyle: true, bodySpacing: 5,
  }, extra || {});
}
function chartScale(c, opts) {
  return Object.assign({
    ticks: { color: c.tick, maxTicksLimit: 8, maxRotation: 0, font: { size: 12 } },
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

// ── Token-Chart-Zoom (Sascha 09.11.): eigenes Zeitfenster, Default 10 min ──
let tokenRange = 600;
document.querySelectorAll('[data-trange]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('[data-trange]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    tokenRange = parseInt(btn.dataset.trange, 10) || 0;
    renderTokenChart();
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
      return `<option value="${id}">${s.name || id}</option>`;
    }).join('');
  if (sel.innerHTML !== html) sel.innerHTML = html;
  sel.value = machineFilter;
}

function fmtAgo(ts, now) {
  const d = now - ts;
  if (d < 60) return `vor ${Math.max(0, d)} s`;
  if (d < 3600) return `vor ${Math.floor(d/60)} min`;
  return `vor ${Math.floor(d/3600)} h ${Math.floor((d%3600)/60)} min`;
}

function statusDotClass(ageSec) {
  if (ageSec < 180) return 'dot';
  if (ageSec < 600) return 'dot stale';
  return 'dot dead';
}

function statusDotColor(ageSec) {
  if (ageSec < 180) return '#76b900';
  if (ageSec < 600) return '#8e8e93';
  return '#ff453a';
}

// ── Tab rendering ────────────────────────────────────────────────────────
// Gehirn-CT (07.11.): eigener Reiter neben den Server-Tabs; Snapshot kommt
// aus dem 10-s-Datenpfad (latest.brain), Visualisierung: assets/brainct.js.
let viewMode = 'server';  // 'server' | 'brain'
let _brainCT = null;
let _brainSnapshot = null;

function updateBrain() {
  const note = document.getElementById('brainNote');
  const servers = (window._lastData && window._lastData.servers) || {};
  let snap = null;
  const order = [activeServerId, ...Object.keys(servers)].filter(Boolean);
  for (const sid of order) {
    const b = servers[sid] && servers[sid].latest && servers[sid].latest.brain;
    if (b) { snap = b; break; }
  }
  _brainSnapshot = snap;
  if (!snap) {
    if (note) note.textContent = 'Noch keine Daten vom Collector.';
  } else {
    const src = snap.sources || {};
    const miss = [];
    if (!src.halogen) miss.push('Halogen');
    if (!src.ollama) miss.push('Ollama');
    if (note) note.textContent = miss.length
      ? 'Signallücke: ' + miss.join(' + ') + ' nicht erreichbar — Nodes zeigen dann offline'
      : 'Live-Signale: Halogen-NPU (:8731) · Ollama (:11434) · TTS · Whisper-STT — 10-s-Takt, graue Nodes = bereit, dunkel = entladen/offline';
  }
}

function openBrainView() {
  if (!_brainCT && window.BrainCT) {
    _brainCT = BrainCT.mount(document.getElementById('brainCanvas'),
                              { getSnapshot: () => _brainSnapshot });
  }
  updateBrain();
}

// Engine-Room-Tab: beide Engines aus data.php (latest evo-x3), keine Zweit-API.
// 1.2.1: Decode-Anzeige einheitlich für Dashboard-Kachel und Engine Room.
// Aktiv -> aktueller Wert; im Leerlauf -> letzte echte Messung (max. 1 h) mit Alter.
function decodeView(cur, last, now) {
  const c = cur != null ? +cur : null;
  if (c != null && c > 0.5) return { value: c, busy: true, note: 'aktuell' };
  if (last && last.v != null) {
    const age = Math.max(0, now - last.ts);
    const ago = age < 60 ? 'vor ' + age + ' s' : 'vor ' + Math.round(age / 60) + ' min';
    return { value: +last.v, busy: false, note: 'zuletzt ' + ago };
  }
  return { value: c, busy: false, note: c != null ? 'Leerlauf' : '' };
}

function renderER() {
  const d = window._lastData;
  if (!d || !d.servers) return;
  const s = d.servers['evo-x3'];
  const l = s && s.latest;
  const now = d.now || Math.floor(Date.now()/1000);
  const set = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };
  const bar = (id, pct) => { const el = document.getElementById(id); if (el) el.style.width = (pct == null ? 0 : Math.max(0, Math.min(100, pct))) + '%'; };
  if (!l) return;
  const erBig = (id, v) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = (v.value != null ? v.value.toFixed(1) : '–') +
      '<small>tok/s Decode' + (v.note ? ' · ' + v.note : '') + '</small>';
    el.classList.toggle('is-idle', !v.busy);
  };
  const fresh = (now - l.ts) < 90;
  const hDot = document.getElementById('er-halo-dot'), hBadge = document.getElementById('er-halo-badge');
  const hAlive = l.halogen_kv_pool_pct != null && fresh;
  const hView = decodeView(l.halogen_decode_tps, l.halogen_decode_last, now);
  const hBusy = hView.busy;
  if (hDot) hDot.className = 'dot ' + (hAlive ? (hBusy ? 'on' : 'idle') : 'off');
  if (hBadge) hBadge.textContent = hAlive ? (hBusy ? 'aktiv' : 'bereit') : 'offline';
  erBig('er-halo-tps', hView);
  set('er-halo-kv', l.halogen_kv_pool_pct != null ? (+l.halogen_kv_pool_pct).toFixed(1) + ' %' : '–');
  bar('er-halo-kv-bar', l.halogen_kv_pool_pct);
  set('er-halo-ram', (l.ram_percent != null ? (+l.ram_percent).toFixed(0) + ' %' : '–'));
  bar('er-halo-ram-bar', l.ram_percent);
  set('er-halo-foot', (l.halogen_kv_pool_used != null && l.halogen_kv_pool_total != null)
    ? l.halogen_kv_pool_used.toLocaleString() + ' / ' + l.halogen_kv_pool_total.toLocaleString() + ' Positionen · ctx 192k'
    : 'ctx 196608');
  const nDot = document.getElementById('er-nvi-dot'), nBadge = document.getElementById('er-nvi-badge');
  const nAlive = l.nvidia_alive != null && fresh;
  const nView = decodeView(l.nvidia_decode_tps, l.nvidia_decode_last, now);
  const nBusy = nView.busy || (l.nvidia_requests_busy || 0) > 0;
  if (nDot) nDot.className = 'dot ' + (nAlive ? (nBusy ? 'on' : 'idle') : 'off');
  if (nBadge) nBadge.textContent = nAlive ? (nBusy ? 'aktiv' : 'bereit') : 'offline';
  erBig('er-nvi-tps', nView);
  set('er-nvi-kv', l.nvidia_kv_pct != null ? (+l.nvidia_kv_pct).toFixed(1) + ' %' : '–');
  bar('er-nvi-kv-bar', l.nvidia_kv_pct);
  const vrU = l.egpu_vram_used_mb, vrT = l.egpu_vram_total_mb;
  set('er-nvi-vram', (vrU != null && vrT) ? (vrU/1024).toFixed(1) + ' / ' + (vrT/1024).toFixed(1) + ' GB' : '–');
  bar('er-nvi-vram-bar', (vrU != null && vrT) ? vrU/vrT*100 : null);
  set('er-nvi-foot', 'ctx 8192 · ' + (l.egpu_watt != null ? (+l.egpu_watt).toFixed(1) + ' W' : '–'));
  set('er-gaming', (l.gaming_pc_watt != null ? (+l.gaming_pc_watt).toFixed(0) : '–') + ' W');
  set('er-watt', (l.egpu_watt != null ? (+l.egpu_watt).toFixed(1) : '–') + ' W');
  const a = document.getElementById('er-autonomy');
  if (a) a.textContent = 'aktiv';
}

// Control-Tab: Recovery-API + Autonomie — beide über die bestehende Anmeldung
// (Session bzw. gemerktes Gerät). Kein Control-Token mehr (1.2.0).
let ctlReady = false, ctlRev = -1, ctlTimer = null, ctlBusy = false;
const CTL_PHASES = { idle: 'Bereit', stopping: 'Stoppt', freeing: 'Gibt RAM frei', loading: 'Lädt Modell', testing: 'Prüft Generierung', running: 'Läuft', off: 'Aus', error: 'Fehler' };
const CTL_LABELS = { off: 'Aus', flash: 'Flash Next', '27b': '27B' };

async function ctlApi(body) {
  const res = await fetch('recovery-api.php?machine=evo-x3', {
    method: body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
    body: body ? JSON.stringify(body) : undefined, cache: 'no-store',
    signal: AbortSignal.timeout(15000),
  });
  let data; try { data = await res.json(); } catch { throw new Error('Server liefert kein JSON'); }
  if (!res.ok) { const err = new Error(data.error || ('Fehler ' + res.status)); err.status = res.status; throw err; }
  return data;
}

function ctlNum(v) { return typeof v === 'number' ? v.toFixed(1) + ' GiB' : '–'; }

function ctlRender(data) {
  ctlRev = data.control.revision;
  const s = data.status || {}, m = s.memory || {};
  const age = data.received_at ? Math.max(0, data.server_time - data.received_at) : Infinity;
  const online = age < 45;
  const conn = document.getElementById('ctl-conn');
  conn.textContent = online ? 'Client verbunden' : (Number.isFinite(age) ? 'Client offline · ' + age + ' s' : 'Kein Client');
  conn.className = 'ctl-badge ' + (online ? 'on' : 'off');
  document.getElementById('ctl-phase').textContent = CTL_PHASES[s.phase] || 'Unbekannt';
  document.getElementById('ctl-msg').textContent = s.message || '–';
  document.getElementById('ctl-ram').textContent = ctlNum(m.available_gib);
  document.getElementById('ctl-target').textContent = ctlNum(s.target_available_gib) + ' / ' + ctlNum(s.released_gib);
  document.getElementById('ctl-desired').textContent = (CTL_LABELS[data.control.desired] || '?') + ' / ' + (CTL_LABELS[s.active] || 'kein Profil');
  const ev = document.getElementById('ctl-events');
  const events = Array.isArray(s.events) ? s.events.slice(-20).reverse() : [];
  ev.innerHTML = events.length ? events.map(e => {
    const d = new Date(Number(e.ts) * 1000);
    return '<div><time>' + (Number.isNaN(d.getTime()) ? '?' : d.toLocaleString('de-DE')) + '</time>' + (e.message || '') + '</div>';
  }).join('') : 'Noch keine Ereignisse.';
  document.querySelectorAll('#ctl-section [data-profile]').forEach(b => { b.disabled = ctlBusy; });
}

async function ctlRefresh() {
  if (!ctlReady || ctlBusy) return;
  try { ctlRender(await ctlApi()); } catch (e) {
    const conn = document.getElementById('ctl-conn');
    conn.textContent = 'Fehler'; conn.className = 'ctl-badge off';
  }
}

async function ctlSend(profile, needsConsent) {
  if (ctlBusy) return;
  if (profile !== 'off' && !needsConsent) {
    if (!confirm('Modell-Wechsel: Halogen wird gestoppt, RAM freigegeben, neues Modell geladen. Notfallbereinigung (Erzwingen von Prozessen) ist erlaubt. Fortfahren?')) return;
    needsConsent = true;
  }
  ctlBusy = true;
  document.querySelectorAll('#ctl-section [data-profile]').forEach(b => { b.disabled = true; });
  try {
    ctlRender(await ctlApi({ kind: 'desired', desired: profile, expected_revision: ctlRev, confirm_cleanup: needsConsent || undefined }));
    setTimeout(ctlRefresh, 2500);
  } catch (e) { alert('Auftrag abgelehnt: ' + e.message); ctlBusy = false; ctlRefresh(); }
}

async function ctlAutoLoad() {
  try {
    const r = await fetch('autonomy-api.php', { cache: 'no-store' });
    if (!r.ok) { document.getElementById('ctl-auto-badge').textContent = 'Login nötig'; return; }
    const d = await r.json();
    const badge = document.getElementById('ctl-auto-badge');
    badge.textContent = d.enabled ? 'AKTIV' : 'PAUSIERT';
    badge.className = 'ctl-badge ' + (d.enabled ? 'on' : 'off');
    document.getElementById('ctl-auto-state').textContent = d.enabled ? 'Autonomie läuft' : 'Angehalten';
    const j = (d.journal || []).slice(-1).pop();
    document.getElementById('ctl-auto-last').textContent = j ? (j.action_text || j.event) : '–';
    const jr = document.getElementById('ctl-journal');
    const items = (d.journal || []).slice(-15).reverse();
    jr.innerHTML = items.length ? items.map(e => {
      const dt = new Date(e.ts * 1000);
      return '<div><time>' + dt.toLocaleString('de-DE') + '</time>' + (e.action_text || e.event) + '</div>';
    }).join('') : '–';
  } catch (e) { /* still */ }
}

async function ctlAutoToggle(on) {
  try {
    await fetch('autonomy-api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'toggle', enabled: on }) });
    ctlAutoLoad();
  } catch (e) { alert('Umschalten fehlgeschlagen: ' + e.message); }
}

function ctlStart() {
  ctlAutoLoad();
  document.querySelectorAll('#ctl-section [data-profile]').forEach(b => {
    b.onclick = () => ctlSend(b.dataset.profile, false);
  });
  const on = document.getElementById('ctl-auto-on'), off = document.getElementById('ctl-auto-off');
  if (on && !on._wired) { on.onclick = () => ctlAutoToggle(true); off.onclick = () => ctlAutoToggle(false); on._wired = true; }
  if (!ctlTimer) {
    ctlApi().then(d => {
      ctlReady = true; ctlRender(d);
      document.getElementById('ctl-token-wrap').hidden = true;
      ctlTimer = setInterval(() => { if (!document.hidden) ctlRefresh(); }, 5000);
    }).catch(e => {
      const hint = document.getElementById('ctl-hint');
      if (hint) hint.textContent = e && e.status === 401
        ? 'Die Wiederherstellungs-Schnittstelle (recovery-api.php) erwartet noch den alten Control-Token. Umstellung siehe docs/RECOVERY-API.md.'
        : e && e.status === 404
          ? 'recovery-api.php ist auf dem Server nicht vorhanden.'
          : 'Wiederherstellungs-Schnittstelle nicht erreichbar (' + ((e && e.message) || 'Netzwerk') + ').';
      document.getElementById('ctl-token-wrap').hidden = false;
      ctlTimer = null;
    });
  }
}

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
    const dotColor = latest ? statusDotColor(ageSec) : '#ff453a';
    const isActive = sid === activeServerId && viewMode === 'server';
    return `<button class="server-tab ${isActive ? 'active' : ''}" data-server-id="${sid}">
      <span class="tab-dot" style="background:${dotColor};box-shadow:0 0 6px ${dotColor}"></span>
      ${s.name}
      <span class="tab-os">${s.os || ''}</span>
    </button>`;
  }).join('') + `<button class="server-tab ${viewMode === 'brain' ? 'active' : ''}" id="brain-tab">Gehirn</button>
    <button class="server-tab ${viewMode === 'er' ? 'active' : ''}" id="er-tab">Engine Room</button>
    <button class="server-tab ${viewMode === 'ctl' ? 'active' : ''}" id="ctl-tab">Control</button>
    <button class="server-tab ${viewMode === 'embed:calendar.php' ? 'active' : ''}" id="emb-cal">Kalender</button>
    <button class="server-tab ${viewMode === 'embed:board.php' ? 'active' : ''}" id="emb-board">Board</button>
    <button class="server-tab ${viewMode === 'embed:autonomy.php' ? 'active' : ''}" id="emb-auto">Autonomie</button>`;

  TABS_EL.querySelectorAll('.server-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      const setMode = (mode) => {
        viewMode = mode;
        document.body.classList.remove('view-brain', 'view-er', 'view-embed', 'view-ctl');
        if (mode === 'brain') document.body.classList.add('view-brain');
        else if (mode === 'er') document.body.classList.add('view-er');
        else if (mode === 'ctl') document.body.classList.add('view-ctl');
        else if (mode.startsWith('embed:')) {
          document.body.classList.add('view-embed');
          const frame = document.getElementById('embed-frame');
          const src = mode.slice(6);
          if (frame.dataset.src !== src) { frame.src = src + '?embedded=1'; frame.dataset.src = src; }
        }
        TABS_EL.querySelectorAll('.server-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        if (mode === 'brain') { openBrainView(); return; }
        if (mode === 'er') { renderER(); return; }
        if (mode === 'ctl') { ctlStart(); return; }
        if (mode === 'server') { renderServerContent(); renderTokenChart(); renderHalogenReqChart(); }
      };
      if (btn.id === 'brain-tab') { setMode('brain'); return; }
      if (btn.id === 'er-tab') { setMode('er'); return; }
      if (btn.id === 'ctl-tab') { setMode('ctl'); return; }
      if (btn.id === 'emb-cal') { setMode('embed:calendar.php'); return; }
      if (btn.id === 'emb-board') { setMode('embed:board.php'); return; }
      if (btn.id === 'emb-auto') { setMode('embed:autonomy.php'); return; }
      activeServerId = btn.dataset.serverId;
      machineFilter = activeServerId;
      const sel = document.getElementById('machine-selector');
      if (sel) sel.value = activeServerId;
      setMode('server');
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
  const ageText = latest ? fmtAgo(latest.ts, now) : 'keine Daten';

  // Fleet Ingest (WP5): generisches Gerät — Metriken sind frei benannt.
  if (s.ingest) {
    const keys = latest ? Object.keys(latest).filter(k => k !== 'ts') : [];
    const tiles = keys.slice(0, 12).map(k => {
      const v = latest[k];
      const txt = (typeof v === 'number') ? (Number.isInteger(v) ? v : v.toFixed(1)) : String(v ?? '–');
      return `<div class="gauge"><div class="label">${k}</div><div class="value" style="font-size:20px" id="ing-${sid}-${k}">${txt}</div></div>`;
    }).join('');
    return `
    <section class="host-block" id="host-${sid}">
      <h2 class="host-title">
        <span class="${dotClass}" id="dot-${sid}"></span>
        ${s.name} <span class="muted" style="font-weight:400;font-size:12px">· Fleet Ingest</span>
        <span class="muted" id="age-${sid}" style="font-weight:400;font-size:13px;margin-left:8px">${ageText}</span>
      </h2>
      <div class="gauges">${tiles || '<div class="gauge"><div class="label">Warten auf erste Metrik…</div></div>'}</div>
    </section>`;
  }

  return `
    <section class="host-block" id="host-${sid}">
      <h2 class="host-title">
        <span class="${dotClass}" id="dot-${sid}"></span>
        ${s.name}
        <span class="muted" id="age-${sid}" style="font-weight:400;font-size:13px;margin-left:8px">${ageText}</span>
      </h2>
      <div class="gauges">
        <div class="gauge">
          <div class="label">CPU</div>
          <div class="value" id="cpu-${sid}">–</div>
          <div class="bar cpu"><div id="cpu-bar-${sid}" style="width:0%"></div></div>
        </div>
        <div class="gauge">
          <div class="label" id="gpu-label-${sid}">GPU</div>
          <div class="dual-head"><span id="gpu-name-${sid}"></span><b id="gpu-${sid}">–</b></div>
          <div class="bar gpu"><div id="gpu-bar-${sid}" style="width:0%"></div></div>
          <div class="sub" id="gpu-temp-${sid}" style="margin-top:2px">–</div>
          <div id="nvg-wrap-${sid}" hidden>
            <div class="dual-head" style="margin-top:10px"><span class="nv-tag">NVIDIA · RTX 4070 SUPER</span><b id="nvg-v-${sid}">–</b></div>
            <div class="bar nv"><div id="nvg-bar-${sid}" style="width:0%"></div></div>
            <div class="sub" id="nvg-sub-${sid}" style="margin-top:2px">–</div>
          </div>
        </div>
        <div class="gauge">
          <div class="label">RAM</div>
          <div class="value" id="ram-${sid}">–</div>
          <div class="sub" id="ram-sub-${sid}"></div>
          <div class="bar ram"><div id="ram-bar-${sid}" style="width:0%"></div></div>
          <div id="nvr-wrap-${sid}" style="display:none">
            <div class="dual-head" style="margin-top:10px"><span class="nv-tag">NVIDIA · VRAM</span><b id="nvr-v-${sid}">–</b></div>
            <div class="bar nv"><div id="nvr-bar-${sid}" style="width:0%"></div></div>
            <div class="sub" id="nvr-sub-${sid}" style="margin-top:2px">–</div>
          </div>
        </div>
        <div class="gauge ollama-box">
          <div class="label">Geladene Modelle</div>
          <div class="value ollama-value" id="models-${sid}" style="font-size:14px;line-height:1.4;"></div>
          <div class="sub muted" id="models-sub-${sid}"></div>
          <div id="unload-wrap-${sid}" style="margin-top:8px"></div>
          <div id="available-wrap-${sid}" style="margin-top:8px;display:none">
            <details><summary style="cursor:pointer;color:var(--muted);font-size:12px">Verfügbar (nicht geladen)</summary>
              <div id="available-${sid}" style="font-size:12px;line-height:1.4;margin-top:6px;color:var(--muted)"></div>
            </details>
          </div>
        </div>
        <div class="gauge">
          <div class="label">Engines · Tokens/s <span style="font-weight:400">(Prefill | Decode)</span></div>
          <div id="engines-${sid}" style="font-size:13px;line-height:1.9"></div>
        </div>
        <div class="gauge" id="kv-gauge-${sid}" style="display:none">
          <div class="label">KV-Cache · beide Engines</div>
          <div class="dual-head"><span>Halogen · NPU</span><b id="kv-${sid}">–</b></div>
          <div class="bar kv"><div id="kv-bar-${sid}" style="width:0%"></div></div>
          <div class="sub muted" id="kv-sub-${sid}"></div>
          <div class="dual-head" style="margin-top:10px"><span class="nv-tag">NVIDIA · RTX 4070 SUPER</span><b id="nvk-v-${sid}">–</b></div>
          <div class="bar nv"><div id="nvk-bar-${sid}" style="width:0%"></div></div>
          <div class="sub muted" id="nvk-sub-${sid}"></div>
        </div>
      </div>
      <div class="chart-wrap"><canvas id="chart-${sid}"></canvas></div>
    </section>
  `;
}

function serverTagColor(server) {
  const colors = {
    'ollama':    '#76b900',
    'lm-studio': '#8e8e93',
    'llama.cpp':  '#a0a0a8',
    'nvidia':     '#76b900',
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

  // Fleet Ingest (WP5): generische Kacheln live nachziehen
  if (window._lastData && window._lastData.servers[sid] && window._lastData.servers[sid].ingest) {
    for (const k of Object.keys(latest)) {
      if (k === 'ts') continue;
      const el = document.getElementById('ing-' + sid + '-' + k);
      if (!el) continue;
      const v = latest[k];
      el.textContent = (typeof v === 'number') ? (Number.isInteger(v) ? v : v.toFixed(1)) : String(v ?? '–');
    }
    return;
  }

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
  if (gpuTempEl) {
    const parts = [];
    if (latest.gpu_temp != null) parts.push(latest.gpu_temp + ' °C');
    if (latest.gpu_watt != null) parts.push((+latest.gpu_watt).toFixed(0) + ' W');
    gpuTempEl.textContent = parts.length ? parts.join(' · ') : '–';
  }

  if (latest.ram_used_gb && latest.ram_total_gb) {
    const subEl = document.getElementById('ram-sub-' + sid);
    if (subEl) subEl.textContent = `${(+latest.ram_used_gb).toFixed(1)} / ${(+latest.ram_total_gb).toFixed(0)} GB`;
  }

  // NVIDIA VRAM-Zeile in der RAM-Kachel (nur bei NVIDIA-Daten)
  const nvrWrap = document.getElementById('nvr-wrap-' + sid);
  if (nvrWrap) {
    const u = latest.egpu_vram_used_mb, t = latest.egpu_vram_total_mb;
    if (u != null && t) {
      nvrWrap.style.display = 'block';
      document.getElementById('nvr-v-' + sid).textContent = ((u/t)*100).toFixed(0) + '%';
      document.getElementById('nvr-bar-' + sid).style.width = Math.min(100, (u/t)*100) + '%';
      document.getElementById('nvr-sub-' + sid).textContent = (u/1024).toFixed(1) + ' / ' + (t/1024).toFixed(1) + ' GB';
    } else {
      nvrWrap.style.display = 'none';
    }
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
    const escM = v => String(v ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
    const SRV_LABEL = { halogen: 'Halogen', ollama: 'Ollama', 'lm-studio': 'LM Studio', 'llama.cpp': 'llama.cpp', nvidia: 'NVIDIA' };
    modelsEl.innerHTML = Object.keys(groups).map(srv =>
      `<div class="fm-model-group"><div class="fm-model-srv">${escM(SRV_LABEL[srv] || srv)}</div>` +
      groups[srv].map(m => {
        // Halogen: size_vram_gb ist Container-RAM (cgroup), kein GPU-Footprint.
        const sz = m.size_vram_gb != null ? `${m.size_vram_gb} GB` : '';
        return `<div class="fm-model"><span class="fm-model-name" title="${escM(m.name)}">${escM(m.name)}</span><span class="fm-model-size">${sz}</span></div>`;
      }).join('') + `</div>`
    ).join('');
    const totalVRAM = loaded.reduce((sum, m) => sum + (parseFloat(m.size_vram_gb) || 0), 0);
    if (modelsSubEl) modelsSubEl.textContent = `Gesamt ${totalVRAM.toFixed(1)} GB`;
    // Unload buttons: one per loaded LM Studio model
    const unloadWrap = document.getElementById('unload-wrap-' + sid);
    if (unloadWrap) {
      const lmModels = loaded.filter(m => m.server === 'lm-studio');
      const hgModels = loaded.filter(m => m.server === 'halogen');
      // 1.2.1: ruhige Steuerzeilen (Status-Punkt + neutrale Buttons, Rot nur für Stop)
      const esc = v => String(v ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
      const row = (label, running, buttons) =>
        `<div class="fm-ctl-row"><span class="fm-ctl-name">${label}</span>` +
        `<span class="fm-ctl-state ${running ? 'on' : 'off'}">${running ? 'aktiv' : 'aus'}</span>` +
        `<span class="fm-ctl-btns">${buttons}</span></div>`;
      const btn = (action, text, kind) =>
        `<button type="button" class="fm-ctl-btn ${kind || ''} backend-btn" data-sid="${esc(sid)}" data-action="${action}">${text}</button>`;
      const lmRunning = lmModels.length > 0;
      const hgRunning = hgModels.length > 0;
      let html = '';
      html += row('LM Studio', lmRunning,
        (lmRunning ? btn('lmstudio_stop', 'Stoppen', 'danger') : btn('lmstudio_start', 'Starten')) +
        lmModels.map(m => {
          const short = (m.name || '').split('/').pop().split(':')[0];
          return `<button type="button" class="fm-ctl-btn unload-btn" data-sid="${esc(sid)}" data-model="${esc(m.name)}" title="Modell entladen">${esc(short)} entladen</button>`;
        }).join(''));
      html += row('Halogen', hgRunning,
        hgRunning ? btn('halogen_restart', 'Neu starten') + btn('halogen_stop', 'Stoppen', 'danger')
                  : btn('halogen_start', 'Starten'));
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
    modelsEl.innerHTML = '<span style="color:var(--muted)">Keine</span>';
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

  // Engines · TPS — eine Zeile pro geladener Engine, Format "Prefill | Decode"
  const enginesEl = document.getElementById('engines-' + sid);
  if (enginesEl) {
    const fmt = v => v != null ? (+v).toFixed(0) : '–';
    const rows = [];
    const loaded = (latest.ollama && latest.ollama.loaded) ? latest.ollama.loaded : [];
    const has = n => loaded.some(m => (m.server || '') === n);
    // Halogen (NPU/iGPU)
    if (has('halogen') || latest.halogen_kv_pool_pct != null) {
      const hv = decodeView(latest.halogen_decode_tps, latest.halogen_decode_last, now);
      rows.push('<div class="dual-head fm-eng' + (hv.busy ? '' : ' is-idle') + '" title="' + hv.note + '"><span>Halogen · NPU</span><b>' +
        fmt(latest.halogen_prefill_tps) + ' | ' + fmt(hv.value) + '</b></div>');
    }
    // NVIDIA llama-server
    if (has('nvidia') || latest.nvidia_alive != null) {
      const nv = decodeView(latest.nvidia_decode_tps, latest.nvidia_decode_last, now);
      rows.push('<div class="dual-head fm-eng' + (nv.busy ? '' : ' is-idle') + '" title="' + nv.note + '"><span class="nv-tag">NVIDIA · RTX 4070 SUPER</span><b>' +
        fmt(latest.nvidia_prompt_tps) + ' | ' + fmt(nv.value) + '</b></div>');
    }
    // Ollama / LM Studio (nur Decode verfügbar)
    if (latest.ollama_tps != null && +latest.ollama_tps > 0) {
      rows.push('<div class="dual-head"><span>Ollama</span><b style="font-size:15px">– | ' + fmt(latest.ollama_tps) + '</b></div>');
    }
    if (latest.lm_studio_tps != null && +latest.lm_studio_tps > 0) {
      rows.push('<div class="dual-head"><span>LM Studio</span><b style="font-size:15px">– | ' + fmt(latest.lm_studio_tps) + '</b></div>');
    }
    enginesEl.innerHTML = rows.length ? rows.join('') : '<span class="muted">keine Engine geladen</span>';
  }

  // KV-Cache dual — Halogen-Zeile + NVIDIA-Zeile in einer Kachel
  const kvGauge = document.getElementById('kv-gauge-' + sid);
  const kvEl = document.getElementById('kv-' + sid);
  const kvSubEl = document.getElementById('kv-sub-' + sid);
  const kvBar = document.getElementById('kv-bar-' + sid);
  if (kvGauge && kvEl) {
    const kvPct = latest.halogen_kv_pool_pct;
    const kvUsed = latest.halogen_kv_pool_used;
    const kvTotal = latest.halogen_kv_pool_total;
    const nvHas = latest.nvidia_alive != null;
    if (kvPct != null || nvHas) {
      kvGauge.style.display = 'block';
      if (kvPct != null) {
        kvEl.textContent = kvPct.toFixed(1) + '%';
        if (kvSubEl && kvUsed != null && kvTotal != null) {
          kvSubEl.textContent = kvUsed.toLocaleString() + ' / ' + kvTotal.toLocaleString() + ' pos';
        }
        if (kvBar) { kvBar.style.width = Math.min(100, kvPct) + '%'; }
        kvGauge.classList.toggle('kv-warn', kvPct > 90);
      } else {
        kvEl.textContent = '–';
        if (kvSubEl) kvSubEl.textContent = 'Halogen ohne Daten';
        if (kvBar) kvBar.style.width = '0%';
      }
    } else {
      kvGauge.style.display = 'none';
    }
  }

  // NVIDIA eGPU (RTX 4070 SUPER) — nur bei Maschinen, die NVIDIA-Daten melden
  const hasNv = latest.nvidia_alive != null || latest.egpu_percent != null;
  const nvgWrap = document.getElementById('nvg-wrap-' + sid);
  if (nvgWrap) nvgWrap.hidden = !hasNv;
  const gpuLabel = document.getElementById('gpu-label-' + sid);
  if (gpuLabel) gpuLabel.textContent = hasNv ? 'GPU · beide Engines' : 'GPU';
  const gpuName = document.getElementById('gpu-name-' + sid);
  if (gpuName) gpuName.textContent = hasNv ? 'iGPU · Strix Halo' : '';
  const nvgV = document.getElementById('nvg-v-' + sid);
  if (nvgV) {
    const alive = latest.nvidia_alive;
    if (alive != null) {
      const pct = latest.egpu_percent != null ? +latest.egpu_percent : 0;
      nvgV.textContent = pct.toFixed(0) + '%';
      const vrU = latest.egpu_vram_used_mb, vrT = latest.egpu_vram_total_mb;
      const watt = latest.egpu_watt != null ? (+latest.egpu_watt).toFixed(0) + ' W' : null;
      const temp = latest.egpu_temp != null ? (+latest.egpu_temp) + ' °C' : null;
      const nvgSub = document.getElementById('nvg-sub-' + sid);
      if (nvgSub) nvgSub.textContent = [temp, watt].filter(Boolean).join(' · ') || '–';
      const nvgBar = document.getElementById('nvg-bar-' + sid);
      if (nvgBar) nvgBar.style.width = Math.min(100, pct) + '%';
      const kv = latest.nvidia_kv_pct;
      const nvkV = document.getElementById('nvk-v-' + sid);
      if (nvkV) nvkV.textContent = kv != null ? (+kv).toFixed(1) + '%' : '–';
      const nvkSub = document.getElementById('nvk-sub-' + sid);
      if (nvkSub) nvkSub.textContent =
        latest.nvidia_kv_tokens != null ? (+latest.nvidia_kv_tokens).toLocaleString() + ' Tok. · ctx 8k' : 'ctx 8192';
      const nvkBar = document.getElementById('nvk-bar-' + sid);
      if (nvkBar) nvkBar.style.width = (kv != null ? Math.min(100, +kv) : 0) + '%';
    } else {
      nvgV.textContent = '–';
      const nvgSub = document.getElementById('nvg-sub-' + sid);
      if (nvgSub) nvgSub.textContent = 'offline';
      const nvgBar = document.getElementById('nvg-bar-' + sid);
      if (nvgBar) nvgBar.style.width = '0%';
      const nvkV = document.getElementById('nvk-v-' + sid);
      if (nvkV) nvkV.textContent = '–';
      const nvkBar = document.getElementById('nvk-bar-' + sid);
      if (nvkBar) nvkBar.style.width = '0%';
    }
  }
}

function updateChart(sid, series) {
  const ctx = document.getElementById('chart-' + sid);
  if (!ctx) return;
  // Kein Datenfluss → Chart zerstören und Platzhalter zeigen statt Geister-Chart
  const wrap = ctx.closest('.chart-wrap');
  if (!series || !series.length) {
    if (charts[sid]) { try { charts[sid].destroy(); } catch(e) {} delete charts[sid]; }
    ctx.style.display = 'none';
    if (wrap && !wrap.querySelector('.no-data-ph')) {
      const ph = document.createElement('div');
      ph.className = 'no-data-ph';
      ph.style.cssText = 'position:absolute;inset:0;display:flex;flex-direction:column;gap:6px;align-items:center;justify-content:center;color:var(--text-m);font-size:13px';
      ph.innerHTML = '<span style="font-size:15px;color:var(--text)">Keine Daten</span><span>Dieser Rechner sendet gerade nichts — sobald der Collector wieder liefert, erscheint der Chart hier automatisch.</span>';
      wrap.appendChild(ph);
    }
    return;
  }
  ctx.style.display = '';
  if (wrap) { const ph = wrap.querySelector('.no-data-ph'); if (ph) ph.remove(); }
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
      borderColor: c.kv, backgroundColor: 'transparent', borderDash: [6, 4],
      tension: 0.3, pointRadius: 0, borderWidth: 1.5,
      yAxisID: 'y', order: -2,
    });
  }
  const datasets = [
    { label: 'GPU', data: series.map(p => Math.max(0, p.gpu)), borderColor: c.gpu, backgroundColor: c.gpu + '14', fill: 'origin', tension: 0.3, pointRadius: 0, borderWidth: 2 },
    { label: 'CPU', data: series.map(p => p.cpu),      borderColor: c.cpu, backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 1.5 },
    { label: 'RAM', data: series.map(p => p.ram),     borderColor: c.ram, backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 2 },
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
  if (/_stop$/.test(action) && !confirm('Dienst auf ' + sid + ' wirklich stoppen?')) return;
  btn.disabled = true;
  const origText = btn.textContent;
  btn.textContent = '…';
  fetch('unload-request.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ action: action, server_id: sid, model_id: 'backend' }),
  }).then(r => r.json()).then(d => {
    if (d.ok) { btn.textContent = 'Gesendet'; setTimeout(() => { btn.textContent = origText; btn.disabled = false; }, 3000); }
    else { btn.textContent = 'Fehler'; btn.disabled = false; }
  }).catch(() => { btn.textContent = 'Fehler'; btn.disabled = false; });
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
      btn.textContent = 'Gesendet';
      btn.classList.add('done');
      btn.disabled = true;
    } else {
      btn.textContent = 'Fehler';
      btn.classList.add('danger');
    }
  }).catch(() => {
    if (btn) {
      btn.textContent = 'Fehler';
      btn.classList.add('danger');
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
  'evo-x3': '#76b900', '127.0.0.1': '#8e8e93', '192.168.178.112': '#8e8e93',
  '100.67.189.1': '#6e6e73', '100.91.16.62': '#6e6e73', '100.77.241.124': '#6e6e73',
};
const IP_COLOR_LIGHT = {
  'evo-x3': '#4c7a00', '127.0.0.1': '#3a3a3c', '192.168.178.112': '#3a3a3c',
  '100.67.189.1': '#6e6e73', '100.91.16.62': '#6e6e73', '100.77.241.124': '#6e6e73',
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
    solarPower.textContent = 'Aktuell: ' + (latestW > 0 ? latestW.toFixed(0) : '0') + ' W';
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
  const solarLabel = 'Solar';

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
          borderColor: '#76b900', backgroundColor: '#76b90022',
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
  let allTs = [...tsSet].sort((a, b) => a - b);
  if (tokenRange > 0 && allTs.length) {
    const maxTs = allTs[allTs.length - 1];
    allTs = allTs.filter(ts => ts >= maxTs - tokenRange);
  }
  if (!allTs.length) {
    wrap.style.display = 'none';
    if (tokenChart) { try { tokenChart.destroy(); } catch(e) {} tokenChart = null; }
    return;
  }
  wrap.style.display = 'block';
  const labels = allTs.map(ts => new Date(ts * 1000).toLocaleTimeString([], { hour12: false, hour: '2-digit', minute: '2-digit' }));
  const multi = Object.keys(perMachine).length > 1;
  const PREFILL = '#8e8e93';   // Neutral (3-Farb-Regel)
  const DECODE  = '#76b900';   // Akzent (3-Farb-Regel)
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
    const col = '#76b900';
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
    UPDATED_EL.textContent = 'Aktualisiert ' + new Date(data.now * 1000).toLocaleTimeString('de-DE');

    // Solar leaf indicator
    for (const [sid, info] of Object.entries(data.servers || {})) {
      const w = info.latest && info.latest.shelly_power;
      const h1 = document.querySelector('h1');
      if (w !== null && w > 0) {
        if (h1 && !h1.querySelector('.leaf')) {
          const leaf = document.createElement('span');
          leaf.className = 'leaf';
          leaf.textContent = ' · Solar';
          leaf.style.cursor = 'help';
          leaf.title = 'Läuft mit Solarstrom';
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
    if (viewMode === 'brain') updateBrain();
    if (viewMode === 'er') renderER();

    updateSolarChart(data);
    renderTokenChart();
    renderHalogenReqChart();
  } catch (e) {
    UPDATED_EL.textContent = 'Keine Verbindung – letzter Stand bleibt sichtbar';
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
  setInterval(() => { if (!document.hidden) refresh(); }, REFRESH_INTERVAL * 1000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}
</script>
</body>
</html>