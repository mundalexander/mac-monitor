# Mac Monitor 1.2.0-rc1 · Glass Control & Halogen Recovery

Aktualisiertes Projekt auf Basis des bereitgestellten ZIP-Archivs.
Neu: Apple-inspiriertes Glass-Control-Center, lokaler Linux-Recovery-Supervisor,
Flash-Next/27B-Umschaltung, kontrollierte RAM-Notfallbereinigung und Telegram-Status.

**Zuerst recovery/INSTALL.md lesen. Nicht den gesamten Ordner blind hochladen.**
Bestehendes Monitoring bleibt erhalten; es ist kein vollstaendig neu gestalteter
Monitoring-Stack. Das neue Glass-Design betrifft das Halogen Control Center.
Der neue Steuerpfad ist bewusst unabhaengig von den mehreren alten Servervarianten.

Neu/gezielt geaendert:
- server/mac-monitor-control.html, recovery-glass.css, recovery-ui.js, recovery-api.php
- recovery/client/*, recovery/scripts/*, recovery/server/*, recovery/tests/*
- client/collectors.py -> legacy_collectors.py; Importkollision behoben
- client/monitor_linux.py: alter Lifecycle deaktiviert, wenn Recovery konfiguriert ist
- server/mac-monitor-unload-request.php: alte Halogen-Aktionen abgewiesen
- server/mac-monitor-index.php: Link zum neuen Control Center
- eingebettete metrics.sqlite und veraltetes unload_queue.php nicht mitgeliefert

**Release Candidate:** keine behauptete Hardware-Abnahme. Python-/Browser-Tests
und Grenzen siehe recovery/TEST-REPORT.md. Bestehende Altkomponenten sind nicht
vollstaendig ueberarbeitet oder sicherheitsgeprueft. Geheimnisse lokal neu setzen.
