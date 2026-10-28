/* ═══════════════════════════════════════════════════════════════════
   mac-monitor — gemeinsames JS (Relaunch 2026-10-28)
   Theme-Engine (auto/light/dark, localStorage 'mm_theme'),
   Toast, Login-Modal (slot-api/board-api Session-Login), Banner.
   Wirkt mit dem Pre-Paint-Snippet im jeweiligen <head>.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  // ── Theme ──────────────────────────────────────────────────────────
  // Initialisierung erfolgt durch das Inline-Snippet im <head> (FOUC-frei).
  window.__mmTheme = window.__mmTheme || 'auto';
  window.__mmResolveTheme = window.__mmResolveTheme || function (mode) {
    if (mode === 'light' || mode === 'dark') return mode;
    return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  };

  const THEME_ORDER = ['auto', 'light', 'dark'];
  const THEME_LABELS = { auto: '🔄 Auto', light: '☀️ Hell', dark: '🌙 Dunkel' };

  function applyTheme() {
    document.documentElement.setAttribute('data-theme', window.__mmResolveTheme(window.__mmTheme));
    document.documentElement.setAttribute('data-theme-mode', window.__mmTheme);
    document.querySelectorAll('[data-theme-toggle]').forEach(b => {
      b.textContent = THEME_LABELS[window.__mmTheme] || THEME_LABELS.auto;
      b.title = 'Theme: ' + window.__mmTheme;
    });
    document.dispatchEvent(new CustomEvent('mm-theme-change'));
  }
  window.__mmApplyTheme = applyTheme;

  window.mmToggleTheme = function () {
    const i = THEME_ORDER.indexOf(window.__mmTheme);
    window.__mmTheme = THEME_ORDER[(i + 1) % THEME_ORDER.length];
    try { localStorage.setItem('mm_theme', window.__mmTheme); } catch (e) {}
    applyTheme();
  };

  window.addEventListener('storage', e => {
    if (e.key === 'mm_theme') {
      window.__mmTheme = e.newValue || 'auto';
      applyTheme();
    }
  });
  window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => {
    if (window.__mmTheme === 'auto') applyTheme();
  });

  // ── Toast ──────────────────────────────────────────────────────────
  window.mmToast = function (msg, type) {
    let t = document.getElementById('mm-toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'mm-toast';
      t.className = 'toast';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.className = 'toast show ' + (type || 'ok');
    clearTimeout(t._tid);
    t._tid = setTimeout(() => t.classList.remove('show'), 4000);
  };

  // ── Banner (persistenter Fehlerhinweis oben auf der Seite) ────────
  window.mmBanner = function (msg, actionLabel, actionFn) {
    let b = document.getElementById('mm-banner');
    if (!b) {
      b = document.createElement('div');
      b.id = 'mm-banner';
      b.className = 'mm-banner';
      const main = document.querySelector('main') || document.body;
      main.insertBefore(b, main.firstChild);
    }
    if (!msg) { b.classList.remove('show'); b.innerHTML = ''; return; }
    b.innerHTML = '<span>⚠️ ' + msg + '</span>';
    if (actionLabel && actionFn) {
      const btn = document.createElement('button');
      btn.className = 'btn btn-sm';
      btn.textContent = actionLabel;
      btn.addEventListener('click', actionFn);
      b.appendChild(btn);
    }
    b.classList.add('show');
  };

  // ── Login-Modal ────────────────────────────────────────────────────
  // Session-Login gegen slot-api.php oder board-api.php (?login=1).
  // Gibt true zurück nach erfolgreichem Login, false bei Abbruch.
  window.mmLogin = function (loginEndpoint) {
    return new Promise(resolve => {
      let ov = document.getElementById('mm-login-ov');
      if (!ov) {
        ov = document.createElement('div');
        ov.id = 'mm-login-ov';
        ov.className = 'dlg-ov mm-login-dlg';
        ov.innerHTML =
          '<div class="dlg">' +
          '<h3>🔐 Login erforderlich</h3>' +
          '<div class="muted" style="line-height:1.5">Für Buchungen und Kalender-Daten wird das Dashboard-Token benötigt.</div>' +
          '<input id="mm-login-token" type="password" placeholder="Dashboard-Token" autocomplete="current-password">' +
          '<div class="mm-login-err" id="mm-login-err"></div>' +
          '<div class="dlg-btns">' +
          '<button class="btn" id="mm-login-cancel">Abbrechen</button>' +
          '<button class="btn btn-p" id="mm-login-ok">Login</button>' +
          '</div></div>';
        document.body.appendChild(ov);
      }
      const input = document.getElementById('mm-login-token');
      const err = document.getElementById('mm-login-err');
      err.textContent = '';
      ov.classList.add('open');
      setTimeout(() => input.focus(), 50);

      function done(ok) {
        ov.classList.remove('open');
        input.value = '';
        document.removeEventListener('keydown', onKey);
        resolve(ok);
      }
      async function submit() {
        const tok = input.value.trim();
        if (!tok) { err.textContent = 'Token eingeben.'; return; }
        try {
          const r = await fetch(loginEndpoint + '?login=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ dashboard_token: tok }),
          });
          if (r.ok) { done(true); window.mmToast && window.mmToast('✅ Angemeldet', 'ok'); }
          else { err.textContent = 'Token falsch.'; input.select(); }
        } catch (e) { err.textContent = 'Netzwerkfehler: ' + e.message; }
      }
      function onKey(e) { if (e.key === 'Escape') done(false); }
      document.getElementById('mm-login-ok').onclick = submit;
      document.getElementById('mm-login-cancel').onclick = () => done(false);
      input.onkeydown = e => { if (e.key === 'Enter') submit(); };
      document.addEventListener('keydown', onKey);
    });
  };

  // ── Init bei DOM ready ─────────────────────────────────────────────
  function init() {
    applyTheme();
    document.querySelectorAll('[data-theme-toggle]').forEach(b =>
      b.addEventListener('click', window.mmToggleTheme));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
