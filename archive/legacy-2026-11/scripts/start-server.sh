#!/usr/bin/env bash
# Startet den mac-monitor Server. Keine Fremdpakete noetig.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] || { cp .env.example .env; echo "[info] .env aus .env.example erzeugt."; }
PY="${PYTHON:-python3}"
command -v "$PY" >/dev/null || { echo "[error] python3 nicht gefunden."; exit 1; }
"$PY" -c 'import sys; sys.exit(0 if sys.version_info >= (3,9) else 1)' \
  || { echo "[error] Python 3.9+ erforderlich."; exit 1; }
mkdir -p data
exec "$PY" -m server.app "$@"
