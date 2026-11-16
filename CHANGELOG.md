# Changelog

## 1.2.0 — 2026-11-16

### Sicherheit: keine Tokens mehr
- **PIN statt Token** für Browser. „Dieses Gerät merken" speichert ein zufälliges
  Geräte-Cookie (90 Tage, gleitend, serverseitig nur als Hash, einzeln widerrufbar).
  Gemerkte Geräte öffnen das Dashboard direkt ohne PIN-Abfrage.
- **Geräte-Kopplung** für Maschinen: `monitor.py --pair` tauscht die PIN einmalig gegen
  einen eigenen Geräteschlüssel (Datei 0600). Kein gemeinsamer Token mehr.
- **Geräteverwaltung** (`devices.php`): Maschinen und Browser ansehen und widerrufen.
- Dashboard, `data.php` und `fleet-api.php` waren öffentlich – jetzt nur mit Anmeldung.
- `unload-request.php` war ohne Anmeldung nutzbar (Modelle entladen, Halogen stoppen) – behoben.
- `commands.php` gab allen Aufrufern alle Befehle – jetzt nur der eigenen Maschine.
- `submit.php` übernimmt die Maschinen-ID aus der Kopplung, nicht aus dem Payload.
- Entfernt: hart codierter Bearer in `unload_queue.php`, Klartext-Schlüssel in
  `client/config.json`, Token-Felder und `localStorage`-Tokens, Token-Login.
- PIN-Fehlversuche zusätzlich je IP gebremst (8 in 15 Minuten), interne Fehlertexte
  nicht mehr in Antworten.

### Funktionen
- Dateinamen deploy-fertig (`config.php`, `submit.php` … statt `mac-monitor-*.php`).
- Schema-Migrationen einmalig per `PRAGMA user_version` statt ~40 ALTER-Versuche je Request.
- Command-Queue mit Dateisperre, atomarem Schreiben und Aufräumen.
- Slot-Buchung und -Verlängerung atomar (`BEGIN IMMEDIATE`), Verlängerung prüft jetzt jede Überschneidung.
- Anfragen (`requests`) werden der Maschine zugeordnet; Transaktion statt Einzel-Inserts.
- Dashboards pausieren das Polling in Hintergrund-Tabs.
- Control-Tab ohne Control-Token (siehe docs/RECOVERY-API.md).
- Client: `--pair`, `--status`, `--unpair`; Geräteschlüssel bindet die `machine_id`.

### Design
- Neues Design-System `assets/apple.css`: Systemschrift, Neutral + ein Akzent (#76B900) + Signal.
- Einheitliche Navigation auf allen Seiten (Dashboard · Kalender · Board · Autonomie · Geräte).
- Neue PIN-Anmeldeseite, keine externen Schriften mehr (Porsche-CDN entfernt), keine Emoji-Icons.

### Aufgeräumt (nach `archive/legacy-2026-11/`)
Python-Referenzserver, `server-php/`, `server-php-v2/`, `monitor_linux.py`, `collectors.py`,
SMC-Root-Daemon, alte Start-Skripte, token-basierte SDKs und Dokumente.


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
