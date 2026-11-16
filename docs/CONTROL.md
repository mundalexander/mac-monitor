# Kommandozentrale (ab 1.3)

Die Steuerung läuft **auf dem Gerät selbst** – über den Monitor-Client. Sie braucht weder
systemd noch launchd und funktioniert damit gleich auf Linux (Evo-X3, Mini-PC) und macOS (BigMac).
Das Dashboard schickt nur einen Auftrag („Profil flash-next starten“); ausgeführt wird er lokal
von `client/control.py`. Fremde Befehle oder Pfade nimmt der Server nicht an.

## Begriffe

| Begriff | Bedeutung | Beispiel |
|---|---|---|
| **Profil** | ein startbares Modell mit Startbefehl und Port | `flash-next`, `27b`, `nvidia-27b` |
| **Engine** | Gruppe von Profilen, von der immer nur eines läuft | `halogen`, `nvidia` |
| **Agent** | Dienst, der neu gestartet werden kann | `openclaw`, `hermes` |

## Einrichtung (einmal pro Gerät, ca. 5 Minuten)

1. `client/control.example.json` ansehen und den Block `"control"` in `client/config.json` übernehmen.
   `advertise_host` auf den Namen setzen, unter dem andere Rechner das Gerät erreichen
   (z. B. `evo-x3.local` oder die Tailscale-IP).
2. **Startbefehle übernehmen statt abtippen.** Profil so starten, wie du es heute tust, dann:
   ```bash
   python3 client/fleet_control.py adopt flash-next --port 8731 --engine halogen \
       --label "Halogen Flash · Qwen 3.8 Flash-Next"
   ```
   Der Client liest den Startbefehl und das Arbeitsverzeichnis aus dem laufenden Prozess,
   schreibt sie in `client/config.json` (mit Sicherungskopie) und misst den RAM-Bedarf.
   Dasselbe für das 27B-Profil und die NVIDIA-Engine wiederholen.
3. Agenten übernehmen (laufender Prozess, Suchmuster für die Befehlszeile):
   ```bash
   python3 client/fleet_control.py adopt-agent openclaw --pattern "openclaw" --label OpenClaw
   python3 client/fleet_control.py adopt-agent hermes   --pattern "hermes"   --label Hermes
   ```
   Hat ein Agent einen eigenen Neustart-Befehl, diesen stattdessen als `"restart": [...]` eintragen.
4. Optional, für „Cache freigeben“: `./scripts/install-control-helper.sh` (einmal sudo-Passwort).
   Legt nur eine eng begrenzte sudo-Regel an (Linux: `drop_caches`-Helfer, macOS: `purge`).
5. Monitor-Client neu starten. Im Dashboard unter **Steuerung** erscheinen Profile und Agenten.

Prüfen: `python3 client/fleet_control.py status`

## Was beim Profilwechsel passiert

1. Sperre: pro Gerät läuft nur eine Steueraktion.
2. Andere Profile derselben Engine werden beendet (erst sanft, nach 25 s hart). Ein fremder
   Prozess auf dem Port – z. B. eine abgestürzte alte Instanz – wird ebenfalls beendet.
3. Ollama- und LM-Studio-Modelle werden entladen.
4. Seiten-Cache wird freigegeben (falls eingerichtet).
5. Warten, bis genug RAM frei ist: gemessener Bedarf + Reserve (`ram_headroom_gb`, Standard 4 GB).
6. Start, Health-Check (`/health` liefert 200), kurzer Generierungstest mit Tokens/s.
7. RAM-Bedarf wird gemessen und gespeichert (Maximum aus Prozess-Speicher und Rückgang des
   freien RAM – wichtig bei Unified Memory wie Strix Halo und Apple Silicon).
8. Schlägt etwas fehl, wird das vorherige Profil wiederhergestellt; das Protokoll zeigt die
   letzten Zeilen aus dem Engine-Log.

Gemessene Werte liegen in `~/.local/share/fleet-monitor/profiles_measured.json`,
Engine-Logs in `~/.local/share/fleet-monitor/logs/`.

## Kommandozeile (auch für Agenten)

```bash
python3 client/fleet_control.py status --json
python3 client/fleet_control.py start flash-next
python3 client/fleet_control.py start 27b
python3 client/fleet_control.py stop halogen
python3 client/fleet_control.py free
python3 client/fleet_control.py restart-agent openclaw
python3 client/fleet_control.py measure flash-next
```
Exit-Code 3 bedeutet: Es läuft bereits eine Aktion.

## Sicherheit

- Der Server nimmt nur Aufträge für Profile/Agenten an, die das Gerät selbst gemeldet hat.
- Aufträge älter als 3 Minuten werden auf dem Gerät verworfen (z. B. nach einem Offline-Zeitraum).
- Pro Gerät ist höchstens ein Steuerauftrag offen.
- Agenten mit Geräteschlüssel (Jarvis) dürfen Profile starten/stoppen und Speicher freigeben,
  **nicht** aber Agenten neu starten.
- Kein Root-Dienst. Nur der optionale Cache-Helfer läuft über eine einzelne sudo-Regel.
