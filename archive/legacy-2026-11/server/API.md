# Fleet Ingest API v1

Standardisierte Anbindung beliebiger Geräte an das Fleet Monitor Dashboard
(`https://mund.bplaced.net/mac-monitor/ingest.php`).
Geräte erscheinen automatisch als eigener Tab im Dashboard.

## 1. Gerät registrieren (einmalig, Admin)

```bash
curl -s -X POST https://mund.bplaced.net/mac-monitor/ingest.php \
  -H 'Content-Type: application/json' \
  -d '{"action":"register","token":"<MASTER_TOKEN>","device_id":"mein-mac","name":"MacBook Pro","os":"macOS"}'
# -> {"ok":true,"device_id":"mein-mac","device_token":"<48-hex>"}
```

Der `device_token` gehört aufs Gerät. Der MASTER-Token ist das Dashboard-Geheimnis
(bei Jarvis/anfordern, nie auf Geräte kopieren).

## 2. Metrik schreiben (Gerät, z.B. alle 10 s)

```bash
curl -s -X POST https://mund.bplaced.net/mac-monitor/ingest.php \
  -H 'Content-Type: application/json' \
  -d '{"device_id":"mein-mac","token":"<DEVICE_TOKEN>","metrics":{"cpu":12.5,"ram_percent":63,"gpu":40,"batterie":88}}'
```

- `metrics` = beliebiges Objekt, max. 40 Felder, Werte: Zahl/String/Bool.
- `ts` optional (Unix-Sekunden, sonst Serverzeit).
- Bewährte Feldnamen (Dashboard füllt dann die bekannten Kacheln):
  `cpu, gpu, ram_percent, ram_used_gb, ram_total_gb, gpu_temp, gpu_watt, tokens_per_second`
- Freie Feldnamen (z.B. `batterie`) erscheinen als eigene Kachel im Geräte-Tab.
- Aufbewahrung: 30 Tage, dann Rollung.

## 3. History lesen

```bash
curl -s 'https://mund.bplaced.net/mac-monitor/ingest.php?device=mein-mac&token=<DEVICE_TOKEN>&hours=6'
# -> {"ok":true,"device_id":"mein-mac","history":[{"ts":...,"metrics":{...}}, ...]}
```

## 4. Geräte verwalten (Admin)

```bash
curl -s 'https://mund.bplaced.net/mac-monitor/ingest.php?devices=1&token=<MASTER_TOKEN>'
curl -s -X POST https://mund.bplaced.net/mac-monitor/ingest.php \
  -H 'Content-Type: application/json' \
  -d '{"action":"delete","token":"<MASTER_TOKEN>","device_id":"mein-mac"}'
```

## Beispiele

### macOS (Apple Silicon)
```bash
CPU=$(top -l1 | awk '/CPU usage/{print 100-$3}')
RAM=$(vm_stat | awk '/active|wired/{c+=$3}END{print c*4096/1073741824}')
curl -s -X POST https://mund.bplaced.net/mac-monitor/ingest.php -H 'Content-Type: application/json' \
  -d "{\"device_id\":\"bigmac\",\"token\":\"<DEVICE_TOKEN>\",\"metrics\":{\"cpu\":$CPU,\"ram_used_gb\":$RAM}}"
```

### LM Studio (TPS abgreifen)
LM Studio liefert `/v1/models`; TPS aus dem Activity-Fenster oder Logs ins
`metrics`-Objekt legen: `{"lm_studio_tps": 42.1, "modell": "qwen3-32b"}`.

### Ollama-Host
```bash
curl -s -X POST .../ingest.php -d '{"device_id":"ollama-box","token":"<T>","metrics":{"ollama_tps":58.6,"modelle":"qwen3:14b"}}'
```

## Auth-Modell
- Pro Gerät ein eigener Token (Rotation durch erneutes `register`).
- MASTER-Token nur für register/delete/devices/history-admin.
- Fehler: 401 unauthorized, 400 Schema-Fehler, Antwort immer JSON `{ok,...}`.
