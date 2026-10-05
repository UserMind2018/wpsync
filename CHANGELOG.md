# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/de/). Tag = Version der CLI; die
Agent-Version steht pro Release dabei.

## [Unveröffentlicht] · Agent 0.4.0

**Agent und CLI gemeinsam aktualisieren.** `wpsync push` braucht Agent 0.4.0. Pull, Scan und
Status funktionieren mit dem neuen Agent auch von einer älteren CLI aus.

### Neu
- **Code pushen:** `wpsync push <site> code` bringt lokal geänderte Plugins, Themes und
  mu-plugins als ganze Verzeichnisse auf die Site. Hochgeladen werden nur geänderte Dateien,
  getauscht wird per `rename`, das alte Verzeichnis bleibt als Snapshot.
- **Lokal neue Verzeichnisse** (vom letzten Pull nicht geliefert) gehen nur mit, wenn sie
  ausdrücklich genannt sind (`wpsync push <site> code plugins/<slug>`); ohne Nennung meldet die
  CLI sie als übersprungen. Liegt ein gleichnamiges Verzeichnis schon auf der Site, ist das ein
  Konflikt.
- **Push-Fenster:** Schreiben geht nur, solange ein Administrator im WP-Admin für das Gerät ein
  Fenster geöffnet hat (15 Minuten, 1 Stunde oder 8 Stunden).
- **Health-Check und automatischer Rollback:** Die CLI ruft Startseite, Login und bei
  WooCommerce Shop, Warenkorb und Kasse vor und nach dem Tausch auf und rollt zurück, wenn eine
  Seite schlechter wird. Weitere Seiten: `health_urls` in der Site-Konfiguration.
- **`rescue.php`:** Rollback ohne WordPress, falls der gepushte Code die Site lahmlegt.
- **`wpsync rollback <site> [push-id]`** und **`wpsync pushes <site>`**; Snapshots der letzten
  3 bestätigten Pushes bleiben bis zu 14 Tage.
- **Konflikterkennung:** Hat sich eine Einheit auf der Site seit dem letzten Pull geändert,
  bricht der Push ab (`--force` überschreibt bewusst).
- **Admin-Seite:** Push-Fenster pro Gerät, Protokoll der Pushes, Zurückrollen.

### Geändert
- Der Agent ist nicht mehr rein lesend: im Push-Fenster schreibt er Code-Verzeichnisse unter
  `wp-content/plugins`, `themes` und `mu-plugins`. Datenbank und Uploads schreibt er weiterhin nie.
- `/ping` und `/delta` liefern `health_urls`.
- Neue eigene Tabelle `wpsync_pushes`, neue Spalte `push_until` in `wpsync_pairings`, neuer
  Ordner `wp-content/wpsync-push-<zufall>/`. Alles davon bleibt bei Pull und Scan auf dem Server.
- Das Plugin-ZIP enthält zusätzlich `rescue.php`.

### Sicherheit
- Der Header `X-Wpsync-Timestamp` muss aus 1–10 Ziffern bestehen; Werte wie `<ts>abc`,
  ` <ts>` oder `+<ts>` lehnt der Agent mit 401 ab (SEC-129).
- Angaben der Site in den Ausgaben von `push`, `pushes` und `rollback` (Pfade, Versionen, Gerät,
  Push-ID, Health-URLs, Fehler von `rescue.php`) erscheinen wie bei `pull` maskiert in
  Anführungszeichen; Steuerzeichen erreichen das Terminal nicht.
- Health-Seiten des Agenten ruft `push` nur auf der gekoppelten Site ab (gleiches Schema, gleicher
  Host mit Port), `health_urls` der Site-Konfiguration nur über http(s); Weiterleitungen nur auf
  denselben Host. Andere Seiten werden mit Hinweis verworfen.

## [Unveröffentlicht]

**Nur CLI, Agent unverändert.**

### Sicherheit
- Der DB-Import führte Client-Kommandos aus dem Dump der Site aus: Eine Zeile `\! …` lief als
  Shell-Kommando im db-Container, `source` las Dateien, `LOAD DATA LOCAL` und `INTO OUTFILE`
  griffen auf Dateien zu. Der Import läuft jetzt mit `--binary-mode --local-infile=0` und als
  DDEV-Nutzer `db` statt `root`. Solche Zeilen brechen den Pull mit „Datenbank-Import
  abgebrochen“ ab, statt ausgeführt zu werden. Bei MySQL-Sites erscheint pro Import die
  Client-Warnung zum Passwort auf der Kommandozeile (DDEVs Standard-Passwort, unkritisch)
- `pull` und `status` brechen ab, wenn die Site einen Tabellennamen mit unzulässigen Zeichen
  liefert (erlaubt: Buchstaben, Ziffern, `_`, `$`, höchstens 64 Zeichen). Bisher wurde der Name
  ungeprüft zum Dateinamen; eine manipulierte Site konnte so Dateien ausserhalb des
  Projektordners schreiben. Zu tun ist nichts – erscheint die Meldung, nennt sie den Ausweg
  über `tables.overrides`
