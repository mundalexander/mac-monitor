# API-Referenz · mac-monitor v1

Basis: `<MM_PUBLIC_URL>/api/v1` — Standard `http://127.0.0.1:8770/api/v1`

Authentifizierung über Header `X-API-Token` (alternativ `Authorization: Bearer <token>`
oder `?token=`). Schreibzugriffe erfordern immer ein Token, Lesezugriffe nur,
wenn `MM_ALLOW_ANONYMOUS_READ=0` gesetzt ist. `/health` ist immer offen.

Alle Antworten sind JSON. Fehler folgen einheitlich:

```json
{ "status": "error", "error": "invalid_request", "message": "Feld 'id' ist erforderlich." }
```

| Code | Bedeutung |
|---|---|
| 200 / 201 | ok bzw. angelegt |
| 400 | Payload ungültig |
| 401 | Token fehlt oder falsch |
| 404 | Route oder Objekt unbekannt |
| 409 | Zustandsübergang nicht erlaubt |
| 429 | Rate Limit (120 Schreibzugriffe / 10 s / IP) |
| 503 | keine Maschine mit freier Kapazität |

---

## Übersicht

| Methode | Route | Zweck |
|---|---|---|
| GET | `/health` | Status, Zählwerte, aufgelöste URLs |
| GET | `/config` | Intervalle und Timeouts fürs Frontend |
| POST | `/ingest` | Messwerte eines Agenten |
| POST | `/tps` | exakte TPS-Messung |
| GET | `/machines`, `/capacity` | Maschinen inkl. Kapazitäts-Score |
| GET | `/agent/discovery` | Empfehlung für Subagenten |
| POST | `/reservations` | Slot reservieren |
| GET | `/reservations`, `/reservations/{id}` | Slots abfragen |
| POST | `/reservations/{id}/start\|heartbeat\|complete\|fail\|cancel` | Zustand ändern |
| DELETE | `/reservations/{id}` | Slot freigeben |
| GET | `/slots` | Zeitachse für die Planung |
| GET | `/series` | Verlaufsdaten für Diagramme |
| GET | `/tps/summary` | TPS je Maschine und Modell |
| GET | `/events` | Serverereignisse |
| POST | `/maintenance` | Retention und `VACUUM` |

---

## POST /ingest

Sendet der Agent alle `MM_SAMPLE_INTERVAL` Sekunden.

```json
{
  "machine": {
    "id": "evo-x3",
    "name": "Evo X3",
    "platform": "linux",
    "backend_url": "http://evo-x3.local:11434",
    "ram_total_gb": 128, "vram_total_gb": 64,
    "max_slots": 3, "agent_version": "1.0.0"
  },
  "sample": {
    "ts": 1790021020,
    "cpu_pct": 25.0, "gpu_pct": 30.0,
    "ram_used_gb": 40.0, "ram_total_gb": 128,
    "vram_used_gb": 12.0, "vram_total_gb": 64,
    "temp_c": 51.0, "power_w": 95.0,
    "loaded_models": ["qwen2.5-coder:14b"],
    "tps_current": 25.6, "tps_avg": 24.1, "tps_peak": 31.0,
    "active_jobs": 1
  },
  "tps_events": []
}
```

Antwort `201`:

```json
{ "status": "accepted", "machine_id": "evo-x3", "ts": 1790021020,
  "tps_events_accepted": 0, "next_sample_in": 10 }
```

Unbekannte Maschinen werden automatisch registriert. Werte werden geklemmt,
`NaN` und `inf` abgewiesen.

---

## POST /tps

Der zuverlässigste Weg zu echten TPS: die Rohwerte der Ollama-Antwort schicken.

```json
{
  "machine_id": "evo-x3",
  "model": "qwen2.5-coder:14b",
  "task_id": "subagent-04",
  "reservation_id": "res_ab12cd34",
  "eval_count": 512,
  "eval_duration": 20000000000,
  "prompt_eval_count": 120,
  "prompt_eval_duration": 1000000000
}
```

Antwort `201`:

```json
{ "status": "accepted", "machine_id": "evo-x3", "tps": 25.6, "prompt_tps": 120.0 }
```

`eval_duration` ist in Nanosekunden, genau wie Ollama es liefert. Alternativ
genügt ein fertiges `"tps": 25.6`. Lässt sich keine Rate ableiten, antwortet der
Server `400` statt einen falschen Wert zu speichern.

Generierung und Prompt-Verarbeitung werden getrennt geführt — sonst wird die
Rechenleistung systematisch überschätzt.

---

## GET /agent/discovery

Der wichtigste Endpunkt für Agenten.

```
GET /agent/discovery?model=qwen2.5-coder:14b&min_ram_gb=18&min_vram_gb=8&require_model=0
```

