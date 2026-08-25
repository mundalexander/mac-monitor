#!/bin/bash
# run_monitor_linux.sh — Loop wrapper for monitor_linux.py
# Runs the monitor every 10 seconds. For systemd service, see the unit file below.
#
# Systemd unit file (save as ~/.config/systemd/user/mac-monitor-linux.service):
# ──────────────────────────────────────────────────────────────────────────
# [Unit]
# Description=Mac Monitor Linux Client (Evo-X3)
# After=network-online.target
# Wants=network-online.target
#
# [Service]
# Type=simple
# ExecStart=/home/sascha/mac-monitor/run_monitor_linux.sh
# Restart=on-failure
# RestartSec=30
# StandardOutput=journal
# StandardError=journal
#
# [Install]
# WantedBy=default.target
# ──────────────────────────────────────────────────────────────────────────
#
# Install:
#   mkdir -p ~/.config/systemd/user
#   cp ~/.config/systemd/user/mac-monitor-linux.service ...
#   systemctl --user daemon-reload
#   systemctl --user enable --now mac-monitor-linux.service
#
# Or as a system service (runs as root, can access all GPU sensors):
#   sudo cp mac-monitor-linux.service /etc/systemd/system/
#   sudo systemctl daemon-reload
#   sudo systemctl enable --now mac-monitor-linux.service

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MONITOR="${SCRIPT_DIR}/monitor_linux.py"

INTERVAL=10  # seconds between samples

while true; do
    python3 "$MONITOR" 2>&1 || true
    sleep "$INTERVAL"
done