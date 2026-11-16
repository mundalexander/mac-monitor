# Agenten und Skripte anbinden (Jarvis, OpenClaw, Cron)

Agenten bekommen keinen eigenen Token mehr. Sie nutzen den Geräteschlüssel der Maschine,
auf der sie laufen – derselbe, den `client/monitor.py --pair` angelegt hat.

```python
import json, os, urllib.request

cred = json.load(open(os.path.expanduser("~/.config/fleet-monitor/device.json")))
H = {"X-Fleet-Device": cred["machine_id"], "X-Fleet-Key": cred["device_key"],
     "Content-Type": "application/json"}
BASE = cred["server_url"]

def call(path, body=None):
    req = urllib.request.Request(BASE + path, headers=H, method="POST" if body else "GET",
                                 data=json.dumps(body).encode() if body else None)
    with urllib.request.urlopen(req, timeout=10) as r:
        return json.load(r)

call("/board-api.php")                                   # Ideen-Board lesen
call("/slots.php", {"machine": "evo-x3", "start_unix": 1790000000,
                    "end_unix": 1790001800, "owner": "jarvis", "task": "WP3"})
call("/autonomy-api.php", {"action": "append", "event": "wake", "note": "Slot gestartet"})
```

Shell:
```bash
K=~/.config/fleet-monitor/device.json
curl -s -H "X-Fleet-Device: $(jq -r .machine_id $K)" -H "X-Fleet-Key: $(jq -r .device_key $K)" \
     https://mund.bplaced.net/mac-monitor/board-api.php
```

| Endpunkt | Gerät | Browser |
|---|---|---|
| submit, requests, ingest | ✓ | – |
| commands (abholen/quittieren, nur eigene) | ✓ | – |
| commands / unload-request (anlegen) | – | ✓ |
| data, fleet-api, slots, board-api, autonomy-api | ✓ | ✓ |
| devices-api, Geräte widerrufen | – | ✓ |

Ein Agent kann damit nie Steuerbefehle für andere Maschinen auslösen oder Geräte verwalten.

## Freie Kapazität finden, buchen, arbeiten (ab 1.3)

```python
cap = call("/capacity.php")                    # sortiert: free, cold, booked, busy, full, offline
best = cap["recommended"]                      # z. B. {"machine":"evo-x3","profile":"flash-next","endpoint":"http://evo-x3.local:8731/v1",...}

# 1. Slot buchen (machine + model = Profil)
now = int(time.time())
call("/slots.php", {"machine": best["machine"], "model": best["profile"], "owner": "jarvis",
                    "task": "WP4 Control", "start_unix": now, "end_unix": now + 3600})

# 2. Falls das Profil noch nicht läuft (status "cold"): starten lassen
if best["status"] == "cold":
    call("/control-api.php", {"machine": best["machine"], "action": "profile", "profile": best["profile"]})
    # bis status "free" erneut capacity.php abfragen

# 3. Direkt gegen den Endpunkt arbeiten (OpenAI-kompatibel)
```

| Status | Bedeutung |
|---|---|
| `free` | geladen, im Leerlauf, kein Slot – sofort nutzbar |
| `cold` | nicht geladen, aber startbar (Ladezeit beachten) |
| `booked` | ein Slot läuft (`busy_until`) |
| `busy` | generiert gerade oder Steueraktion läuft |
| `full` | Modell passt nicht in den freien Speicher |
| `offline` | Gerät meldet sich nicht |

Lokal auf derselben Maschine geht es auch ohne Server: `python3 client/fleet_control.py status --json`.

| Endpunkt | Gerät | Browser |
|---|---|---|
| capacity.php | ✓ | ✓ |
| control-api.php GET | ✓ | ✓ |
| control-api.php POST profile/stop/free | ✓ | ✓ |
| control-api.php POST agent_restart | – | ✓ |
