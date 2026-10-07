# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/de/). Tag = Version der CLI; die
Agent-Version steht pro Release dabei.

## [0.4.0] – YYYY-MM-DD · Agent 0.5.0

**CLI und Agent ändern sich.** `staging` und `push --to staging` brauchen Agent 0.5.0; Pull,
Scan und Push nach Live laufen weiter mit älteren Agents.

### Neu
- `wpsync staging create|refresh|open|status|delete`: Staging-Kopie auf dem Server des Kunden –
  Unterordner im Webroot, eigene Tabellen mit eigenem Präfix, anonymisiert, nach Pull-Profil,
  ohne Uploads (Weiterleitung auf Live). Zugang nur per Einmal-Link mit Auto-Login (5 Minuten,
  einmal gültig, nur am Einstieg der Kopie einlösbar); während eines Jobs und nach dem Verfall
  liefert die Kopie für alles 403
- `wpsync push … --to staging`: Code auf die Kopie, mit Push-Fenster, Snapshot, Health-Check
  und Rollback wie nach Live; Baseline und internes Git bleiben unverändert
- Mehrmals nach Staging pushen ohne `--force`: Die CLI merkt sich den Stand der gepushten
  Einheiten in der Kopie in `.wpsync/staging-base.json` (mit dem Pairing-Secret versiegelt) und
  vergleicht den nächsten Push nach Staging damit. Der Stand verfällt bei `staging create` und
  `staging refresh --code`, nicht bei `staging refresh` ohne Code (Agent-Feld `code_copied_at`).
  Ein Push nach Live liest die Datei nie
- Unterbrochene Läufe: Trifft `staging create`, `refresh` oder `delete` auf einen laufenden Job
  derselben Art, setzt derselbe Befehl ihn fort; sonst Exit 44
- `--json` für `staging`, `push`, `pushes`, `rollback`; `--secret-stdin` für `staging`. Neue
  Exit-Codes 40–44 (Push) und 50–53 (Staging). `wpsync status` meldet die Kopie
- `error.reason` bei Exit 1: `staging_failed`, `staging_state`, `staging_copy`, `foreign_url`

### Geändert
- `wpsync rollback <site>` ohne Push-ID nimmt den neuesten Push nach Live; Staging-Pushes mit
  `--to staging` oder ID. `rollback <id> --to <anderes Ziel>` wird abgelehnt
- Exit-Codes von `push` bei geschlossenem Fenster (40), Konflikt (41), hängendem Push (42),
  automatischem Rollback (43) und belegter Site auf dem Server (44) statt 1. Die lokale
  Site-Sperre bleibt Exit 20 mit `error.reason: "site_locked"`
- Exit 21 (`disk_full`) auch, wenn der Server keinen Platz meldet (HTTP 507) – für `push`
  bisher Exit 1
- `wpsync pushes` zeigt das Ziel jedes Pushs (Spalte ZIEL, `target` in `--json`)
- `push --to` nimmt nur `live` oder `staging`; alles andere ist Exit 2

### Behoben
- Pull von einer Quelle mit PHP vor 8.0.16 und MariaDB ab 10: Die Quelle meldet die Version als
  `5.5.5-10.11.19-MariaDB`, wpsync verlangte von DDEV deshalb `mariadb:5.5` und die lokale
  Umgebung startete nicht. Das Präfix `5.5.5-` wird jetzt übergangen

### Sicherheit
- Die Kopie schreibt nie in Live-Tabellen oder ausserhalb ihres Ordners: eine Prüf-Funktion für
  jede Tabelle und jeden Pfad, kein Präfix, das mit dem Live-Präfix beginnt. Live liefert
  Staging-Tabellen und -Ordner nie aus. Mails, Zahlungs-, Newsletter-Dienste und Live selbst sind
  von der Kopie aus gesperrt, auch als Ziel einer Weiterleitung; eigene Salts, Cookies und
  Cache-Salt
- Nie in der Kopie: `wp-config.php` von Live und ihre Varianten, `.user.ini` und jede `.htaccess`
  eines Unterordners mit Rewrite-Direktiven (sie nähme dem Ordner die Zugangssperre) – auch nicht
  über einen Push nach Staging
