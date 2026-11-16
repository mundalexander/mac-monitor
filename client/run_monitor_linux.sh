#!/bin/bash
# run_monitor_linux.sh — Fleet Monitor Client-Start (seit 1.2.0)
# monitor.py loop selbst (interval_seconds in config.json); kein externer Loop mehr.
# Systemd-Unit: ~/.config/systemd/user/mac-monitor-linux.service
cd "$(dirname "$0")" || exit 1
exec python3 monitor.py --config config.json
