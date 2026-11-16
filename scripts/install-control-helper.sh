#!/usr/bin/env bash
# Optional: erlaubt dem Monitor-Client, den Seiten-Cache freizugeben (einmalig mit sudo).
#   Linux : /usr/local/sbin/fleet-dropcache + sudoers-Regel nur für dieses Skript
#   macOS : sudoers-Regel nur für /usr/sbin/purge
# Ohne diesen Schritt funktioniert alles andere; "Cache freigeben" wird dann übersprungen.
set -euo pipefail
USER_NAME="${SUDO_USER:-$USER}"
RULE=/etc/sudoers.d/fleet-monitor
if [ "$(uname)" = "Darwin" ]; then
  LINE="$USER_NAME ALL=(root) NOPASSWD: /usr/sbin/purge"
else
  HELPER=/usr/local/sbin/fleet-dropcache
  sudo tee "$HELPER" >/dev/null <<'SH'
#!/bin/sh
# Fleet Monitor: Seiten-Cache freigeben (keine Parameter, keine weiteren Rechte)
sync
echo 3 > /proc/sys/vm/drop_caches
SH
  sudo chown root:root "$HELPER"
  sudo chmod 0755 "$HELPER"
  LINE="$USER_NAME ALL=(root) NOPASSWD: $HELPER"
fi
echo "$LINE" | sudo tee "$RULE.tmp" >/dev/null
sudo chmod 0440 "$RULE.tmp"
if sudo visudo -cf "$RULE.tmp" >/dev/null; then
  sudo mv "$RULE.tmp" "$RULE"
  echo "Eingerichtet: $LINE"
else
  sudo rm -f "$RULE.tmp"
  echo "[error] sudoers-Regel ungültig – nichts geändert." >&2
  exit 1
fi
