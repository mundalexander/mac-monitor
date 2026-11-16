#!/usr/bin/env bash
# Installiert den Agenten als launchd LaunchAgent (ohne Root, kein SMC-Daemon).
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
[ -f client/config.json ] || cp client/config.example.json client/config.json

# Einmalige Kopplung per PIN (falls noch kein Geraeteschluessel vorhanden)
if ! python3 client/monitor.py --config client/config.json --status >/dev/null 2>&1; then
  echo "Dieses Geraet ist noch nicht gekoppelt."
  read -r -p "Maschinen-ID (z. B. evo-x3, mac, mini-pc): " MID
  python3 client/monitor.py --config client/config.json --pair --machine-id "$MID" || {
    echo "[error] Kopplung fehlgeschlagen - Installation abgebrochen."; exit 1; }
fi
PLIST="$HOME/Library/LaunchAgents/de.macmonitor.agent.plist"
mkdir -p "$HOME/Library/LaunchAgents" "$ROOT/logs"
sed "s|__ROOT__|$ROOT|g; s|__PYTHON__|$(command -v python3)|g" \
  scripts/launchd/de.macmonitor.agent.plist > "$PLIST"
launchctl unload "$PLIST" 2>/dev/null || true
launchctl load "$PLIST"
echo "Agent geladen. Logs: tail -f $ROOT/logs/agent.log"
