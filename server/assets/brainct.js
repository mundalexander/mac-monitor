'use strict';
/**
 * BrainCT — stilisierte Gehirn-CT-/Neuronen-Visualisierung auf <canvas>.
 * Vanilla-JS, dependency-frei. Global: window.BrainCT.mount(canvasEl, { getSnapshot }).
 */
(function () {
  var FLOW_COLORS = {
    thinking: '#5ac8fa',
    search:   '#ffa657',
    memory:   '#bf5af2',
    code:     '#30d158',
    speaking: '#ff6482'
  };
  var DEFAULT_ACCENT = '#5ac8fa';
  var GROUP_ANCHORS = {
    npu:   { x:  0.00, y:  0.12 },
    llm:   { x: -0.42, y: -0.12 },
    tts:   { x:  0.34, y:  0.40 },
    stt:   { x: -0.38, y:  0.46 },
    other: { x:  0.00, y:  0.00 }
  };
  var GROUP_LABEL = { npu:'NPU', llm:'LLM', tts:'TTS', stt:'STT', other:'OTHER' };
  var MAX_PARTICLES = 250;

  function hashStr(s) {
    s = String(s);
    var h = 2166136261;
    for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); }
    return (h >>> 0) / 4294967295;
  }
  function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
  function hexA(c, a) {
    if (typeof c === 'string' && c.charAt(0) === '#') {
      var r = parseInt(c.slice(1, 3), 16),
          g = parseInt(c.slice(3, 5), 16),
          b = parseInt(c.slice(5, 7), 16);
      return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
    }
    return c;
  }

  function mount(canvas, opts) {
    opts = opts || {};
    var getSnapshot = typeof opts.getSnapshot === 'function' ? opts.getSnapshot : function () { return null; };
    if (!canvas || !canvas.getContext) return { stop: function () {} };
    var ctx = canvas.getContext('2d');

    var dpr = window.devicePixelRatio || 1;
    var W = 0, H = 0;
    var rafId = null, pollTimer = null, reducedTimer = null, resizeTimer = null;
    var stopped = false;

    var particles = [];
    var pulses = [];
    var scanAngle = 0;
    var lastTs = 0;

    var lastSnapRef = null;
    var lastSnapKey = '';
    var nodes = [];
    var edges = [];
    var accent = DEFAULT_ACCENT;
    var flowLabel = 'Bereitschaft';
    var hasActive = false;

    var hoverX = 0, hoverY = 0, hovered = null;

    var reducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function resize() {
      var rect = canvas.getBoundingClientRect();
      dpr = window.devicePixelRatio || 1;
      W = Math.max(1, rect.width);
      H = Math.max(1, rect.height);
      canvas.width = Math.round(W * dpr);
      canvas.height = Math.round(H * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      layoutNodes();
    }

    var ro = new ResizeObserver(function () {
      if (reducedMotion) { resize(); renderStatic(); return; }
      if (resizeTimer) clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () { resize(); }, 80);
    });
    ro.observe(canvas);

    function onMouseMove(e) {
      var rect = canvas.getBoundingClientRect();
      hoverX = e.clientX - rect.left;
      hoverY = e.clientY - rect.top;
      var best = null, bestD = 14 * 14;
      for (var i = 0; i < nodes.length; i++) {
        var n = nodes[i];
        var dx = n.x - hoverX, dy = n.y - hoverY;
        var d = dx * dx + dy * dy;
        if (d <= bestD) { bestD = d; best = n; }
      }
      hovered = best;
      if (reducedMotion) renderStatic();
    }
    function onMouseLeave() {
      hovered = null;
      if (reducedMotion) renderStatic();
    }
    canvas.addEventListener('mousemove', onMouseMove);
    canvas.addEventListener('mouseleave', onMouseLeave);

    function processSnapshot(snap) {
      var flow = (snap && snap.flow) || {};
      var kind = flow.kind;
      accent = FLOW_COLORS[kind] || DEFAULT_ACCENT;
      if (kind === 'search' && flow.query) flowLabel = 'sucht: ' + flow.query;
      else if (kind === 'thinking') flowLabel = 'denkt nach';
      else if (kind === 'memory') flowLabel = 'Erinnerung';
      else if (kind === 'code') flowLabel = 'Code';
      else if (kind === 'speaking') flowLabel = 'spricht';
      else flowLabel = 'Bereitschaft';

      var raw = (snap && snap.nodes) ? snap.nodes : [];
      var prevMap = {};
      for (var p = 0; p < nodes.length; p++) prevMap[nodes[p].id] = nodes[p];
      var out = [];
      for (var i = 0; i < raw.length; i++) {
        var n = raw[i] || {};
        var id = n.id != null ? String(n.id) : ('node' + i);
        var group = ['npu','llm','tts','stt','other'].indexOf(n.group) >= 0 ? n.group : 'other';
        var state = ['active','ready','idle','down'].indexOf(n.state) >= 0 ? n.state : 'idle';
        var activity = typeof n.activity === 'number' ? clamp(n.activity, 0, 1)
                                                  : (state === 'active' ? 0.6 : 0);
        var lastActive = (typeof n.last_active_ts === 'number' && isFinite(n.last_active_ts) && n.last_active_ts > 0) ? n.last_active_ts * 1000 : 0;
        var tokensMin = (typeof n.tokens_min === 'number') ? n.tokens_min : null;
        out.push({
          id: id, label: n.label || id, group: group, state: state,
          activity: activity, meta: n.meta || '',
          hash: hashStr(id), seed: hashStr(id + 'x'),
          lastActive: lastActive, tokensMin: tokensMin, afterglow: 0
        });
      }
      hasActive = false;
      for (var a = 0; a < out.length; a++) {
        if (out[a].state === 'active') { hasActive = true; break; }
      }
      var now = Date.now();
      for (var b = 0; b < out.length; b++) {
        var nd = out[b];
        if (nd.state === 'active' || nd.lastActive <= 0) { nd.afterglow = 0; continue; }
        var age = now - nd.lastActive;
        if (age >= 600000) { nd.afterglow = 0; continue; }
        var prev = prevMap[nd.id];
        var lastActiveChanged = !prev || prev.lastActive !== nd.lastActive;
        if (hasActive || lastActiveChanged) {
          nd.afterglow = 1 - age / 600000;
        } else {
          nd.afterglow = prev ? (prev.afterglow || 0) : 0;
        }
      }
      nodes = out;
      hovered = null; // Node-Objekte wurden ersetzt — Tooltip-Referenz fällt
      layoutNodes();  // auf das nächste mousemove neu zu.
      buildEdges();
    }

    function layoutNodes() {
      if (W === 0 || H === 0) return;
      var cx = W / 2, cy = H / 2;
      var R = Math.min(W, H) * 0.32;
      var groups = { npu:[], llm:[], tts:[], stt:[], other:[] };
      for (var i = 0; i < nodes.length; i++) groups[nodes[i].group].push(nodes[i]);
      for (var g in groups) {
        var arr = groups[g];
        var anchor = GROUP_ANCHORS[g];
        for (var j = 0; j < arr.length; j++) {
          var n = arr[j];
          var ax = anchor.x, ay = anchor.y;
          if (g === 'other') {
            var ang = n.seed * Math.PI * 2;
            var rad = 0.7 + n.hash * 0.5;
            ax = Math.cos(ang) * rad * 0.6;
            ay = Math.sin(ang) * rad * 0.5;
          }
          var spread = arr.length > 1 ? (0.10 + n.hash * 0.09) : 0;
          var a2 = n.hash * Math.PI * 2;
          n.x = cx + (ax + Math.cos(a2) * spread) * R;
          n.y = cy + (ay + Math.sin(a2) * spread) * R;
          n.r = 4 + n.hash * 2;
        }
      }
    }

    function buildEdges() {
      edges = [];
      var npus = [];
      for (var i = 0; i < nodes.length; i++) if (nodes[i].group === 'npu') npus.push(nodes[i]);
      for (var k = 0; k < nodes.length; k++) {
        var n = nodes[k];
        if (n.group === 'npu') continue;
        var target = null;
        if (npus.length) {
          var best = Infinity;
          for (var j = 0; j < npus.length; j++) {
            var m = npus[j];
            var dx = n.x - m.x, dy = n.y - m.y;
            var d = dx * dx + dy * dy;
            if (d < best) { best = d; target = m; }
          }
        } else if (nodes.length > 1) {
          for (var j2 = 0; j2 < nodes.length; j2++) {
            if (nodes[j2] !== n) { target = nodes[j2]; break; }
          }
        }
        if (target) edges.push({ a: n, b: target });
      }
    }

    // ---- Rendering ----
    function clear() { ctx.fillStyle = '#030305'; ctx.fillRect(0, 0, W, H); }

    function drawGrid() {
      ctx.save();
      ctx.strokeStyle = 'rgba(90,200,250,0.04)';
      ctx.lineWidth = 1;
      ctx.beginPath();
      for (var x = 0; x <= W; x += 28) { ctx.moveTo(x, 0); ctx.lineTo(x, H); }
      for (var y = 0; y <= H; y += 28) { ctx.moveTo(0, y); ctx.lineTo(W, y); }
      ctx.stroke();
      ctx.restore();
    }

    function drawScan() {
      var cx = W / 2, cy = H / 2;
      var R = Math.min(W, H) * 0.55;
      ctx.save();
      ctx.translate(cx, cy);
      ctx.rotate(scanAngle);
      var g = ctx.createLinearGradient(-R, 0, R, 0);
      g.addColorStop(0, 'rgba(0,0,0,0)');
      g.addColorStop(0.5, hexA(accent, 0.10));
      g.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = g;
      ctx.fillRect(-R, -2, R * 2, 4);
      ctx.restore();
    }

    function drawBrain() {
      var cx = W / 2, cy = H / 2;
      var R = Math.min(W, H) * 0.32;
      ctx.save();
      ctx.strokeStyle = 'rgba(120,180,220,0.28)';
      ctx.lineWidth = 1.2;
      ctx.shadowColor = 'rgba(90,160,220,0.4)';
      ctx.shadowBlur = 12;
      // linke Hemisphäre
      ctx.beginPath();
      ctx.moveTo(cx, cy - R * 0.9);
      ctx.bezierCurveTo(cx - R * 1.1, cy - R * 0.9, cx - R * 1.15, cy + R * 0.6, cx, cy + R * 0.7);
      ctx.bezierCurveTo(cx - R * 0.2, cy + R * 0.5, cx - R * 0.6, cy - R * 0.7, cx, cy - R * 0.9);
      ctx.stroke();
      // rechte Hemisphäre
      ctx.beginPath();
      ctx.moveTo(cx, cy - R * 0.9);
      ctx.bezierCurveTo(cx + R * 1.1, cy - R * 0.9, cx + R * 1.15, cy + R * 0.6, cx, cy + R * 0.7);
      ctx.bezierCurveTo(cx + R * 0.2, cy + R * 0.5, cx + R * 0.6, cy - R * 0.7, cx, cy - R * 0.9);
      ctx.stroke();
      // Mittellinie
      ctx.beginPath();
      ctx.moveTo(cx, cy - R * 0.9);
      ctx.lineTo(cx, cy + R * 0.7);
      ctx.stroke();
      // Stammhirn
      ctx.beginPath();
      ctx.moveTo(cx - R * 0.12, cy + R * 0.7);
      ctx.bezierCurveTo(cx - R * 0.18, cy + R * 0.95, cx + R * 0.18, cy + R * 0.95, cx + R * 0.12, cy + R * 0.7);
      ctx.stroke();
      ctx.restore();
    }

    function drawEdges() {
      ctx.save();
      for (var i = 0; i < edges.length; i++) {
        var e = edges[i];
        var active = e.a.state === 'active' || e.b.state === 'active';
        ctx.strokeStyle = active ? hexA(accent, 0.18) : 'rgba(120,160,200,0.06)';
        ctx.lineWidth = active ? 1.2 : 0.6;
        ctx.beginPath();
        ctx.moveTo(e.a.x, e.a.y);
        ctx.lineTo(e.b.x, e.b.y);
        ctx.stroke();
      }
      ctx.restore();
    }

    function drawNodes(t) {
      for (var i = 0; i < nodes.length; i++) {
        var n = nodes[i];
        var r = n.r;
        if (n.state === 'active') {
          var pulse = 1 + 0.25 * Math.sin(t * 0.006 + n.hash * 10) * n.activity;
          r = n.r * pulse;
          var halo = ctx.createRadialGradient(n.x, n.y, 0, n.x, n.y, r * 4);
          halo.addColorStop(0, hexA(accent, 0.4 * n.activity));
          halo.addColorStop(1, 'rgba(0,0,0,0)');
          ctx.fillStyle = halo;
          ctx.beginPath(); ctx.arc(n.x, n.y, r * 4, 0, Math.PI * 2); ctx.fill();
          ctx.fillStyle = 'rgba(255,255,255,0.9)';
          ctx.beginPath(); ctx.arc(n.x, n.y, r * 0.7, 0, Math.PI * 2); ctx.fill();
          ctx.fillStyle = hexA(accent, 0.95);
          ctx.beginPath(); ctx.arc(n.x, n.y, r, 0, Math.PI * 2); ctx.fill();
        } else if (n.state === 'ready') {
          if (hasActive) {
            var br = 1 + 0.12 * Math.sin(t * 0.003 + n.hash * 10);
            r = n.r * br;
          } else {
            r = n.r;
          }
          ctx.fillStyle = hexA(accent, 0.85);
          ctx.beginPath(); ctx.arc(n.x, n.y, r, 0, Math.PI * 2); ctx.fill();
        } else if (n.state === 'idle') {
          ctx.strokeStyle = 'rgba(140,160,180,0.4)';
          ctx.lineWidth = 1;
          ctx.beginPath(); ctx.arc(n.x, n.y, r, 0, Math.PI * 2); ctx.stroke();
        } else if (n.state === 'down') {
          ctx.fillStyle = 'rgba(120,60,60,0.6)';
          ctx.beginPath(); ctx.arc(n.x, n.y, r * 0.6, 0, Math.PI * 2); ctx.fill();
          ctx.strokeStyle = 'rgba(180,80,80,0.7)';
          ctx.lineWidth = 1.2;
          ctx.beginPath(); ctx.arc(n.x, n.y, r, 0, Math.PI * 2); ctx.stroke();
          ctx.beginPath();
          ctx.moveTo(n.x - r * 0.8, n.y - r * 0.8);
          ctx.lineTo(n.x + r * 0.8, n.y + r * 0.8);
          ctx.stroke();
        }
        if (n.afterglow > 0 && n.state !== 'active') {
          var gr = ctx.createRadialGradient(n.x, n.y, 0, n.x, n.y, n.r * 3.2);
          gr.addColorStop(0, hexA(accent, 0.28 * n.afterglow));
          gr.addColorStop(1, 'rgba(0,0,0,0)');
          ctx.fillStyle = gr;
          ctx.beginPath(); ctx.arc(n.x, n.y, n.r * 3.2, 0, Math.PI * 2); ctx.fill();
        }
      }
    }

    function drawParticles() {
      for (var i = 0; i < particles.length; i++) {
        var p = particles[i];
        var a = p.life / p.maxLife;
        ctx.fillStyle = hexA(p.color, a * 0.8);
        ctx.beginPath(); ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2); ctx.fill();
      }
    }

    function drawPulses() {
      for (var i = 0; i < pulses.length; i++) {
        var p = pulses[i];
        var x = p.edge.a.x + (p.edge.b.x - p.edge.a.x) * p.t;
        var y = p.edge.a.y + (p.edge.b.y - p.edge.a.y) * p.t;
        ctx.fillStyle = hexA(p.color, 0.3);
        ctx.beginPath(); ctx.arc(x, y, 3.5, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = hexA(p.color, 0.95);
        ctx.beginPath(); ctx.arc(x, y, 1.8, 0, Math.PI * 2); ctx.fill();
      }
    }

    function drawLabels() {
      ctx.save();
      ctx.font = '10px ui-monospace, Menlo, Consolas, monospace';
      ctx.textBaseline = 'middle';
      var groups = {};
      for (var i = 0; i < nodes.length; i++) {
        var n = nodes[i];
        if (!groups[n.group]) groups[n.group] = { xs: 0, ys: 0, c: 0, ns: [] };
        groups[n.group].xs += n.x; groups[n.group].ys += n.y; groups[n.group].c++;
        groups[n.group].ns.push(n);
      }
      for (var g in groups) {
        var o = groups[g];
        if (!o.c) continue;
        var labelX = o.xs / o.c + 8;
        var labelY = o.ys / o.c - 12;
        var intensity = 0;
        for (var ii = 0; ii < o.ns.length; ii++) {
          var nn = o.ns[ii];
          var v = nn.state === 'active' ? Math.max(0.25, nn.activity) : (nn.afterglow || 0) * 0.5;
          if (v > intensity) intensity = v;
        }
        ctx.fillStyle = 'rgba(255,255,255,0.10)';
        ctx.fillRect(labelX, labelY + 8, 46, 3);
        if (intensity > 0) {
          ctx.fillStyle = hexA(accent, 0.55 + 0.35 * intensity);
          ctx.fillRect(labelX, labelY + 8, 46 * intensity, 3);
        }
        ctx.fillStyle = 'rgba(150,180,210,0.5)';
        ctx.fillText(GROUP_LABEL[g] || g.toUpperCase(), labelX, labelY);
      }
      ctx.fillStyle = 'rgba(220,235,255,0.85)';
      for (var k = 0; k < nodes.length; k++) {
        var nd = nodes[k];
        if (nd.state !== 'active') continue;
        ctx.fillText(nd.label + (nd.meta ? ' · ' + nd.meta : ''), nd.x + nd.r + 6, nd.y);
      }
      ctx.restore();
    }

    function drawHeader() {
      ctx.save();
      ctx.textBaseline = 'top';
      ctx.font = 'bold 11px ui-monospace, Menlo, Consolas, monospace';
      ctx.fillStyle = hexA(accent, 0.9);
      ctx.fillText('GEHIRN-CT · LIVE', 12, 12);
      ctx.font = '10px ui-monospace, Menlo, Consolas, monospace';
      ctx.fillStyle = 'rgba(200,220,240,0.7)';
      ctx.fillText(flowLabel, 12, 28);
      ctx.restore();
    }

    function drawVignette() {
      var g = ctx.createRadialGradient(W / 2, H / 2, Math.min(W, H) * 0.3,
                                       W / 2, H / 2, Math.max(W, H) * 0.7);
      g.addColorStop(0, 'rgba(0,0,0,0)');
      g.addColorStop(1, 'rgba(0,0,0,0.55)');
      ctx.fillStyle = g;
      ctx.fillRect(0, 0, W, H);
    }

    function drawTooltip() {
      var n = hovered;
      if (!n) return;
      ctx.save();
      ctx.strokeStyle = hexA(accent, 0.6);
      ctx.lineWidth = 1.2;
      ctx.beginPath(); ctx.arc(n.x, n.y, n.r + 6, 0, Math.PI * 2); ctx.stroke();
      var lines = [];
      lines.push(n.label);
      if (n.meta) lines.push(n.meta);
      if (n.tokensMin) lines.push(n.tokensMin + ' tok/min');
      if (n.lastActive) lines.push('zuletzt vor ' + Math.max(1, Math.round((Date.now() - n.lastActive) / 60000)) + ' min');
      ctx.font = '10px ui-monospace, Menlo, Consolas, monospace';
      ctx.textBaseline = 'top';
      var maxW = 0;
      for (var i = 0; i < lines.length; i++) {
        var w = ctx.measureText(lines[i]).width;
        if (w > maxW) maxW = w;
      }
      var pad = 6, lh = 14;
      var tw = maxW + pad * 2;
      var th = lines.length * lh + pad * 2 - 4;
      var tx = n.x + 14, ty = n.y + 14;
      if (tx + tw > W - 4) tx = n.x - 14 - tw;
      if (ty + th > H - 4) ty = n.y - 14 - th;
      if (tx < 4) tx = 4;
      if (ty < 4) ty = 4;
      ctx.fillStyle = 'rgba(10,14,20,0.92)';
      ctx.fillRect(tx, ty, tw, th);
      ctx.strokeStyle = hexA(accent, 0.5);
      ctx.lineWidth = 1;
      ctx.strokeRect(tx, ty, tw, th);
      ctx.fillStyle = 'rgba(220,235,255,0.9)';
      for (var j = 0; j < lines.length; j++) {
        ctx.fillText(lines[j], tx + pad, ty + pad + j * lh);
      }
      ctx.restore();
    }

    function spawnParticle(n) {
      if (particles.length >= MAX_PARTICLES) return;
      var ang = Math.random() * Math.PI * 2;
      var spd = 0.3 + Math.random() * 0.8;
      particles.push({
        x: n.x, y: n.y,
        vx: Math.cos(ang) * spd, vy: Math.sin(ang) * spd,
        life: 0, maxLife: 40 + Math.random() * 40,
        color: accent, size: 1 + Math.random() * 1.5
      });
    }

    function maybeSpawnPulses() {
      for (var i = 0; i < edges.length; i++) {
        var e = edges[i];
        var src = e.a.state === 'active' ? e.a : (e.b.state === 'active' ? e.b : null);
        if (src && Math.random() < src.activity * 0.04 && pulses.length < MAX_PARTICLES) {
          pulses.push({ edge: e, t: 0, speed: 0.0008 + Math.random() * 0.0008, color: accent });
        }
      }
    }

    function update(dt) {
      if (hasActive) scanAngle += 0.0006 * dt;
      for (var i = particles.length - 1; i >= 0; i--) {
        var p = particles[i];
        p.x += p.vx; p.y += p.vy; p.life++;
        p.vx *= 0.98; p.vy *= 0.98;
        if (p.life >= p.maxLife) particles.splice(i, 1);
      }
      for (var j = pulses.length - 1; j >= 0; j--) {
        var pu = pulses[j];
        pu.t += pu.speed * dt;
        if (pu.t >= 1) pulses.splice(j, 1);
      }
      for (var k = 0; k < nodes.length; k++) {
        var n = nodes[k];
        if (n.state === 'active' && Math.random() < n.activity * 0.3) spawnParticle(n);
      }
      maybeSpawnPulses();
    }

    function render(t) {
      clear();
      drawGrid();
      if (!reducedMotion && hasActive) drawScan();
      drawBrain();
      drawEdges();
      drawPulses();
      drawNodes(t);
      drawParticles();
      drawVignette();
      drawLabels();
      drawHeader();
      drawTooltip();
    }
    function renderStatic() { render(performance.now()); }

    function pollSnapshot() {
      var snap = getSnapshot();
      if (snap == null) {
        if (lastSnapRef !== null) {
          lastSnapRef = null; lastSnapKey = '';
          nodes = []; edges = []; particles = []; pulses = [];
          hasActive = false;
          accent = DEFAULT_ACCENT; flowLabel = 'Bereitschaft';
        }
        return;
      }
      var key = (snap.ts || '') + '|' + ((snap.flow && snap.flow.kind) || '') + '|' + ((snap.nodes && snap.nodes.length) || 0);
      if (snap !== lastSnapRef && key !== lastSnapKey) {
        lastSnapRef = snap; lastSnapKey = key;
        processSnapshot(snap);
      }
    }

    function frame(t) {
      if (stopped) return;
      if (document.hidden) { rafId = requestAnimationFrame(frame); return; }
      if (!lastTs) lastTs = t;
      var dt = t - lastTs; lastTs = t;
      update(dt);
      render(t);
      rafId = requestAnimationFrame(frame);
    }

    resize();

    if (reducedMotion) {
      reducedTimer = setInterval(function () {
        var prev = lastSnapKey;
        pollSnapshot();
        if (lastSnapKey !== prev) renderStatic();
      }, 2000);
      pollSnapshot();
      renderStatic();
    } else {
      pollTimer = setInterval(pollSnapshot, 4000);
      pollSnapshot();
      rafId = requestAnimationFrame(frame);
    }

    function stop() {
      stopped = true;
      if (rafId) cancelAnimationFrame(rafId);
      if (pollTimer) clearInterval(pollTimer);
      if (reducedTimer) clearInterval(reducedTimer);
      if (resizeTimer) clearTimeout(resizeTimer);
      try { ro.disconnect(); } catch (e) {}
      canvas.removeEventListener('mousemove', onMouseMove);
      canvas.removeEventListener('mouseleave', onMouseLeave);
      particles = []; pulses = []; nodes = []; edges = [];
      hovered = null;
      try { ctx.clearRect(0, 0, canvas.width, canvas.height); } catch (e) {}
    }

    return { stop: stop };
  }

  window.BrainCT = { mount: mount };
})();
