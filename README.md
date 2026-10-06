# wpsync – WordPress Live ↔ Lokal

`wpsync` holt eine produktive WordPress-Site in ein lokales DDEV-Projekt – schonend für den
Server, fortsetzbar nach Abbrüchen und so, dass lokal keine Mail an echte Empfänger rausgeht.

Das Projekt besteht aus zwei Teilen:

| Teil | Ordner | Läuft auf | Aufgabe |
|---|---|---|---|
| **CLI** `wpsync` | `cli/` (Go) | deinem Mac | koppeln, scannen, ziehen, lokales DDEV-Projekt einrichten, Code pushen |
| **Agent** `wpsync-agent` | `agent/` (PHP-Plugin) | der Live-Site | signierte Endpunkte für Infosheet, Delta, DB und Dateien; im Push-Fenster zusätzlich für Code |

Beim Pull liest der Agent nur und schreibt ausschliesslich in eigene Tabellen (`wpsync_*`).
Schreiben kann er einzig über `wpsync push`: ganze Plugin-, Theme- und mu-plugins-Verzeichnisse,
und nur solange ein Administrator im WP-Admin ein Push-Fenster geöffnet hat. Datenbank und
Uploads der Live-Site schreibt wpsync nie.

---

## Inhalt

- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Schnellstart](#schnellstart)
- [Befehle](#befehle)
- [Pull-Profile](#pull-profile)
- [Was beim Pull passiert](#was-beim-pull-passiert)
- [Code pushen](#code-pushen)
- [Sicherheit](#sicherheit)
- [Serverschonung und IP-Sperren](#serverschonung-und-ip-sperren)
- [Lokale Ablage und Konfiguration](#lokale-ablage-und-konfiguration)
- [Fehlerbehebung](#fehlerbehebung)
- [Entwicklung](#entwicklung)
- [Release](#release)

---

## Voraussetzungen

**Mac (CLI)**

- macOS (Apple Silicon oder Intel)
- Docker ≥ 25 – empfohlen [OrbStack](https://orbstack.dev) (`brew install --cask orbstack`)
- [DDEV](https://ddev.com) (`brew install ddev/ddev/ddev`)
- `mkcert` (kommt mit DDEV)
Den Mail-Riegel (local-mailguard) bringt `wpsync` selbst mit – siehe [Mail-Riegel](#mail-riegel).

**Live-Site (Agent)**

- WordPress ≥ 5.9, PHP ≥ 7.4
- Admin-Zugang, um ein Plugin zu installieren
- Optional: WP-CLI (für `wp wpsync pair-code`)

---

## Installation

### CLI per Homebrew (empfohlen)

Homebrew lädt den Quellcode des Releases und baut lokal (Go wird dafür automatisch
installiert; die Xcode Command Line Tools müssen zur macOS-Version passen).

```sh
brew tap usermind2018/tap https://github.com/UserMind2018/homebrew-tap.git
brew install usermind2018/tap/wpsync
wpsync version
```

Update: `brew update && brew upgrade wpsync`. Die Agent-ZIP liegt danach unter
`$(brew --prefix)/share/wpsync/wpsync-agent.zip`.

### CLI aus dem Release

Unter [Releases](../../releases) liegen fertige Binaries
(`wpsync_<version>_darwin_arm64.tar.gz` bzw. `_amd64`) samt `checksums.txt`:

```sh
tar -xzf wpsync_0.1.4_darwin_arm64.tar.gz
sudo mv wpsync /usr/local/bin/
```

> Per Browser geladene Binaries markiert macOS als „aus dem Internet“. Einmalig:
> `xattr -d com.apple.quarantine /usr/local/bin/wpsync`

### CLI aus dem Quellcode

```sh
cd cli
go build -o bin/wpsync ./cmd/wpsync
```

### Agent auf der Live-Site

1. `wpsync-agent-<version>.zip` aus dem Release laden (oder selbst bauen: `agent/build.sh`).
2. WP-Admin → Plugins → Installieren → Plugin hochladen → aktivieren.
3. Unter **Werkzeuge → wpsync** erscheint die Seite für Pairing-Codes und gekoppelte Geräte.

---

## Schnellstart

```sh
# 1. Einmal pro Mac (einziger Moment mit sudo)
wpsync setup
wpsync doctor

# 2. Auf der Site: Werkzeuge → wpsync → „Pairing-Code erzeugen“
#    (oder per SSH: wp wpsync pair-code)
wpsync pair https://www.example.com <code>

# 3. Ansehen, was auf der Site liegt, und auswählen, was gezogen wird
wpsync scan example-com

# 4. Ziehen
wpsync pull example-com
```

Danach läuft die Site unter `https://example-com.ddev.site` in `~/wpsync-sites/example-com/`.

---

## Befehle

| Befehl | Was er tut |
|---|---|
| `wpsync setup` | Einmalig pro Mac: legt `/etc/resolver/ddev.site` an (umgeht den DNS-Rebind-Schutz von Routern wie der FRITZ!Box), führt `mkcert -install` aus, prüft Docker und DDEV. Fragt einmal nach dem Passwort. |
| `wpsync doctor` | Prüft ohne sudo: Resolver, Docker ≥ 25, DDEV, local-mailguard, freie Ports. Nennt pro Problem die Lösung, Exit-Code ≠ 0 bei Fehlern. |
| `wpsync pair <url> <code> [--name n] [--device d] [--insecure]` | Koppelt eine Site. Folgt Redirects und speichert die kanonische URL; das Secret landet in der macOS-Keychain. Der Name wird sonst aus der URL abgeleitet (`www.example.com` → `example-com`). Nur über `https://`; `--insecure` erlaubt `http://` für lokale Testumgebungen. |
| `wpsync unpair <site>` | Entfernt Konfiguration und Keychain-Eintrag lokal. Das Pairing danach im WP-Admin widerrufen. |
| `wpsync list` | Alle lokalen wpsync-Umgebungen (DDEV-Projekte unter `~/wpsync-sites`) und gekoppelten Sites: Status (`läuft`, `pausiert`, `gestoppt`, `nicht angelegt`), lokale URL der laufenden, Live-URL. Andere DDEV-Projekte erscheinen nicht. |
| `wpsync stop <site>… \| --all` | Stoppt einzelne Umgebungen oder mit `--all` alle laufenden – per `ddev stop`, Datenbank und Dateien bleiben erhalten. Weicht `.ddev` einer Site vom geprüften Stand ab, hält `wpsync` sie ohne ddev per `docker stop` an, meldet das und endet mit Exit-Code ≠ 0; die übrigen werden normal gestoppt. Wieder starten: `ddev start` im Site-Ordner oder der nächste `wpsync pull`. |
| `wpsync scan <site> [--refresh] [--preset p] [--uploads-since JJJJ]` | Holt das Infosheet (Plugins, Tabellen mit Einstufung, Post-Typen, Uploads pro Jahr, Auffälligkeiten) und fragt im Terminal Preset und Checkliste ab. Ohne Terminal: `--preset`. `--refresh` lässt die Site das Infosheet neu erstellen (nötig, wenn WP-Cron aus ist). Speichert das Profil. |
| `wpsync pull <site> [--full] [--yes] [--dry-run] [--no-anonymize]` | Zieht nach Profil. Ohne Profil Abbruch mit Hinweis auf `scan`. `--full` ignoriert die Baseline, `--yes` behandelt neue Tabellen/Plugins nach der Preset-Regel ohne Rückfrage, `--dry-run` zeigt nur an (wie `status`). `--no-anonymize` zieht personenbezogene Daten im Klartext – fragt nach, ohne Terminal zusätzlich `--yes`. |
| `wpsync status <site>` | Was sich seit dem letzten Pull auf der Site geändert hat – Dateien und Tabellen, ohne Inhalte zu übertragen. |
| `wpsync trust <site> [--fingerprint fp]` | Zeigt, wie `.ddev` der Site vom geprüften Stand abweicht (Hooks, Host-Kommandos, zusätzliche Mounts hervorgehoben), und gibt den angezeigten Stand nach Rückfrage frei. Ohne Terminal nur mit dem angezeigten `--fingerprint`; `--yes` gibt nie frei. Siehe [Sicherheit](#sicherheit). |
| `wpsync push <site> code [einheit…] [--dry-run] [--force] [--yes] [--allow-version-change]` | Bringt lokal geänderte Plugins, Themes und mu-plugins als ganze Verzeichnisse auf die Site. Ohne Einheiten: alle geänderten, die der letzte Pull geliefert hat – lokal neue Verzeichnisse nur, wenn sie ausdrücklich genannt sind. Braucht ein offenes Push-Fenster. `--dry-run` zeigt nur den Plan, `--force` überschreibt einen Stand, der sich auf der Site seit dem letzten Pull geändert hat. Details: [Code pushen](#code-pushen). |
| `wpsync pushes <site> [--confirm <id>]` | Protokoll der Pushes mit Status. `--confirm` markiert einen getauschten, aber nicht bestätigten Push als in Ordnung. |
| `wpsync rollback <site> [push-id]` | Nimmt den letzten bzw. den genannten Push zurück – über den Agent, und wenn WordPress nicht mehr antwortet über `rescue.php`. |
| `wpsync version` | Version der CLI. |

---

## Pull-Profile

Jede Site braucht ein Profil, bevor sie gezogen werden kann. `wpsync scan` legt es an. Es
speichert nur ein **Preset** plus Abweichungen und wird bei jedem Scan und Pull gegen das
aktuelle Infosheet aufgelöst.

### Presets

| Preset | Tabellen ohne Daten | Post-Typen nicht gezogen | Plugins/Themes | Uploads |
|---|---|---|---|---|
| `vollstaendig` | keine | keine | alle | alle Jahre |
| `ohne-transaktionen` (Standard) | Einstufung `pii`, `log`, `cache`, `backup` | Einstufung `log`, `pii` (z. B. Revisionen, Bestellungen) | nur aktive | neueste Jahre bis 1 GB, Rest per Proxy |
| `nur-content` | zusätzlich `unknown` | wie Standard | nur aktive | wie Standard |

Die Kern-Tabellen `posts`, `postmeta`, `terms`, `term_taxonomy`, `term_relationships`,
`termmeta`, `options`, `users` und `usermeta` kommen **immer mit Daten** – personenbezogene
Werte darin pseudonymisiert, siehe [Anonymisierung](#anonymisierung).

### Regeln

- Abgewählte Tabellen kommen als **Struktur ohne Daten** – Plugins finden ihre Tabellen, nur leer.
- Abgewählte Post-Typen werden **zeilenweise** gefiltert: `posts` samt zugehöriger `postmeta`,
  `term_relationships` und `comments`.
- Ein abgewähltes, aber auf der Site aktives Plugin wird lokal **deaktiviert**.
- Tauchen neue Tabellen, Plugins oder Post-Typen auf, fragen `scan` und `pull` nach
  (`--yes` übernimmt die Preset-Regel).

### Format

Das Profil steht unter `profile:` in `~/.config/wpsync/sites/<site>.yaml` und darf von Hand
bearbeitet werden:

```yaml
name: example-com
url: https://www.example.com
key_id: 0123456789abcdef
rps: 1                           # Requests pro Sekunde gegen diese Site
profile:
  preset: ohne-transaktionen     # vollstaendig | ohne-transaktionen | nur-content
  tables:
    overrides:                   # Abweichung vom Preset: full | structure | skip
      wp_comments: full
  post_types:
    exclude: [jobpost]           # zusätzlich nicht ziehen
    include: [shop_order]        # trotz Preset ziehen
  plugins:
    exclude: [duplicator-pro]
  themes:
    include: [twentytwentyfive]
  uploads:
    since: "2025"                # ältere Jahre lädt der Uploads-Proxy bei Bedarf
    proxy: true
  seen:                          # Stand beim letzten Scan – verwaltet wpsync
    tables: [wp_comments, wp_posts]
```

### Anonymisierung

Personenbezogene Daten werden **auf der Site** ersetzt, bevor sie den Server verlassen – in jedem
Preset, ohne Einstellung im Profil.

| Bereich | Was ersetzt wird |
|---|---|
| Benutzer (`users`, `usermeta`) | Login, Nicename, E-Mail, Anzeigename, Vor-/Nachname, Website, Passwort-Hash, Sitzungen, Application Passwords, Rechnungs- und Lieferadresse |
| Kommentare | Autor, E-Mail, URL, IP, User-Agent; der Text von WooCommerce-Bestellnotizen |
| Einstellungen | `admin_email`, `new_admin_email` |
| WooCommerce | Adressen, E-Mail, Telefon, IP, Kundennotiz, Order-Key, Transaktions-ID in HPOS-Tabellen **und** klassischer Bestell-Postmeta; Customer-Lookup; Sessions, API-Keys, Payment-Tokens, Webhook-Secrets |

- **Deterministisch:** Dieselbe Person bekommt überall und bei jedem Pull dasselbe Pseudonym
  (`user-3f2a…@example.invalid`), Bestellungen bleiben ihrem Kunden zugeordnet. Der Schlüssel dafür
  liegt nur auf der Site.
- **Unverändert:** IDs, Datumswerte, Beträge, Land und Bundesland, Rollen, Kommentartexte.
- **Login lokal:** Kein übernommenes Konto ist anmeldbar. Jeder Pull legt den Admin
  **`wpsync` / `wpsync`** an und nennt ihn am Ende.
- **PII-Tabellen** (Bestellungen, Formular-Einträge) bleiben im Standard-Preset ohne Daten. Wer sie
  im Scan anhakt, bekommt sie pseudonymisiert, **wenn eine Regel existiert**. Für
  Formular-Plugins gibt es keine – `scan` und `pull` weisen solche Tabellen als `KLARTEXT` aus.
- **Klartext:** `wpsync pull <site> --no-anonymize` – nur für diesen einen Pull, mit Rückfrage.
  Der nächste Pull ohne das Flag lädt die betroffenen Tabellen wieder pseudonymisiert.

Grenzen: Erkannt wird nur, wofür es eine Regel gibt. Metadaten von Zahlungs- oder
Membership-Plugins, Freitext in Beiträgen, Zugangsdaten in Plugin-Optionen und hochgeladene
Dateien bleiben, wie sie sind. Autoren heissen lokal „Nutzer ab12cd".

Voraussetzung: Agent ≥ 0.3.0. Gegen einen älteren Agent bricht `pull` ab, statt Klartext zu ziehen.

### Uploads-Proxy

Nicht gezogene Upload-Jahre fehlen lokal nicht sichtbar: nginx im DDEV-Container holt eine
fehlende Datei beim ersten Aufruf von der Live-Site (höchstens 2 Requests/s, jede Datei nur
einmal) und legt sie lokal ab.

---

## Was beim Pull passiert

1. **Infosheet** holen und mit dem Profil abgleichen.
2. **`.ddev` prüfen**, bevor irgendein ddev-Befehl läuft: Container, die `.ddev` noch beschreiben
   können, werden ohne ddev per `docker` gestoppt; die Dateien, die DDEV auf dem Mac auswertet,
   müssen dem geprüften Stand entsprechen, sonst Abbruch. Dieselbe Prüfung läuft vor jedem
   weiteren ddev-Aufruf des Pulls. wpsync-eigene Compose-Dateien (Mailguard, Härtung) werden auf
   den erwarteten Inhalt gebracht; dann startet DDEV neu.
3. **Erstlauf:** DDEV-Projekt mit PHP- und DB-Version der Quelle, WordPress-Core in exakt der
   Version der Site, `wp-config.php` von der Site, local-mailguard eingebunden, `.ddev` in den
   Containern schreibgeschützt.
4. **Delta-Check** gegen die Baseline – seitenweise, nur für den Profil-Umfang.
5. **Dateien:** nur neue und geänderte. Ein abgebrochener Download wird fortgesetzt.
6. **Datenbank:** nur geänderte Tabellen. Kleine gebündelt, große per Keyset-Cursor – alles in
   eine SQL-Datei, ein Import. Fortsetzbar pro Tabelle. Personenbezogene Werte kommen
   pseudonymisiert an.
7. **Post-Setup:**
   - Search-Replace der Live-URL (Klartext und JSON-escaped), danach ein zweiter Durchlauf
     mit geladenen Plugins für plugin-serialisierte Objekte (z. B. Borlabs Cookie)
   - `WP_ENVIRONMENT_TYPE=local`, `DISABLE_WP_CRON`
   - Mail-, Security-, Zugriffsschutz- und Caching-Plugins sowie abgewählte Plugins deaktivieren
   - Drop-ins `advanced-cache.php` und `object-cache.php` entfernen
   - Uploads-Proxy einrichten
   - lokalen Admin `wpsync` anlegen bzw. dessen Passwort zurücksetzen
8. **Mailguard-Pflichtprüfung:** Ist im Container kein aktiver `wp_mail`-Filter nachweisbar,
   bricht der Pull ab und das Projekt wird gestoppt.
9. **Baseline** speichern und Schnappschuss ins interne Git neben dem Site-Ordner.

Ein Folge-Pull ohne Änderungen auf der Site kostet wenige Requests.

---

## Code pushen

```sh
wpsync push kunde code --dry-run     # zeigt, was sich lokal geändert hat
wpsync push kunde code               # fragt nach und pusht
wpsync rollback kunde                # nimmt den letzten Push zurück
```

**Vorher im WP-Admin:** Werkzeuge → wpsync → beim eigenen Gerät „Push-Fenster öffnen" (15 Minuten,
1 Stunde oder 8 Stunden). Ausserhalb des Fensters kann auch ein gültiger Schlüssel nichts schreiben.

**Was gepusht wird.** Eine Einheit ist immer ein ganzes Verzeichnis: `plugins/<slug>`,
`themes/<slug>` oder `mu-plugins`. Als geändert gilt eine Einheit, wenn eine ihrer Dateien lokal
von dem Stand abweicht, den der letzte Pull geliefert hat. Hochgeladen werden nur die geänderten
Dateien; den Rest kopiert der Agent auf dem Server.

**Lokal neue Verzeichnisse** – solche, die der letzte Pull nicht geliefert hat – pusht
`wpsync push <site> code` ohne genannte Einheiten nie; die CLI nennt sie als „übersprungen“.
Das schützt vor alten lokalen Kopien, etwa von Plugins, die ein Pull-Profil ausschliesst. Ein
neues Plugin geht nur mit ausdrücklicher Nennung raus: `wpsync push <site> code plugins/<slug>`.
Liegt ein gleichnamiges Verzeichnis schon auf der Site, ist das ein Konflikt.

**Symlinks.** Ist eine Einheit selbst ein symbolischer Link (etwa ein lokal verlinktes
Entwicklungs-Plugin), überspringt die CLI sie mit Hinweis und liest sie nie; ausdrücklich genannt
bricht der Push ab. Ist `public`, `wp-content`, `plugins`, `themes` oder `mu-plugins` ein Symlink,
bricht jeder Push ab.

**Ablauf.**

1. Probelauf: Der Agent meldet pro Einheit, welche Dateien er braucht, ob sich die Einheit auf
   der Site seit deinem letzten Pull geändert hat und welche Version dort liegt.
2. Die CLI prüft, ob `rescue.php` erreichbar ist, und ruft Startseite, Login und – bei
   WooCommerce – Shop, Warenkorb und Kasse auf. Weitere Seiten: `health_urls` in der
   Site-Konfiguration.
3. Upload in einen Arbeitsordner, Prüfung jeder Datei gegen ihren Hash.
4. Der Agent baut das neue Verzeichnis neben dem alten und tauscht mit zwei `rename`. Das alte
   Verzeichnis bleibt als Snapshot liegen.
5. Dieselben Seiten noch einmal. Ist eine schlechter als vorher (5xx, Fehlermeldung von
   WordPress oder PHP, leere Seite, keine Antwort), rollt die CLI sofort zurück.
6. Sonst wird der Push bestätigt und die Baseline fortgeschrieben; ein Folge-Pull überträgt
   die gepushten Dateien nicht noch einmal.

**Konflikte.** Hat sich die Einheit auf der Site seit deinem letzten Pull geändert
(Auto-Update, Update im WP-Admin, Push eines Kollegen), bricht der Push ab und nennt die Dateien.
Erst `wpsync pull`, lokal zusammenführen, dann erneut pushen. `--force` überschreibt bewusst.

**Rollback.** Snapshots der letzten 3 bestätigten Pushes bleiben bis zu 14 Tage liegen.
`wpsync rollback <site> [push-id]` stellt einen davon wieder her – bei einem bestätigten Push nur,
solange das Push-Fenster des Geräts offen ist; sonst öffnet ein Administrator das Fenster oder
rollt im WP-Admin unter Werkzeuge → wpsync → Pushes selbst zurück (dort ohne Fenster). Ein
unbestätigter Push (getauscht, Health-Check noch nicht bestanden) lässt sich immer zurückrollen.
Legt er WordPress lahm, geht die CLI über `wp-content/plugins/wpsync-agent/rescue.php`: Das
Skript lädt kein WordPress, prüft einen Schlüssel, den nur dein Mac aus dem Pairing-Secret
ableiten kann, und rollt nur unbestätigte Pushes zurück. Ein Push lässt sich nur zurückrollen,
solange kein späterer Push dieselbe Einheit getauscht hat.

**Was ein Push nie tut.**

- Er aktiviert nichts: Ein neues Plugin liegt danach inaktiv auf der Site.
- Er löscht nichts: Ein lokal entferntes Plugin bleibt auf der Site bestehen.
- Er schreibt weder Datenbank noch Uploads.
- Er überträgt nie den Agent selbst, den lokalen Mail-Riegel (`00-local-mailguard.php`),
  Dateien in `mu-plugins`, die mit `wpsync` beginnen, `.git`, `*.log`, `.env*`, `.DS_Store`
  und Symlinks. Was davon auf der Site liegt, bleibt beim Tausch unverändert stehen.

**Grenzen.**

- **Datenbank-Migrationen:** Ändert sich die Versionsnummer eines Plugins, kann es beim nächsten
  Aufruf seine Tabellen umbauen. Ein Rollback nimmt nur Dateien zurück. Die CLI zeigt den
  Versionswechsel und verlangt eine eigene Bestätigung (`--yes` allein genügt nicht, zusätzlich
  `--allow-version-change`). Vorher ein Backup der Datenbank anlegen.
- **Kein Rückweg, kein Push:** Sperrt ein Sicherheits-Plugin oder der Server direkte PHP-Aufrufe
  unter `wp-content/plugins/`, ist `rescue.php` nicht erreichbar und die CLI pusht nicht.
- **Unterbrochener Push:** Bricht die CLI nach dem Tausch ab, bleibt der neue Stand unbestätigt
  live und blockiert weitere Pushes. Der nächste Aufruf nennt die Auswege:
  `wpsync pushes <site> --confirm <id>` oder `wpsync rollback <site> <id>`.
- **Der Tausch ist nicht atomar:** Zwischen den beiden `rename` fehlt das Verzeichnis für einen
  Moment. WordPress überspringt ein fehlendes aktives Plugin für diesen einen Request.
- **Dateibesitzer:** Gepushte Dateien gehören dem Benutzer, unter dem PHP läuft. Auf Hostern mit
  getrenntem FTP-Benutzer kann der sie danach eventuell nicht mehr ändern. Darf PHP das
  Verzeichnis nicht ersetzen, bricht der Push vor dem Upload ab.
- **Nicht unterstützt:** Einzeldatei-Plugins direkt unter `plugins/`, Drop-ins, Sprachdateien
  unter `languages/`, Multisite, ein verschobenes `wp-content/plugins`.

---

## Sicherheit

- **Pairing:** Einmal-Code (8 Zeichen), 10 Minuten gültig, 5 Versuche. Danach ist der Code verbraucht.
- **Secret:** liegt lokal nur in der macOS-Keychain (`service=wpsync:<site>`), nie in einer Datei.
- **Signatur:** jeder Request ist per HMAC signiert, mit Zeitstempel und Nonce gegen Replays.
  Nicht signierte Requests bekommen `401`.
- **Antworten der Site:** Die Signatur schützt den Request, nicht die Antwort. Das CLI prüft
  deshalb Tabellennamen, die die Site liefert (Buchstaben, Ziffern, `_`, `$`, höchstens
  64 Zeichen), den Tabellenpräfix (Buchstaben, Ziffern, `_`) und die Adressen der Site
  (`http(s)`-URL), und bricht `pull` und `status` bei anderen Werten ab, bevor lokal etwas
  geschrieben wird. Präfix und Adressen gehen als Argumente an WP-CLI, das einen Wert mit `--`
  am Anfang als eigene Option liest.
- **Widerruf:** Werkzeuge → wpsync → Gerät widerrufen. Danach ist das Secret wertlos.
- **Schreiben nur im Push-Fenster:** Beim Pull liest der Agent und schreibt nur in eigene
  `wpsync_*`-Tabellen und -Optionen. Code schreibt er ausschliesslich über `wpsync push`, pro
  Gerät und nur solange ein Administrator das Push-Fenster geöffnet hat (höchstens 8 Stunden).
  Ein Push ist Code-Ausführung auf dem Server – das Fenster nur öffnen, wenn gepusht wird.
- **Push-Schutz im Agent, nicht in der CLI:** erlaubte Einheiten, verbotene Dateinamen,
  Hash-Prüfung vor dem Tausch und die Sperre „ein Push gleichzeitig" prüft der Server selbst.
- **`rescue.php`:** kennt nur „ping" und „rollback", lädt weder WordPress noch die Datenbank,
  rollt nur unbestätigte Pushes zurück und sperrt einen Push nach 5 falschen Schlüsseln für
  10 Minuten. Der Schlüssel ist pro Push
  aus dem Pairing-Secret abgeleitet und geht als POST-Formularfeld an das Skript, nie in der
  URL; auf dem Server liegt nur sein Hash.
- **Snapshots:** liegen in `wp-content/wpsync-push-<zufall>/` mit `.htaccess`-Sperre. Auf
  Servern ohne `.htaccess`-Auswertung schützt nur der zufällige Name.
- **Datenminimierung:** Personenbezogene Daten verlassen den Server pseudonymisiert –
  auch dann, wenn ein älteres CLI nichts dazu sagt. Klartext nur mit `--no-anonymize`.
- **Transport:** Der Agent antwortet nur über HTTPS. Für lokale Umgebungen:
  `define('WPSYNC_ALLOW_HTTP', true);` in `wp-config.php` und `wpsync pair … --insecure`.
- **Feste Ausschlüsse (serverseitig, für Liste und Abruf):** eigene und fremde
  `wpsync_*`-Tabellen und -Optionen, Views, Tabellen einer zweiten Installation in derselben
  Datenbank, `wp-content/cache`, `upgrade`, `wflogs`, bekannte Backup-Ordner, `backup(s)`,
  `.git`/`.svn`/`.hg`, Symlinks, `*.log`, `.env*`, `.htpasswd`, SQL-Dumps ausserhalb von
  Plugins und Themes, Archive direkt unter `wp-content`, Dateien > 256 MB.
- **Zugriffsschutz:** Nur wpsync-Routen passieren das Plugin „Password Protected" – ohne
  Signatur-Header ausschliesslich der Namespace-Index und `/pair`.
- **Datenbank-Import:** Der Dump der Site wird nur als SQL importiert. Client-Kommandos
  (`\!`, `source`, `tee` …) und `LOAD DATA LOCAL` sind abgeschaltet, und der Import läuft als
  DDEV-Nutzer `db` ohne Dateirechte statt als `root`. Enthält der Dump solche Zeilen, bricht der
  Import ab. Bei MySQL-Sites meldet der Client dabei jedes Mal „Using a password on the command
  line interface can be insecure“ – das ist DDEVs öffentliches Standard-Passwort, kein Fehler.
- **Keine Mails lokal:** Ohne aktiven local-mailguard läuft kein Pull. Grund: Ein Prod-Dump
  bringt oft SMTP-Plugins mit echten Zugangsdaten mit.
- **Code der Site läuft lokal:** `wp-config.php`, Plugins, Themes und das SQL der Site laufen
  auf deinem Mac – nur durch DDEV/Docker isoliert. Eine kompromittierte Site kann im Container
  alles, was dort möglich ist. Damit sie von dort nicht auf den Mac kommt:
  - `.ddev` ist im web- und im db-Container schreibgeschützt (eigene Compose-Datei
    `docker-compose.wpsync-hardening.yaml`); beschreibbar bleibt nur `db_snapshots/`. Nach jedem
    Start prüft `wpsync` die Mounts und bricht ab, wenn der Schutz nicht greift.
  - Vor jedem ddev-Aufruf prüft `wpsync` die Dateien in `.ddev`, die DDEV auf dem Mac auswertet
    (`config*.yaml`, `docker-compose.*.yaml`, `commands/`, `.env*`, `providers/`,
    `share-providers/`), gegen den zuletzt geprüften Stand. Weicht etwas ab, läuft kein
    ddev-Befehl. Eigene Anpassungen gibst du mit `wpsync trust <site>` frei.
  - `wpsync list` ruft DDEV ohne Hooks auf; `wpsync stop` hält eine abweichende Site ohne ddev an.
  - `wpsync` führt im Site-Ordner kein git aus. Das interne Repo liegt ausserhalb, in
    `~/wpsync-sites/.wpsync-git/`, wo kein Container hinkommt; ein `.git` im Site-Ordner wird
    nicht benutzt, sondern gemeldet.

  Nicht abgedeckt: ddev-Befehle, die du selbst im Site-Ordner aufrufst (dort wirkt nur der
  Schreibschutz), und Schwachstellen in Docker selbst. Einer Site, der du nicht traust, gibst du
  keine eigenen `.ddev`-Anpassungen frei. Leg im Site-Ordner kein eigenes Git-Repo an und lass
  git dort nicht ungeprüft laufen – auch nicht über Shell-Prompt oder IDE: Die Site kann jedes
  `.git` darin (auch in Unterordnern) beschreiben, und git führt dessen Hooks und Config auf dem
  Mac aus.

### Mail-Riegel

`wpsync` bindet in jedes Projekt das mu-plugin `00-local-mailguard.php` ein (Quelle:
`cli/internal/mailguard/`, im Binary enthalten). Es leitet alle Mails auf
`blocked@mailguard.invalid` um und über Mailpit aus – mit drei Riegeln:

1. Filter `wp_mail` ersetzt Empfänger, Cc und Bcc; der echte Empfänger steht im Betreff.
2. Filter `phpmailer_init` (zuletzt, nach jedem SMTP-Plugin) leert Empfänger erneut und
   setzt den Transport zurück auf PHP `mail()` → Mailpit.
3. Filter `pre_http_request` bricht Aufrufe an bekannte Mail-APIs (SendGrid, Brevo,
   Mailgun, Postmark …) ab.

`wpsync` schreibt die Datei nach `~/.config/wpsync/mailguard/` und hängt sie read-only in den
Container. Mit `WPSYNC_MAILGUARD=/pfad/zur/datei.php` lässt sich ein eigener Riegel nutzen;
zeigt die Variable ins Leere, bricht `wpsync` ab statt auszuweichen. `wpsync doctor` zeigt,
welche Datei genutzt wird.

---

## Serverschonung und IP-Sperren

- Feste Kennung `User-Agent: wpsync/<version>` – im WAF/fail2ban gezielt freigebbar.
- Standard: 1 Request/s, eine Verbindung; pro Site über `rps` in der Site-Datei änderbar.
- Die Site arbeitet in Häppchen mit Zeitbudget, damit kein Request in ein PHP-Timeout läuft.
- `429`/`502`/`503`/`504`: Backoff 10/20/40 s, `Retry-After` wird beachtet, höchstens 3 Versuche.
- **Verbindungsabbruch nach vorherigem Erfolg:** sofortiger Abbruch ohne Retry – weitere
  Versuche würden eine fail2ban-Sperre nur verlängern. `wpsync` nennt dann deine öffentliche
  IP, damit du sie auf dem Server entsperren und whitelisten kannst.

---

## Lokale Ablage und Konfiguration

```
~/.config/wpsync/sites/<site>.yaml   Site-Konfiguration und Profil (kein Secret)
~/.config/wpsync/ddev-state/<site>.json
                                     geprüfter Stand von .ddev (Prüfsummen, nur für dich lesbar)
~/wpsync-sites/<site>/
├── .ddev/                           DDEV-Konfiguration inkl. Mailguard-Mount und Härtung
│                                    (in den Containern schreibgeschützt)
├── public/                          WordPress
├── .wpsync/
│   ├── baseline.json                Stand des letzten Pulls
│   ├── pushes/<push-id>.json        Journal je Push: vorheriger Baseline-Stand, Rescue-URL
│   └── db/pull.sql                  letzter Dump (wird beim nächsten Pull ersetzt)
└── .gitignore                       Umfang des Schnappschusses (von wpsync geschrieben)
~/wpsync-sites/.wpsync-git/
├── <site>.git/                      internes Repo, Auto-Commit nach jedem Pull, Push und Rollback (nur für dich lesbar)
└── <site>.alt-<datum>.git/          früheres .git aus dem Site-Ordner, beim Update verschoben
```

Das interne Git versioniert `public/wp-content` ohne Uploads, Cache und Upgrade-Ordner, dazu
die Baseline – keine DB-Dumps. Es liegt bewusst nicht im Site-Ordner (siehe
[Sicherheit](#sicherheit)). Ordner mit einem eigenen `.git` (etwa ein Plugin, das jemand als
Git-Checkout abgelegt hat) fehlen im Schnappschuss: git würde darin mit deren Config und Hooks
arbeiten. Historie ansehen:

```bash
git --git-dir ~/wpsync-sites/.wpsync-git/<site>.git log --stat
```

Bis CLI 0.1.8 lag das Repo als `.git` im Site-Ordner. Der erste Pull nach dem Update verschiebt
es unverändert nach `.wpsync-git/<site>.alt-<datum>.git`; die Historie beginnt neu.

| Umgebungsvariable | Standard | Wofür |
|---|---|---|
| `WPSYNC_CONFIG_DIR` | `~/.config/wpsync` | Ablage der Site-Konfigurationen und des geprüften `.ddev`-Stands; darf nicht im Sites-Ordner liegen |
| `WPSYNC_SITES_DIR` | `~/wpsync-sites` | Ablage der lokalen Projekte |
| `WPSYNC_MAILGUARD` | – | eigener Mail-Riegel statt des mitgelieferten (siehe [Mail-Riegel](#mail-riegel)) |

Zusätzliche Seiten für den Health-Check eines Pushs stehen in der Site-Konfiguration:

```yaml
health_urls:
  - https://kunde.de/kontakt/
  - https://kunde.de/mein-konto/
```

Selbst eingetragene `health_urls` dürfen bewusst auch auf andere Hosts oder per `http` zeigen
(etwa eine Statusseite); vom Agenten gelieferte Seiten ruft `push` nur auf der gekoppelten Site ab.

---

## Fehlerbehebung

| Symptom | Ursache / Lösung |
|---|---|
| `*.ddev.site` löst nicht auf | `wpsync setup` fehlt oder der Router blockiert DNS-Rebind → `wpsync doctor` |
| „noch kein Pull-Profil – zuerst wpsync scan …“ | einmal `wpsync scan <site>` |
| `401` beim Pull | Pairing auf der Site widerrufen → `wpsync unpair <site>`, neu koppeln |
| `400 wpsync_https` | Der Agent sieht die Verbindung als unverschlüsselt → HTTPS-Erkennung hinter dem Proxy einrichten; lokal `WPSYNC_ALLOW_HTTP` setzen |
| „Der Server antwortet nicht mehr – vermutlich eine IP-Sperre“ | fail2ban/WAF hat gesperrt. Genannte IP entsperren und whitelisten, `User-Agent: wpsync/*` freigeben, ggf. `rps` senken |
| Infosheet veraltet / fehlt | WP-Cron ist auf der Site aus → `wpsync scan <site> --refresh` |
| Pull bricht mit Mailguard-Fehler ab | `wpsync doctor` zeigt, welche Datei gesucht wird – meist zeigt `WPSYNC_MAILGUARD` ins Leere. Hat die Site eigene Mail-Wege außerhalb von `wp_mail`, greift der Riegel dort nicht |
| „Datenbank-Import abgebrochen“ | Der lokale Client hat eine Zeile des Dumps abgelehnt – die Meldung darüber nennt sie. Ein Client-Kommando (`\!`, `source`) oder ein Dateizugriff im Dump deutet auf eine manipulierte Site hin; ein Rechtefehler auf eine Tabelle mit Optionen, die mehr als den Nutzer `db` brauchen. Die lokale Datenbank kann unvollständig sein. Der Fehler wiederholt sich bei jedem Pull, bis sich die Tabelle auf der Site ändert |
| „die Site liefert Tabellennamen mit unzulässigen Zeichen“ | Ein Tabellenname enthält etwas anderes als Buchstaben, Ziffern, `_` oder `$`. Gehört die Tabelle zur Site: im Profil `tables.overrides: <name>: skip` setzen und erneut ziehen (die Tabelle fehlt dann lokal; ist sie neuer als der letzte Scan, vorher `wpsync scan <site> --refresh`). Auf einer sonst unauffälligen Site ist so ein Name ein Grund, auf dem Server nachzusehen |
| „die Site meldet Angaben in unzulässiger Form“ | Die Site meldet einen unzulässigen Tabellenpräfix (anderes als Buchstaben, Ziffern, `_`, auch: leer) oder eine Adresse, die keine `http(s)`-URL ist – die Meldung nennt das Feld. Auf der Site `$table_prefix` in `wp-config.php` bzw. WordPress- und Website-Adresse unter Einstellungen → Allgemein prüfen. Ist dort alles unauffällig, ist das ein Grund, die Site zu untersuchen |
| „.ddev von … weicht vom geprüften Stand ab“ | Eine Datei, die DDEV auf dem Mac auswertet, ist neu, geändert oder fehlt – die Meldung nennt sie. Es wurde kein ddev-Befehl ausgeführt. Stammt die Änderung von dir (eigene `config.local.yaml`, Add-on, Compose-Datei): `wpsync trust <site>` zeigt sie mit Hooks und Mounts an und gibt sie frei. Kennst du sie nicht, Datei ansehen und entfernen – die Site könnte manipuliert sein |
| „noch kein geprüfter Stand für .ddev“ | Erster Pull einer bestehenden Site nach dem Update. Im Terminal fragt der Pull einmal nach der Übernahme, sonst `wpsync trust <site>`. Traust du der Site nicht: `wpsync stop <site>` (hält sie ohne ddev an), den Ordner `.ddev` der Site löschen und `wpsync pull <site> --full` – wpsync legt `.ddev` frisch an und lädt alles neu. Eigene Anpassungen in `.ddev` gehen dabei verloren |
| „Vor dem ersten Pull … liegt schon ein .ddev mit Inhalt“ | Im Site-Ordner liegt ein `.ddev` ohne `config.yaml`. Ordner `.ddev` entfernen und erneut ziehen |
| „ein DDEV-Container kann .ddev weiterhin beschreiben“ | Eine eigene `docker-compose.*.yaml` (z. B. von einem Add-on) mountet `.ddev` oder den Site-Ordner erneut beschreibbar. wpsync hat die Container gestoppt; Mount anpassen, dann `wpsync trust <site>` |
| „… .git liegt im Site-Ordner – nicht von wpsync angelegt und nicht benutzt“ | Im Site-Ordner ist (wieder) ein `.git` aufgetaucht – von dir, einem Werkzeug oder der Site selbst. wpsync benutzt es nicht, der Pull läuft weiter. Kennst du es nicht, **nicht** mit git öffnen, sondern löschen: Es kann Hooks oder Config enthalten, die beim nächsten git-Aufruf auf dem Mac laufen |
| „… hat ein eigenes .git – der Ordner fehlt im Schnappschuss“ | Ein Ordner unterhalb des Site-Ordners (meist ein Plugin oder Theme) enthält ein `.git`. wpsync lässt ihn im internen Git aus und führt darin nichts aus; der Pull läuft weiter, WordPress nutzt den Ordner normal. Stammt das `.git` nicht von dir, nicht mit git öffnen, sondern löschen – beim nächsten Pull ist der Ordner wieder im Schnappschuss |
| „Bisheriges Site-Git verschoben nach …“ | Einmalig beim ersten Pull nach dem Update: Das alte Repo aus dem Site-Ordner liegt jetzt unter `.wpsync-git/<site>.alt-<datum>.git`. Es wird nicht mehr gebraucht und darf gelöscht werden; nicht mit git öffnen, die Site konnte es verändern |
| „bisheriges Git-Repo … ließ sich nicht aus dem Site-Ordner verschieben“ | Meist liegt der Site-Ordner auf einem anderen Volume als `.wpsync-git` oder es fehlen Rechte. Es wurde kein git ausgeführt. `.git` im Site-Ordner von Hand löschen oder wegräumen und erneut ziehen |
| Bilder fehlen lokal | Jahr liegt vor `uploads.since`; der Proxy lädt beim ersten Aufruf nach. Dauerhaft: `since` im Profil anpassen und erneut ziehen |
| „das Push-Fenster ist geschlossen“ | Im WP-Admin unter Werkzeuge → wpsync beim eigenen Gerät öffnen |
| „die Site hat sich seit dem letzten Pull geändert“ | `wpsync pull <site>`, lokal zusammenführen, erneut pushen – oder bewusst `--force` |
| „rescue.php ist nicht erreichbar“ | Server oder Sicherheits-Plugin sperrt direkte PHP-Aufrufe unter `wp-content/plugins/` → dort eine Ausnahme für `wpsync-agent/rescue.php` einrichten |
| „Push … ist getauscht, aber nicht bestätigt“ | Site ansehen, dann `wpsync pushes <site> --confirm <id>` oder `wpsync rollback <site> <id>` |
| „der Webserver darf das Verzeichnis nicht ersetzen“ | Das Verzeichnis gehört einem anderen Benutzer als PHP (typisch nach FTP-Upload) → Besitzer oder Rechte auf dem Server anpassen |
| Push wurde automatisch zurückgerollt | Die Meldung nennt die Seite, die schlechter wurde. Lokal reparieren und erneut pushen; auf der Site ist der alte Stand live |
| „übersprungen: plugins/… – neu, nur mit ausdrücklicher Nennung“ | Das Verzeichnis stand in keinem Pull. Gewollt neu → `wpsync push <site> code plugins/<slug>`; sonst eine alte lokale Kopie, die liegen bleiben kann |

---

## Entwicklung

```sh
# CLI
cd cli
go vet ./... && go test ./...
go build -o bin/wpsync ./cmd/wpsync

# Agent
cd agent
composer install
vendor/bin/phpunit
./build.sh                 # → dist/wpsync-agent.zip

# End-to-End (DDEV-Quelle → pair → scan → pull → status)
scripts/e2e-local.sh       # endet mit „E2E OK“; Arbeitsordner: $WPSYNC_E2E_DIR (~/wpsync-e2e)

# Sicherheits-Regressionen (braucht die Quelle aus e2e-local.sh)
scripts/e2e-security.sh
```

Struktur:

```
cli/
├── cmd/wpsync/            Einstiegspunkt, Befehle
└── internal/
    ├── agentapi/          signierter HTTP-Client, Frame-Parser, Backoff
    ├── baseline/          Stand des letzten Pulls
    ├── ddev/              DDEV-Projekt, Uploads-Proxy
    ├── keychain/          macOS-Keychain
    ├── localgit/          internes Site-Git (ausserhalb des Site-Ordners)
    ├── mailguard/         mitgelieferter Mail-Riegel (mu-plugin) und Auswahl
    ├── profile/           Presets, Profil-Auflösung, Scope
    ├── pull/              Delta, Dateien, DB, Post-Setup, Status
    ├── scan/              Infosheet-Darstellung, Checkliste
    ├── setup/             setup und doctor
    └── sites/             Site-Konfiguration
agent/
├── wpsync-agent.php       Plugin-Header, Bootstrap
├── src/                   Endpunkte, Signatur, Infosheet, Klassifizierung, Scope
└── tests/                 PHPUnit
```

Konventionen: Branches `feature/`, `fix/`, `chore/`; Commits nach Conventional Commits auf
Englisch; Meldungen an Nutzer auf Deutsch. Vor jedem Commit laufen `go vet`, `go test` und
PHPUnit.

---

## Release

Versionen: Tag `vX.Y.Z` = Version der CLI. Der Agent hat eine eigene Version
(`WPSYNC_VERSION` in `agent/wpsync-agent.php`), die im Changelog pro Release genannt wird.

1. `CHANGELOG.md` ergänzen, Versionen anheben (`cli/internal/agentapi/client.go`, ggf. Agent).
2. Taggen: `git tag -a vX.Y.Z -m "…" && git push origin vX.Y.Z`
3. Binaries bauen (Version per ldflags):
   ```sh
   cd cli
   for a in arm64 amd64; do
     CGO_ENABLED=0 GOOS=darwin GOARCH=$a go build -trimpath \
       -ldflags "-s -w -X github.com/usermind/wpsync/internal/agentapi.Version=X.Y.Z" \
       -o wpsync ./cmd/wpsync && tar -czf wpsync_X.Y.Z_darwin_$a.tar.gz wpsync
   done
   ```
4. `gh release create vX.Y.Z` mit den Tarballs, der Agent-ZIP und `checksums.txt`.
5. Im Tap `UserMind2018/homebrew-tap` in `Formula/wpsync.rb` `url` auf den neuen Tag setzen
   und `sha256` anpassen (Befehl steht in der Tap-README). Die Formel baut das Agent-ZIP selbst:
   Sie muss dieselben Dateien einpacken wie `agent/build.sh` (seit 0.4.0 auch `rescue.php`).

---

## Lizenz

MIT – siehe [LICENSE](LICENSE).
