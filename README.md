# Mac Monitor — Multi-Server System & Ollama Monitoring

A two-part system for monitoring system stats (CPU, RAM, GPU, Ollama models, Shelly smart-plug power) with a live web dashboard. Supports multiple servers.

```
┌──────────────────────────────────────────────────────────────────┐
│                         ARCHITECTURE                              │
│                                                                   │
│   BigMac (macOS)              bornhauser.net                     │
│  ┌──────────────┐            ┌──────────────────┐                │
│  │ monitor.py   │──POST──▶   │ submit.php       │                │
│  │ (every 10s)  │   metrics  │ (writes SQLite)  │                │
│  └──────────────┘            └────────┬─────────┘                │
│        │                              │                          │
│        │ nc (temp)                   ▼                          │
│        ▼                    ┌──────────────────┐                 │
│  ┌───────────┐             │ index.php         │                 │
│  │ smc_daemon│             │ (Chart.js UI)    │◀──── browser     │
│  │ (root)    │             └──────────────────┘                 │
│  └───────────┘                   ▲                              │
│                                   │ GET /data.php                │
│                                   └────────────────────────────  │
│                                                                   │
│   Evo-X3 (Ubuntu 24.04)                                          │
│  ┌──────────────────┐                                           │
│  │ monitor_linux.py │──POST──▶ (same endpoint)                  │
│  │ (every 10s)      │   metrics  (server_id="evo-x3")          │
│  └──────────────────┘                                           │
└──────────────────────────────────────────────────────────────────┘
```

## Contents

```
mac-monitor/
├── README.md              ← you are here
├── client/
│   ├── monitor.py             Python client — macOS (BigMac)
│   ├── run_monitor.sh         Launch script for macOS (launchd)
│   ├── monitor_linux.py       Python client — Linux/Evo-X3
│   ├── run_monitor_linux.sh   Launch script for Linux (systemd)
│   ├── install_smc_daemon.sh  macOS SMC daemon installer
│   ├── install_smc_helper.sh  macOS SMC helper installer
│   ├── smc_daemon.c           Root daemon (SMC temps, macOS)
│   ├── smc_helper.c           Helper tool (sudo, macOS)
│   └── README-client.md
└── server/
    ├── mac-monitor-config.php   Shared config + DB schema + SERVERS registry
    ├── mac-monitor-submit.php   POST endpoint — receives metrics
    ├── mac-monitor-data.php     GET endpoint — JSON for dashboard
    ├── mac-monitor-index.php    Chart.js dashboard UI (multi-server tabs)
    └── README-server.md
```

## Supported Servers

| server_id | Name    | OS           | GPU              | Client              |
|-----------|---------|--------------|------------------|---------------------|
| `mac`     | BigMac  | macOS        | Apple Silicon    | `monitor.py`        |
| `evo-x3`  | Evo-X3  | Ubuntu 24.04 | Radeon 8060S     | `monitor_linux.py`  |

To add a third server, add an entry to `SERVERS` in `config.php` and write
a client that sends `server_id` in its POST payload.

## Quick Start

### 1. Server (already running at bornhauser.net/assistant/mac-monitor/)

Deploy `server/` to any PHP web host with SQLite support.

See `server/README-server.md` for full deployment instructions.

### 2. Client — macOS (BigMac)

See `client/README-client.md` for full setup. TL;DR:

```bash
# 1. Edit tokens in monitor.py
SERVER_URL = "https://your-host.com/mac-monitor/submit.php"
API_TOKEN  = "your-secret-token"   # must match config.php

# 2. Start manually
python3 monitor.py

# 3. Install as launchd agent (auto-starts on boot)
bash install_smc_daemon.sh   # root daemon for SMC temps
python3 run_monitor.sh       # user-level launchd
```

### 3. Client — Linux (Evo-X3)

```bash
# 1. Copy client files to Evo-X3
scp client/monitor_linux.py client/run_monitor_linux.sh evo-x3:~/mac-monitor/

# 2. Edit tokens in monitor_linux.py
SERVER_URL = "https://your-host.com/mac-monitor/submit.php"
API_TOKEN  = "your-secret-token"   # must match config.php

# 3. Test manually
python3 ~/mac-monitor/monitor_linux.py

# 4. Install as systemd service
mkdir -p ~/.config/systemd/user
cat > ~/.config/systemd/user/mac-monitor-linux.service << 'EOF'
[Unit]
Description=Mac Monitor Linux Client (Evo-X3)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=%h/mac-monitor/run_monitor_linux.sh
Restart=on-failure
RestartSec=30
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=default.target
EOF
systemctl --user daemon-reload
systemctl --user enable --now mac-monitor-linux.service
```

## What Gets Monitored

| Metric | macOS (BigMac) | Linux (Evo-X3) | Source |
|--------|----------------|-----------------|--------|
| CPU % | ✅ | ✅ | `top -l 1` / `/proc/stat` |
| GPU % | ✅ | ✅ | `ioreg` / `rocm-smi` |
| VRAM | — | ✅ | `rocm-smi --showmeminfo` |
| RAM % | ✅ | ✅ | `vm_stat` / `/proc/meminfo` |
| RAM used/total | ✅ | ✅ | — |
| Ollama models | ✅ | ✅ | `http://127.0.0.1:11434/api/tags` |
| Ollama loaded | ✅ | ✅ | `http://127.0.0.1:11434/api/ps` |
| Shelly power | ✅ (optional) | ✅ (optional) | Shelly Plug S API |
| Ollama API calls | ✅ | — | Parsed server log (macOS only) |
| SMC temps | ✅ | — | SMC daemon (macOS only) |

## Server Identification

Clients identify themselves via a `server_id` field in the POST payload:

```json
{
  "token": "...",
  "host": "sascha-EVO-X3",
  "server_id": "evo-x3",
  "ts": 1724234567,
  "cpu": 12.4,
  "gpu": 8.0,
  "ram_percent": 31.2,
  ...
}
```

**Backward compatible:** If `server_id` is absent, the server resolves it
from the hostname via the `SERVERS` registry in `config.php`. The original
macOS client (`monitor.py`) does not send `server_id` — it is resolved
automatically from `host: "BigMac"`.