- Ein Agent, der in einer Kopie geladen wird, beantwortet keinen Aufruf
- Bekannte Grenzen der Kopie stehen im README unter „Staging auf dem Server“

## [0.3.1] – 2026-10-06 · Agent 0.4.1

**Nur die CLI ändert sich.** Agent 0.4.1 bleibt.

### Behoben
- Container-Modus: Der Pull scheiterte mit Exit 22, wenn der Aufrufer `wp-config.php`
  read-only mountet. Das Post-Setup liest `WP_ENVIRONMENT_TYPE` und `DISABLE_WP_CRON` jetzt
  zuerst mit `wp config get` und setzt sie nur, wenn sie fehlen oder abweichen. Weicht ein Wert
  ab und ist die Datei nicht beschreibbar, bleibt es ein Fehler (Exit 22)

## [0.3.0] – 2026-10-06 · Agent 0.4.1

**Nur die CLI ändert sich (CLI 0.3.0).** Agent 0.4.1 bleibt; Pull, Scan und Status brauchen
weiterhin Agent 0.3.0 oder neuer, `push` 0.4.0.

### Sicherheit
- Pull schreibt keine VCS-Pfade der Quelle (`.git`, `.svn`, `.hg`, auch `.git`-Dateien) in den
  Docroot (W11). Sie fallen schon aus der Delta-Liste („n VCS-Pfade der Quelle übersprungen“),
  ungefragt gelieferte VCS-Pfade brechen den Pull ab, und lokal löscht wpsync keinen. Bisher
  konnte ein manipulierter Agent etwa `wp-content/plugins/a/.git/config` in den Docroot legen
- Pull folgt keinem Symlink mehr (SEC-113). Bisher prüfte er nur den Pfadtext: Legte Code der
  Site einen Symlink in den Docroot (etwa `wp-content/plugins/evil -> ../../.wpsync/history.git`),
  überschrieb die Quelle damit `history.git/config`, und der nächste Schnappschuss führte einen
  Git-Filter im wpsync-Prozess aus – im Server-Modus im OS-Container mit Docker-Socket. Jetzt
  laufen alle Dateioperationen des Pulls über ein `os.Root` auf dem Docroot selbst – nie auf dem
  Site-Ordner darüber, der auch `.wpsync/` enthält. Dateien unter einem Symlink werden
  übersprungen und gemeldet, eine Datei, die ein Symlink ist, wird ersetzt statt ihr Ziel.
  Dasselbe gilt für DB-Zwischenablage, `baseline.json` (Lesen und Schreiben), Push-Journal,
  `.gitignore` des Schnappschusses, `proxy-tmp`, die Uploads-Proxy-Konfiguration unter `.ddev`
  und `wp-config.php` beim DDEV-Setup – auf dem Mac liegt `<site>/` im DDEV-Mount
- Tiefenverteidigung im internen Git: Attribute kommen aus dem leeren Baum (`GIT_ATTR_SOURCE`,
  git ≥ 2.40), eine `.gitattributes` der Site benennt also keinen Filter mehr;
  `info/attributes` wird entfernt und die `config` des Repos vor jedem Aufruf auf die Einträge
  von `git init` zurückgesetzt, falls dort Filter, `include`, `core.fsmonitor` o. Ä. stehen.
  `GIT_COMMON_DIR` zeigt auf das Repo selbst; fremde Einträge (`commondir`, `worktrees/`,
  `config.worktree`, `objects/info/alternates` …) werden entfernt, ein Symlink im Repo bricht ab.
  Bisher genügte eine Datei `commondir`, um Config und Attribute aus dem Docroot zu laden
- Die PHP-Version der Quelle wird mit Präfix und Adressen geprüft (`<major>.<minor>[.…]`), bevor
  sie Teil des WP-CLI-Image-Namens (Container-Modus) bzw. von `--php-version` (DDEV) wird.
  Unzulässige Werte der Quelle enden einheitlich mit Exit 1
- Container-Modus: `--docroot` darf nicht `/`, nicht direkt unter `/` und nicht `.wpsync` oder
  `.git` sein (Exit 2)

