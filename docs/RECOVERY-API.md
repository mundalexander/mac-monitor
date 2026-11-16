# recovery-api.php auf Session-Anmeldung umstellen

`recovery-api.php` (Halogen-Modellwechsel) liegt nur auf dem Server, nicht im Repository.
Der Control-Tab im Dashboard ruft die Datei seit 1.2.0 **ohne** Control-Token auf und
verlässt sich auf die bestehende Anmeldung.

Am Anfang von `recovery-api.php` die bisherige Bearer-Prüfung ersetzen durch:

```php
require_once __DIR__ . '/config.php';
mm_require_session();      // Browser mit PIN-Session oder gemerktem Gerät
```

Falls ein Dienst auf dem Evo-X3 die Datei ebenfalls abfragt, stattdessen:

```php
require_once __DIR__ . '/config.php';
mm_require_api_access();   // Session ODER Geräteschlüssel
```

Danach den alten Control-Token aus der Datei entfernen. Gleiches gilt für
`mac-monitor-control.html`, falls sie weiter genutzt wird.
