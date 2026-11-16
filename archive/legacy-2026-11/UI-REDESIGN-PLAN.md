# mac-monitor — UI-Redesign-PLAN

> Stand: 2026-11-14 · Board-Karte 19 · Autor: CEO-Agent (Jarvis-Autonomie-Slot)
> Status: **Plan, nicht umgesetzt.** Kein Deploy, kein Git-Push. Keine Credentials in diesem Dokument.

---

## (a) Ist-Aufnahme

Das Repo enthält **zwei parallele UI-Stacks**, die sich Panels und Design-Sprache doppeln:

### Stack 1 — `server/` (v1, „Relaunch 2026-10-28/07.11.")

| Datei | Rolle | Umfang |
|---|---|---|
| `mac-monitor-index.php` | Dashboard: Server-Tabs, Gauges, Charts, **Solar-Panel** (`shelly_power`, Chart + Schwellwert-Filter), Halogen-Request-Counter, Gehirn-CT-Panel | 1263 Zeilen, Inline-CSS/JS |
| `calendar.php` | Slot-Kalender (Woche/Tag, Stundenraster, Buchung per Klick, Board-Karten mit Fälligkeit als Blöcke) | 806 Zeilen |
| `board.php` | Ideen-Board (Kanban, Drag&Drop, Filter/Sort, In-Kalender-buchen) | 394 Zeilen |
| `slot-planner.php` | Feldermatrix-Fallback-Planner | 716 Zeilen |
| `slots.php` / `slot-api.php` / `board-api.php` | REST-APIs (Session-gated) | — |
| `auth.php` / `auth-v2.php` / `auth-api.php` | PIN- + Passkey-Login | — |
| `assets/ui.css` | **Design-System Porsche DS v4**: `light-dark()`-Token, Porsche-Next-Font-CDN, Theme via `data-theme` + `localStorage mm_theme` | gemeinsam |
| `assets/glass-theme.css` | Apple-Glass-Overlay (nur Dark) | gemeinsam |
| `assets/ui.js` | Theme-Engine (auto/light/dark, FOUC-frei), Toast, **Login-Modal**, Banner | gemeinsam |
| `assets/brainct.js` | Gehirn-CT-Visualisierung | — |

**Navigation:** Header-Nav (Dashboard / Kalender / Ideen-Board) + separate Mobile-Bottomnav (`mm-bottomnav`) ≤ 760 px.
**Mobile:** Media-Queries pro Seite (760/860 px), Bottomnav, `main`-Padding-Reduktion.
**CSS-Ansatz:** zentralisierte Token in `assets/ui.css` (Porsche-DS-Farbwellen, Radii, Schatten), Legacy-Aliase (`--bg`, `--panel`, …) halten Seiten kompatibel. Glass-Theme additiv darüber.

### Stack 2 — `server-php-v2/` (aktueller Live-Server, API + SPA)

| Datei | Rolle |
|---|---|
| `index.php` | liefert nur `dashboard/index.html` (readfile) |
| `api.php` | Front-Controller `/api/v1/*`: health, machines, capacity, slots, series, tps, reservations, ingest, events, maintenance, agent/discovery |
| `lib.php` / `config.php` | SQLite-Schema, Scheduler-Gewichte, Token-Auth |
| `dashboard/index.html` | 1-Seiten-SPA: KPI-Topbar, Maschinenauswahl, Agent-Empfehlung, Maschinen-Grid, System-Charts (CPU/GPU, RAM/VRAM, TPS), Token-Durchsatz (Halogen Prefill/Decode), Request-Counter, Slot-Timeline, Reservierungs-Formular, TPS-Tabelle, Endpunkte |
| `dashboard/style.css` | **eigenes** Token-Set (`--bg/--panel/--accent`, hell/dunkel/auto via `data-theme`), 257 Zeilen |
| `dashboard/glass-theme.css` | Apple-Glass-Overlay (nur Dark), überschreibt v2-Tokens |
| `dashboard/app.js` | Fetch-Logik gegen `/api/v1`, Chart.js, Theme-Toggle |

