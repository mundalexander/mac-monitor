#!/usr/bin/env bash
# Startet den mac-monitor Agenten auf dieser Maschine.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f client/config.json ] || { cp client/config.example.json client/config.json
  echo "[info] client/config.json erzeugt - server_url und machine_id pruefen."; }
PY="${PYTHON:-python3}"
exec "$PY" client/monitor.py --config client/config.json "$@"
