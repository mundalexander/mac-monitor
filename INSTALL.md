# Mac Monitor 1.2.0-rc1: Installation und Abnahme

Dies ist ein Release Candidate, kein auf dem Evo-X3 abgenommener Produktionsstand.
Der neue Steuerpfad besteht aus PHP-Relay, Glass-Control-Center und eigenstaendigem
Linux-Recovery-Dienst. Der bisherige Monitor bleibt fuer Messwerte erhalten.
Das gesamte bestehende Projekt ist beigefuegt; nur die unten genannten Dateien
werden fuer Recovery benoetigt. Bestehende Server-Konfiguration NICHT ueberschreiben.

## 1. Backup und Voraussetzungen
- Bestehenden Webordner, Client-Konfiguration und Podman-Startbefehl sichern.
- Python >=3.9, Podman, systemd --user; PHP >=7.4 auf dem Webhost.
- Den normalen Benutzer verwenden, der auch die bisherigen Container besitzt.
- Keine Root-Ausfuehrung des Recovery-Agenten. Keine sudo-Podman-Mischung.
- GPU-Zugriff auf /dev/kfd und /dev/dri muss bereits funktionieren.
- Recovery ist fuer evo-x3 implementiert, kein allgemeiner Multi-Host-Controller.

## 2. Webserver: nur vier neue Dateien hochladen
Aus server/ in den bestehenden mac-monitor-Webordner kopieren:
- mac-monitor-control.html
- recovery-glass.css
- recovery-ui.js
- recovery-api.php
Dateinamen NICHT umbenennen. Das bestehende Monitoring bleibt separat erreichbar.
Optional mac-monitor-index.php mit dem neuen Control-Link aktualisieren; bei einer
bestehenden Installation namens index.php den Link dort manuell einfuegen.
Die mitgelieferten mac-monitor-*.php sind die vorhandene Quellstruktur, nicht ein
fertig konfigurierter Neuaufbau des alten PHP-Servers.

Ausserhalb des oeffentlichen DocumentRoot einen Ordner mac-monitor-private anlegen,
Modus 700, fuer PHP schreibbar. recovery/server/config.example.php als config.php
hineinkopieren. Zwei unterschiedliche Tokens erzeugen, z.B. zweimal:

    python3 -c 'import secrets; print(secrets.token_hex(32))'

browser_token kommt nur in den Browser; agent_token in die Client-Konfiguration.
Standardpfad: dirname(DOCUMENT_ROOT)/mac-monitor-private/config.php.
Alternativ MM_RECOVERY_CONFIG serverseitig auf den absoluten privaten Pfad setzen.
Der Endpunkt verweigert Konfiguration und Storage innerhalb des DocumentRoot.
Falls Shared Hosting keinen privaten schreibbaren Ordner oder keinen Authorization-
Header erlaubt: nicht unsicher umgehen. Hosting-Konfiguration anpassen oder das
Relay auf einen geeigneten eigenen HTTPS-Host legen. Browser und Relay gleicher Origin.

Nur HTTPS verwenden, vorzugsweise zusaetzlich VPN/Access-Gateway. Nicht den gesamten
Projektordner hochladen. Alte PHP-Endpunkte sind NICHT vollstaendig sicherheitsgeprueft.

## 3. Evo-X3: installieren, noch nicht starten
Im entpackten Projekt als normaler Podman-Benutzer:

    sh recovery/scripts/install-user.sh

~/.config/mac-monitor-recovery/config.json bearbeiten:
- relay_url: vollstaendige HTTPS-Adresse von recovery-api.php
- token: agent_token aus der privaten PHP-Konfiguration
- Flash-Profil: Nutzer-Image 0.20.2 wird beibehalten; Verfuegbarkeit beim Pull pruefen.
- 27B: ghcr.io/peonist-ai/halogen:0.1.4, eigenes ~/halogen-27b-models.
- bind_address ist sicherheitshalber 127.0.0.1. Bestehende LAN-Clients erreichen diesen
  Port dann NICHT direkt. Falls erforderlich, explizit die VPN/LAN-IP konfigurieren
  und Firewall/API-Authentifizierung passend einrichten. health_url und completion_url
  muessen dann ebenfalls diese erreichbare IP verwenden. Kein ungeschuetztes 0.0.0.0.

