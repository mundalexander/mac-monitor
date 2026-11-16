# BigMac (macOS) an den Fleet Monitor anbinden

Kurzanleitung für Bernd. Dauer: etwa 5 Minuten. Kein Token, keine Admin-Rechte nötig.

## 1. Projekt auf den Mac kopieren

Den Ordner `fleet-monitor` z. B. nach `~/fleet-monitor` legen, dann im Terminal:

```bash
cd ~/fleet-monitor
cp client/config.example.json client/config.json
```

In `client/config.json` nur diese drei Werte prüfen:

```json
"server_url": "https://mund.bplaced.net/mac-monitor",
"machine_id": "mac",
"machine_name": "BigMac"
```

## 2. Einmal koppeln

```bash
python3 client/monitor.py --pair --machine-id mac
```

Die PIN bekommst du von Sascha. Sie wird nicht gespeichert. Der Mac erhält einen eigenen
Schlüssel unter `~/.config/fleet-monitor/device.json`.

Prüfen:

```bash
python3 client/monitor.py --status     # „Schluessel gueltig"
```

## 3. Dauerhaft starten

```bash
./scripts/install-macos.sh
tail -f logs/agent.log
```

Der Agent startet ab jetzt bei jeder Anmeldung automatisch.

## 4. Alte Installation entfernen

Falls noch der alte Monitor läuft:

```bash
launchctl unload ~/Library/LaunchAgents/com.bernd.mac-monitor.plist 2>/dev/null
rm -f ~/Library/LaunchAgents/com.bernd.mac-monitor.plist
sudo launchctl bootout system /Library/LaunchDaemons/com.openclaw.smc-daemon.plist 2>/dev/null
```

## Probleme

| Meldung | Lösung |
|---|---|
| `HTTP 401 … nicht gekoppelt` | Schritt 2 wiederholen |
| `Kopplung fehlgeschlagen: PIN falsch` | PIN bei Sascha erfragen; nach 8 Fehlversuchen 15 min warten |
| Mac erscheint nicht im Dashboard | `machine_id` muss `mac` heißen |
