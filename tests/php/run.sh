#!/usr/bin/env bash
# Ende-zu-Ende-Test des PHP-Servers mit echtem PHP + SQLite (php -S).
# Voraussetzung: php >= 8.1 mit pdo_sqlite, curl, python3.
#   bash tests/php/run.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${MM_TEST_PORT:-8899}"
BASE="http://127.0.0.1:$PORT"
PIN="482913"
TMP="$(mktemp -d)"
trap 'kill $SRV 2>/dev/null; rm -rf "$TMP"' EXIT

cp -R "$ROOT/server" "$TMP/srv"
{ echo "<?php"; php "$TMP/srv/tools/gen-pin.php" "$PIN" | grep "^define"; } > "$TMP/srv/secrets.php"
php -S "127.0.0.1:$PORT" -t "$TMP/srv" > "$TMP/php.log" 2>&1 &
SRV=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE/pair.php" && break; sleep 0.1; done

pass=0; fail=0
check() { # name expected actual
  if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "  [ok]   $1"
  else fail=$((fail+1)); echo "  [FAIL] $1 (erwartet $2, erhalten $3)"; fi
}
code() { curl -s -o "$TMP/out" -w '%{http_code}' "$@"; }
J='Content-Type: application/json'
JAR="$TMP/jar"

echo "1. Ohne Anmeldung gesperrt"
check "index.php zeigt PIN-Seite (401)" 401 "$(code "$BASE/index.php")"
grep -q 'id="fm-pin"' "$TMP/out" && check "PIN-Feld vorhanden" 1 1 || check "PIN-Feld vorhanden" 1 0
check "data.php ohne Anmeldung" 401 "$(code "$BASE/data.php")"
check "submit.php ohne Gerät" 401 "$(code -X POST -H "$J" -d '{"host":"x","ts":1790000000,"cpu":1,"gpu":1,"ram_percent":1}' "$BASE/submit.php")"
check "unload-request.php ohne Anmeldung" 401 "$(code -X POST -H "$J" -d '{"action":"ollama_unload","server_id":"evo-x3"}' "$BASE/unload-request.php")"
check "Token-Login abgeschafft" 401 "$(code -X POST -H "$J" -d '{"dashboard_token":"irgendwas"}' "$BASE/auth-api.php?login=1")"

echo "2. Browser: PIN + Gerät merken"
check "falsche PIN" 401 "$(code -X POST -H "$J" -d '{"pin":"111111"}' "$BASE/auth-api.php?login=1")"
check "richtige PIN" 200 "$(code -c "$JAR" -b "$JAR" -X POST -H "$J" -d "{\"pin\":\"$PIN\",\"remember\":1}" "$BASE/auth-api.php?login=1")"
REM="$(awk '$6=="mm_device"{print $7}' "$JAR")"
check "Geräte-Cookie gesetzt" 1 "$([ -n "$REM" ] && echo 1 || echo 0)"
check "Dashboard mit Session" 200 "$(code -b "$JAR" "$BASE/index.php")"
check "neuer Browser-Start nur mit Geräte-Cookie" 200 "$(code -H "Cookie: mm_device=$REM" "$BASE/index.php")"
check "data.php mit Geräte-Cookie" 200 "$(code -H "Cookie: mm_device=$REM" "$BASE/data.php")"

