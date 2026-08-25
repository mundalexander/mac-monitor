# Client Setup

## Prerequisites

- macOS (tested on Mac Studio / Mac mini)
- Python 3
- Ollama running locally on port 11434 (optional — still sends CPU/RAM without it)
- Root access for SMC temperature daemon (optional)

## File Overview

| File | Purpose |
|------|---------|
| `monitor.py` | Main script — collects & sends stats every run |
| `run_monitor.sh` | Launches `monitor.py` in a loop (every 10s) |
| `smc_daemon.c` | Root daemon: reads SMC sensor data via AppleSMC |
| `smc_helper.c` | Helper tool for `smc_daemon` (needs sudo) |
| `install_smc_daemon.sh` | Installs `smc_daemon` as a LaunchDaemon |
| `install_smc_helper.sh` | Installs `smc_helper` (sudoers rule) |

## Setup

### 1. Configure tokens

Edit `monitor.py` — set your server URL and token:

```python
SERVER_URL = "https://your-host.com/assistant/mac-monitor/submit.php"
REQUESTS_URL = "https://your-host.com/assistant/mac-monitor/requests.php"
API_TOKEN = "your-secret-token"
```

The token **must match** the `SECRET_TOKEN` in the server's `config.php`.

### 2. (Optional) Build SMC tools

```bash
# Compile
gcc -o smc_helper smc_helper.c   -framework IOKit -framework CoreFoundation
gcc -o smc_daemon  smc_daemon.c  -framework IOKit -framework CoreFoundation

# Install helper + sudoers rule
bash install_smc_helper.sh

# Install daemon as LaunchDaemon (auto-starts on boot)
bash install_smc_daemon.sh
```

### 3. Start the monitor

**Manual test:**
```bash
python3 monitor.py
# Check monitor.log for results
```

**Automated (launchd — recommended):**
```bash
# Start the loop script as a LaunchAgent
# (Create ~/Library/LaunchAgents/com.openclaw.mac-monitor.plist)
# Then:
launchctl load ~/Library/LaunchAgents/com.openclaw.mac-monitor.plist
```

**Or just run the loop directly:**
```bash
bash run_monitor.sh   # runs in background, loop every 10s
```

## What gets sent

Every 10 seconds, `monitor.py` POSTs to `submit.php`:

```json
{
  "token": "...",
  "host": "BigMac",
  "ts": 1724234567,
  "cpu": 12.4,
  "gpu": 8.0,
  "ram_percent": 31.2,
  "ram_used_gb": 13.9,
  "ram_total_gb": 44.51,
  "ollama": {
    "loaded": [{"name": "qwen3.8:27b-mlx-64k", "size_vram_gb": 16.9}],
    "available": [{"name": "qwen3.8:27b-mlx", "size_gb": 16.9}, ...]
  },
  "shelly_power": 35.7
}
```

## Ollama API Log Parsing

`monitor.py` also parses `~/.ollama/logs/server.log` for API request records
and sends them to `requests.php`. The dashboard shows these grouped by caller IP
(Bernd, Dorian, Sascha — configurable in `index.php`).

## Logs

```
monitor.log        — successful runs
monitor.err.log   — errors
```

## Adding a new host

### Evo-X3 (Ubuntu 24.04, Linux client)

The Linux client (`monitor_linux.py`) monitors CPU, RAM, GPU (via `rocm-smi`),
and Ollama models on Evo-X3.

**Setup:**

```bash
# 1. Copy files to Evo-X3
scp monitor_linux.py run_monitor_linux.sh evo-x3:~/mac-monitor/

# 2. Edit tokens in monitor_linux.py
SERVER_URL = "https://your-host.com/mac-monitor/submit.php"
API_TOKEN  = "your-secret-token"   # must match config.php

# 3. Test manually
ssh evo-x3 'python3 ~/mac-monitor/monitor_linux.py'

# 4. Install as systemd user service
ssh evo-x3 'mkdir -p ~/.config/systemd/user && \
  cat > ~/.config/systemd/user/mac-monitor-linux.service << "EOF"
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
systemctl --user enable --now mac-monitor-linux.service'
```

**What the Linux client sends:**

```json
{
  "token": "...",
  "host": "sascha-EVO-X3",
  "server_id": "evo-x3",
  "ts": 1724234567,
  "cpu": 15.2,
  "gpu": 42.0,
  "ram_percent": 28.5,
  "ram_used_gb": 27.3,
  "ram_total_gb": 96.0,
  "ollama": {
    "loaded": [{"name": "qwen3.6:35b", "size_vram_gb": 22.1}],
    "available": [{"name": "qwen3.6:35b", "size_gb": 22.1}, ...]
  },
  "shelly_power": null
}
```

**GPU monitoring** uses `rocm-smi --showuse --showmeminfo vram --json`.
The client auto-detects the `rocm-smi` binary in standard paths.

### Other hosts

1. Install `monitor.py` (macOS) or `monitor_linux.py` (Linux) on the new host
2. Set a unique `hostname` and `server_id` in the client config
3. Add the server to `SERVERS` in `server/config.php`
4. Set `ALLOWED_HOSTS` to `null` (or add the hostname to the list)
5. The dashboard will show the new server automatically
