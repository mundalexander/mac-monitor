# Changelog

## 1.0.0 — 2026-09-21

Erste Produktivversion. Vollständige Überarbeitung von Server, Client,
Dashboard und Agent-Anbindung.

### Behoben
- **TPS-Wert**: Wird nicht mehr aus Logzeilen geraten und bleibt nicht mehr als
  Altwert stehen. Berechnung exakt aus `eval_count / eval_duration` der
  Ollama-Antwort, Fenster von 30 s für "jetzt", 5 min für Durchschnitt und Peak.
  Ohne Messung sauber `0.0`. Prompt-Verarbeitung und Token-Generierung werden
  getrennt geführt.
- **TPS-Diagramm**: Gemessene Einzelpunkte und gleitender Durchschnitt je
  Maschine, korrekte Skalierung, zusätzliche Tabelle je Modell.
- **URLs**: Keine hart codierten Adressen mehr. Server liest alles aus `.env`,
  das Frontend löst seine API-Aufrufe relativ auf und funktioniert damit auch
  in Unterverzeichnissen und hinter Reverse Proxys. Schrägstriche und fehlende
  Schemata werden normalisiert.
- **Scoring-Fehler**: Eine Maschine ohne das angefragte Modell erbte die
  TPS-Historie eines fremden Modells und gewann dadurch die Auswahl.
  Fremdmodell-Historie wird jetzt abgewertet (`MM_TPS_FALLBACK_DISCOUNT`).
- **Datenverlust**: Messwerte werden bei Server- oder Netzausfall lokal
  gepuffert und später nachgesendet, mit exponentiellem Backoff und Jitter.
- **Datenwachstum**: WAL-Modus, Indizes, Retention und `VACUUM` über
  `POST /api/v1/maintenance`.
- **Root-Daemon**: Der SMC-Root-Daemon für Temperaturen entfällt ersatzlos.
  Es werden nur noch unprivilegierte Quellen verwendet.
- **Fehlertoleranz**: Ausfall einzelner Collector stoppt den Agenten nicht mehr.
  Fehlende Werte bleiben leer statt eingefroren.
- **Fehlerbehandlung**: Interne Details erscheinen nicht mehr in HTTP-Antworten.

### Neu
- **Slotplanung Option C**: Kapazitäts-Scoring aus freiem VRAM/RAM, GPU- und
  CPU-Leerlauf, gemessener TPS und bereits geladenem Modell. Reservierte
  Ressourcen werden abgezogen, dadurch keine Doppelbelegung.
- **Reservierungen** mit Lebenszyklus `reserved → running → completed`,
  TTL, Heartbeat-Überwachung und automatischem Verfall.
- **Agent-API** `/agent/discovery`: Empfehlung, Kandidatenliste und
  Ablehnungsgründe je Maschine.
- **Agent-SDK** `integrations/agent_client.py` mit Kontextmanager, der Slot,
  Heartbeat, TPS-Meldung und Freigabe übernimmt.
- **OpenClaw-Brücke** `integrations/openclaw/pick_machine.py`, liefert auf
  Wunsch direkt `OLLAMA_BASE_URL` als Shell-Export.
- **Dashboard** neu aufgebaut: Maschinenkarten mit Score-Ring, KPI-Leiste,
  Slot-Zeitachse, Reservierungsformular, drei Diagramme, Endpunktübersicht.
  Ohne externe Abhängigkeiten, damit offline und im Firmennetz nutzbar.
- **Einheitlicher Client** für macOS und Linux statt zwei getrennter Skripte.
- **Maschinenregister** `config/machines.json` für Namen, Labels, `max_slots`
  und manuelle Präferenz.
- **Rate Limiting** für Schreibzugriffe.
- **Diagnose** `scripts/doctor.py`.
- **Testsuite** `tests/test_end_to_end.py` mit 59 Prüfungen.
- **PHP-Variante** `server-php/` mit identischer API für Shared Hosting.
- **Dienste**: systemd `--user` inklusive `linger`, launchd ohne Root.

### Bewusst unverändert
- Das API-Token bleibt ein statisches Testtoken. Laut Vorgabe akzeptiert.
