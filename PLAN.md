# Fleet Control — Arbeitsplan (Stand 15.11.2026)

Ziel: EIN Dashboard (Logo „m."), das alle Engines (Halogen-NPU, NVIDIA, Ollama, LM Studio, Apple-Geräte)
einheitlich zeigt. Standardsprache im UI: Deutsch. Farbpalette: max. 3 Farben (Neutral + 1 Akzent + 1 Signal).

## Arbeitspakete

### WP1 — GPU-Kachel vereinheitlichen (beide Engines, gleiches Format)
Soll-Format pro Zeile: `Name / Last% / Temperatur · Watt`
- Strix Halo: Temperatur + Watt fehlen. Quelle: `/sys/class/drm/card2/device/hwmon/hwmon5/temp1_input` (63 °C live) + `power1_input` (µW → W, 103 W live). In `client/collectors/gpu.py` rocm() ergänzen → Felder `gpu_temp`, `gpu_watt`.
- NVIDIA: `egpu.py` nvidia-smi-Query um `temperature.gpu` erweitern → `egpu_temp` (30 °C live).
- `mac-monitor-submit.php` + `mac-monitor-data.php`: neue Spalten `gpu_temp, gpu_watt, egpu_temp`.
- Frontend index.php: GPU-Kachel zwei identische Zeilen (Strix oben, NVIDIA unten), je `%` + `°C · W`.
- Aufwand: ~1,5 h | Engine: **Jarvis direkt** (Collector + Deploy, kein Modell nötig)
- Akzeptanz: data.php liefert gpu_temp/gpu_watt/egpu_temp; Kachel zeigt beide Zeilen im gleichen Format.

### WP2 — RAM-Kachel dual (Strix RAM + NVIDIA VRAM)
- Zweite Zeile „NVIDIA · VRAM" mit `egpu_vram_used/total_mb` (vorhanden).
- Format wie GPU-Kachel: Name / Wert / Belegung.
- Aufwand: ~0,5 h | Engine: **NVIDIA/dsh** (Ein-Datei-Frontend-Task, 8k-tauglich)
- Akzeptanz: RAM-Kachel zeigt beide Speicher, keine nackten Zahlen ohne Label.

### WP3 — TPS-Kachel: geladene Engines mit Prefill | Generation
Soll-Format:
```
(Engine 1) Halogen   65 | 1750
(Engine 2) NVIDIA    37 | 192
```
- `monitor_linux.py`: `nvidia_prefill_tps` erfassen (gleiche Methode wie halogen_prefill_tps über llama.cpp-Metriken von :8750).
- data.php: Feld ergänzen; `loaded`-Liste ist schon da (Engine-Namen ableiten: halogen-flash→Halogen, qwen3.8-27b-nvidia→NVIDIA, ollama, lm-studio).
- Frontend: Kachel „Engines · TPS" mit einer Zeile pro geladener Engine, Format `Prefill | Decode`.
- Aufwand: ~2 h | Engine: Backend **Jarvis**, Frontend **NVIDIA/dsh**
- Akzeptanz: jede geladene Engine sichtbar, zwei Zahlen je Engine.

### WP4 — Control Center nativ integrieren + Autonomie übernehmen
Größtes Paket. `mac-monitor-control.html` (Modell-Switch Flash/27B, Recovery, Consent-Flow, Phasen)
wird zum nativen Tab „Control" im Dashboard — kein separates HTML, kein zweites Login.
- Control-Logik (consent/execute/phase-APIs) als Sektion in index.php; Session-Auth statt Token-Login.
- Autonomie-Tab übernehmen: Master-Switch, Slots, Journal (autonomy.php-Logik nativ nachbauen).
- Iframe-Tabs Kalender/Board bleiben erst mal bestehen (funktionieren, nur technisch Iframe).
- Aufwand: ~4–6 h | Engine: **Halogen** (192k, Multi-File) — Review + Deploy durch Jarvis
- Akzeptanz: Modellwechsel + Recovery + Autonomie-Schalter im Dashboard ohne Seitenwechsel; ein Login für alles.

### WP5 — Fleet Ingest API v1 (standardisierte Geräte-Anbindung)
Damit jedes Gerät (Apple, NVIDIA-Host, LM Studio, Ollama, künftige) gleich andockt.
- `ingest.php`: `POST {device_id, token, ts, metrics:{...frei}}` → Tabelle `device_metrics`; `GET ?device=..&hours=..`.
- Geräte-Token: pro Gerät ein Secret in `secrets.php` (kein geteiltes SECRET_TOKEN mehr nötig).
- `data.php` generisch: liefert alle registrierten Geräte + latest + history (heute: evo-x3 hartkodiert).
- Doku `API.md`: Schema, Beispiel-Curls für macOS (monitor.py existiert), LM Studio, Ollama, NVIDIA.
- Aufwand: ~3–4 h | Engine: **Halogen** (Schema-Design, Multi-File) — Abnahme Jarvis
- Akzeptanz: Test-Gerät „test-laptop" schreibt via curl Metriken, erscheint als Tab im Dashboard.

### WP6 — Design: 3-Farb-System + Logo „m." überall
- Tokens: Neutral (canvas/surface/text/muted) + Akzent (NVIDIA-Grün #76b900 wird DER Akzent) + Signal (Rot nur für Fehler/Warnung). Blau/Violett/Orange aus Charts & Badges zurückbauen.
- Logo „m." (Recovery-Glass) in Header + Bottom-Nav + Control; Charts auf Akzent/Neutral umfärben.
- Aufwand: ~2–3 h | Engine: **NVIDIA/dsh** (CSS-lastig, dateiweise in 8k-Scheiben) — Jarvis prüft Live-Look
- Akzeptanz: Screenshot-Vergleich: max. 3 Farbton-Familien im aktiven View.

### WP7 — Rebranding: Name ohne Apple-Bezug
Vorschläge (Sascha entscheidet):
1. **Fleet Control** (meine Empfehlung — passt zu Engines/Flotte, generisch)
2. **Engine Room** (griffig, aber eng auf GPUs)
3. **m.Fleet** (kurz, Logo-nah)
- Umsetzung: `<title>`, Header-Text, Bottom-Nav, README; URL bleibt `/mac-monitor/` (Deploy-Pfad), später optional Redirect.
- Aufwand: ~0,5 h | Engine: Jarvis | Blocker: Namens-Entscheidung Sascha

### WP8 — Login dauerhaft pro Gerät (Standard machen)
- „Gerät merken"-Cookie existiert (90 Tage). Neu: Cookie vorhanden → Dashboard öffnet OHNE PIN-Modal (Auto-Session).
- Login-Modal nur noch bei fehlendem/gültig abgelaufenem Cookie.
- Aufwand: ~1 h | Engine: **NVIDIA/dsh** (ui.js, klein)
- Akzeptanz: Gemerktes Gerät öffnet Dashboard direkt; fremdes Gerät sieht PIN.

## Reihenfolge & Engine-Verteilung

| # | WP | Engine | Aufwand | Vorbedingung |
|---|----|--------|---------|--------------|
| 1 | WP1 GPU-Kachel | Jarvis | 1,5 h | — |
| 2 | WP2 RAM dual | NVIDIA/dsh | 0,5 h | — |
| 3 | WP8 Auto-Login | NVIDIA/dsh | 1 h | — |
| 4 | WP3 TPS-Engines | Jarvis + dsh | 2 h | WP1 (Felder) |
| 5 | WP5 Ingest API | Halogen | 3–4 h | — |
| 6 | WP4 Control nativ | Halogen | 4–6 h | WP5 (Auth-Schema) |
| 7 | WP6 3-Farb-Design | NVIDIA/dsh | 2–3 h | WP4 (alles sichtbar) |
| 8 | WP7 Rebranding | Jarvis | 0,5 h | Namens-Entscheidung |

Gesamt: ~15–18 h Arbeitszeit, verteilbar über 2–3 Abende.
Kalender-Buchung je WP über Slot-API (machine=evo-x3, model=Ziel-Engine, tags=fleet-plan).

## Erledigt 15.11. (nicht mehr im Plan)
- KV-Kachel dual (Halogen + NVIDIA) ✓
- Tabs Engine Room/Kalender/Board/Autonomie im Dashboard ✓
- „Gerät merken" (HMAC-Cookie 90 Tage) ✓
- Hotfix: Tabs umbrechen statt verschwinden; Mobile-Nav mit Kalender/Board/Control ✓ (dc17932)
