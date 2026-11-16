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