```json
{
  "can_start_subagent": true,
  "recommended": {
    "machine_id": "evo-x3",
    "name": "Evo X3",
    "backend_url": "http://evo-x3:11434",
    "capacity_score": 74.8,
    "ram_free_gb": 86.0,
    "vram_free_gb": 51.5,
    "free_slots": 3,
    "model_loaded": true,
    "expected_tps": 25.6,
    "reserve_endpoint": "http://127.0.0.1:8770/api/v1/reservations"
  },
  "candidates": [ "... gleiche Struktur, absteigend sortiert ..." ],
  "unavailable": [ { "machine_id": "thinkpad", "reason": "RAM zu knapp (2.7 < 18 GB)" } ]
}
```

`unavailable` nennt immer den konkreten Grund — der Agent kann daraus
entscheiden, ob er wartet, kleiner plant oder abbricht.

---

## POST /reservations

```json
{
  "requester": "openclaw",
  "task_id": "subagent-04",
  "model": "qwen2.5-coder:14b",
  "minimum_ram_gb": 18,
  "minimum_vram_gb": 8,
  "estimated_duration_seconds": 900,
  "priority": 50,
  "require_model_loaded": false,
  "machine_id": "evo-x3"
}
```

`machine_id` ist optional und erzwingt eine bestimmte Maschine. Ohne Angabe
wählt der Scheduler nach Option C.

Erfolg `201`:

```json
{
  "status": "reserved",
  "reservation_id": "res_ab12cd34",
  "machine_id": "evo-x3",
  "backend_url": "http://evo-x3:11434",
  "model_already_loaded": true,
  "capacity_score": 74.8,
  "expected_tps": 25.6,
  "valid_until": 1790021140,
  "ttl_seconds": 120,
  "heartbeat_timeout_seconds": 90
}
```

Keine Kapazität `503`:

```json
{
  "status": "unavailable",
  "reason": "no_machine_available",
  "details": [ { "machine_id": "evo-x3", "reason": "RAM zu knapp (4.0 < 18 GB)" } ],
  "retry_after_seconds": 20
}
```

---

## Lebenszyklus einer Reservierung

```text
   POST /reservations
          │
     ┌────▼─────┐   TTL 120 s ohne start()
     │ reserved ├──────────────────────────► expired
     └────┬─────┘
          │ POST /{id}/start
     ┌────▼─────┐   90 s ohne heartbeat
     │ running  ├──────────────────────────► expired
     └────┬─────┘
          │ complete / fail / cancel
     ┌────▼───────────────────────────┐
     │ completed · failed · cancelled │
     └────────────────────────────────┘
```

Solange ein Slot `reserved` oder `running` ist, zieht der Scheduler dessen
`minimum_ram_gb` und `minimum_vram_gb` von der freien Kapazität ab. Verfallene
Slots geben die Ressourcen automatisch frei — ein abgestürzter Agent blockiert
also nichts dauerhaft.

**Wichtig:** `heartbeat` mindestens alle 30 s senden. Das SDK erledigt das
selbstständig im Hintergrund.

---

## GET /capacity

Liefert je Maschine unter anderem:

| Feld | Bedeutung |
|---|---|
| `capacity_score` | 0–100, Option-C-Bewertung |
| `ram_free_gb`, `vram_free_gb` | frei **nach** Abzug von Reservierungen und Headroom |
| `reserved_ram_gb` | aktuell durch Slots gebunden |
| `free_slots` / `max_slots` | parallele Jobs |
| `tps_current`, `tps_avg`, `tps_peak` | Live-Werte des Agenten |
| `tps_history_model` | gemessene TPS für das angefragte Modell |
| `tps_history_source` | `model` = echte Messung, `machine` = abgewerteter Näherungswert, `none` |
| `model_loaded` | Modell bereits im Speicher |
| `online`, `seconds_since_seen` | Heartbeat der Maschine |

Mit `?model=<name>` werden Scoring und TPS-Historie auf dieses Modell bezogen.

---

## GET /series

```
GET /series?minutes=60&machine_id=evo-x3
```

Liefert `samples` (Verlauf) und `tps_samples` (Einzelmessungen) für die
Diagramme. Begrenzt auf 20.000 bzw. 5.000 Zeilen.

## GET /slots

```
GET /slots?window=60
```

Zeitachse aller Reservierungen im Fenster, jeweils mit `start`, `end` und
`state`. Laufende Slots erhalten ein geplantes Ende aus `est_duration_s`.

## POST /maintenance

Löscht abgelaufene Daten gemäß Retention und führt `VACUUM` aus. Sinnvoll als
täglicher Cronjob.
