#!/bin/sh
set -eu
[ "$(id -u)" -ne 0 ] || { echo "Als normaler Podman-Benutzer starten, nicht mit sudo."; exit 1; }
HERE=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DEST="$HOME/.local/share/mac-monitor-recovery"
CFG="$HOME/.config/mac-monitor-recovery"
mkdir -p "$DEST" "$CFG" "$HOME/.config/systemd/user"
chmod 700 "$CFG"
cp "$HERE/client/recovery.py" "$DEST/recovery.py"
if [ ! -f "$CFG/config.json" ]; then
 cp "$HERE/client/config.example.json" "$CFG/config.json"
 chmod 600 "$CFG/config.json"
fi
PYTHON=$(command -v python3)
cat > "$HOME/.config/systemd/user/mac-monitor-recovery.service" <<EOF
[Unit]
Description=Mac Monitor Halogen Recovery
After=network-online.target
[Service]
Type=simple
ExecStart="$PYTHON" "$DEST/recovery.py" --config "$CFG/config.json"
Restart=always
RestartSec=15
TimeoutStopSec=15
KillMode=process
UMask=0077
[Install]
WantedBy=default.target
EOF
systemctl --user daemon-reload
echo "Installiert, noch NICHT gestartet. Zuerst config.json bearbeiten und INSTALL.md abarbeiten."
echo "Config: $CFG/config.json"
