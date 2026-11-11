# Server Setup

## Prerequisites

- PHP 7.4+ with SQLite support (`php-sqlite3`)
- HTTPS (required for secure token transmission)
- SQLite write permissions

## Files

| File | Endpoint | Method | Purpose |
|------|----------|--------|---------|
| `config.php` | — | — | Shared config: token, DB schema, `db()` helper |
| `submit.php` | `/submit.php` | POST | Receives metric payloads from clients |
| `data.php` | `/data.php` | GET | Returns JSON feed for the dashboard |
| `index.php` | `/` | GET | Chart.js dashboard (browser UI) |

## Deployment

### 1. Upload files

Upload all 4 PHP files to your web host under `/assistant/mac-monitor/`:

```
/www/newcms/assistant/mac-monitor/
├── config.php
├── submit.php
├── data.php
└── index.php
```

The SQLite database (`metrics.sqlite`) will be created automatically on first
`submit.php` request in the same directory.

> ⚠️ **Strato/similar shared hosts:** Place the DB next to PHP files and rely on
> `.htaccess` to deny direct access. Do NOT put the DB in a publicly readable folder.

### 2. Set the secret token

Edit `config.php`:

```php
const SECRET_TOKEN = 'your-secret-token-here';
```

This must match the `API_TOKEN` in each client's `monitor.py`.

### 3. Test it

```bash
# Send a fake payload
curl -X POST https://your-host.com/assistant/mac-monitor/submit.php \
  -H "Content-Type: application/json" \
  -d '{
    "token": "your-secret-token-here",
    "host": "TestMac",
    "ts": '$(date +%s)',
    "cpu": 10.5,
    "gpu": 5.0,
    "ram_percent": 30.0
  }'
# Expected: {"ok": true}

# Check the dashboard
curl "https://your-host.com/assistant/mac-monitor/data.php?range=1h"
```

### 4. Secure the database

Add to `.htaccess` in the `mac-monitor/` directory:

```apache
# Deny direct access to SQLite
<Files "metrics.sqlite">
    Order allow,deny
    Deny from all
</Files>
```

## API Reference

### POST /submit.php

Receives metric snapshots from clients.

**Request body:**
```json
{
  "token": "secret",
  "host": "BigMac",
  "server_id": "mac",
  "ts": 1724234567,
  "cpu": 12.4,
  "gpu": 8.0,
  "ram_percent": 31.2,
  "ram_used_gb": 13.9,
  "ram_total_gb": 44.51,
  "ollama": { "loaded": [], "available": [] },
  "shelly_power": 35.7
}
```

The `server_id` field is optional but recommended. If absent, the server
resolves it from the hostname via the `SERVERS` registry in `config.php`.

**Response:** `{"ok": true}` or error JSON

### GET /data.php

Returns JSON for the dashboard.

**Query params:**
| Param | Default | Description |
|-------|---------|-------------|
| `range` | `1h` | `10m`, `30m`, `1h`, `6h`, `12h`, `24h` |
| `host` | all | Filter by hostname |
| `cleanup` | — | Set to any value to delete stale hosts |

**Response shape:**
```json
{
  "range_hours": 1,
  "now": 1724234567,
  "servers": {
    "mac": {
      "id": "mac", "name": "BigMac", "host": "BigMac", "os": "macOS",
      "icon": "🖥️", "color": "#58a6ff",
      "latest": { "ts": 1724234567, "cpu": 8.4, "gpu": 20, "ram_percent": 33.3, ... },
      "series": [{ "ts": 1724234000, "cpu": 10.2, "gpu": 0, "ram": 33.1 }, ...]
    },
    "evo-x3": {
      "id": "evo-x3", "name": "Evo-X3", "host": "sascha-EVO-X3", "os": "Ubuntu 24.04",
      "icon": "🟠", "color": "#ffa657",
      "latest": { ... }, "series": [ ... ]
    }
  },
  "hosts": { ... },  // legacy compat — keyed by hostname
  "requests": [{ "ts": 1724234000, "ip": "127.0.0.1", "method": "POST", "endpoint": "/api/chat", "duration_ms": 4200, "status": 200 }, ...]
}
```

