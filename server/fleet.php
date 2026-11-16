<?php
require_once __DIR__ . '/config.php';
mm_gate_page();   // PIN-Anmeldung; gemerkte Geräte kommen direkt durch
// Engine Room — Doppel-Karte Halogen + NVIDIA. Daten: fleet-api.php (10s-Poll).
// Design: recovery-glass.css Tokens 1:1 (assets/fleet.css), kein zweites Design-System.
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Fleet Monitor · Engine Room</title>
<link rel="stylesheet" href="assets/fleet.css?v=130">
<link rel="stylesheet" href="assets/apple.css?v=130">
</head>
<body>
<div class="ambient" aria-hidden="true"></div>
<main>
<header>
  <a class="brand" href="fleet.php"><span class="brand-icon">m.</span><span>Fleet Monitor<small>ENGINE ROOM</small></span></a>
  <nav>
    <a href="index.php">Monitoring ↗</a>
    <button id="theme" type="button" class="quiet">Darstellung</button>
  </nav>
</header>

<section class="intro">
  <p class="eyebrow">ZWEI ENGINES · EINE MASCHINE</p>
  <h1>Der Maschinenraum.</h1>
  <p class="subtitle">Halogen auf iGPU + NPU. NVIDIA auf RTX 4070 SUPER. Beide sichtbar, beide ehrlich.</p>
</section>

<div id="notice" role="status" aria-live="polite">Verbinde mit der Telemetrie…</div>

<section class="engines">
  <article class="glass engine-card" id="card-halogen">
    <div class="engine-head">
      <div>
        <p class="eyebrow">HALOGEN · iGPU + NPU</p>
        <div class="orb">H<span class="dot" id="halo-dot"></span></div>
        <h2 id="halo-model">Halogen<small>Qwen3.8 Flash Next</small></h2>
      </div>
      <span class="badge" id="halo-badge">–</span>
    </div>
    <div class="big-metric"><strong id="halo-tps">–</strong><span>Token/s · Decode</span></div>
    <div class="meter">
      <div class="meter-top"><span>KV-Pool</span><b id="halo-kv-label">–</b></div>
      <div class="bar" id="halo-kv-bar"><i id="halo-kv" style="width:0%"></i></div>
    </div>
    <div class="meter">
      <div class="meter-top"><span>RAM</span><b id="halo-ram-label">–</b></div>
      <div class="bar" id="halo-ram-bar"><i id="halo-ram" style="width:0%"></i></div>
    </div>
    <svg class="spark" id="halo-spark" viewBox="0 0 240 38" preserveAspectRatio="none" aria-hidden="true"></svg>
    <div class="engine-foot">
      <span>Context <b id="halo-ctx">–</b></span>
      <span>Prefill <b id="halo-prefill">–</b> t/s</span>
      <span id="halo-ts">–</span>
    </div>
  </article>

  <article class="glass engine-card" id="card-nvidia">
    <div class="engine-head">
      <div>
        <p class="eyebrow">NVIDIA · RTX 4070 SUPER</p>
        <div class="orb">N<span class="dot" id="nvi-dot"></span></div>
        <h2 id="nvi-model">NVIDIA<small>qwen3.8-27b-nvidia</small></h2>
      </div>
      <span class="badge" id="nvi-badge">–</span>
    </div>
    <div class="big-metric"><strong id="nvi-tps">–</strong><span>Token/s · Decode</span></div>
    <div class="meter">
      <div class="meter-top"><span>KV-Cache</span><b id="nvi-kv-label">–</b></div>
      <div class="bar" id="nvi-kv-bar"><i id="nvi-kv" style="width:0%"></i></div>
    </div>
    <div class="meter">
      <div class="meter-top"><span>VRAM</span><b id="nvi-vram-label">–</b></div>
      <div class="bar" id="nvi-vram-bar"><i id="nvi-vram" style="width:0%"></i></div>
    </div>
    <svg class="spark" id="nvi-spark" viewBox="0 0 240 38" preserveAspectRatio="none" aria-hidden="true"></svg>
    <div class="engine-foot">
      <span>Context <b id="nvi-ctx">–</b></span>
      <span>Verbrauch <b id="nvi-watt">–</b> W</span>
      <span>Slots <b id="nvi-slots">–</b></span>
    </div>
  </article>
