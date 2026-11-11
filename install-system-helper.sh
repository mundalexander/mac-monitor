#!/bin/sh
set -eu
[ "$(id -u)" -eq 0 ] || { echo "Mit sudo und Benutzername aufrufen."; exit 1; }
USER_NAME=${1:?Benutzername fehlt}
case "$USER_NAME" in ''|*[!a-zA-Z0-9_-]*) echo "Ungueltiger Benutzername"; exit 1;; esac
id "$USER_NAME" >/dev/null
[ "$USER_NAME" != root ] || exit 1
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
install -o root -g root -m 755 "$HERE/mm-stop-ollama" /usr/local/sbin/mm-stop-ollama
TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT
printf '%s ALL=(root) NOPASSWD: /usr/local/sbin/mm-stop-ollama ""\n' "$USER_NAME" > "$TMP"
visudo -cf "$TMP"
install -o root -g root -m 440 "$TMP" /etc/sudoers.d/mac-monitor-recovery
loginctl enable-linger "$USER_NAME"
echo "Nur systemweites Ollama-Stoppen freigegeben; kein allgemeines sudo. Linger aktiviert."
