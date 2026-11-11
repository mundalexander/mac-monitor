#!/usr/bin/env bash
# Installiert den Agenten als systemd --user Service (ohne Root).
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
[ -f client/config.json ] || cp client/config.example.json client/config.json
mkdir -p "$HOME/.config/systemd/user"
sed "s|__ROOT__|$ROOT|g; s|__PYTHON__|$(command -v python3)|g" \
  scripts/systemd/mac-monitor-agent.service > "$HOME/.config/systemd/user/mac-monitor-agent.service"
systemctl --user daemon-reload
systemctl --user enable --now mac-monitor-agent.service
# Ohne 'linger' stoppt der Dienst beim Logout.
loginctl enable-linger "$USER" 2>/dev/null || echo "[warn] linger nicht setzbar - Dienst laeuft nur bei aktiver Sitzung."
systemctl --user --no-pager status mac-monitor-agent.service || true
echo "Logs: journalctl --user -u mac-monitor-agent -f"