Diagnose ohne Prozessbeendigung:

    python3 ~/.local/share/mac-monitor-recovery/recovery.py --config ~/.config/mac-monitor-recovery/config.json --doctor

Die Diagnose zeigt die tatsaechlich erkannten Prozesse. AppImage-/Flatpak-/Snap-
Varianten ggf. lokal passend konfigurieren; keine pauschalen Namen wie java,
python, electron oder systemd in process_names aufnehmen.

Images VOR dem ersten Modellwechsel herunterladen:

    python3 ~/.local/share/mac-monitor-recovery/recovery.py --config ~/.config/mac-monitor-recovery/config.json --prepare-images

Dieser Schritt prueft insbesondere das vom Nutzer genannte Flash-Tag 0.20.2.
Es gibt absichtlich keinen stillen Versionswechsel, falls es nicht verfuegbar ist.
Die Modellgewichte werden beim ersten Modellstart geladen; Internet und ausreichend
Plattenplatz erforderlich. Beide Modelle einmal zu Hause vollstaendig laden und testen.

## 4. System-Ollama und Start nach Abmeldung/Neustart
Ein System-Ollama gehoert oft einem anderen Benutzer. Der normale Agent kann diesen
Dienst nicht einfach beenden. Fuer diesen Fall:

    sudo sh recovery/scripts/install-system-helper.sh DEIN_LINUX_BENUTZER

Danach system_ollama_helper in config.json auf true setzen. Der Helper erlaubt
NUR systemctl stop ollama.service, ohne Argumente; keine allgemeine Root-Shell.
Das Skript aktiviert auch Linger. Ohne Helper kann Linger separat aktiviert werden:

    sudo loginctl enable-linger DEIN_LINUX_BENUTZER

Fremde Dienste, andere Benutzer, beliebige Container und das Betriebssystem werden
nicht pauschal beendet. Wenn deren RAM das Ziel blockiert, bricht Recovery sichtbar ab.
Die Notfallliste umfasst eigene Ollama-/LM-Studio-/Blender-/Android-Studio-Prozesse,
zugehoerige Kindprozesse sowie den explizit konfigurierten gufo-flash-Container.
Zuerst SIGTERM, 30 Sekunden spaeter SIGKILL nur fuer dieselbe PID mit gleicher Startzeit.
Ungespeicherte Arbeit kann verloren gehen. Apps werden nicht automatisch wieder gestartet.

## 5. Einmalige Uebernahme alter Container
Vor dem ersten Start: podman ps -a ansehen. Alten Halogen-Container gezielt stoppen.
Bei einem unbenannten Container die passende ID verwenden. Nicht alle Container stoppen.
Ein bestehender Container namens halogen ohne Recovery-Label wird NICHT automatisch
uebernommen oder geloescht. Nach Backup/Pruefung gezielt:

    podman stop --time 30 halogen
    podman rm halogen

Bei --rm kann der zweite Befehl entfallen, weil der Container bereits entfernt ist.
Alte Cronjobs/systemd-Units, die Halogen automatisch starten, deaktivieren, damit nur
EIN Supervisor zustaendig ist. Kein paralleler manueller podman run auf Port 8731.
Der neue Agent erzeugt einen benannten Container halogen mit Ownership-Label.
Er nutzt kein --rm; Stop/Rm/Run werden explizit und mit Rueckgabecode-Pruefung ausgefuehrt.

Alte Halogen-Steuerbuttons nicht mehr verwenden. Die gepatchte Linux-Monitor-Datei
ignoriert alte Halogen-Befehle, sobald die neue Konfiguration existiert. Alte ausstehende
Halogen-Befehle in der bisherigen Queue einmalig entfernen. Die alte Watchdog-Telegram-
Meldung wird dann zugunsten des neuen Supervisors unterdrueckt. Die neue Recovery
funktioniert unabhaengig vom alten Monitor. Nicht beide als Lifecycle-Owner betreiben.

## 6. Starten und bedienen

    systemctl --user enable --now mac-monitor-recovery.service
    journalctl --user -u mac-monitor-recovery.service -f