The `servers` object is keyed by `server_id` and includes all servers from
the `SERVERS` registry, even if no data has arrived yet. The `hosts` object
is kept for backward compatibility.

## Database Schema

SQLite, created automatically by `config.php`:

```sql
CREATE TABLE metrics (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ts           INTEGER NOT NULL,
    host         TEXT    NOT NULL,
    server_id    TEXT    DEFAULT NULL,   -- 'mac' | 'evo-x3' | ...
    cpu          REAL    NOT NULL,
    gpu          REAL    NOT NULL,
    ram_percent  REAL    NOT NULL,
    ram_used_gb  REAL,
    ram_total_gb REAL,
    ollama       TEXT,
    shelly_power REAL
);

CREATE TABLE requests (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ts           INTEGER NOT NULL,
    ip           TEXT    NOT NULL,
    method       TEXT    NOT NULL,
    endpoint     TEXT    NOT NULL,
    duration_ms  REAL,
    status       INTEGER
);
```

Retention: rows older than `RETENTION_HOURS` (default 25h) are deleted on every
new `submit.php` call.

## Customization

### Caller IP labels (dashboard)

In `index.php`, edit the `IP_LABELS` and `IP_COLORS` objects near the bottom
of the `<script>` tag to map Ollama caller IPs to human-readable names:

```javascript
const IP_LABELS = {
  '127.0.0.1':   'Bernd',
  '100.67.189.1': 'Dorian',
  '100.91.16.62': 'Sascha',
};
```

### Server registry (multi-host)

In `config.php`, edit the `SERVERS` constant to register additional servers:

```php
const SERVERS = [
    'mac' => [
        'id' => 'mac', 'name' => 'BigMac', 'host' => 'BigMac',
        'os' => 'macOS', 'icon' => '🖥️', 'color' => '#58a6ff',
    ],
    'evo-x3' => [
        'id' => 'evo-x3', 'name' => 'Evo-X3', 'host' => 'sascha-EVO-X3',
        'os' => 'Ubuntu 24.04', 'icon' => '🟠', 'color' => '#ffa657',
    ],
];
```

The dashboard shows one tab per registered server, with a side-by-side
view when two or more servers have data and the screen is wide enough.

### Shelly plug IP

In `client/monitor.py` (macOS) or `client/monitor_linux.py` (Linux), edit:
```python
shelly_resp = urllib.request.urlopen("http://192.168.178.73/rpc/Shelly.GetStatus", timeout=3)
```
Change the IP to your Shelly Plug S address.

## Sicherheit (seit 2026-09-06)

Hochzuladende Dateien (Web-Verzeichnis, hier als Beispiel `/mac-monitor/`):

| Datei | Zweck |
|---|---|
| `.htaccess` | Sperrt Download von `metrics.sqlite*`, Logs und Includes |
| `auth.php`  | Dashboard-Login (Token) + API-Gate für `data.php` |
| `secrets.php` | **Einmalig** erzeugen, enthält `DASHBOARD_TOKEN`; niemals committen |

Beispiel `secrets.php`:

```php
<?php
define('DASHBOARD_TOKEN', '<starkes Zufallstoken>');
```

- Browser: `index.php` zeigt Login-Maske, Session-Cookie hält 30 Tage.
- API/Curl: Header `X-Monitor-Token: <token>` (oder `?token=<token>`) auf `data.php`.
- `submit.php` bleibt offen, Client-Auth läuft über `SECRET_TOKEN` im JSON-Body.
- Nach dem Upload prüfen: `curl -s -o /dev/null -w '%{http_code}' https://HOST/mac-monitor/metrics.sqlite` → muss **403** sein.