**Navigation:** keine — alles eine Scroll-Seite, Panels hintereinander.
**Mobile:** nur **eine** Media-Query (`two-col` → 1 Spalte bei ≤ 1000 px). KPI-Topbar, Charts und Formulare sind nicht mobil-optimiert.
**Fehlend ggü. v1:** Kalender, Ideen-Board, Solar-Panel, Passkey/PIN-Login-Modal, Bottomnav.
**v1-Features ohne v2-Pendant:** Solar (`shelly_power`), Board, Kalender, Slot-Planner-Matrix.

### Kernprobleme

1. **Zwei Design-Systeme** (Porsche-DS-Token in `server/assets/ui.css` vs. ad-hoc-Token in `dashboard/style.css`) → inkonsistente Farben/Radii/Fonts je nach Seite.
2. **Zwei Glass-Theme-Dateien** mit leicht abweichenden Werten.
3. v2-SPA ist eine endlose Scroll-Liste ohne Navigation; Solar/Board/Kalender fehlen komplett.
4. Solar-Daten existieren nur im v1-Pfad (`mac-monitor-data.php` → `shelly_power`); die v2-API hat `power_w` (Geräteleistung), aber **keinen Solar-Endpunkt**.
5. Inline-CSS/JS in v1-Seiten (index 1263 Zeilen) ist schwer wartbar.

---

## (b) Zielbild

**Ein Stack, ein Design-System, vier Areas.** `server-php-v2/` bleibt der Live-Stack; die v1-Panels (Board, Kalender, Solar) werden als v2-Panels nachgezogen — nicht umgekehrt.

1. **Design-System: Porsche-DS-Token gewinnt.**
   `server/assets/ui.css` (Token-Block + Porsche-Next-@font-face + `light-dark()`) wird nach `server-php-v2/dashboard/ui-tokens.css` überführt (identische Dateiinhalte, nur Pfad). `dashboard/style.css` wird auf die Aliase (`--bg`, `--panel`, `--muted`, `--accent`, …) umgestellt — die `ui.css` bereits definiert —, eigenes Token-Set entfällt. Eine Glass-Datei (`glass-theme.css`, Stand 09.11.) live überall.
   Ergebnis: Dark/Light-Wechsel (auto/light/dark, `localStorage mm_theme`, FOUC-freies Pre-Paint-Snippet aus v1) ist auf allen Panels bitgleich.

2. **Moderne Anordnung (Dashboard-Home):**
   - **KPI-Leiste** oben (online, freie Slots, TPS, Requests) — bleibt, wird aber zum Sticky-Header mit Theme-Toggle.
   - **Grid-Layout** (CSS Grid, `repeat(auto-fit, minmax(340px, 1fr))`) statt Stapel: System-Charts (CPU/GPU, RAM/VRAM), Token-Durchsatz, Request-Counter, **Solar-Kachel** (neu in v2), Slot-Timeline.
   - Agent-Empfehlung + Maschinenauswahl als schmales Steuerpult über dem Grid.
   - Reservierungs-Formular und TPS-Tabelle rutschen in einen „Betrieb"-Tab (nicht mehr Home).

3. **Panels:** System (Charts + Maschinen-Grid), Slots (Timeline + Kalender-Ansicht + Reservierung), Board (Kanban aus v1 portiert), Solar (Kachel auf Home + Detailchart).

4. **Mobile:**
   - Bottomnav (wie v1 `mm-bottomnav`) ≤ 760 px auf allen Views.
   - KPI-Leiste: horizontal scrollbar / 2×2-Grid statt 5 nebeneinander.
   - Charts: `height` responsiv (CSS `aspect-ratio` statt fix 220 px), Grid kollabiert zu 1 Spalte bereits ≤ 760 px.
   - Tabellen (`res-table`, `tps-table`): `overflow-x: auto` vorhanden; erste Spalte sticky.
   - Touch-Ziele ≥ 44 px (Buttons, Segmented Controls).