### Neu
- **Server-Modus** für den Aufruf als Subprozess (Agentic OS): `--json` für `pair`, `scan`, `pull`,
  `status`, `unpair`, `doctor`, `version` mit Fortschrittszeilen beim Pull, `--secret-stdin`,
  `pair --json --secret-out`, `doctor --server`
- `--driver container` für `pull`, `status`, `list`, `stop`: Pull in einen vorhandenen
  WordPress-Container statt in ein DDEV-Projekt; der SQL-Import läuft dort mit derselben Härtung
  (`--binary-mode --local-infile=0`) wie unter DDEV
- Container-Modus: Fehlt `<docroot>/.htaccess`, legt wpsync den WordPress-Standardblock an
  (sonst 404 auf `/wp-json/`, Elementor-Editor lädt nicht); eine vorhandene bleibt unberührt.
  Einzige Datei, die wpsync ausserhalb von `wp-content/` in den Docroot schreibt
- `scan --uploads-since alle` zieht jedes Upload-Jahr
- Linux-Binary `wpsync_0.3.0_linux_arm64` mit `.sha256` am GitHub-Release
  (`scripts/build-linux.sh`, reproduzierbar)

### Geändert
- Feste Exit-Codes für alle Befehle (README → Server-Modus); bisher endete jeder Fehler mit 1,
  Aufruffehler mit 2. Skripte, die nur „≠ 0“ prüfen, sind nicht betroffen
- SIGTERM bricht einen Pull fortsetzbar ab (Exit 30)
- Das interne Git wartet sich nicht mehr während des Commits (`gc.auto=0`), sondern danach im
  Vordergrund; liegengebliebene Locks eines abgebrochenen Laufs (`index.lock`, `HEAD.lock`,
  `refs/heads/*.lock`, `gc.pid`) räumt der nächste Pull weg. Bisher blockierte ein solcher Lock
  jeden weiteren Pull mit Exit 1
- Scheitert nur der Schnappschuss im internen Git, bleibt der Pull erfolgreich: Warnung auf der
  Ausgabe und `"warnings": ["snapshot_failed"]` im Ergebnis von `pull --json`
- Dateien unter einem per Symlink eingebundenen Ordner im Docroot überspringt der Pull, statt
  abzubrechen: Meldung je Pfad, `"warnings": ["symlink_skipped"]`, der nächste Pull fragt sie
  erneut an
- Pro Site läuft nur ein `pull`, `push` oder `rollback` gleichzeitig (Site-Lock); ein zweiter
  endet sofort mit Exit 20 und `error.reason: "site_locked"` im JSON. Push und Rollback räumen alte git-Locks wie der Pull weg
- Container-Modus: Jeder `docker run` von wpsync läuft mit `--init`, Namen `wpsync-<site>-…` und
  Label `wpsync.site=<Schlüssel des Site-Ordners>`. Nach SIGTERM entfernt wpsync den laufenden Hilfscontainer, beim
  nächsten Pull auch verwaiste eines gekillten Laufs; bisher lief er nach dem Abbruch weiter
- Ist der Mail-Riegel nach einem Pull nicht aktiv und weicht `.ddev` vom geprüften Stand ab,
  hält wpsync die Container jetzt per `docker stop` an, statt die Site laufen zu lassen
- Intern: DDEV-Verhalten inklusive `.ddev`-Prüfung steckt hinter einem Laufzeittreiber; auf dem
  Mac ändern sich Ablage, Meldungen und Rückfragen nicht

### Zu tun nach dem Update
- Nichts auf dem Mac (`brew upgrade wpsync`). Keine Agent-Aktualisierung nötig.

## [0.2.1] – 2026-10-06 · Agent 0.4.1

**Nur der Agent ändert sich.** Agent 0.4.1 ist das eigentliche Update. Die CLI 0.2.1 ändert
nur ihre Versionsnummer und funktioniert wie 0.2.0 mit Agent 0.4.0 und 0.4.1.

