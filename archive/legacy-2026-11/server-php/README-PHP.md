# PHP-Variante (Shared Hosting)

Identische API und identisches SQLite-Schema wie der Python-Server. Sinnvoll,
wenn der Monitor dauerhaft auf einem Webhosting laufen soll, das kein Python
als Dienst erlaubt.

## Anforderungen
- PHP 8.0 oder neuer
- PDO-SQLite (`pdo_sqlite`)
- Apache mit `mod_rewrite` (bei nginx siehe unten)

## Installation
1. Inhalt von `server-php/` in ein Webverzeichnis laden, z. B. `/mac-monitor/`.
2. Einen Ordner `data/` **eine Ebene oberhalb** des Webverzeichnisses anlegen und
   beschreibbar machen. Der Pfad steht in `config.php` (`MM_DB_PATH`).
3. Aufruf von `https://<host>/mac-monitor/` -> Dashboard.
4. Im Client `server_url` auf `https://<host>/mac-monitor` setzen.

Die Datenbank wird beim ersten Request automatisch angelegt.

## nginx statt Apache
`.htaccess` wird dort nicht gelesen. Entsprechende Regel:

```nginx
location /mac-monitor/api/v1/ {
    rewrite ^/mac-monitor/api/v1/(.*)$ /mac-monitor/api.php?route=$1 last;
}
location ~* \.(sqlite3|env)$ { deny all; }
```

## Wartung
Aufräumen und Verdichten per Cron, einmal täglich:

```bash
curl -s -X POST -H "X-API-Token: <token>" \
  https://<host>/mac-monitor/api/v1/maintenance
```

## Hinweis zum Reifegrad
Die Python-Referenz unter `server/` ist die getestete Implementierung
(59 automatisierte End-to-End-Prüfungen). Die PHP-Portierung bildet dieselbe
Logik ab, wurde in der Build-Umgebung aber nicht automatisiert getestet, da
dort keine PHP-Laufzeit verfügbar war. Vor produktivem Einsatz bitte
`api/v1/health` und eine Testreservierung prüfen.