echo "2b. Gast-Freigaben"
JAR2="$TMP/jar2"
check "Freigabe-Seite ohne Login gesperrt" 401 "$(code "$BASE/freigaben.php")"
check "Freigabe-Seite mit Session" 200 "$(code -b "$JAR" "$BASE/freigaben.php")"
check "Freigabe erzeugen" 200 "$(code -b "$JAR" -X POST -H "$J" -d '{"label":"Test-Gast"}' "$BASE/freigaben.php?create=1")"
FPIN="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["pin"])' "$TMP/out")"
check "Freigabe-PIN 8-stellig" 1 "$(printf '%s' "$FPIN" | grep -cE '^[0-9]{8}$')"
code -b "$JAR" "$BASE/freigaben.php?list=1" >/dev/null
grep -q 'Test-Gast' "$TMP/out" && check "Liste zeigt Freigabe" 1 1 || check "Liste zeigt Freigabe" 1 0
grep -q 'pin_hash' "$TMP/out" && check "Liste ohne Pin-Hash" 1 0 || check "Liste ohne Pin-Hash" 1 1
check "Login mit Freigabe-PIN" 200 "$(code -c "$JAR2" -X POST -H "$J" -d "{\"pin\":\"$FPIN\"}" "$BASE/auth-api.php?login=1")"
grep -q '"method":"freigabe' "$TMP/out" && check "Session-Methode freigabe" 1 1 || check "Session-Methode freigabe" 1 0
check "Dashboard mit Gast-Session" 200 "$(code -b "$JAR2" "$BASE/index.php")"
check "Gast darf keine Freigabe erzeugen" 403 "$(code -b "$JAR2" -X POST -H "$J" -d '{}' "$BASE/freigaben.php?create=1")"
check "slot-api Login mit Freigabe-PIN" 200 "$(code -X POST -H "$J" -d "{\"pin\":\"$FPIN\"}" "$BASE/slot-api.php?login=1")"
check "Falsche Gast-PIN abgewiesen" 401 "$(code -X POST -H "$J" -d '{"pin":"12345678"}' "$BASE/auth-api.php?login=1")"
FID="$(code -b "$JAR" "$BASE/freigaben.php?list=1" >/dev/null; python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["freigaben"][0]["id"])' "$TMP/out")"
check "Freigabe widerrufen" 200 "$(code -b "$JAR" -X POST -H "$J" -d "{\"id\":\"$FID\"}" "$BASE/freigaben.php?revoke=1")"
check "Widerrufene PIN abgewiesen" 401 "$(code -X POST -H "$J" -d "{\"pin\":\"$FPIN\"}" "$BASE/auth-api.php?login=1")"

echo "3. Maschine koppeln"
check "Kopplung falsche PIN" 401 "$(code -X POST -H "$J" -d '{"pin":"000000","machine_id":"evo-x3"}' "$BASE/pair.php")"
check "Kopplung evo-x3" 200 "$(code -X POST -H "$J" -d "{\"pin\":\"$PIN\",\"machine_id\":\"evo-x3\",\"name\":\"Evo X3\"}" "$BASE/pair.php")"
KEY="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["device_key"])' "$TMP/out")"
check "Kopplung mini-pc" 200 "$(code -X POST -H "$J" -d "{\"pin\":\"$PIN\",\"machine_id\":\"mini-pc\"}" "$BASE/pair.php")"
KEY2="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["device_key"])' "$TMP/out")"
D1=(-H "X-Fleet-Device: evo-x3" -H "X-Fleet-Key: $KEY")
D2=(-H "X-Fleet-Device: mini-pc" -H "X-Fleet-Key: $KEY2")
NOW=$(date +%s)
check "submit mit Geräteschlüssel" 200 "$(code -X POST -H "$J" "${D1[@]}" -d "{\"host\":\"sascha-EVO-X3\",\"server_id\":\"mac\",\"ts\":$NOW,\"cpu\":12,\"gpu\":30,\"ram_percent\":40}" "$BASE/submit.php")"
grep -q '"server_id":"evo-x3"' "$TMP/out" && check "server_id aus Kopplung (nicht aus Payload)" 1 1 || check "server_id aus Kopplung" 1 0
check "submit mit falschem Schlüssel" 401 "$(code -X POST -H "$J" -H "X-Fleet-Device: evo-x3" -H "X-Fleet-Key: $(printf '0%.0s' $(seq 64))" -d "{\"host\":\"x\",\"ts\":$NOW,\"cpu\":1,\"gpu\":1,\"ram_percent\":1}" "$BASE/submit.php")"
check "requests.php" 200 "$(code -X POST -H "$J" "${D1[@]}" -d "{\"calls\":[{\"ts\":$NOW,\"ip\":\"jarvis\",\"method\":\"POST\",\"endpoint\":\"/v1/chat/completions\",\"duration_ms\":420,\"status\":200}]}" "$BASE/requests.php")"
check "data.php per Geräteschlüssel" 200 "$(code "${D1[@]}" "$BASE/data.php?range=1h")"
grep -q 'evo-x3' "$TMP/out" && check "Messwert im Dashboard-Feed" 1 1 || check "Messwert im Dashboard-Feed" 1 0
check "ingest.php generische Metrik" 200 "$(code -X POST -H "$J" "${D2[@]}" -d '{"metrics":{"cpu":5,"batterie":80}}' "$BASE/ingest.php")"

