# Fleet Monitor

Monitoring und Slotplanung für lokale LLM-Rechner (Evo-X3, BigMac, Mini-PC, NVIDIA-eGPU).
Ein schlankes PHP/SQLite-Dashboard auf bplaced, dazu ein Python-Agent auf jeder Maschine.

**Version 1.2.1** · ohne API-Tokens · PIN-Anmeldung mit „Gerät merken" · neues, ruhiges Design

```
 Maschinen (Python-Agent)                    bplaced (nur PHP + SQLite)
 ┌──────────────────────────┐   HTTPS        ┌──────────────────────────────┐
 │ client/monitor.py        │ ─────────────► │ submit · requests · ingest   │
 │ Geräteschlüssel (0600)   │ ◄───────────── │ commands (nur eigene Befehle)│
 └──────────────────────────┘                │ data · slots · board · …     │
                                             │ Dashboard (PIN / gemerkt)    │
 Browser ── PIN einmal ── 90 Tage gemerkt ──►│ Geräteverwaltung             │
                                             └──────────────────────────────┘
```

## Anmeldung ohne Tokens

| Wer | Wie | Wie oft |
|---|---|---|
| Browser (Mac, iPhone, Windows) | PIN oder Passkey, „Dieses Gerät merken" | einmal, dann 90 Tage gleitend |
| Maschine / Dienst | `python3 client/monitor.py --pair` mit PIN | einmal, danach dauerhaft |
| Agent (Jarvis, Skripte) | nutzt den Geräteschlüssel seiner Maschine | nie |

Die PIN wird nirgends gespeichert. Jede Maschine und jeder Browser hat einen eigenen,
zufälligen Schlüssel, den der Server nur als Hash kennt. Unter **Geräte** im Dashboard
lässt sich jedes Gerät einzeln widerrufen.

## Schnellstart

1. **Server** – Inhalt von `server/` nach bplaced laden, `secrets.php` mit PIN-Hash anlegen
   (siehe [docs/INSTALLATION.md](docs/INSTALLATION.md)).
2. **Maschine koppeln**
   ```bash
   cp client/config.example.json client/config.json        # machine_id prüfen
   python3 client/monitor.py --pair --machine-id evo-x3     # PIN eingeben
   ./scripts/install-linux.sh                               # bzw. install-macos.sh
   ```
3. **Browser** – Dashboard öffnen, PIN eingeben, fertig.

## Projektstruktur

```
server/      PHP-Anwendung für bplaced (einziger Server-Stack)
  auth.php        Sessions, Gerät merken, Geräteschlüssel, PIN-Gate
  pair.php        Kopplung einer Maschine per PIN
  devices.php     Geräteverwaltung (+ devices-api.php)
  assets/apple.css  Design-System 1.2
client/      Python-Agent (macOS + Linux), Collector, Spool
scripts/     Installation als systemd- bzw. launchd-Dienst, Doctor
tests/       Client-Tests, Repo-Prüfung, JS-Prüfung, PHP-Ende-zu-Ende
docs/        Installation, Agenten-Anbindung, Recovery-API
archive/     Alte Stände (Python-Server, server-php, server-php-v2, SMC-Daemon …)
```

## Tests

```bash
python3 -m unittest discover -s tests -v   # Client (15 Tests)
python3 tests/check_repo.py                # keine Tokens, alle Gates vorhanden
python3 tests/check_js.py                  # JavaScript-Syntax inkl. Inline-Skripte
bash tests/php/run.sh                      # Server Ende-zu-Ende (benötigt PHP 8.1+)
```
GitHub Actions führt alle vier Prüfungen bei jedem Push aus.
