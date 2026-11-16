# Installation und Umstellung auf 1.3

## 1. Server auf bplaced

Voraussetzung: PHP 8.1+ mit `pdo_sqlite` (bei bplaced vorhanden).

1. **Sicherung** des bisherigen Verzeichnisses `/mac-monitor/` inklusive `metrics.sqlite`.
2. Inhalt von `server/` hochladen. Die Dateinamen sind jetzt deploy-fertig
   (`config.php`, `submit.php`, `data.php`, `index.php` … – kein Umbenennen mehr).
   `metrics.sqlite` bleibt erhalten; neue Tabellen und Spalten legt der Server selbst an.
3. **PIN-Hash erzeugen** (lokal, PIN wird verdeckt abgefragt):
   ```bash
   python3 scripts/gen-pin.py               # ohne PHP, z. B. auf dem Laptop
   # oder: php server/tools/gen-pin.php 482913
   ```
   Ausgabe als `secrets.php` neben `config.php` speichern (Vorlage: `secrets.example.php`).
   Eine 6–8-stellige PIN verwenden.
4. Diese Dateien auf dem Server **löschen**, falls noch vorhanden:
   `unload_queue.php`, `recovery-api.php`, `mac-monitor-*.php`, `mac-monitor-control.html`, `glass-theme.css`,
   `apple-theme.css`, `commands.json` (wird neu angelegt).
5. Prüfen:
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' https://mund.bplaced.net/mac-monitor/metrics.sqlite   # 403
   curl -s -o /dev/null -w '%{http_code}\n' https://mund.bplaced.net/mac-monitor/data.php         # 401
   ```

### Sanfte Umstellung (optional)

Damit laufende Clients nicht sofort ausfallen, kann in `secrets.php` vorübergehend
```php
define('LEGACY_CLIENT_TOKEN', 'bisheriger-token');
```
gesetzt werden. Dann nimmt `submit.php` alte Clients noch an, bis alle Maschinen gekoppelt
sind. **Danach die Zeile löschen.** Der alte Token galt als offengelegt und sollte nirgends
mehr verwendet werden.

## 2. Maschinen koppeln

Auf jeder Maschine im Projektordner:

```bash
cp client/config.example.json client/config.json
python3 client/monitor.py --pair --machine-id evo-x3      # mac, mini-pc, …
python3 client/monitor.py --status                        # „Schlüssel gültig"
```

- Die `machine_id` muss in `server/config.php` unter `SERVERS` stehen, damit die Maschine
  als Reiter im Dashboard erscheint.
- Der Schlüssel liegt in `~/.config/fleet-monitor/device.json` (Rechte 0600).
- Alte Einträge `api_token` in `client/config.json` werden ignoriert und können gelöscht werden.

Dauerbetrieb:

| System | Befehl | Logs |
|---|---|---|
| Linux (Evo-X3, Mini-PC) | `./scripts/install-linux.sh` | `journalctl --user -u mac-monitor-agent -f` |
| macOS (BigMac) | `./scripts/install-macos.sh` | `tail -f logs/agent.log` |

Beide Skripte fragen die PIN beim ersten Mal ab, falls noch nicht gekoppelt.
Alte Dienste (`mac-monitor-linux.service`, `run_monitor_linux.sh`, SMC-LaunchDaemon) vorher stoppen:

```bash
systemctl --user disable --now mac-monitor-linux.service 2>/dev/null
sudo launchctl bootout system /Library/LaunchDaemons/com.openclaw.smc-daemon.plist 2>/dev/null
```

## 3. Browser

Dashboard öffnen → PIN → „Dieses Gerät merken" bleibt angehakt. Danach öffnet sich das
Dashboard auf diesem Gerät 90 Tage ohne PIN; jede Nutzung verlängert die Frist.
Optional unter Geräte → Passkey (Touch ID / Face ID) einrichten.

## 4. Gerät verloren oder ersetzt

Dashboard → **Geräte** → „Widerrufen" bzw. „Abmelden". Die Maschine bekommt sofort 401
und muss mit `--pair` neu gekoppelt werden.

## 5. Fehlerbilder

| Meldung | Ursache | Lösung |
|---|---|---|
| Client: `HTTP 401 … nicht gekoppelt` | kein/widerrufener Schlüssel | `--pair` |
| Login: „Zu viele Fehlversuche" | 8 Fehlversuche in 15 min je IP | 15 Minuten warten |
| Maschine fehlt im Dashboard | `machine_id` nicht in `SERVERS` | Eintrag in `config.php` |
| Steuerung: „meldet noch keine Steuerdaten“ | Client < 1.3 oder keine Profile | Client aktualisieren, `docs/CONTROL.md` |
| Steuerung: „Zu wenig RAM“ | gemessener Bedarf + Reserve passt nicht | anderes Profil stoppen oder `ram_headroom_gb` senken |

## 6. Kommandozentrale (ab 1.3)

Nach dem Update des Clients auf jedem Gerät einmal die Profile übernehmen –
Anleitung in [CONTROL.md](CONTROL.md). Das bisherige `recovery-api.php` auf dem Server
wird nicht mehr benötigt und kann gelöscht werden.

Optional für ein Dashboard ohne CDN: `chart.umd.min.js` (Chart.js 4.4.4) nach
`server/assets/` legen.
