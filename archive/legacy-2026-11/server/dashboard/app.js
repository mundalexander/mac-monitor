/* mac-monitor Dashboard - ohne externe Abhaengigkeiten.
   Alle URLs werden relativ aufgeloest -> funktioniert unter jedem Pfad. */
(() => {
"use strict";

// --- URL-Aufloesung (Fix fuer kaputte/hart codierte URLs) -------------------
const BASE = (() => {
  let p = window.location.pathname;
  if (!p.endsWith("/")) p = p.slice(0, p.lastIndexOf("/") + 1);
  return window.location.origin + p.replace(/\/+$/, "");
})();
const API = BASE + "/api/v1";

const COLORS = ["#4c9aff","#a371f7","#3fb950","#d29922","#f85149","#39c5cf","#ff8fab","#c9a227"];
const $ = (s) => document.querySelector(s);
const fmt = (n, d = 1) => (n === null || n === undefined || isNaN(n)) ? "–" : Number(n).toFixed(d);
const state = { machines: [], series: null, range: 60, colorOf: {}, token: "" };

function color(id) {
  if (!state.colorOf[id]) {
    state.colorOf[id] = COLORS[Object.keys(state.colorOf).length % COLORS.length];
  }
  return state.colorOf[id];
}

async function call(path, opts = {}) {
  const o = Object.assign({ headers: {} }, opts);
  o.headers["Content-Type"] = "application/json";
  if (state.token) o.headers["X-API-Token"] = state.token;
  const r = await fetch(API + path, o);
  const body = await r.json().catch(() => ({}));
  return { ok: r.ok, status: r.status, body };
}

function setConn(ok, text) {
  $("#conn-dot").className = "dot " + (ok ? "ok" : "bad");
  $("#conn-text").textContent = text;
}

function relTime(sec) {
  if (sec === null || sec === undefined) return "nie";
  if (sec < 60) return sec + " s";
  if (sec < 3600) return Math.round(sec / 60) + " min";
  return Math.round(sec / 3600) + " h";
}

// --- Maschinenkarten --------------------------------------------------------
function ring(score, online) {
  const r = 26, c = 2 * Math.PI * r, pct = Math.max(0, Math.min(100, score));
  const col = !online ? "#8b98a5" : pct >= 60 ? "#3fb950" : pct >= 30 ? "#d29922" : "#f85149";
  return `<svg class="ring" viewBox="0 0 64 64">
    <circle cx="32" cy="32" r="${r}" fill="none" stroke="#2a323d" stroke-width="6"/>
    <circle cx="32" cy="32" r="${r}" fill="none" stroke="${col}" stroke-width="6"
      stroke-linecap="round" stroke-dasharray="${c}"
      stroke-dashoffset="${c * (1 - pct / 100)}" transform="rotate(-90 32 32)"/>
    <text x="32" y="32" text-anchor="middle" dominant-baseline="middle">${Math.round(pct)}</text>
    <text x="32" y="43" text-anchor="middle" class="label">SCORE</text></svg>`;
}

function bar(used, total, label) {
  const pct = total > 0 ? Math.min(100, (used / total) * 100) : 0;
  const cls = pct > 90 ? "bad" : pct > 75 ? "warn" : "";
  return `<div class="bar-row"><span>${label}</span>
      <span>${fmt(used)} / ${fmt(total)} GB</span></div>
    <div class="bar"><i class="${cls}" style="width:${pct}%"></i></div>`;
}

function badge(m) {
  if (!m.online) return '<span class="badge bad">offline</span>';
  if (m.free_slots <= 0) return '<span class="badge busy">ausgelastet</span>';
  if (m.capacity_score < 30) return '<span class="badge warn">knapp</span>';
  return '<span class="badge ok">verfügbar</span>';
}

function renderMachines(machines) {
  const el = $("#machines");
  if (!machines.length) {
    el.innerHTML = '<div class="empty">Noch keine Maschine registriert. Starte einen Client mit scripts/start-client.sh</div>';
    return;
  }
  el.innerHTML = machines.map(m => `
    <div class="card ${m.online ? "" : "offline"}" style="border-left:3px solid ${color(m.machine_id)}">
      <div class="card-head">
        <div>
          <div class="card-title">${m.name} ${badge(m)}</div>
          <div class="card-sub">${m.machine_id} · ${m.platform} · gesehen vor ${relTime(m.seconds_since_seen)}</div>
        </div>
        ${ring(m.capacity_score, m.online)}
      </div>
      <div class="metrics">
        <div class="metric"><b>${fmt(m.cpu_pct, 0)}%</b><span>CPU</span></div>
        <div class="metric"><b>${fmt(m.gpu_pct, 0)}%</b><span>GPU</span></div>
        <div class="metric"><b>${fmt(m.tps_current, 1)}</b><span>TPS jetzt</span></div>
        <div class="metric"><b>${fmt(m.tps_avg, 1)}</b><span>TPS Ø</span></div>
      </div>
      ${bar(m.ram_used_gb, m.ram_total_gb, "RAM")}
      ${m.vram_total_gb > 0 ? bar(m.vram_used_gb, m.vram_total_gb, "VRAM") : ""}
      <div class="bar-row"><span>frei planbar</span>
        <span>${fmt(m.ram_free_gb)} GB RAM · ${fmt(m.vram_free_gb)} GB VRAM</span></div>
      <div class="tags">
        <span class="tag slot">${m.free_slots}/${m.max_slots} Slots frei</span>
        ${m.tps_peak > 0 ? `<span class="tag">Peak ${fmt(m.tps_peak, 1)} TPS</span>` : ""}
        ${m.temp_c !== null ? `<span class="tag">${fmt(m.temp_c, 0)} °C</span>` : ""}
        ${m.power_w !== null ? `<span class="tag">${fmt(m.power_w, 0)} W</span>` : ""}
        ${m.loaded_models.map(x => `<span class="tag busy">${x}</span>`).join("")}
      </div>
    </div>`).join("");
}

// --- Canvas-Diagramme -------------------------------------------------------
function drawChart(canvas, series, opts = {}) {
  const dpr = window.devicePixelRatio || 1;
  const w = canvas.clientWidth, h = parseInt(canvas.getAttribute("height"), 10);
  canvas.width = w * dpr; canvas.height = h * dpr;
  const ctx = canvas.getContext("2d");
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.clearRect(0, 0, w, h);

  const pad = { l: 44, r: 10, t: 10, b: 20 };
  const pw = w - pad.l - pad.r, ph = h - pad.t - pad.b;
  const all = series.flatMap(s => s.points);
  if (!all.length) {
    ctx.fillStyle = "#8b98a5"; ctx.font = "12px sans-serif"; ctx.textAlign = "center";
    ctx.fillText("Keine Daten im gewählten Zeitraum", w / 2, h / 2);
    return;
  }
  const xs = all.map(p => p[0]), ys = all.map(p => p[1]);
  const x0 = Math.min(...xs), x1 = Math.max(...xs) || x0 + 1;
  let y1 = opts.max !== undefined ? opts.max : Math.max(...ys, 0.0001);
  if (opts.max === undefined) y1 *= 1.15;
  const y0 = 0;
  const X = t => pad.l + ((t - x0) / Math.max(1, x1 - x0)) * pw;
  const Y = v => pad.t + ph - ((v - y0) / Math.max(1e-9, y1 - y0)) * ph;

  ctx.strokeStyle = "#212a34"; ctx.fillStyle = "#8b98a5";
  ctx.font = "10px sans-serif"; ctx.lineWidth = 1; ctx.textAlign = "right";
  for (let i = 0; i <= 4; i++) {
    const v = y0 + (y1 - y0) * i / 4, y = Y(v);
    ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(w - pad.r, y); ctx.stroke();
    ctx.fillText(v >= 100 ? v.toFixed(0) : v.toFixed(1), pad.l - 6, y + 3);
  }
  ctx.textAlign = "center";
  for (let i = 0; i <= 4; i++) {
    const t = x0 + (x1 - x0) * i / 4;
    const d = new Date(t * 1000);
    ctx.fillText(d.getHours().toString().padStart(2, "0") + ":" +
      d.getMinutes().toString().padStart(2, "0"), X(t), h - 6);
  }

  series.forEach(s => {
    if (!s.points.length) return;
    ctx.beginPath();
    ctx.strokeStyle = s.color; ctx.lineWidth = s.width || 1.8;
    ctx.setLineDash(s.dash || []);
    s.points.forEach((p, i) => i ? ctx.lineTo(X(p[0]), Y(p[1])) : ctx.moveTo(X(p[0]), Y(p[1])));
    ctx.stroke(); ctx.setLineDash([]);
    if (s.dots) {
      ctx.fillStyle = s.color;
      s.points.forEach(p => { ctx.beginPath(); ctx.arc(X(p[0]), Y(p[1]), 2.4, 0, 7); ctx.fill(); });
    }
  });
}

function buildSeries(data) {
  const byMachine = {};
  data.samples.forEach(s => {
    (byMachine[s.machine_id] = byMachine[s.machine_id] || []).push(s);
  });
  const tpsByMachine = {};
  data.tps_samples.forEach(s => {
    (tpsByMachine[s.machine_id] = tpsByMachine[s.machine_id] || []).push(s);
  });

  const ids = Array.from(new Set([...Object.keys(byMachine), ...Object.keys(tpsByMachine)]));
  const tps = [], load = [], mem = [];
  ids.forEach(id => {
    const c = color(id);
    const rows = byMachine[id] || [];
    // TPS: echte Messpunkte bevorzugen, sonst Client-Rollwert.
    const measured = (tpsByMachine[id] || []).map(s => [s.ts, s.tps]);
    if (measured.length) tps.push({ name: id + " gemessen", color: c, points: measured, dots: true });
    const rolling = rows.filter(s => s.tps_avg > 0).map(s => [s.ts, s.tps_avg]);
    if (rolling.length) tps.push({ name: id + " Ø", color: c, points: rolling, dash: [4, 3], width: 1.2 });

    load.push({ name: id + " CPU", color: c, points: rows.map(s => [s.ts, s.cpu_pct || 0]) });
    load.push({ name: id + " GPU", color: c, dash: [4, 3], points: rows.map(s => [s.ts, s.gpu_pct || 0]) });
    mem.push({ name: id + " RAM", color: c, points: rows.map(s => [s.ts, s.ram_used_gb || 0]) });
    mem.push({ name: id + " VRAM", color: c, dash: [4, 3], points: rows.map(s => [s.ts, s.vram_used_gb || 0]) });
  });

  drawChart($("#chart-tps"), tps);
  drawChart($("#chart-load"), load, { max: 100 });
  drawChart($("#chart-mem"), mem);
  $("#legend").innerHTML = ids.map(id =>
    `<span><i style="background:${color(id)}"></i>${id}</span>`).join("") +
    '<span style="opacity:.7">durchgezogen = CPU/RAM/gemessene TPS · gestrichelt = GPU/VRAM/Durchschnitt</span>';
}

// --- Timeline ---------------------------------------------------------------
function renderTimeline(slots, machines) {
  const el = $("#timeline");
  const now = Math.floor(Date.now() / 1000);
  const t0 = now - 60 * 60, t1 = now + 15 * 60;
  const ids = machines.map(m => m.machine_id);
  if (!ids.length) { el.innerHTML = '<div class="empty">Keine Maschinen.</div>'; return; }
  const pos = t => ((t - t0) / (t1 - t0)) * 100;

  el.innerHTML = ids.map(id => {
    const blocks = slots.filter(s => s.machine_id === id).map(s => {
      const l = Math.max(0, pos(s.start)), r = Math.min(100, pos(s.end));
      if (r <= 0 || l >= 100) return "";
      return `<div class="tl-block ${s.state}" style="left:${l}%;width:${Math.max(1.2, r - l)}%"
        title="${s.id} · ${s.requester} · ${s.task_id} · ${s.model} · ${s.state}">${s.task_id || s.requester}</div>`;
    }).join("");
    const m = machines.find(x => x.machine_id === id);
    return `<div class="tl-row"><div class="tl-name" title="${id}">${m ? m.name : id}</div>
      <div class="tl-track">${blocks}<div class="tl-now" style="left:${pos(now)}%"></div></div></div>`;
  }).join("") +
  `<div class="tl-axis"><div>Zeit</div><div><span>-60 min</span><span>-30 min</span><span>jetzt</span><span>+15 min</span></div></div>`;
}

// --- Reservierungen ---------------------------------------------------------
function renderReservations(rows) {
  const tb = $("#res-table tbody");
  const now = Math.floor(Date.now() / 1000);
  if (!rows.length) {
    tb.innerHTML = '<tr><td colspan="7" class="empty">Keine Reservierungen.</td></tr>'; return;
  }
  tb.innerHTML = rows.slice(0, 25).map(r => {
    const active = r.state === "reserved" || r.state === "running";
    const rest = active ? Math.max(0, r.valid_until - now) + " s" : "–";
    const cls = r.state === "running" ? "busy" : r.state === "reserved" ? "ok"
      : r.state === "completed" ? "ok" : "warn";
    return `<tr>
      <td class="mono">${r.id}</td><td>${r.machine_id}</td>
      <td>${r.task_id || "–"}</td><td class="mono">${r.model || "–"}</td>
      <td><span class="badge ${cls}">${r.state}</span></td><td>${rest}</td>
      <td>${active ? `<button class="btn small" data-cancel="${r.id}">stop</button>` : ""}</td></tr>`;
  }).join("");
  tb.querySelectorAll("[data-cancel]").forEach(b => b.onclick = async () => {
    await call("/reservations/" + b.dataset.cancel, { method: "DELETE" });
    refresh();
  });
}

// --- Empfehlung -------------------------------------------------------------
function renderRecommendation(d) {
  const el = $("#recommendation");
  if (!d.can_start_subagent) {
    el.innerHTML = `<div class="rec-main"><div>
      <div class="rec-name">Keine freie Kapazität</div>
      <div class="rec-meta">${(d.unavailable || []).map(u => u.machine_id + ": " + u.reason).join(" · ") || "Keine Maschine online."}</div>
    </div></div>`;
    return;
  }
  const r = d.recommended;
  const body = JSON.stringify({
    requester: "openclaw", task_id: "subagent-01", model: d.model || "qwen2.5-coder:14b",
    minimum_ram_gb: 8, estimated_duration_seconds: 900
  });
  el.innerHTML = `
    <div class="rec-main">
      ${ring(r.capacity_score, true)}
      <div>
        <div class="rec-name">${r.name}</div>
        <div class="rec-meta">${r.machine_id} · ${r.platform} · ${fmt(r.ram_free_gb)} GB RAM frei ·
          ${fmt(r.vram_free_gb)} GB VRAM frei · ${r.free_slots} Slots frei
          ${r.model_loaded ? " · Modell bereits geladen" : ""}
          ${r.expected_tps > 0 ? " · erwartet " + fmt(r.expected_tps, 1) + " TPS" : ""}</div>
        <div class="tags"><span class="tag">${r.backend_url || "keine Backend-URL gemeldet"}</span></div>
      </div>
    </div>
    <div class="rec-code"><pre>curl -s -X POST ${API}/reservations \\
  -H "X-API-Token: &lt;token&gt;" -H "Content-Type: application/json" \\
  -d '${body}'</pre></div>`;
}

function renderEndpoints(cfg) {
  $("#endpoints").innerHTML = [
    ["Dashboard", BASE + "/"],
    ["Health", API + "/health"],
    ["Agent-Discovery", API + "/agent/discovery"],
    ["Kapazität", API + "/capacity"],
    ["Reservierung", API + "/reservations"],
    ["Ingest (Client)", API + "/ingest"],
    ["TPS-Meldung", API + "/tps"],
    ["Verlauf", API + "/series?minutes=60"]
  ].map(([k, v]) => `<div><b>${k}</b><br>${v}</div>`).join("");
  $("#footer-info").textContent =
    `Intervall ${cfg.sample_interval}s · offline nach ${cfg.offline_after}s · ` +
    `Slot-TTL ${cfg.reservation_ttl}s · Heartbeat-Timeout ${cfg.heartbeat_timeout}s · ` +
    `Rohdaten ${cfg.retention_raw_days} Tage`;
}

// --- Refresh ----------------------------------------------------------------
async function refresh() {
  try {
    const model = $("#filter-model").value.trim();
    const ram = $("#filter-ram").value || 0, vram = $("#filter-vram").value || 0;
    const qs = `?model=${encodeURIComponent(model)}&min_ram_gb=${ram}&min_vram_gb=${vram}`;

    const [mach, disc, slots, res, series, tpsSum] = await Promise.all([
      call("/capacity" + (model ? "?model=" + encodeURIComponent(model) : "")),
      call("/agent/discovery" + qs),
      call("/slots?window=75"),
      call("/reservations?limit=25"),
      call("/series?minutes=" + state.range),
      call("/tps/summary?minutes=" + state.range)
    ]);
    if (!mach.ok) throw new Error("HTTP " + mach.status);

    state.machines = mach.body.machines || [];
    renderMachines(state.machines);
    renderRecommendation(disc.body);
    renderTimeline(slots.body.slots || [], state.machines);
    renderReservations(res.body.reservations || []);
    buildSeries(series.body);

    const online = state.machines.filter(m => m.online);
    $("#kpi-online").textContent = `${online.length}/${state.machines.length}`;
    $("#kpi-slots").textContent = online.reduce((a, m) => a + m.free_slots, 0);
    $("#kpi-tps").textContent = fmt(online.reduce((a, m) => a + (m.tps_current || 0), 0), 1);
    $("#kpi-res").textContent = (res.body.reservations || [])
      .filter(r => r.state === "running" || r.state === "reserved").length;

    $("#tps-table tbody").innerHTML = (tpsSum.body.rows || []).length
      ? tpsSum.body.rows.map(r => `<tr><td>${r.machine_id}</td><td class="mono">${r.model || "–"}</td>
          <td>${r.samples}</td><td>${fmt(r.avg_tps)}</td><td>${fmt(r.peak_tps)}</td>
          <td>${r.tokens}</td></tr>`).join("")
      : '<tr><td colspan="6" class="empty">Noch keine TPS-Messung. Agenten melden sie an POST /api/v1/tps.</td></tr>';

    const sel = $('select[name="machine_id"]');
    const cur = sel.value;
    sel.innerHTML = '<option value="">automatisch (Option C)</option>' +
      state.machines.map(m => `<option value="${m.machine_id}">${m.name}</option>`).join("");
    sel.value = cur;

    setConn(true, "verbunden · " + new Date().toLocaleTimeString("de-DE"));
  } catch (e) {
    setConn(false, "keine Verbindung zum Server");
  }
}

// --- Init -------------------------------------------------------------------
$("#range").onchange = e => { state.range = +e.target.value; refresh(); };
$("#btn-plan").onclick = refresh;
$("#filter-model").onkeydown = e => { if (e.key === "Enter") refresh(); };
$("#token").oninput = e => {
  state.token = e.target.value.trim();
  try { localStorage.setItem("mm_token", state.token); } catch (_) {}
};
try {
  state.token = localStorage.getItem("mm_token") || "";
  $("#token").value = state.token;
} catch (_) {}

$("#res-form").onsubmit = async ev => {
  ev.preventDefault();
  const f = new FormData(ev.target);
  const payload = {
    requester: f.get("requester"), task_id: f.get("task_id"), model: f.get("model"),
    minimum_ram_gb: +f.get("minimum_ram_gb") || 0,
    minimum_vram_gb: +f.get("minimum_vram_gb") || 0,
    estimated_duration_seconds: +f.get("estimated_duration_seconds") || 600,
    priority: +f.get("priority") || 50
  };
  if (f.get("machine_id")) payload.machine_id = f.get("machine_id");
  state.token = (f.get("token") || state.token || "").trim();
  const r = await call("/reservations", { method: "POST", body: JSON.stringify(payload) });
  $("#res-result").textContent = JSON.stringify(r.body, null, 2);
  refresh();
};

call("/config").then(r => r.ok && renderEndpoints(r.body));
refresh();
setInterval(refresh, 10000);
window.addEventListener("resize", () => state.series && buildSeries(state.series));
})();
