# mac-monitor · Produktivsystem

Multi-Maschinen-Monitoring **und** intelligente Slotplanung für lokale
LLM-Infrastruktur. Agenten wie OpenClaw, Hermes, OpenCode oder VS Code fragen
über eine API ab, **wo gerade Rechenkapazität frei ist**, reservieren dort einen
Slot und starten ihre Subagenten auf der passenden Maschine.

Version 1.0.0 · getestet mit 59 automatisierten End-to-End-Prüfungen.

---

## Was in dieser Version repariert wurde

| Thema | Vorher | Jetzt |
|---|---|---|
| **TPS-Wert** | aus Logzeilen geraten, blieb als Altwert stehen | exakt aus `eval_count / eval_duration`, Fenster von 30 s, ohne Messung sauber `0.0` |
| **TPS-Diagramm** | unbrauchbare Skalierung, keine Zuordnung | gemessene Punkte + gleitender Durchschnitt je Maschine, Tabelle je Modell |
| **Slotplanung** | nicht vorhanden | Option C: Kapazitäts-Scoring, Reservierung, Heartbeat, Zeitachse |
| **Agent-Anbindung** | keine | `/agent/discovery`, SDK, OpenClaw-Brücke |
| **URLs** | hart codiert, brachen bei Unterpfaden | zentral in `.env`, Frontend löst relativ auf |
| **Ausfallsicherheit** | Messwerte gingen verloren | lokaler Spool, exponentielles Backoff mit Jitter |
| **Datenwachstum** | unbegrenzt | Retention, WAL, Indizes, `VACUUM` |
| **Root-Daemon (SMC)** | Root-C-Daemon für Temperaturen | entfällt, nur unprivilegierte Quellen |

API-Token bleibt bewusst ein statisches Testtoken – laut Vorgabe akzeptiert.

---

## Schnellstart

```bash
unzip mac-monitor-production.zip && cd mac-monitor-production

# 1. Server starten (Dashboard + API)
./scripts/start-server.sh

# 2. Auf jeder Maschine den Agenten starten
./scripts/start-client.sh

# 3. Dashboard öffnen
#    http://127.0.0.1:8770/
```

Voraussetzung: **Python 3.9+**. Keine Fremdpakete, kein Docker, kein Build.

Diagnose bei Problemen:

```bash
python3 scripts/doctor.py
```

Tests:

```bash
python3 tests/test_end_to_end.py     # 59 Prüfungen
```

---

## Für den Agenten: wo läuft der nächste Subagent?

**Variante 1 – eine Zeile Shell:**

```bash
eval "$(python3 integrations/openclaw/pick_machine.py \
        --model qwen2.5-coder:14b --min-ram 18 --env)"
# setzt OLLAMA_BASE_URL, MM_MACHINE_ID, MM_RESERVATION_ID
```

**Variante 2 – Python-SDK mit automatischem Heartbeat:**

```python
from integrations.agent_client import MacMonitor

mm = MacMonitor("http://127.0.0.1:8770", "mac-monitor-test-token")

with mm.slot(requester="openclaw", task_id="subagent-04",
             model="qwen2.5-coder:14b", min_ram_gb=18, wait_s=120) as slot:
    if slot is None:
        print("keine Kapazität frei")
    else:
        print("läuft auf", slot.machine_id, "->", slot.backend_url)
        antwort = mm.generate(slot, "Schreibe eine Python-Funktion ...")
        print(antwort["measured_tps"], "TPS")
```

Der Kontextmanager reserviert, hält den Slot per Heartbeat, meldet die
gemessene TPS und gibt den Slot am Ende zuverlässig frei – auch bei Exception.

**Variante 3 – nur nachschauen, nichts belegen:**

```bash
curl -s "http://127.0.0.1:8770/api/v1/agent/discovery?model=qwen2.5-coder:14b&min_ram_gb=18"
```

---

## Architektur