Webseite mac-monitor-control.html ueber HTTPS oeffnen, browser_token eingeben.
Flash Next / 27B / Ausschalten waehlen. Startbestaetigung erlaubt Notfallbereinigung.
Gleichen Startbutton erneut verwenden, um einen manuellen Neustart anzufordern.
Der gewuenschte Zustand wird dauerhaft im Relay und lokal gespeichert.
Bei Verbindungsverlust wird ein bereits bekannter Startwunsch lokal weiter ueberwacht.
Nach Agent-Neustart wird vor einer neuen Transition erst der Relay-Zustand gelesen.
Bei neuem Webauftrag wird der alte Ablauf abgebrochen, sobald der laufende begrenzte
Systemaufruf zurueckkehrt. Ein Generierungstest kann bis zu 90 Sekunden blockieren.

## 7. RAM-Politik und Grenzen
Flash-Startziel: vom Kernel sichtbarer Gesamtspeicher minus 8 GiB Systemreserve.
27B-Startziel: 64 GiB MemAvailable. Beides sind konservative KONFIGURATIONSWERTE,
keine gemessenen Herstelleranforderungen. Auf dem Evo-X3 bei der Abnahme kalibrieren.
MemAvailable statt MemFree: Linux-Dateicache ist weitgehend rueckgewinnbar.
Kein drop_caches, kein swapoff, kein blindes Killen aller Prozesse. Ein Cache-Flush
wuerde belegten Anwendungsspeicher nicht verlaesslich freigeben.
UMA/BIOS-Carve-out, GPU-Allokationen und Treiberprobleme koennen einen Start trotz
hohem MemAvailable verhindern. Flash-Dokumentation empfiehlt minimale UMA-Reservierung.
Das Skript veraendert weder BIOS noch Kernelparameter und garantiert keine GPU-Recovery.
Nach Start: /v1/models UND ein echter 1-Token-Completion-Test, erst dann Bereit.
Laufende Ueberwachung: Containerstatus und /v1/models, sechs Fehler im Abstand von
mindestens zehn Sekunden. Ein intern haengender Generator bei weiter antwortendem
Models-Endpunkt wird damit NICHT sicher erkannt; manueller Neustart bleibt moeglich.
Maximal drei Versuche pro 30 Minuten; 120 Sekunden Abstand nach Fehlern. Danach Pause,
Telegram-Meldung und erneuter Webauftrag moeglich. Telegram-Konfiguration wird aus
~/.config/mac-monitor/telegram.json uebernommen; Bot/Chat muessen vorhanden sein.

## 8. Pflichtabnahme zu Hause, bevor du dich darauf verlaesst
1. Flash starten, Antwort ueber die API erzeugen.
2. 27B starten, Flash beendet und 27B-Antwort pruefen.
3. Flash erneut starten, RAM vor/nach Bereinigung und Prozesse kontrollieren.
4. Blender/LM Studio mit entbehrlichen Testdaten oeffnen, RAM-Druck simulieren,
   SIGTERM/SIGKILL und reale Speicherfreigabe beobachten. Keine wichtigen Dateien offen.
5. podman kill halogen: automatischen Wiederanlauf und Telegram pruefen.
6. Ausschalten: mindestens 5 Minuten warten, darf NICHT wieder starten.
7. Agent neu starten und Rechner neu starten: gespeicherten Sollzustand pruefen.
8. Webverbindung trennen: Clientstatus muss als veraltet erscheinen.
9. Falsches Token, fehlendes Image, belegter Port, zu wenig RAM: klare Fehler pruefen.
10. Von unterwegs/VPN testen; erst danach als verlaessliche Fernwartung betrachten.

## Rollback
systemctl --user disable --now mac-monitor-recovery.service
Falls gewuenscht verwalteten Halogen-Container gezielt stoppen/entfernen und alten
Startbefehl wieder verwenden. Originalbackup wiederherstellen. Fuer alte Monitor-
Steuerung die Recovery-Konfiguration umbenennen; keine zwei Supervisoren aktiv lassen.
Der Root-Helper kann mit /etc/sudoers.d/mac-monitor-recovery und
/usr/local/sbin/mm-stop-ollama entfernt werden. Linger nur deaktivieren, wenn keine
anderen Benutzerdienste es benoetigen.

## Quellen der Profile
- https://github.com/peonist-ai/halogen-server (27B 0.1.4, HALOGEN_TOKENIZER)
- https://github.com/peonist-ai/halogen-flash-server (Flash, Host-/UMA-Hinweise)
- Flash 0.20.2: vom Nutzer vorgegeben, nicht in dieser Umgebung aus Registry gepullt.
