#!/usr/bin/env bash
# Server + lokaler Agent in einem Befehl (Einzelrechner-Betrieb).
set -euo pipefail
cd "$(dirname "$0")/.."
./scripts/start-server.sh & SRV=$!
trap 'kill $SRV 2>/dev/null || true' EXIT INT TERM
sleep 2
./scripts/start-client.sh