### Sicherheit
- Pairing-Secrets stehen nicht mehr im Klartext in der Datenbank (SEC-006). Der Agent speichert
  sie verschlüsselt (libsodium `secretbox`, Format `v1:…`) in `wpsync_pairings`. Der Schlüssel
  kommt aus `wp-config.php` – aus `WPSYNC_KEY`, sonst aus `AUTH_KEY` und `SECURE_AUTH_KEY` – und
  nie aus der Datenbank. Bisher genügte reiner Lesezugriff auf die Datenbank (SQL-Injection in
  einem anderen Plugin, Datenbank-Backup, Hosting-Panel), um Requests im Namen eines Geräts zu
  signieren und bei offenem Push-Fenster Code zu pushen
- Beim Update erweitert der Agent die Spalte `secret` auf `VARCHAR(255)` und verschlüsselt alle
  vorhandenen Secrets einmal. Bestehende Kopplungen bleiben gültig. Ein Secret, das noch im
  Klartext steht, gilt weiter und wird bei der nächsten Benutzung verschlüsselt
- Fehlen die Salts oder stehen sie auf dem WordPress-Standardwert und ist kein `WPSYNC_KEY`
  gesetzt, speichert der Agent wie bisher im Klartext und warnt auf der Admin-Seite
- Die Admin-Seite zeigt pro Gerät, ob sein Secret verschlüsselt, im Klartext oder nicht mehr
  entschlüsselbar ist. Ein nicht entschlüsselbares Pairing wird wie ein widerrufenes behandelt
  (`401 wpsync_unpaired`, die CLI fordert zum neuen Koppeln auf)

### Zu tun nach dem Update
- **Agent 0.4.1 installieren** auf den gekoppelten Sites (Plugin-ZIP im WP-Admin hochladen und
  ersetzen). Neu koppeln ist nicht nötig.
- **Optional, empfohlen:** in `wp-config.php` einen eigenen Schlüssel setzen,
  `define('WPSYNC_KEY', '…');` mit mindestens 32 zufälligen Zeichen (z. B. `openssl rand -hex 32`).
  Dann überstehen die Kopplungen eine Rotation der WordPress-Salts.
- **Nach einer Rotation der Salts** (ohne `WPSYNC_KEY`) oder nach Ändern bzw. Entfernen von
  `WPSYNC_KEY` alle Geräte neu koppeln. Die Admin-Seite markiert betroffene Pairings mit
  „nicht entschlüsselbar – neu koppeln“; alte Einträge dort widerrufen.

## [0.2.0] – 2026-10-06 · Agent 0.4.0

**Agent und CLI gemeinsam aktualisieren.** `wpsync push` braucht Agent 0.4.0. Pull, Scan und
Status funktionieren mit dem neuen Agent auch von einer älteren CLI aus. Die CLI schließt
zusätzlich mehrere Wege, über die Code einer gezogenen Site den Mac erreichen konnte (siehe
Sicherheit).

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
- Der Header `X-Wpsync-Timestamp` muss aus 1–10 Ziffern bestehen; Werte wie `<ts>abc`,
  ` <ts>` oder `+<ts>` lehnt der Agent mit 401 ab (SEC-129).
- Angaben der Site in den Ausgaben von `push`, `pushes` und `rollback` (Pfade, Versionen, Gerät,
  Push-ID, Health-URLs, Fehler von `rescue.php`) erscheinen wie bei `pull` maskiert in
  Anführungszeichen; Steuerzeichen erreichen das Terminal nicht.
- Health-Seiten des Agenten ruft `push` nur auf der gekoppelten Site ab (gleiches Schema, gleicher
  Host mit Port), `health_urls` der Site-Konfiguration nur über http(s); Weiterleitungen nur auf
  denselben Host. Andere Seiten werden mit Hinweis verworfen.
- Ein bestätigter Push lässt sich über `/push/rollback` nur bei offenem Push-Fenster des Geräts
  zurückrollen (sonst 403 `wpsync_push_window`, kein Umweg über `rescue.php`); ein unbestätigter
  immer. Im WP-Admin geht der Rollback weiterhin ohne Fenster (U18).