---

## (c) Navigationsstruktur-Vorschlag

```
┌ Topbar: mac-monitor · [Dashboard] [Slots] [Board] [Solar] · ⚙ · 🌙 Theme ┐
│  (Desktop: Tabs in Topbar;  Mobile ≤760px: Bottomnav, Topbar nur Brand+Theme)
├ Dashboard  = KPI-Leiste + Steuerpult + Panel-Grid (System, Token, Requests, Solar-Kachel, Timeline)
├ Slots      = Kalender (Woche/Tag, portiert aus calendar.php) + Timeline + Reservierung + TPS-Tabelle
├ Board      = Kanban (portiert aus board.php)
└ Solar      = Solar-Detail (Power-Chart, Tagesertrag) — nur sichtbar, wenn Solar-Daten vorhanden
```

Realisierung als **Hash-Routing in `app.js`** (`#/dashboard`, `#/slots`, `#/board`, `#/solar`): Panels sind `<section>`-Blöcke in `index.html`, nur das aktive wird angezeigt (`hidden`). Kein Router-Framework, kein Framework — passt zum bestehenden No-Build-Stack und zu bplaced (nur statisch + PHP).

---

## (d) Umsetzungsschritte

Reihenfolge so gewählt, dass jeder Schritt deploybar ist, ohne das Live-System zu brechen.

