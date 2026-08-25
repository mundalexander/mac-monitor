# Mac Monitor — Installations-Anleitung für Bernd

Diese Anleitung beschreibt, wie du den Mac-Monitor-Client auf deinem Mac (BigMac) installierst, damit deine Systemdaten im Dashboard unter [https://mund.bplaced.net/mac-monitor/](https://mund.bplaced.net/mac-monitor/) erscheinen.

---

## Was macht der Client?

Das Skript `monitor.py` sammelt alle 10 Sekunden folgende Daten von deinem Mac:

- **CPU-Auslastung** (in %)
- **RAM-Verbrauch** (in % und GB)
- **GPU-Auslastung** (via `ioreg`, kein sudo nötig)
- **Geladene KI-Modelle** von:
  - **Ollama** (Port 11434)
  - **LM Studio** (Port 1234)
  - **llama.cpp** (Port 8080, falls installiert)
- **Shelly Plus Plug S** (Stromverbrauch, falls vorhanden unter 192.168.178.73)

Die Daten werden an den Server auf bplaced gesendet und im Dashboard angezeigt.

---

## Voraussetzungen

- macOS (getestet auf macOS 14+)
- Python 3 (ist auf dem Mac vorinstalliert — prüfe mit `python3 --version`)
- Internetverbindung

---

## Schritt 1: Dateien herunterladen

Lade den Ordner `client/` aus diesem Projekt auf deinen Mac. Am einfachsten:

```bash
# In deinem Home-Verzeichnis:
mkdir -p ~/.openclaw/workspace/projects/mac-monitor/client
cd ~/.openclaw/workspace/projects/mac-monitor/client
```

Kopiere folgende Dateien dorthin:
- `monitor.py`
- `run_monitor.sh`

Du kannst die Dateien von GitHub oder vom bplaced-Server laden (frage Sascha nach dem Link).

---

## Schritt 2: Pfade anpassen

Die Skripte verwenden folgende Pfade (sind bereits korrekt für `/Users/bernd/`):

| Datei | Pfad |
|-------|------|
| `monitor.py` | `/Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor.py` |
| Log-Datei | `/Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor.log` |
| Fehler-Log | `/Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor.err.log` |
| Status-Datei | `/Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor_state.json` |

Falls du ein anderes Verzeichnis verwenden willst, passe die Pfade `LOG_FILE`, `ERROR_LOG`, `STATE_FILE` und `OLLAMA_LOG` oben in `monitor.py` an.

---

## Schritt 3: API-Token

Der API-Token steht bereits in der `monitor.py`:

```
API_TOKEN = "YOUR_API_TOKEN_HERE"
```

Du musst nichts ändern. Falls Sascha den Token mal rotiert, bekommst du einen neuen.

---

## Schritt 4: Ollama installieren (optional, aber empfohlen)

Falls du Ollama nutzt (für lokale KI-Modelle):

```bash
# Installieren:
brew install ollama
# Oder von https://ollama.com herunterladen

# Starten:
ollama serve
```

Der Monitor erkennt Ollama automatisch unter `http://127.0.0.1:11434`.

---

## Schritt 5: LM Studio (optional)

Falls du LM Studio nutzt:

1. LM Studio starten
2. Den lokalen Server aktivieren (Port 1234)
3. Der Monitor erkennt LM Studio automatisch

**Wichtig:** LM Studio's API listet alle heruntergeladenen Modelle auf, nicht nur die geladenen. Der Monitor unterscheidet automatisch: Modelle mit tatsächlicher VRAM-Größe werden als "loaded" angezeigt, der Rest als "available".

---

## Schritt 6: Monitor starten (manuell)

Zum Testen:

```bash
cd /Users/bernd/.openclaw/workspace/projects/mac-monitor/client
python3 monitor.py
```

Du solltest im Log sehen:

```
Collected stats: CPU=12.3%, RAM=45.6%, Disk=78%, Shelly=123.4W
Sent to server: {"ok":true,"server_id":"mac"}
```

Für den Dauerbetrieb:

```bash
bash run_monitor.sh
```

Das läuft in einer Schleife mit 10 Sekunden Pause.

---

## Schritt 7: Automatischer Start (LaunchAgent)

Damit der Monitor beim Login automatisch startet:

### 7a. LaunchAgent erstellen

```bash
cat > ~/Library/LaunchAgents/com.bernd.mac-monitor.plist << 'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.bernd.mac-monitor</string>
    <key>ProgramArguments</key>
    <array>
        <string>/bin/bash</string>
        <string>/Users/bernd/.openclaw/workspace/projects/mac-monitor/client/run_monitor.sh</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>/Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor.log</string>
    <key>StandardErrorPath</key>
    <string>/Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor.err.log</string>
    <key>WorkingDirectory</key>
    <string>/Users/bernd/.openclaw/workspace/projects/mac-monitor/client</string>
</dict>
</plist>
EOF
```

### 7b. LaunchAgent laden

```bash
launchctl load ~/Library/LaunchAgents/com.bernd.mac-monitor.plist
```

### 7c. Status prüfen

```bash
launchctl list | grep mac-monitor
```

### 7d. LaunchAgent stoppen

```bash
launchctl unload ~/Library/LaunchAgents/com.bernd.mac-monitor.plist
```

---

## Schritt 8: GPU-Temperatur (optional)

Für GPU-Temperaturen auf dem Mac braucht man normalerweise `sudo` für `powermetrics`. Der Monitor nutzt stattdessen `ioreg` für die GPU-Auslastung, was ohne sudo funktioniert.

Wenn du trotzdem Temperaturen willst:

```bash
# SMC-Tool installieren (optional):
brew install --cask istat-menus
# Oder gratis:
brew install fsmont
```

Das ist optional — das Dashboard zeigt auch ohne Temperaturen die GPU-Auslastung.

---

## Troubleshooting

### Keine Daten im Dashboard

1. **Monitor läuft?**
   ```bash
   ps aux | grep monitor.py
   ```

2. **Log prüfen:**
   ```bash
   tail -20 /Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor.log
   ```

3. **Fehler-Log prüfen:**
   ```bash
   tail -20 /Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor.err.log
   ```

4. **Manuell testen:**
   ```bash
   python3 /Users/bernd/.openclaw/workspace/projects/mac-monitor/client/monitor.py
   ```
   Prüfe die Ausgabe auf Fehlermeldungen.

5. **Netzwerk prüfen:**
   ```bash
   curl -s https://mund.bplaced.net/mac-monitor/data.php?range=10m | head -c 200
   ```
   Das sollte JSON zurückgeben.

### Ollama wird nicht angezeigt

- Stelle sicher, dass Ollama läuft: `ollama serve`
- Prüfe: `curl http://127.0.0.1:11434/api/tags`
- Der Monitor zeigt "Ollama offline" im Dashboard, wenn Ollama nicht läuft

### LM Studio wird nicht angezeigt

- LM Studio muss laufen und der **lokale Server** muss aktiviert sein (Port 1234)
- Prüfe: `curl http://127.0.0.1:1234/v1/models`
- LM Studio Modelle erscheinen nur als "loaded", wenn sie wirklich in VRAM geladen sind

### LaunchAgent startet nicht

- Pfad in der plist korrekt? User muss `bernd` heißen (oder Pfad anpassen)
- Log prüfen: `tail -20 ~/Library/LaunchAgents/com.bernd.mac-monitor.plist`
- Neu laden:
  ```bash
  launchctl unload ~/Library/LaunchAgents/com.bernd.mac-monitor.plist
  launchctl load ~/Library/LaunchAgents/com.bernd.mac-monitor.plist
  ```

### Shelly wird nicht angezeigt

- Die Shelly Plus Plug S muss unter `192.168.178.73` im Netzwerk erreichbar sein
- Prüfe: `curl http://192.168.178.73/rpc/Shelly.GetStatus`
- Falls du keine Shelly hast: ignoriere das Feld, der Monitor läuft trotzdem

---

## Dashboard aufrufen

Sobald der Client läuft, siehst du deine Daten hier:

**[https://mund.bplaced.net/mac-monitor/](https://mund.bplaced.net/mac-monitor/)**

Wähle den Tab "BigMac" oben. Die Daten aktualisieren sich automatisch alle 5 Sekunden.

---

## Was wird übertragen?

Die übertragenen Daten enthalten **keine** sensitiven Informationen:

- Hostname ("BigMac")
- CPU/RAM/GPU Auslastung in %
- Geladene Modellnamen und VRAM-Größe
- Stromverbrauch der Shelly (falls vorhanden)
- Ollama API-Request-Logs (IP, Endpoint, Dauer) — nur für lokale Requests

Es werden **keine** Dateiinhalte, Passwörter oder persönlichen Daten übertragen.

---

## Fragen?

Frag Sascha. 📞