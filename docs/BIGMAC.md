# BigMac (macOS) an den Fleet Monitor anbinden

Kurzanleitung für Bernd. Dauer etwa 10 Minuten. Kein Token, keine Admin-Rechte nötig.

## 1. Projekt kopieren und koppeln

```bash
cd ~/fleet-monitor
cp client/config.example.json client/config.json      # machine_id "mac", machine_name "BigMac"
python3 client/monitor.py --pair --machine-id mac     # PIN von Sascha
python3 client/monitor.py --status                    # „Schluessel gueltig"
./scripts/install-macos.sh                            # startet ab jetzt automatisch
```

## 2. Steuerung einrichten (optional)

Damit das Dashboard Modelle auf dem Mac starten/wechseln kann: Modell so starten wie
gewohnt (z. B. `llama-server` oder MLX-Server), dann übernehmen:

```bash
python3 client/fleet_control.py adopt mac-27b --port 8080 --engine mlx --label "BigMac · Qwen 3.8 27B MLX"
python3 client/fleet_control.py status
```
In `client/config.json` unter `control` noch `"advertise_host": "bigmac.local"` setzen.
Ollama wird automatisch erkannt und bei einem Profilwechsel entladen.

Optional (Cache leeren mit `purge`): `./scripts/install-control-helper.sh`

## 3. Alte Installation entfernen

```bash
launchctl unload ~/Library/LaunchAgents/com.bernd.mac-monitor.plist 2>/dev/null
rm -f ~/Library/LaunchAgents/com.bernd.mac-monitor.plist
sudo launchctl bootout system /Library/LaunchDaemons/com.openclaw.smc-daemon.plist 2>/dev/null
```

## Probleme

| Meldung | Lösung |
|---|---|
| `HTTP 401 … nicht gekoppelt` | Schritt 1 wiederholen |
| Steuerung zeigt „keine Steuerdaten“ | Client neu starten, `fleet_control.py status` prüfen |
| Mac fehlt im Dashboard | `machine_id` muss `mac` heißen |
