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
  const THEME_LABELS = { auto: 'Auto', light: 'Hell', dark: 'Dunkel' };

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
    b.innerHTML = '<span>' + msg + '</span>';
    if (actionLabel && actionFn) {
      const btn = document.createElement('button');
      btn.className = 'btn btn-sm';
      btn.textContent = actionLabel;
      btn.addEventListener('click', actionFn);
      b.appendChild(btn);
    }
    b.classList.add('show');
  };

  // ── Auth-Helfer (PIN + Passkey/WebAuthn) ─────────────────────────
  // Backend: auth-api.php (PIN gegen PBKDF2-Hash, WebAuthn-Server).
  // Passkey wird priorisiert, PIN ist Fallback. Legacy-Token akzeptiert
  // das Backend weiterhin (API-Clients).
  function b64url(buf) {
    const bytes = new Uint8Array(buf);
    let s = '';
    for (const b of bytes) s += String.fromCharCode(b);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function unb64url(str) {
    const m = str.length % 4 ? '='.repeat(4 - (str.length % 4)) : '';
    const bin = atob(str.replace(/-/g, '+').replace(/_/g, '/') + m);
    const u8 = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) u8[i] = bin.charCodeAt(i);
    return u8;
  }
  async function authApi(action, body) {
    const r = await fetch('auth-api.php?' + action + '=1', {
      method: action === 'status' ? 'GET' : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: action === 'status' ? undefined : JSON.stringify(body || {}),
      credentials: 'same-origin',
    });
    let j = {};
    try { j = await r.json(); } catch (e) {}
    return { ok: r.ok, status: r.status, json: j };
  }
  function webauthnUsable() {
    return !!(window.PublicKeyCredential && navigator.credentials);
  }

  // Passkey-Login: assert-Flow gegen auth-api.php. true bei Erfolg.
  window.mmPasskeyLogin = async function () {
    if (!webauthnUsable()) throw new Error('Kein WebAuthn im Browser');
    const begin = await authApi('passkey-assert-begin');
    if (!begin.ok) throw new Error(begin.json.message || 'Server: Assert-Begin fehlgeschlagen');
    const pk = begin.json.publicKey;
    pk.challenge = unb64url(pk.challenge);
    (pk.allowCredentials || []).forEach(c => { c.id = unb64url(c.id); });
    const cred = await navigator.credentials.get({ publicKey: pk });
    const resp = cred.response;
    const sent = await authApi('passkey-assert-finish', {
      credential: {
        id: cred.id,
        response: {
          clientDataJSON: b64url(resp.clientDataJSON),
          authenticatorData: b64url(resp.authenticatorData),
          signature: b64url(resp.signature),
        },
      },
    });
    return sent.ok;
  };

  // Passkey einrichten (erfordert bestehende Session, z. B. PIN-Login).
  window.mmPasskeySetup = async function (label) {
    if (!webauthnUsable()) throw new Error('Kein WebAuthn im Browser');
    const begin = await authApi('passkey-register-begin');
    if (!begin.ok) throw new Error(begin.json.message || 'Server: Register-Begin fehlgeschlagen');
    const pk = begin.json.publicKey;
    pk.challenge = unb64url(pk.challenge);
    pk.user.id = unb64url(pk.user.id);
    (pk.excludeCredentials || []).forEach(c => { c.id = unb64url(c.id); });
    const cred = await navigator.credentials.create({ publicKey: pk });
    const resp = cred.response;
    const sent = await authApi('passkey-register-finish', {
      label: label || (navigator.platform || 'Gerät'),
      credential: {
        id: cred.id,
        response: {
          clientDataJSON: b64url(resp.clientDataJSON),
          attestationObject: b64url(resp.attestationObject),
        },
      },
    });
    if (!sent.ok) throw new Error(sent.json.message || 'Registrierung abgelehnt');
    return true;
  };

  // ── Login-Modal ────────────────────────────────────────────────────
  // Session-Login: Passkey (bevorzugt) oder PIN gegen auth-api.php;
  // das Backend akzeptiert im Feld auch das alte Dashboard-Token.
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
          '<h3>Login</h3>' +
          '<div class="muted" style="line-height:1.5">PIN eingeben oder Anmelden mit Passkey.</div>' +
          '<input id="mm-login-pin" type="password" inputmode="numeric" autocomplete="one-time-code" placeholder="PIN" maxlength="8">' +
          '<label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px;color:var(--text-m);cursor:pointer"><input type="checkbox" id="mm-login-remember" checked style="width:auto;margin:0"> Gerät merken (90 Tage, ohne erneute PIN)</label>' +
          '<div class="mm-login-err" id="mm-login-err"></div>' +
          '<div class="dlg-btns">' +
          '<button class="btn" id="mm-login-cancel">Abbrechen</button>' +
          '<button class="btn" id="mm-login-passkey" title="Fingerabdruck / Gesicht">Passkey</button>' +
          '<button class="btn btn-p" id="mm-login-ok">Login</button>' +
          '</div></div>';
        document.body.appendChild(ov);
      }
      const input = document.getElementById('mm-login-pin');
      const err = document.getElementById('mm-login-err');
      const pkBtn = document.getElementById('mm-login-passkey');
      err.textContent = '';
      ov.classList.add('open');
      // Passkey-Button nur zeigen, wenn Browser WebAuthn kann.
      pkBtn.style.display = webauthnUsable() ? '' : 'none';
      setTimeout(() => input.focus(), 50);

      function done(ok) {
        ov.classList.remove('open');
        input.value = '';
        document.removeEventListener('keydown', onKey);
        resolve(ok);
      }
      async function afterLogin(method) {
        done(true);
        window.mmToast && window.mmToast('Angemeldet', 'ok');
        // Nach PIN-Login dezent Passkey-Registrierung anbieten (1× pro Session).
        if (method === 'pin' && webauthnUsable() && !window.__mmPkOffered) {
          window.__mmPkOffered = true;
          try {
            const st = await authApi('status');
            if (st.ok && st.json.passkeyCount === 0) {
              window.mmBanner('Noch kein Passkey hinterlegt — Fingerabdruck-Login einrichten?',
                'Einrichten', async () => {
                  window.mmBanner(null);
                  try { await window.mmPasskeySetup(); window.mmToast('Passkey eingerichtet', 'ok'); }
                  catch (e) { window.mmToast('Passkey: ' + e.message, 'err'); }
                });
            }
          } catch (e) {}
        }
      }
      async function submit() {
        const pin = input.value.trim();
        if (!pin) { err.textContent = 'PIN eingeben.'; return; }
        const remember = !!(document.getElementById('mm-login-remember') || {}).checked;
        try {
          const r = await authApi('login', { pin: pin, remember: remember ? 1 : 0 });
          if (r.ok) await afterLogin(r.json.method || 'pin');
          else { err.textContent = (r.json && r.json.message) || 'PIN falsch.'; input.select(); }
        } catch (e) { err.textContent = 'Netzwerkfehler: ' + e.message; }
      }
      async function passkey() {
        pkBtn.disabled = true;
        err.textContent = '';
        try {
          if (await window.mmPasskeyLogin()) await afterLogin('passkey');
          else err.textContent = 'Passkey abgelehnt.';
        } catch (e) {
          err.textContent = (e && e.name === 'NotAllowedError') ? 'Passkey abgebrochen.' : ('Passkey: ' + e.message);
        } finally { pkBtn.disabled = false; }
      }
      function onKey(e) { if (e.key === 'Escape') done(false); }
      document.getElementById('mm-login-ok').onclick = submit;
      document.getElementById('mm-login-cancel').onclick = () => done(false);
      pkBtn.onclick = passkey;
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
