# Testbericht · 1.2.0-rc1

## Tatsaechlich ausgefuehrt
- 32 Recovery-Unit-/Simulationstests: bestanden.
- Python-Syntaxpruefung: 25 Dateien bestanden, keine Syntaxfehler.
- Neue Shell-Skripte: sh -n bestanden.
- recovery-ui.js: node --check bestanden.
- Chromium-Browsertest mit ausdruecklichen Test-Fixtures, NICHT echten Evo-X3-Daten:
  Login, gesperrte Buttons vor Anmeldung, RAM-Anzeige, XSS-Textbehandlung,
  Bestaetigungspflicht, Flash-Auftrag, Aus-Auftrag, Theme-Wechsel,
  320px-Layout ohne horizontalen Ueberlauf, keine JavaScript-Laufzeitfehler.
- Bestehender Python-End-to-End-Test: 58 bestanden, 1 fehlgeschlagen.
  Fehler: "Client registriert". Der bestehende client/monitor.py sendet standardmaessig
  an /submit.php, der Testserver stellt die neue REST-API bereit. Das ist eine
  vorhandene Transport-Inkonsistenz, nicht Teil des neuen Recovery-Steuerpfads.
  Dieser Fehler wurde NICHT durch Abschwaechen des Tests versteckt oder als behoben
  ausgegeben. Der bestehende Python-/PHP-Mischbetrieb ist nicht vollstaendig bereinigt.

## Nicht ausgefuehrt / nicht nachgewiesen
- PHP-Lint und PHP-Relay-Laufzeittest: PHP hier nicht installiert.
- Reale systemd-/sudoers-/Linger-Installation.
- Podman-Pull, GPU-Zugriff, reale Modellgewichte und Halogen-Generierung.
- Reale Prozessbeendigung oder reale RAM-Rueckgewinnung auf dem Evo-X3.
- Reale Telegram-Zustellung.
- 27B/Flash-Wechsel auf der Zielhardware, BIOS-/UMA-Konfiguration.
- Vollstaendige Sicherheitspruefung der unveraenderten alten PHP-Endpunkte.

Die Prozesssignale, Systemaufrufe und Speicherwerte in den Recovery-Tests sind
gezielt gemockt. Das testet Entscheidungslogik, keine physische Speicherfreigabe.
Deshalb Release Candidate, nicht als garantiert funktionierende Fernwartung ausgeben.

## Wesentliche gepruefte Recovery-Faelle
- Kein Stoppen des alten Modells, wenn das Zielimage fehlt.
- Keine Uebernahme fremder/unmarkierter Container.
- SIGTERM vor SIGKILL; kein SIGKILL, wenn RAM bereits ausreichend freigegeben wurde.
- PID-Wiederverwendungsschutz; kein pauschales Beenden von Java oder Electron.
- Kein Start ohne ausreichenden Speicher bzw. ohne Bereinigungsfreigabe.
- Bereit erst nach erfolgreichem Generierungstest; Testfehler bleibt Fehler.
- Bewusst Aus bleibt Aus; neuer Aus-Auftrag ueberstimmt alten Fehler.
- Revisionen idempotent, Rueckwaertsspruenge und unbekannte Profile abgelehnt.
- Begrenzte Neustartversuche, gespeicherter Sollzustand und abbrechbare Wartephasen.

## Naechster Abnahmeschritt
Die Checkliste in INSTALL.md auf dem Evo-X3 zu Hause durchfuehren, einschliesslich
Modellwechsel, RAM-Druck, Absturz, bewusstem Stop und Rechnerneustart.
