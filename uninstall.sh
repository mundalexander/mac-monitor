#!/usr/bin/env bash
set -euo pipefail
if [ "$(uname)" = "Darwin" ]; then
  P="$HOME/Library/LaunchAgents/de.macmonitor.agent.plist"
  launchctl unload "$P" 2>/dev/null || true; rm -f "$P"
else
  systemctl --user disable --now mac-monitor-agent.service 2>/dev/null || true
  rm -f "$HOME/.config/systemd/user/mac-monitor-agent.service"
  systemctl --user daemon-reload 2>/dev/null || true
fi
echo "Agent entfernt. Daten unter data/ bleiben erhalten."
