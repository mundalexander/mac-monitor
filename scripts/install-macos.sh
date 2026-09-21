#!/usr/bin/env bash
# Installiert den Agenten als launchd LaunchAgent (ohne Root, kein SMC-Daemon).
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
[ -f client/config.json ] || cp client/config.example.json client/config.json
PLIST="$HOME/Library/LaunchAgents/de.macmonitor.agent.plist"
mkdir -p "$HOME/Library/LaunchAgents" "$ROOT/logs"
sed "s|__ROOT__|$ROOT|g; s|__PYTHON__|$(command -v python3)|g" \
  scripts/launchd/de.macmonitor.agent.plist > "$PLIST"
launchctl unload "$PLIST" 2>/dev/null || true
launchctl load "$PLIST"
echo "Agent geladen. Logs: tail -f $ROOT/logs/agent.log"