echo "4. Steuerbefehle"
check "Befehl aus Browser" 200 "$(code -b "$JAR" -X POST -H "$J" -d '{"action":"ollama_unload","server_id":"evo-x3"}' "$BASE/unload-request.php")"
CMD="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["id"])' "$TMP/out")"
check "evo-x3 sieht Befehl" 200 "$(code "${D1[@]}" "$BASE/commands.php")"
grep -q "$CMD" "$TMP/out" && check "Befehl enthalten" 1 1 || check "Befehl enthalten" 1 0
code "${D2[@]}" "$BASE/commands.php" >/dev/null
grep -q "$CMD" "$TMP/out" && check "mini-pc sieht fremden Befehl NICHT" 0 1 || check "mini-pc sieht fremden Befehl NICHT" 0 0
check "fremdes Gerät kann nicht quittieren" 404 "$(code -X POST -H "$J" "${D2[@]}" -d "{\"id\":\"$CMD\",\"done\":true}" "$BASE/commands.php")"
check "evo-x3 quittiert" 200 "$(code -X POST -H "$J" "${D1[@]}" -d "{\"id\":\"$CMD\",\"done\":true,\"success\":true}" "$BASE/commands.php")"
check "Gerät darf keine Befehle anlegen" 401 "$(code -X POST -H "$J" "${D1[@]}" -d '{"action":"halogen_stop","server_id":"evo-x3"}' "$BASE/commands.php")"

echo "5. Board, Slots, Autonomie"
check "board-api per Geräteschlüssel (Jarvis)" 200 "$(code "${D1[@]}" "$BASE/board-api.php")"
check "autonomy-api per Session" 200 "$(code -b "$JAR" "$BASE/autonomy-api.php")"
S=$((NOW + 3600)); E=$((NOW + 5400))
check "Slot buchen" 200 "$(code -b "$JAR" -X POST -H "$J" -d "{\"machine\":\"evo-x3\",\"start_unix\":$S,\"end_unix\":$E,\"owner\":\"sascha\",\"task\":\"Test\"}" "$BASE/slot-api.php")"
check "Doppelbuchung abgewiesen" 409 "$(code -b "$JAR" -X POST -H "$J" -d "{\"machine\":\"evo-x3\",\"start_unix\":$((S+600)),\"end_unix\":$((E+600)),\"owner\":\"jarvis\"}" "$BASE/slot-api.php")"
check "Slot-Liste" 200 "$(code -b "$JAR" "$BASE/slot-api.php?from=$NOW&to=$((NOW+86400))")"

echo "6. Geräteverwaltung und Widerruf"
check "Geräteliste" 200 "$(code -b "$JAR" "$BASE/devices-api.php")"
check "Geräteliste nicht per Geräteschlüssel" 401 "$(code "${D1[@]}" "$BASE/devices-api.php")"
check "mini-pc widerrufen" 200 "$(code -b "$JAR" -X POST -H "$J" -d '{"action":"revoke_machine","id":"mini-pc"}' "$BASE/devices-api.php")"
check "widerrufenes Gerät abgewiesen" 401 "$(code -X POST -H "$J" "${D2[@]}" -d '{"metrics":{"cpu":5}}' "$BASE/ingest.php")"
check "Abmelden" 200 "$(code -b "$JAR" -c "$JAR" -X POST "$BASE/auth-api.php?logout=1")"
check "altes Geräte-Cookie nach Abmelden ungültig" 401 "$(code -H "Cookie: mm_device=$REM" "$BASE/index.php")"

echo "7. Brute-Force-Bremse"
for _ in $(seq 1 8); do code -X POST -H "$J" -d '{"pin":"000000","machine_id":"x1"}' "$BASE/pair.php" >/dev/null; done
check "Sperre nach Fehlversuchen" 429 "$(code -X POST -H "$J" -d "{\"pin\":\"$PIN\",\"machine_id\":\"x1\"}" "$BASE/pair.php")"

echo "8. Python-Client gegen echten Server"
sqlite3 "$TMP/srv/metrics.sqlite" "DELETE FROM auth_attempts" 2>/dev/null || \
  php -r "\$p=new PDO('sqlite:$TMP/srv/metrics.sqlite');\$p->exec('DELETE FROM auth_attempts');"
CFG="$TMP/client.json"
echo "{\"server_url\":\"$BASE\",\"credential_path\":\"$TMP/dev.json\",\"spool_path\":\"$TMP/spool.jsonl\",\"timeout_seconds\":5}" > "$CFG"
MM_PAIR_PIN="$PIN" python3 "$ROOT/client/monitor.py" --config "$CFG" --pair --machine-id mac >/dev/null
check "Client koppelt per PIN" 0 $?
python3 "$ROOT/client/monitor.py" --config "$CFG" --status >/dev/null
check "Client-Status ok" 0 $?
python3 "$ROOT/client/monitor.py" --config "$CFG" --once > "$TMP/client.log" 2>&1
grep -q "\[error\]" "$TMP/client.log" && check "Client sendet ohne Fehler" 0 1 || check "Client sendet ohne Fehler" 0 0