| # | Schritt | Dateien | Aufwand |
|---|---|---|---|
| 1 | **Token-Konsolidierung:** Porsche-DS-Token aus `server/assets/ui.css` nach `server-php-v2/dashboard/ui-tokens.css` übernehmen; `dashboard/style.css` auf Aliase umstellen (eigenes `:root`/`[data-theme]`-Block löschen); `glass-theme.css` durch v1-Stand (09.11.) ersetzen; Theme-Engine + Pre-Paint-Snippet aus `ui.js`/v1-`<head>` in `dashboard/index.html` integrieren (ersetzt den simplen `theme-toggle`-Button in `app.js`) | `dashboard/ui-tokens.css` (neu), `dashboard/style.css`, `dashboard/glass-theme.css`, `dashboard/index.html`, `dashboard/app.js` | **M** |
| 2 | **Hash-Routing + Navigation:** Topbar-Tabs + Mobile-Bottomnav (CSS aus v1 `ui.css` mitnehmen); `app.js` in `initDashboard()/initSlots()/initBoard()/initSolar()` aufteilen, nur aktiver View pollt/rendert; Polling pausieren, wenn View unsichtbar (`document.hidden` + inaktiver Tab) | `dashboard/index.html`, `dashboard/app.js`, `dashboard/style.css` | **M** |
| 3 | **Dashboard-Grid + Mobile-Härtung:** Panels in CSS-Grid-Layout; KPI-Leiste responsiv (2×2 mobil); Chart-Figuren auf `aspect-ratio` umstellen; `two-col`-Query auf 760 px anpassen; Sticky-Header | `dashboard/index.html`, `dashboard/style.css` | **M** |
| 4 | **Solar-Panel portieren:** (a) v2-Datenpfad: Spalte `solar_w` in `lib.php`-Schema (ALTER/CREATE), `api.php` `/ingest` + `/series` erweitern; Client (`client/monitor.py`) sendet Shelly-Wert zusätzlich — *Separate Teilaufgabe an client-Betreuer, hier nur API-Seite.* (b) Frontend: Solar-Kachel im Home-Grid + `#/solar`-Detailchart; Logik (≤ 2 W → 0, „–" ohne Daten) aus `mac-monitor-index.php` übernehmen | `server-php-v2/lib.php`, `server-php-v2/api.php`, `dashboard/*` | **M** (L gesamt inkl. Client) |
| 5 | **Board-Panel portieren:** `board.php`-Markup/-CSS als `#/board`-Sektion nach `dashboard/index.html` überführen; Datenzugriff: neuer v2-Endpunkt `/api/v1/board` in `api.php` (Gegenstück zu `board-api.php`, gleiche SQLite-Tabelle) **oder** v1-`board-api.php` vorübergehend mitdeployen; Drag&Drop-JS aus `board.php` übernehmen | `dashboard/index.html`, `dashboard/app.js`, `server-php-v2/api.php` | **L** |
| 6 | **Slot-/Kalender-Panel:** Slot-Timeline bleibt; Kalender-Wochenansicht aus `calendar.php` als `#/slots`-Unteransicht portieren (Klick=Buchung); Reservierungs-Form + TPS-Tabelle hierher verschieben; `slot-planner.php`-Matrix als „Matrix"-Link nur verlinken, nicht portieren | `dashboard/index.html`, `dashboard/app.js`, `dashboard/style.css` | **L** |
| 7 | **Feinschliff:** Login-Modal/Passkey aus `ui.js` integrieren (falls v2-Endpunkte token-gated werden — aktuell anonym-lesbar, daher optional); leere Zustände + Fehler-Banner einheitlich; `prefers-reduced-motion` aus v1 übernehmen; Konsistenz-Check Dark/Light auf allen Views | diverse | **S** |

**Reihenfolge-Empfehlung:** 1 → 2 → 3 ist der geschlossene Kern („Redesign v2-Dashboard"), sofort sichtbar, ohne neue Datenquellen. 4–6 sind Portierungen und können einzeln nachziehen. 7 am Ende.

---

## (e) Risiken / Nicht-Ziele

**Risiken**

- **Theme-Umstellung bricht Chart-Farben:** `app.js` liest Chart-Farben vermutlich aus CSS-Variablen/JS-Konstanten; nach Token-Tausch müssen alle `Chart()`-Aufrufe die neuen Token nutzen und auf `mm-theme-change` re-agieren (v1-Muster existiert). → Schritt 1 nur mit visuellem Check beider Themes.
- **bplaced-Caching:** Cache-Header müssen in PHP gesetzt bleiben (bekanntes bplaced-Verhalten, Kommentar in `index.php`); neue CSS-Dateien mit Query-String versionieren (`ui-tokens.css?v=1`).
- **Zwei SQLite-DBs:** v1 (`server/metrics.sqlite`) und v2 (`../data/mac-monitor.sqlite3`) sind getrennt. Board/Kalender-Port auf v2 setzt voraus, dass Board-/Slot-Tabellen in der v2-DB angelegt werden — nicht stumm die v1-DB anbinden (Web-Root-Lage, Berechtigungen).
- **Solar-Datenpfad hängt am Client:** Ohne `solar_w`-Ingest bleibt das Panel leer; Frontend muss „keine Daten" sauber anzeigen, damit Schritt 4a nicht Schritt 4b blockiert.
- **Scope-Kriechen durch v1:** Nicht versuchen, `mac-monitor-index.php` (1263 Zeilen) 1:1 zu portieren — nur die Panels, die in (b) genannt sind.

**Nicht-Ziele**

- Kein Framework/Build-System (kein React/Vite/Tailwind) — No-Build-Stack bleibt, bplaced-tauglich.
- Kein Redesign des v1-Stacks; `server/` wird nicht weiterentwickelt, nur als Referenz/Quelle genutzt.
- Keine Änderung an `client/`-APK oder Python-Collectors über den optionalen Solar-Feld-Anhang hinaus.
- Keine neue Auth-Architektur; bestehendes Token/Session-Modell bleibt.
- Kein Deploy, kein Git-Push aus diesem Plan heraus; Umsetzung erfolgt in separaten, abgenommenen Schritten.
- Gehirn-CT (`brainct.js`) wird nicht portiert (Nice-to-have, eigene Karte wert).