```text
┌────────────────────────────────────────────────────────────┐
│  Agenten:  OpenClaw · Hermes · OpenCode · VS Code · Shell   │
└───────────────┬────────────────────────────────────────────┘
                │  discovery · reserve · heartbeat · TPS
┌───────────────▼────────────────────────────────────────────┐
│  mac-monitor Server                                        │
│  ├── Scheduler (Option C)   Scoring, Reservierung, TTL     │
│  ├── API /api/v1            Ingest, Kapazität, Slots       │
│  ├── SQLite (WAL)           Samples, TPS, Reservierungen   │
│  └── Dashboard              Karten, Charts, Zeitachse      │
└───────────────▲────────────────────────────────────────────┘
                │  alle 10 s ein Sample (gepuffert bei Ausfall)
┌───────────────┴────────────────────────────────────────────┐
│  Agent je Maschine  ·  macOS & Linux, identischer Code      │
│  CPU · RAM · GPU/VRAM · Temperatur · Shelly · Ollama · TPS  │
└────────────────────────────────────────────────────────────┘
```

---

## Slotplanung (Option C)

Nicht die leerste Maschine gewinnt, sondern die mit der besten **real
verfügbaren und prognostizierten** Ausführungskapazität.

| Kriterium | Gewicht | Warum |
|---|---:|---|
| freies VRAM | 28 | entscheidet, ob das Modell überhaupt auf die GPU passt |
| freies RAM | 22 | Fit bei CPU-Inferenz und großen Kontexten |
| GPU-Leerlauf | 14 | aktuelle Konkurrenz um die GPU |
| CPU-Leerlauf | 8 | Konkurrenz bei Prompt-Verarbeitung |
| gemessene TPS | 16 | echte Historie **für dieses Modell** |
| Modell geladen | 12 | spart den Kaltstart, bei 14B schnell 20–60 s |

Reservierte Ressourcen anderer Slots werden **abgezogen**, bevor gerechnet wird –
dadurch gibt es keine Doppelbelegung, wenn mehrere Agenten gleichzeitig planen.

Details und Tuning: **SLOT-SCHEDULER.md**

---

## Inhalt des Pakets

```text
server/            Python-Server (Referenz, getestet)
  app.py           HTTP-Router, Auth, Rate Limit, Housekeeping
  scheduler.py     Option-C-Scoring und Reservierungslogik
  api.py           Endpunkte inkl. strenger Validierung
  db.py            Schema, WAL, Indizes, Retention
  dashboard/       Frontend ohne externe Abhängigkeiten
server-php/        gleiche API für Shared Hosting (PHP 8 + SQLite)
client/            Agent für macOS und Linux
  collectors/      CPU, RAM, GPU, Ollama, Shelly, Temperatur
integrations/      Agent-SDK und OpenClaw-Brücke
scripts/           Start, Installation, Doctor, systemd/launchd
tests/             End-to-End-Test ohne Fremdpakete
docs/              API.md, SLOT-SCHEDULER.md, INSTALLATION.md
```

---

## Konfiguration

Alles zentral, keine Adresse mehr im Quellcode:

- Server: `.env` (aus `.env.example`)
- Client: `client/config.json` (aus `client/config.example.json`)
- Optionales Maschinenregister: `config/machines.json`

Die wichtigsten Werte:

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `MM_PUBLIC_URL` | `http://127.0.0.1:8770` | öffentliche Basis-URL |
| `MM_SAMPLE_INTERVAL` | `10` | Messintervall in Sekunden |
| `MM_OFFLINE_AFTER` | `45` | Maschine gilt danach als offline |
| `MM_RESERVATION_TTL` | `120` | Reservierung ohne Start verfällt |
| `MM_HEARTBEAT_TIMEOUT` | `90` | laufender Job ohne Heartbeat verfällt |
| `MM_RETENTION_RAW_DAYS` | `14` | Aufbewahrung der Rohdaten |

---

## Betriebshinweise

- **Dauerbetrieb**: `scripts/install-linux.sh` (systemd `--user`, inkl. `linger`)
  oder `scripts/install-macos.sh` (launchd, ohne Root).
- **Datenwachstum**: Bei 10 s Intervall entstehen je Maschine rund 8.640 Samples
  pro Tag. Die Retention hält die Datenbank klein; `POST /api/v1/maintenance`
  räumt zusätzlich auf.
- **Erreichbarkeit im Netz**: Das Testtoken ist bewusst schwach. Wenn der Server
  über das offene Internet erreichbar sein soll, gehört er hinter VPN,
  Tailscale, Cloudflare Access oder eine Webserver-Authentifizierung.