echo "9. Kommandozentrale und Kapazität (1.3)"
JAR3="$TMP/jar3"
code -c "$JAR3" -b "$JAR3" -X POST -H "$J" -d "{\"pin\":\"$PIN\"}" "$BASE/auth-api.php?login=1" >/dev/null
NOW=$(date +%s)
CTL='{"phase":"idle","busy":false,"ram_available_gb":80,"ram_total_gb":122,"profiles":[{"id":"flash-next","label":"Flash","engine":"halogen","port":8731,"running":true,"ram_gb":40,"endpoint":"http://evo-x3.local:8731/v1"},{"id":"27b","label":"27B","engine":"halogen","port":8731,"running":false,"ram_gb":60}],"agents":[{"id":"openclaw","label":"OpenClaw","running":true,"can_restart":true}],"events":[]}'
check "submit mit Steuerzustand" 200 "$(code -X POST -H "$J" "${D1[@]}" -d "{\"host\":\"sascha-EVO-X3\",\"ts\":$NOW,\"cpu\":5,\"gpu\":5,\"ram_percent\":40,\"control\":$CTL}" "$BASE/submit.php")"
check "control-api GET" 200 "$(code -b "$JAR3" "$BASE/control-api.php")"
grep -q '"flash-next"' "$TMP/out" && check "Profile im Zustand" 1 1 || check "Profile im Zustand" 1 0
check "unbekanntes Profil abgewiesen" 400 "$(code -b "$JAR3" -X POST -H "$J" -d '{"machine":"evo-x3","action":"profile","profile":"rm -rf"}' "$BASE/control-api.php")"
check "Profilwechsel beauftragt" 200 "$(code -b "$JAR3" -X POST -H "$J" -d '{"machine":"evo-x3","action":"profile","profile":"27b"}' "$BASE/control-api.php")"
check "zweiter Auftrag gesperrt" 409 "$(code -b "$JAR3" -X POST -H "$J" -d '{"machine":"evo-x3","action":"free"}' "$BASE/control-api.php")"
code "${D1[@]}" "$BASE/commands.php" >/dev/null
grep -q '"control_profile"' "$TMP/out" && check "Gerät erhält Auftrag" 1 1 || check "Gerät erhält Auftrag" 1 0
grep -q '"age_s"' "$TMP/out" && check "Auftrag mit Alter" 1 1 || check "Auftrag mit Alter" 1 0
CID="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["commands"][0]["id"])' "$TMP/out")"
check "Auftrag quittiert" 200 "$(code -X POST -H "$J" "${D1[@]}" -d "{\"id\":\"$CID\",\"done\":true,\"success\":true,\"note\":\"27b bereit\"}" "$BASE/commands.php")"
check "Gerät darf keinen Agent-Neustart" 403 "$(code -X POST -H "$J" "${D1[@]}" -d '{"machine":"evo-x3","action":"agent_restart","agent":"openclaw"}' "$BASE/control-api.php")"
check "Gerät darf Profil starten" 200 "$(code -X POST -H "$J" "${D1[@]}" -d '{"machine":"evo-x3","action":"profile","profile":"flash-next"}' "$BASE/control-api.php")"
check "capacity.php" 200 "$(code -b "$JAR3" "$BASE/capacity.php")"
grep -q '"status":"free"' "$TMP/out" && check "laufendes Profil ist frei" 1 1 || check "laufendes Profil ist frei" 1 0
grep -q '"recommended":{' "$TMP/out" && check "Empfehlung vorhanden" 1 1 || check "Empfehlung vorhanden" 1 0
check "capacity ohne Anmeldung gesperrt" 401 "$(code "$BASE/capacity.php")"

if grep -iE "fatal|parse error|warning" "$TMP/php.log" | grep -viE "JIT (is incompatible|disabled)" | grep -q .; then
  echo "  PHP-Log enthält Warnungen/Fehler:"; grep -iE "fatal|parse error|warning" "$TMP/php.log" | grep -viE "JIT (is incompatible|disabled)" | head -20; fail=$((fail+1))
fi
echo
echo "Ergebnis: $pass bestanden, $fail fehlgeschlagen"
[ "$fail" -eq 0 ]
