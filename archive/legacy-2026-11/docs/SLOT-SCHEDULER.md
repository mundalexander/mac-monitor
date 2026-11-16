# Slotplanung · Option C

Ziel: Ein Agent soll **ohne Raten** wissen, auf welchem Gerät gerade
Rechenkapazität frei ist, und diese Kapazität verbindlich belegen können,
bevor ein zweiter Agent dieselbe Maschine wählt.

---

## Warum nicht einfach "geringste CPU-Last"

Die naheliegende Regel wählt regelmäßig die falsche Maschine:

- Ein ThinkPad mit 2 % CPU-Last kann ein 14B-Modell trotzdem nicht laden.
- Eine Maschine mit 60 % GPU-Last kann schneller sein, wenn das Modell dort
  bereits im Speicher liegt — der Kaltstart entfällt.
- Zwei Agenten, die gleichzeitig "die leerste Maschine" suchen, wählen
  dieselbe und überbuchen sie.

Option C bewertet stattdessen **Fit, Konkurrenz, gemessene Leistung und
Startkosten** und reserviert das Ergebnis verbindlich.

---

## Der Kapazitäts-Score

Jede Komponente wird auf 0–1 normalisiert, gewichtet und auf 0–100 skaliert.

| Komponente | Gewicht | Berechnung |
|---|---:|---|
| freies VRAM | 28 | `vram_free / vram_total` |
| freies RAM | 22 | `ram_free / ram_total` |
| GPU-Leerlauf | 14 | `(100 - gpu_pct) / 100` |
| CPU-Leerlauf | 8 | `(100 - cpu_pct) / 100` |
| gemessene TPS | 16 | `tps_modell / beste_tps_aller_maschinen` |
| Modell geladen | 12 | `1` wenn im Speicher, sonst `0` |

Freie Ressourcen werden **nach** Abzug von Reservierungen und Sicherheitsreserve
berechnet:

```text
ram_free  = ram_total  - ram_used  - reservierter_ram  - MM_RAM_HEADROOM_GB
vram_free = vram_total - vram_used - reserviertes_vram - MM_VRAM_HEADROOM_GB
```

Anschließend greifen Korrekturen:

```text
score += preference           # manuelle Gewichtung je Maschine, -20 .. +20
score  = 0                    # wenn offline
score *= 0.15                 # wenn alle Slots belegt (geeignet, aber besetzt)
```

### Die TPS-Abwertung

Liegt für das **angefragte Modell** auf einer Maschine keine Messung vor, wird
die allgemeine TPS-Historie dieser Maschine nur mit Faktor
`MM_TPS_FALLBACK_DISCOUNT` (Standard `0.6`) verwendet.

Ohne diese Regel gewinnt eine Maschine, die nur ein kleines, schnelles Modell
kennt, gegen die Maschine, auf der das angefragte 14B-Modell tatsächlich
gemessen und bereits geladen ist. Genau dieser Fehler ist beim Test aufgetreten
und wird durch die Abwertung verhindert. Das Feld `tps_history_source` macht
transparent, welche Quelle gewonnen hat: `model`, `machine` oder `none`.

---

## Rechenbeispiel

Angefragt: `qwen2.5-coder:14b`, mindestens 18 GB RAM.

| Maschine | RAM frei | VRAM frei | CPU | GPU | Modell geladen | TPS-Quelle | Score |
|---|---:|---:|---:|---:|:--:|---|---:|
| Evo X3 | 86 GB | 51,5 GB | 25 % | 30 % | ja | gemessen 25,6 | **74,8** |
| Mac Studio | 42 GB | 43,5 GB | 10 % | 5 % | nein | abgewertet 25,5 | 63,6 |
| ThinkPad | 2,7 GB | 0 GB | 88 % | 95 % | nein | keine | abgelehnt |

Der Mac Studio ist im Leerlauf ruhiger, verliert aber, weil er das Modell erst
laden müsste und für dieses Modell keine belastbare Messung existiert. Der
ThinkPad fällt an der Mindestanforderung heraus, mit Begründung
`RAM zu knapp (2.7 < 18 GB)`.

---

## Reservierung gegen Doppelbelegung

```text
Agent A: POST /reservations  {model: "...", minimum_ram_gb: 60}
         -> res_a auf evo-x3, 60 GB gebunden

Agent B: POST /reservations  {minimum_ram_gb: 60}
         -> evo-x3 hat jetzt nur noch 26 GB frei
         -> weicht auf mac-studio aus

Agent C: POST /reservations  {minimum_ram_gb: 60}
         -> 503 unavailable, mit Begründung je Maschine
```

Zwischen Anfrage und Belegung liegt kein Zeitfenster, in dem eine zweite
Anfrage dieselbe Kapazität sehen könnte: Die Reservierung wird geschrieben,
bevor geantwortet wird, und jede Kapazitätsberechnung liest aktive
Reservierungen mit.

### Slots

`max_slots` begrenzt die Zahl paralleler Jobs je Maschine, unabhängig vom
Speicher. Sinnvolle Werte:

| Maschine | Empfehlung |
|---|---|
| Workstation mit viel VRAM | 2–3 |
| Notebook, CPU-Inferenz | 1 |
| Maschine, die nebenbei interaktiv genutzt wird | 1 |

---

## Zeitliche Absicherung

| Parameter | Standard | Wirkung |
|---|---:|---|
| `MM_RESERVATION_TTL` | 120 s | Reservierung ohne `start()` verfällt |
| `MM_HEARTBEAT_TIMEOUT` | 90 s | laufender Job ohne Lebenszeichen verfällt |
| `MM_OFFLINE_AFTER` | 45 s | Maschine ohne Sample gilt als offline |

Ein abgestürzter Agent blockiert dadurch maximal 90 Sekunden. Das Aufräumen
läuft bei jeder Kapazitätsabfrage und zusätzlich minütlich im Hintergrund.

---

## Prioritäten

`priority` (0–100) wird gespeichert und in der Zeitachse angezeigt. Die
aktuelle Version **verdrängt keine laufenden Jobs** — ein hochpriorer Auftrag
wartet, statt einen laufenden Subagenten zu beenden. Das ist bewusst so, weil
ein abgebrochener LLM-Lauf verlorene Rechenzeit bedeutet.

Wer Verdrängung will, kann sie in `scheduler.py` in `reserve()` ergänzen:
Kandidaten mit `state='reserved'` und niedrigerer Priorität vor der eigenen
Reservierung auf `cancelled` setzen.

---

## Tuning

Alle Gewichte sind über `.env` änderbar, ohne Codeänderung:

```env
MM_W_VRAM=28
MM_W_RAM=22
MM_W_GPU_IDLE=14
MM_W_CPU_IDLE=8
MM_W_TPS=16
MM_W_MODEL_LOADED=12
MM_TPS_FALLBACK_DISCOUNT=0.6
```

Typische Anpassungen:

| Situation | Änderung |
|---|---|
| Kaltstarts sind besonders teuer | `MM_W_MODEL_LOADED` auf 18–20 |
| reine CPU-Inferenz im Netz | `MM_W_VRAM` senken, `MM_W_RAM` erhöhen |
| eine Maschine soll bevorzugt werden | `preference` in `config/machines.json` |
| Maschine wird interaktiv genutzt | `preference` negativ, `max_slots` auf 1 |

Wirkung prüfen ohne etwas zu belegen:

```bash
curl -s "http://127.0.0.1:8770/api/v1/capacity?model=qwen2.5-coder:14b" \
  | python3 -m json.tool
```