</section>

<section class="secondary">
  <article class="glass mini">
    <p class="eyebrow">GAMING-PC · ÜBER SHUNK</p>
    <h3 id="gaming-watt">– W</h3>
    <p class="sub" id="gaming-sub">Home-Assistant-Leistungsmessung</p>
  </article>
  <article class="glass mini">
    <p class="eyebrow">eGPU · VERBRAUCH</p>
    <h3 id="egpu-watt">– W</h3>
    <p class="sub">RTX 4070 SUPER · nvidia-smi</p>
  </article>
  <article class="glass mini">
    <p class="eyebrow">AUTONOMIE</p>
    <h3 id="autonomy">–</h3>
    <p class="sub">Wake-Journal · Kill-Switch in der Autonomie-Ansicht</p>
  </article>
</section>

<footer>
  <span>Alles lokal. Keine Cloud. Datenweg: Client → Server → diese Seite.</span>
  <span id="foot-ts">–</span>
</footer>
</main>

<script>
(function(){
  'use strict';
  var $ = function(id){ return document.getElementById(id); };
  var THEME_KEY = 'mm_theme';
  function applyTheme(t){
    document.body.classList.remove('dark','light');
    if (t === 'dark' || t === 'light') document.body.classList.add(t);
  }
  var saved = null;
  try { saved = localStorage.getItem(THEME_KEY); } catch (e) {}
  if (saved) applyTheme(saved);
  $('theme').addEventListener('click', function(){
    var dark = document.body.classList.contains('dark') ||
      (!document.body.classList.contains('light') && matchMedia('(prefers-color-scheme: dark)').matches);
    var next = dark ? 'light' : 'dark';
    applyTheme(next);
    try { localStorage.setItem(THEME_KEY, next); } catch (e) {}
  });

  function fmt(v, d){ return (v === null || v === undefined || isNaN(v)) ? '–' : Number(v).toFixed(d === undefined ? 1 : d); }
  function setBar(id, barId, pct){
    var el = $(id), bar = $(barId);
    var p = (pct === null || pct === undefined || isNaN(pct)) ? 0 : Math.max(0, Math.min(100, Number(pct)));
    el.style.width = p + '%';
    bar.classList.remove('warm','hot');
    if (p >= 90) bar.classList.add('hot'); else if (p >= 75) bar.classList.add('warm');
  }
  function setEngine(prefix, e){
    var dot = $(prefix+'-dot'), badge = $(prefix+'-badge');
    dot.className = 'dot'; badge.className = 'badge';
    if (!e || e.alive === undefined) { badge.textContent = 'keine Daten'; $(prefix+'-tps').textContent = '–'; return; }
    var busy = (e.decode_tps && Number(e.decode_tps) > 0) || (e.slots_busy && Number(e.slots_busy) > 0) || (e.requests && Number(e.requests) > 0);
    if (e.alive) { dot.classList.add(busy ? 'on' : 'idle'); badge.classList.add('online'); badge.textContent = busy ? 'aktiv' : 'bereit'; }
    else { dot.classList.add('off'); badge.classList.add('offline'); badge.textContent = 'offline'; }
  }
  function spark(id, pts){
    var svg = $(id);
    svg.innerHTML = '';
    var vals = (pts || []).map(function(p){ return p; }).filter(function(v){ return v !== null && v !== undefined && !isNaN(v); });
    if (vals.length < 2) return;
    var W = 240, H = 38, max = Math.max.apply(null, vals), min = Math.min.apply(null, vals);
    var span = (max - min) || 1;
    var step = W / (vals.length - 1);
    var d = '', area = 'M0 ' + H + ' ';
    vals.forEach(function(v, i){
      var x = i * step, y = H - 3 - ((v - min) / span) * (H - 6);
      d += (i === 0 ? 'M' : 'L') + x.toFixed(1) + ' ' + y.toFixed(1) + ' ';
      area += 'L' + x.toFixed(1) + ' ' + y.toFixed(1) + ' ';
    });
    area += 'L' + W + ' ' + H + ' Z';
    var ns = 'http://www.w3.org/2000/svg';
    var f = document.createElementNS(ns, 'path'); f.setAttribute('class', 'fill'); f.setAttribute('d', area);
    var p = document.createElementNS(ns, 'path'); p.setAttribute('d', d);
    svg.appendChild(f); svg.appendChild(p);
  }
  function ago(ts){
    if (!ts) return '–';
    var s = Math.max(0, Math.floor(Date.now() / 1000) - Number(ts));
    if (s < 90) return 'gerade eben';
    if (s < 5400) return 'vor ' + Math.round(s / 60) + ' Min';
    return 'vor ' + Math.round(s / 3600) + ' Std';
  }

  function render(d){
    var h = d.halogen || {}, n = d.nvidia || {};
    setEngine('halo', h.alive === undefined ? null : h);
    setEngine('nvi', n.alive === undefined ? null : n);
    $('halo-tps').textContent = fmt(h.decode_tps, 1);
    setBar('halo-kv', 'halo-kv-bar', h.kv_pct);
    $('halo-kv-label').textContent = (h.kv_pct === null || h.kv_pct === undefined) ? '–' : fmt(h.kv_pct, 1) + ' %' + (h.kv_used ? ' · ' + Math.round(h.kv_used / 1024) + 'k/' + Math.round(h.kv_total / 1024) + 'k Pos.' : '');
    setBar('halo-ram', 'halo-ram-bar', h.ram_pct);
    $('halo-ram-label').textContent = fmt(h.ram_pct, 0) + ' %';
    $('halo-ctx').textContent = h.ctx ? Math.round(h.ctx / 1024) + 'k' : '–';
    $('halo-prefill').textContent = fmt(h.prefill_tps, 0);
    $('halo-ts').textContent = ago(d.ts);
    $('nvi-tps').textContent = fmt(n.decode_tps, 1);
    setBar('nvi-kv', 'nvi-kv-bar', n.kv_pct);
    $('nvi-kv-label').textContent = (n.kv_pct === null || n.kv_pct === undefined) ? '–' : fmt(n.kv_pct, 1) + ' %' + (n.kv_tokens ? ' · ' + n.kv_tokens + ' Tok.' : '');
    var vPct = (n.vram_used_mb && n.vram_total_mb) ? n.vram_used_mb / n.vram_total_mb * 100 : null;
    setBar('nvi-vram', 'nvi-vram-bar', vPct);
    $('nvi-vram-label').textContent = (n.vram_used_mb ? (n.vram_used_mb / 1024).toFixed(1) + ' / ' + (n.vram_total_mb / 1024).toFixed(1) + ' GB' : '–');
    $('nvi-ctx').textContent = n.ctx ? (n.ctx / 1024) + 'k' : '–';
    $('nvi-watt').textContent = fmt(n.watt, 1);
    $('nvi-slots').textContent = (n.slots_busy === null || n.slots_busy === undefined) ? '–' : n.slots_busy + ' / 4';
    $('gaming-watt').textContent = fmt(d.gaming_pc_watt, 0) + ' W';
    $('egpu-watt').textContent = fmt(n.watt, 1) + ' W';
    var a = $('autonomy');
    a.innerHTML = d.autonomy ? '<span class="pill">aktiv</span>' : '<span class="pill off">pausiert</span>';
    var hist = d.history || [];
    spark('halo-spark', hist.map(function(r){ return r.halogen_kv; }));
    spark('nvi-spark', hist.map(function(r){ return r.nvidia_kv; }));
    $('foot-ts').textContent = 'Stand ' + new Date(Number(d.ts) * 1000).toLocaleTimeString('de-DE');
    $('notice').textContent = 'Telemetrie live · Intervall 10 s · Verlauf 24 h';
  }

  function tick(){
    fetch('fleet-api.php', { cache: 'no-store' })
      .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function(d){ if (d && d.ok) render(d); else throw new Error(d && d.error || 'API-Fehler'); })
      .catch(function(err){
        $('notice').textContent = 'Keine Daten (' + err.message + ') — letzte Werte bleiben stehen.';
        ['halo','nvi'].forEach(function(p){ var b = $(p+'-badge'); b.className = 'badge'; b.textContent = '–'; });
      });
  }
  tick();
  setInterval(() => { if (!document.hidden) tick(); }, 10000);
})();
</script>
</body>
</html>