- `rollback` über `rescue.php` prüft die Rescue-URL aus dem Journal (`.wpsync/pushes/`, von
  Containern beschreibbar) erneut gegen die gekoppelte Site der Konfiguration; Push-ID und Salt
  des Journals müssen das Format des Agenten haben. Sonst kein Aufruf, der Schlüssel bleibt lokal.
- `push` bricht ab, wenn `public`, `wp-content` oder `plugins`/`themes`/`mu-plugins` ein Symlink
  ist, und liest Dateien ohne Symlinks zu folgen; ändert sich eine Datei nach dem Scan, bricht der
  Push vor dem Tausch ab. Ist eine Einheit selbst ein Symlink (z. B. `plugins/x`), überspringt
  `push` sie mit Hinweis und liest sie nie; ausdrücklich genannt bricht der Push ab (U19).
- Fehlermeldungen des Agenten (Code, Text, Weiterleitungsziel) erreichen das Terminal ohne
  Steuer- und Bidi-Zeichen; Umlaute bleiben lesbar. Gilt zentral für alle Befehle.
- Dateinamen mit C1- oder Bidi-Steuerzeichen pusht die CLI nicht und lehnt der Agent ab; die
  Dateiliste im Push-Plan zeigt Namen nur maskiert, wenn sie nicht sicher darstellbar sind.

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
- **`rescue.php`:** Rollback ohne WordPress, falls der gepushte Code die Site lahmlegt – nur für
  den unbestätigten Push (nach dem Tausch, vor der Bestätigung).
- **`wpsync rollback <site> [push-id]`** und **`wpsync pushes <site>`**; Snapshots der letzten
  3 bestätigten Pushes bleiben bis zu 14 Tage.
- **Konflikterkennung:** Hat sich eine Einheit auf der Site seit dem letzten Pull geändert,
  bricht der Push ab (`--force` überschreibt bewusst).
- **Admin-Seite:** Push-Fenster pro Gerät, Protokoll der Pushes, Zurückrollen.
- `wpsync trust <site>`: Abweichungen in `.ddev` ansehen (Hooks, Host-Kommandos und Mounts
  hervorgehoben) und freigeben; ohne Terminal nur mit `--fingerprint`, nie über `--yes`

### Geändert
- Der Agent ist nicht mehr rein lesend: im Push-Fenster schreibt er Code-Verzeichnisse unter
  `wp-content/plugins`, `themes` und `mu-plugins`. Datenbank und Uploads schreibt er weiterhin nie.
- `/ping` und `/delta` liefern `health_urls`.
- Neue eigene Tabelle `wpsync_pushes`, neue Spalte `push_until` in `wpsync_pairings`, neuer
  Ordner `wp-content/wpsync-push-<zufall>/`. Alles davon bleibt bei Pull und Scan auf dem Server.
- Das Plugin-ZIP enthält zusätzlich `rescue.php`.
- Das bisherige `.git` im Site-Ordner verschiebt der erste Pull nach dem Update unverändert nach
  `~/wpsync-sites/.wpsync-git/<site>.alt-<datum>.git`, ohne darin git auszuführen. Die Historie
  beginnt neu; `git log` im Site-Ordner zeigt nichts mehr (Historie: siehe README › Lokale Ablage)

### Zu tun nach dem Update
- **CLI aktualisieren** (`brew update && brew upgrade wpsync`). Braucht das `docker`-CLI.
- **Agent 0.4.0 installieren** auf den gekoppelten Sites (Plugin-ZIP im WP-Admin hochladen und
  ersetzen); `wpsync push` braucht ihn.
- **Einmal je Site `.ddev` freigeben:** Der erste Pull jeder bestehenden Site stoppt deren
  Container ohne ddev, fragt einmal nach der Übernahme des heutigen `.ddev` (ohne Terminal:
  `wpsync trust <site>`) und startet DDEV neu. Eigene Anpassungen in `.ddev` (Add-ons,
  `config.local.yaml`, eigene Compose-Dateien) später jeweils einmal mit `wpsync trust` freigeben.
- Das verschobene alte Git-Repo (`.wpsync-git/<site>.alt-<datum>.git`) wird nicht mehr
  gebraucht und darf gelöscht werden – nicht mit git öffnen, die Site konnte es verändern.

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