- `pull` und `status` brechen ab, wenn die Site einen Tabellenpräfix oder eine WordPress- bzw.
  Website-Adresse in unerwarteter Form meldet. Diese Angaben gehen als Argumente an WP-CLI, das
  einen Wert mit `--` am Anfang als eigene Option liest; eine manipulierte Site konnte so PHP-Code
  im web-Container ausführen, bevor die Dateien geladen waren. Zu tun ist nichts, ausser die
  Meldung erscheint – dann die Angaben auf der Site prüfen. Sites mit leerem Tabellenpräfix
  bekommen jetzt eine klare Meldung statt einer lokal unbrauchbaren Kopie
- **Code der Site konnte den Mac erreichen:** web- und db-Container konnten in `.ddev` schreiben,
  etwa eine `config.*.yaml` mit `exec-host`-Hook, die DDEV beim nächsten Aufruf als dein Benutzer
  auf dem Mac ausführte. Jetzt ist `.ddev` in beiden Containern schreibgeschützt (neue Datei
  `.ddev/docker-compose.wpsync-hardening.yaml`; Snapshots funktionieren weiter), und `wpsync`
  prüft vor jedem ddev-Aufruf die Dateien, die DDEV auf dem Mac auswertet, gegen den zuletzt
  geprüften Stand. Bei einer Abweichung läuft kein ddev-Befehl. `wpsync list` ruft DDEV ohne
  Hooks auf, `wpsync stop` hält eine abweichende Site ohne ddev an
- **Code der Site konnte den Mac über das interne Git erreichen:** Das Schnappschuss-Repo lag als
  `.git` im Site-Ordner, den der web-Container beschreiben kann. Ein dort angelegter Hook, ein
  `core.fsmonitor`- oder Filter-Eintrag lief beim Auto-Commit am Ende desselben Pulls als dein
  Benutzer auf dem Mac. Das Repo liegt jetzt unter `~/wpsync-sites/.wpsync-git/<site>.git`,
  ausserhalb jedes Containers; `wpsync` ruft git nur noch mit ausdrücklichem Repo und Arbeitsbaum
  und ohne deine globale git-Config auf. Ein `.git` im Site-Ordner wird nicht mehr benutzt, sondern
  bei jedem Pull gemeldet. Ordner mit eigenem `.git` (z. B. ein Plugin als Git-Checkout) lässt der
  Schnappschuss aus und meldet sie: Ab dem zweiten Pull hätte git darin sonst deren Config und Hooks
  ausgeführt. Ein solches Repo ohne Commit lässt den Schnappschuss auch nicht mehr scheitern

### Geändert
- Das bisherige `.git` im Site-Ordner verschiebt der erste Pull nach dem Update unverändert nach
  `~/wpsync-sites/.wpsync-git/<site>.alt-<datum>.git`, ohne darin git auszuführen. Die Historie
  beginnt neu; `git log` im Site-Ordner zeigt nichts mehr (Historie: siehe README › Lokale Ablage)

### Neu
- `wpsync trust <site>`: Abweichungen in `.ddev` ansehen (Hooks, Host-Kommandos und Mounts
  hervorgehoben) und freigeben; ohne Terminal nur mit `--fingerprint`, nie über `--yes`

**Zu tun nach dem Update:** CLI aktualisieren. Der erste Pull jeder bestehenden Site stoppt
deren Container ohne ddev, fragt einmal nach der Übernahme des heutigen `.ddev` (ohne Terminal:
`wpsync trust <site>`) und startet DDEV neu. Eigene Anpassungen in `.ddev` (Add-ons,
`config.local.yaml`, eigene Compose-Dateien) später jeweils einmal mit `wpsync trust` freigeben.
Braucht das `docker`-CLI. Das verschobene alte Git-Repo (`.wpsync-git/<site>.alt-<datum>.git`)
wird nicht mehr gebraucht und darf gelöscht werden – nicht mit git öffnen, die Site konnte es
verändern.

## [0.1.8] – 2026-10-05 · Agent 0.3.1

**Agent auf allen gekoppelten Sites aktualisieren.** Die CLI ist bis auf die Versionsnummer unverändert.

### Behoben
- **Anonymisierung:** Bei klassischer Bestell-Speicherung (ohne HPOS) blieb der User-Agent aus
  der WooCommerce-Bestellzuordnung (`_wc_order_attribution_user_agent`) in `postmeta` im
  Klartext. Die Regel galt nur für `wc_orders_meta`. Die Regelversion steigt auf 2, dadurch
  laden alle Sites die Tabellen mit Regel beim nächsten Pull einmal neu.

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
