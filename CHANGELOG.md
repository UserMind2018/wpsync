# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/de/). Tag = Version der CLI; die
Agent-Version steht pro Release dabei.

## [0.1.7] – 2026-10-05 · Agent 0.3.0

**Agent und CLI gemeinsam aktualisieren.** Der neue Agent pseudonymisiert auch für ein älteres
CLI – dort gibt es dann keinen lokalen Admin, und kein übernommenes Konto ist anmeldbar.

### Neu
- **Anonymisierung:** Personenbezogene Daten werden auf der Site pseudonymisiert, bevor sie den
  Server verlassen – Benutzer, Kommentare, `admin_email` und WooCommerce (HPOS, klassische
  Bestell-Postmeta, Customer-Lookup, Sessions, API-Keys, Payment-Tokens). Deterministisch: dieselbe
  Person bekommt in jeder Tabelle und bei jedem Pull dasselbe Pseudonym
- Lokaler Admin `wpsync` / `wpsync` nach jedem anonymisierten Pull
- `wpsync pull --no-anonymize` zieht Klartext – mit Rückfrage, ohne Terminal zusätzlich `--yes`
- `scan` und `pull` nennen PII-Tabellen, die mit Daten gezogen werden und keine Regel haben

### Geändert
- Der erste Pull nach dem Update lädt `users`, `usermeta`, `options`, `posts` und `postmeta` einmal neu
- `pull` und `status` brechen gegen einen Agent vor 0.3.0 ab, statt Klartext zu ziehen

### Behoben
- `wpsync scan` wurde auf Sites mit vielen Dateien unter `wp-content` nie fertig: Der Größenscan
  des Infosheets lief pro Häppchen die ganze bisherige Strecke erneut ab und schaffte ab etwa
  21.000 Dateien nur noch eine Datei pro Häppchen. Er setzt jetzt am Pfad der zuletzt gezählten
  Datei fort. **Agent aktualisieren**; ein laufender Scan eines älteren Agents zählt die Dateien neu

## [0.1.6] – 2026-10-02 · Agent 0.2.1

Sicherheits-Release nach dem Audit des Agents 0.2.0. **Agent auf allen gekoppelten Sites aktualisieren.**

### Sicherheit
- „Password Protected" liess sich mit `?x=/wp-json/wpsync/v1` an jeder URL abschalten. Der
  Bypass gilt jetzt nur noch für exakt bekannte wpsync-Routen, ohne Signatur-Header nur für
  den Namespace-Index und `/pair`
- `/files` wendet dieselben festen Ausschlüsse an wie die Dateiliste. Neu ausgeschlossen:
  `.svn`, `.hg`, `backup(s)`, `backup-*`, `.env*`, `.htpasswd`, SQL-Dumps ausserhalb von
  Plugins/Themes, Archive direkt unter `wp-content`. Alle Vergleiche ohne Gross-/Kleinschreibung
- Der Agent verlangt HTTPS. Lokale Umgebungen: `define('WPSYNC_ALLOW_HTTP', true);`.
  `wpsync pair` koppelt über `http://` nur noch mit `--insecure`
- Ein Pairing-Code lässt sich auch parallel nur einmal einlösen; höchstens ein Versuch pro Sekunde
- Parameter werden nur noch aus dem signierten Body gelesen
- Tabellen fremder Installationen in derselben Datenbank und jede `wpsync_*`-Tabelle bleiben auf dem Server
- Quelldateien des Agents lassen sich nicht mehr direkt aufrufen

### Behoben
- `/pair` meldete Erfolg, obwohl das Pairing bei bestimmten Gerätenamen nicht gespeichert wurde
- Views werden nicht mehr wie Tabellen gedumpt
- `/db` beachtet den Tabellenmodus des Profils (`skip`, `structure`)
- Ein Pfad mit Nullbyte brach den Dateistream ab
- Anonyme Requests lösten `CREATE TABLE` aus; die Authentifizierung lief pro Request doppelt
- Das tägliche Inventar läuft nur noch auf gekoppelten Sites; ein hängender Job wird nach 1 h ersetzt

### Hinweis
- Erkennt WordPress hinter einem TLS-Proxy die Verbindung nicht als HTTPS (`is_ssl()`),
  antwortet der Agent mit `400 wpsync_https`. Dann die Proxy-Erkennung in `wp-config.php`
  einrichten.

## [0.1.5] – 2026-10-01 · Agent 0.2.0

### Hinzugefügt
- `wpsync list` zeigt alle lokalen wpsync-Umgebungen mit Status, lokaler und Live-URL –
  auch solche, deren Pairing schon entfernt ist
- `wpsync stop <site>…` und `wpsync stop --all` stoppen Umgebungen, ohne dass man in die
  einzelnen DDEV-Projekte wechseln muss

## [0.1.4] – 2026-09-30 · Agent 0.2.0

### Geändert
- Der Mail-Riegel ist fester Bestandteil von wpsync: gesucht wird nur noch
  `WPSYNC_MAILGUARD`, sonst gilt der mitgelieferte. Keine Abhängigkeit mehr von
  Ordnern außerhalb von wpsync.

## [0.1.3] – 2026-09-30 · Agent 0.2.0

### Hinzugefügt
- Mitgelieferter local-mailguard; eigener Pfad per `WPSYNC_MAILGUARD`. `wpsync doctor`
  zeigt die genutzte Datei.

## [0.1.2] – 2026-09-30 · Agent 0.2.0

### Hinzugefügt
- Agent erstellt ein Infosheet im Hintergrund (Häppchen ≤ 5 s, WP-Cron täglich, nach dem
  Pairing, per `wpsync scan --refresh` auch ohne WP-Cron) und stuft Tabellen und Post-Typen ein
- `wpsync scan` mit Presets (`vollstaendig`, `ohne-transaktionen`, `nur-content`) und
  Checkliste im Terminal; Profil in der Site-Konfiguration
- Pull nach Profil: Tabellen nur als Struktur, Post-Typen zeilenweise gefiltert (inkl.
  postmeta), Plugins/Themes/Upload-Jahre ausgelassen, abgewählte aktive Plugins lokal deaktiviert
- Uploads-Proxy in DDEV (nginx, 2 Requests/s, jede Datei nur einmal)
- `wpsync status` und `wpsync pull --dry-run`

### Behoben
- Plugin-serialisierte Optionen (z. B. Borlabs) erhalten die lokale URL durch einen zweiten
  Search-Replace mit geladenen Plugins

### Geändert
- `wpsync pull` verlangt ein Profil – bestehende Sites einmal `wpsync scan <site>` ausführen
- Agent auf der Site auf 0.2.0 aktualisieren

## [0.1.0] – 2026-09-29 · Agent 0.1.0

### Hinzugefügt
- Agent (PHP ≥ 7.4) und CLI: `setup`, `doctor`, `pair`, `unpair`, `list`, `pull`
- Pairing per Einmal-Code (10 min, 5 Versuche), Secret nur in der macOS-Keychain;
  HMAC-Signatur mit Nonce-Tabelle
- Keyset-Pagination, fortsetzbarer DB- und Datei-Download, seitenweiser Delta-Check mit
  Zeitbudget, Drosselung, Backoff und Sofort-Abbruch bei vermuteter IP-Sperre
- Pull bricht ab und stoppt das Projekt, wenn local-mailguard im Container nicht aktiv ist
- Internes Site-Git ohne `wp-content/cache/` und Upgrade-Ordner
