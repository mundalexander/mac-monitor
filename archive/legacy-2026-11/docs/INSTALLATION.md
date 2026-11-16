# Installation

Voraussetzung: **Python 3.9 oder neuer**. Keine Fremdpakete, kein Docker,
kein Build-Schritt. Funktioniert in Umgebungen ohne Administratorrechte.

---

## 1. Server (eine Maschine im Netz)

```bash
unzip mac-monitor-production.zip
cd mac-monitor-production
./scripts/start-server.sh
```

Beim ersten Start entsteht `.env` aus `.env.example` und die SQLite-Datenbank
unter `data/`. Dashboard danach: `http://127.0.0.1:8770/`

Soll der Server von anderen Rechnern erreichbar sein, in `.env` setzen:

```env
MM_HOST=0.0.0.0
MM_PUBLIC_URL=http://<hostname-oder-ip>:8770
```

`MM_PUBLIC_URL` ist reine Anzeige- und Dokumentationsadresse. Das Dashboard
löst seine API-Aufrufe immer relativ auf und funktioniert deshalb auch hinter
Reverse Proxys und in Unterverzeichnissen.

---

## 2. Agent (auf jeder Maschine)

```bash
cp client/config.example.json client/config.json
```

Anpassen:

```json
{
  "server_url": "http://<server>:8770",
  "api_token": "mac-monitor-test-token",
  "machine_id": "evo-x3",
  "machine_name": "Evo X3 (Ryzen AI Max, Radeon 8060S)",
  "ollama_url": "http://127.0.0.1:11434",
  "backend_url": "http://evo-x3.local:11434",
  "max_slots": 3
}
```

| Feld | Bedeutung |
|---|---|
| `machine_id` | stabile Kennung, **nicht** der wechselnde Hostname |
| `ollama_url` | wie der Agent Ollama lokal erreicht |
| `backend_url` | wie **andere** Rechner diese Ollama-Instanz erreichen |
| `max_slots` | erlaubte parallele Jobs |

`backend_url` ist der Wert, den ein Subagent später als `OLLAMA_BASE_URL`
bekommt. Bei `127.0.0.1` könnte nur diese Maschine selbst den Slot nutzen.

Starten:

```bash
./scripts/start-client.sh
```

Testlauf ohne Server:

```bash
python3 client/monitor.py --once --dry-run
```

---

## 3. Dauerbetrieb

### Linux (systemd, ohne Root)

```bash
./scripts/install-linux.sh
journalctl --user -u mac-monitor-agent -f
```

Das Skript setzt `loginctl enable-linger`, damit der Dienst auch ohne aktive
Sitzung läuft. Ohne diesen Schritt stoppt er beim Abmelden.

Soll auch der Server als Dienst laufen:

```bash
sed "s|__ROOT__|$(pwd)|g; s|__PYTHON__|$(command -v python3)|g" \
  scripts/systemd/mac-monitor-server.service \
  > ~/.config/systemd/user/mac-monitor-server.service
systemctl --user enable --now mac-monitor-server
```

### macOS (launchd, ohne Root)

```bash
./scripts/install-macos.sh
tail -f logs/agent.log
```

Es wird **kein** Root-Daemon installiert. Temperaturen kommen nur aus
unprivilegierten Quellen; fehlen sie, bleibt das Feld leer und alles andere
läuft normal weiter.

### Entfernen

```bash
./scripts/uninstall.sh
```

---

## 4. Prüfen

```bash
python3 scripts/doctor.py
```

Der Doctor zeigt, welche Quellen verfügbar sind — CPU, RAM, GPU, Ollama,
Temperatur, Serververbindung — und meldet fehlende optionale Werkzeuge als
Hinweis, nicht als Fehler.

Vollständiger Systemtest:

```bash
python3 tests/test_end_to_end.py
```

Erwartet: `59 bestanden, 0 fehlgeschlagen`.

---

## 5. Agenten anbinden

Ein Subagent wird so auf der passenden Maschine gestartet:

```bash
eval "$(python3 integrations/openclaw/pick_machine.py \
        --model qwen2.5-coder:14b --min-ram 18 --env)"

echo "$OLLAMA_BASE_URL"    # Ziel-Backend
echo "$MM_RESERVATION_ID"  # Slot, der gehalten werden muss
```

Slot während der Laufzeit halten und danach freigeben:

```bash
python3 integrations/openclaw/pick_machine.py --hold "$MM_RESERVATION_ID" &
HOLD=$!
# ... Arbeit ...
kill $HOLD
python3 integrations/openclaw/pick_machine.py --release "$MM_RESERVATION_ID"
```

Im Python-Code übernimmt der Kontextmanager aus `integrations/agent_client.py`
das vollständig, inklusive Heartbeat und TPS-Meldung.

---

## 6. GPU-Quellen

| Plattform | Werkzeug | Ergebnis |
|---|---|---|
| AMD / ROCm | `rocm-smi` | Auslastung und VRAM |
| NVIDIA | `nvidia-smi` | Auslastung, VRAM, Temperatur, Leistung |
| Apple Silicon | `ioreg` | Auslastung, Unified Memory |
| keins vorhanden | – | GPU bleibt 0, RAM-Werte zählen weiter |

Fehlt ein Werkzeug, läuft der Agent unverändert weiter. Ohne eigene
VRAM-Meldung nutzt der Agent ersatzweise die von Ollama gemeldete
Modellbelegung.

---

## 7. Betrieb und Wartung

Täglicher Cronjob zum Aufräumen:

```cron
0 4 * * * curl -s -X POST -H "X-API-Token: mac-monitor-test-token" \
          http://127.0.0.1:8770/api/v1/maintenance > /dev/null
```

Sicherung der Datenbank im laufenden Betrieb:

```bash
sqlite3 data/mac-monitor.sqlite3 ".backup 'backup-$(date +%F).sqlite3'"
```

Bei 10 Sekunden Intervall entstehen je Maschine rund 8.640 Datensätze pro Tag.
Mit der Standard-Retention von 14 Tagen bleibt die Datenbank bei wenigen
Maschinen im einstelligen Megabytebereich.

---

## 8. Problembehebung

| Symptom | Ursache und Lösung |
|---|---|
| Dashboard zeigt "keine Verbindung" | Server läuft nicht oder Port belegt: `MM_PORT` in `.env` ändern |
| Maschine bleibt offline | Agent läuft nicht, oder `server_url` falsch; `scripts/doctor.py` prüfen |
| `HTTP 401` im Agentenlog | `api_token` im Client stimmt nicht mit `MM_API_TOKEN` überein |
| TPS bleibt 0 | normal, solange nichts generiert wird; echte Werte entstehen erst, wenn ein Agent Messungen an `/api/v1/tps` meldet |
| Reservierung liefert 503 | keine Maschine erfüllt die Mindestanforderung; `details` im Antwort-JSON nennt den Grund je Maschine |
| Slot verfällt zu früh | Heartbeat fehlt; mindestens alle 30 s senden oder das SDK nutzen |
| systemd-Dienst stoppt beim Abmelden | `loginctl enable-linger $USER` ausführen |
