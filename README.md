# wpsync – WordPress Live ↔ Lokal

`wpsync` holt eine produktive WordPress-Site in ein lokales DDEV-Projekt – schonend für den
Server, fortsetzbar nach Abbrüchen und so, dass lokal keine Mail an echte Empfänger rausgeht.

Das Projekt besteht aus zwei Teilen:

| Teil | Ordner | Läuft auf | Aufgabe |
|---|---|---|---|
| **CLI** `wpsync` | `cli/` (Go) | deinem Mac | koppeln, scannen, ziehen, lokales DDEV-Projekt einrichten, Code, Uploads und Inhalte pushen |
| **Agent** `wpsync-agent` | `agent/` (PHP-Plugin) | der Live-Site | signierte Endpunkte für Infosheet, Delta, DB und Dateien; im Push-Fenster zusätzlich für Code, neue Uploads und Inhalte |

Beim Pull liest der Agent nur und schreibt ausschliesslich in eigene Tabellen (`wpsync_*`).
Schreiben kann er einzig über `wpsync push`, und nur solange ein Administrator im WP-Admin ein
Push-Fenster geöffnet hat: ganze Plugin-, Theme- und mu-plugins-Verzeichnisse, mit `--uploads`
neue Dateien unter `wp-content/uploads/` (nie ersetzen, nie löschen) und mit `--content` geprüfte
Zeilen der sieben Inhaltstabellen. Alles andere in Datenbank und Uploads der Live-Site schreibt
wpsync nie.

---

## Inhalt

- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Schnellstart](#schnellstart)
- [Befehle](#befehle)
- [Pull-Profile](#pull-profile)
- [Was beim Pull passiert](#was-beim-pull-passiert)
- [Code pushen](#code-pushen)
- [Inhalte: Manifest, Baseline, Export](#inhalte-manifest-baseline-export)
- [Staging auf dem Server](#staging-auf-dem-server)
- [Sicherheit](#sicherheit)
- [Serverschonung und IP-Sperren](#serverschonung-und-ip-sperren)
- [Lokale Ablage und Konfiguration](#lokale-ablage-und-konfiguration)
- [Fehlerbehebung](#fehlerbehebung)
- [Server-Modus](#server-modus)
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
| `wpsync doctor [--server]` | Prüft ohne sudo: Resolver, Docker ≥ 25, DDEV, local-mailguard, freie Ports. Nennt pro Problem die Lösung, Exit-Code ≠ 0 bei Fehlern. `--server` prüft nur Version, Mail-Riegel, Docker-CLI und `WPSYNC_CONFIG_DIR` (OS-Container). |
| `wpsync pair <url> <code> [--name n] [--device d] [--insecure]` | Koppelt eine Site. Folgt Redirects und speichert die kanonische URL; das Secret landet in der macOS-Keychain. Der Name wird sonst aus der URL abgeleitet (`www.example.com` → `example-com`). Nur über `https://`; `--insecure` erlaubt `http://` für lokale Testumgebungen. |
| `wpsync unpair <site>` | Entfernt Konfiguration und Keychain-Eintrag lokal. Das Pairing danach im WP-Admin widerrufen. |
| `wpsync list` | Alle lokalen wpsync-Umgebungen (DDEV-Projekte unter `~/wpsync-sites`) und gekoppelten Sites: Status (`läuft`, `pausiert`, `gestoppt`, `nicht angelegt`), lokale URL der laufenden, Live-URL. Andere DDEV-Projekte erscheinen nicht. |
| `wpsync stop <site>… \| --all` | Stoppt einzelne Umgebungen oder mit `--all` alle laufenden – per `ddev stop`, Datenbank und Dateien bleiben erhalten. Weicht `.ddev` einer Site vom geprüften Stand ab, hält `wpsync` sie ohne ddev per `docker stop` an, meldet das und endet mit Exit-Code ≠ 0; die übrigen werden normal gestoppt. Wieder starten: `ddev start` im Site-Ordner oder der nächste `wpsync pull`. |
| `wpsync scan <site> [--refresh] [--preset p] [--uploads-since JJJJ]` | Holt das Infosheet (Plugins, Tabellen mit Einstufung, Post-Typen, Uploads pro Jahr, Auffälligkeiten) und fragt im Terminal Preset und Checkliste ab. Ohne Terminal: `--preset`. `--refresh` lässt die Site das Infosheet neu erstellen (nötig, wenn WP-Cron aus ist). Speichert das Profil. |
| `wpsync pull <site> [--full] [--yes] [--dry-run] [--no-anonymize] [--content]` | Zieht nach Profil. Ohne Profil Abbruch mit Hinweis auf `scan`. `--full` ignoriert die Baseline, `--yes` behandelt neue Tabellen/Plugins nach der Preset-Regel ohne Rückfrage, `--dry-run` zeigt nur an (wie `status`); mit `--json` steht in `data` `status: "dry_run"` und `pulled: false` – es wurde nichts gezogen, `last_pull` nennt den letzten echten Pull. `--no-anonymize` zieht personenbezogene Daten im Klartext – fragt nach, ohne Terminal zusätzlich `--yes`. `--content` holt zusätzlich das Inhalts-Manifest und baut die Baseline der Inhalte (ab Agent 0.7.0); lädt dafür alle sieben Inhaltstabellen neu, sobald sich eine geändert hat. Details: [Inhalte](#inhalte-manifest-baseline-export). |
| `wpsync status <site>` | Was sich seit dem letzten Pull auf der Site geändert hat – Dateien und Tabellen, ohne Inhalte zu übertragen. |
| `wpsync content export <site>` | Schreibt die normalisierten Zeilen und Fingerabdrücke der Inhaltstabellen der Arbeitskopie als JSON-Lines auf stdout – ohne Request an die Site, ohne etwas zu ändern. Braucht einen aktuellen Inhaltsstand aus `pull --content`. Details: [Inhalte](#inhalte-manifest-baseline-export). |
| `wpsync trust <site> [--fingerprint fp]` | Zeigt, wie `.ddev` der Site vom geprüften Stand abweicht (Hooks, Host-Kommandos, zusätzliche Mounts hervorgehoben), und gibt den angezeigten Stand nach Rückfrage frei. Ohne Terminal nur mit dem angezeigten `--fingerprint`; `--yes` gibt nie frei. Siehe [Sicherheit](#sicherheit). |
| `wpsync push <site> code [einheit…] [--uploads <liste>] [--content <package.jsonl>] [--no-code] [--require-rescue-db] [--to staging] [--dry-run] [--force] [--yes] [--allow-version-change]` | Bringt lokal geänderte Plugins, Themes und mu-plugins als ganze Verzeichnisse auf die Site, mit `--uploads` dazu neue Dateien unter `wp-content/uploads/` (ab Agent 0.6.0), mit `--content` ein Paket aus Inhaltszeilen (ab Agent 0.7.0, [Inhalte pushen](#inhalte-pushen)); `--no-code` lässt den Code weg, `--require-rescue-db` pusht Inhalte nur, wenn `rescue.php` sie auch ohne WordPress zurücknehmen kann (ab Agent 0.8.0, [Rücknahme ohne WordPress](#rücknahme-ohne-wordpress)). Ohne Einheiten: alle geänderten, die der letzte Pull geliefert hat – lokal neue Verzeichnisse nur, wenn sie ausdrücklich genannt sind. Braucht ein offenes Push-Fenster. `--dry-run` zeigt nur den Plan, `--force` überschreibt einen Stand, der sich auf der Site seit dem letzten Pull geändert hat. `--to staging` pusht auf die Staging-Kopie statt nach Live; die Baseline bleibt. Details: [Code pushen](#code-pushen). |
| `wpsync pushes <site> [--confirm <id>]` | Protokoll der Pushes beider Ziele (Spalte ZIEL) mit Status. `--confirm` markiert einen getauschten, aber nicht bestätigten Push als in Ordnung. |
| `wpsync rollback <site> [push-id] [--to staging]` | Nimmt einen Push zurück – über den Agent, und wenn WordPress nicht mehr antwortet über `rescue.php` (ab Agent 0.8.0 samt Inhalten). Ohne Push-ID der neueste Push nach Live, mit `--to staging` der neueste nach Staging; mit Push-ID entscheidet der Push selbst über das Ziel. |
| `wpsync staging create <site> [--yes] [--no-anonymize]` | Legt die Staging-Kopie auf dem Server an: Code und Datenbank nach Pull-Profil, pseudonymisiert. Braucht wie `open`, `refresh` und `delete` ein offenes Push-Fenster. Details: [Staging auf dem Server](#staging-auf-dem-server). |
| `wpsync staging open <site> [--print]` | Holt einen Einmal-Link (Zugang und Anmeldung als Staging-Admin) und öffnet ihn im Browser; `--print` gibt ihn nur aus. Hebt eine Sperre nach Verfall auf. Nicht im selben Browserprofil öffnen, in dem man bei Live angemeldet ist (siehe [Zugang](#staging-auf-dem-server)). |
| `wpsync staging refresh <site> [--code] [--yes] [--no-anonymize]` | Datenbank der Kopie neu von Live, mit `--code` auch den Code. Fragt nach, weil Daten der Kopie verloren gehen. |
| `wpsync staging status <site>` | Zustand, Adresse, Alter, letzte Nutzung, laufender Job und Pushes nach Staging. |
| `wpsync staging delete <site> [--yes]` | Löscht die Kopie: ihre Tabellen und ihren Ordner. |
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
   - Search-Replace der Live-URL (Klartext, JSON-escaped `https:\/\/…` und doppelt escaped
     `https:\\\/\\\/…`, JSON in JSON), danach ein zweiter Durchlauf
     mit geladenen Plugins für plugin-serialisierte Objekte (z. B. Borlabs Cookie)
   - `WP_ENVIRONMENT_TYPE=local`, `DISABLE_WP_CRON`
   - Mail-, Security-, Zugriffsschutz- und Caching-Plugins sowie abgewählte Plugins deaktivieren
   - Drop-ins `advanced-cache.php` und `object-cache.php` entfernen
   - Uploads-Proxy einrichten
   - lokalen Admin `wpsync` anlegen bzw. dessen Passwort zurücksetzen
8. **Mailguard-Pflichtprüfung:** Ist im Container kein aktiver `wp_mail`-Filter nachweisbar,
   bricht der Pull ab und das Projekt wird gestoppt.
9. **Nur mit `--content`:** Inhalts-Manifest der Site holen, Baseline der Inhalte aus der lokalen
   Site rechnen, beide vergleichen (siehe [Inhalte](#inhalte-manifest-baseline-export)).
10. **Baseline** speichern und Schnappschuss ins interne Git neben dem Site-Ordner.

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
Ab Agent 0.6.0 merkt sich der Agent, welcher WordPress-Benutzer das Fenster geöffnet hat; jeder
Push in diesem Fenster nennt ihn im Protokoll (`opened_by` mit der Benutzer-ID in
`wpsync pushes --json`, Spalte „Fenster von“ im WP-Admin). Ein Fenster, das nicht im WP-Admin
geöffnet wurde, hat keinen.

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

**Uploads mitschicken (ab Agent 0.6.0).** `--uploads <liste>` nennt eine Datei mit einem Pfad je
Zeile relativ zu `wp-content/uploads/` (Leerzeilen und Zeilen, die mit `#` beginnen, zählen nicht;
höchstens 5000 Dateien, Liste höchstens 1 MB). Sie gehen als Einheit `uploads` im selben Push mit.
Wie immer gilt: Ohne genannte Einheiten nimmt `push` alle lokal geänderten Code-Einheiten mit –
wer genau einen Satz pushen will (etwa das Website Studio), nennt die Einheiten ausdrücklich:

    printf '2026/10/titelbild.jpg\n2026/10/titelbild-300x200.jpg\n' > neue-medien.txt
    wpsync push kunde code themes/kunde --uploads neue-medien.txt --dry-run

Im Container-Modus liegt die Liste im Container der CLI (Pfad wie `--docroot` absolut oder
relativ zum Arbeitsverzeichnis dort); `--uploads` funktioniert dort genauso.

- Der Agent entscheidet je Datei: fehlt sie auf der Site, wird sie übertragen; liegt sie dort mit
  gleichem Inhalt, passiert nichts; liegt dort eine andere Datei, bricht der ganze Push ab
  (`upload_exists`) – ein Push ersetzt nie einen Upload, auch nicht mit `--force`.
- PHP in jeder Schreibweise (`.php`, `.phtml`, `.phar`, `.php7`, `bild.php.jpg` …), versteckte
  Dateien (`.htaccess`, `.user.ini`), Logs, Dumps und Typen, die WordPress auf der Site nicht
  erlaubt, lehnt der Agent ab (`upload_type_blocked`) – ebenso aktive Typen (`.svg`, `.svgz`,
  `.htm`, `.html`, `.xhtml`, `.shtml`, `.xml`, `.js`, `.mjs`), auch wenn ein Theme sie per
  `upload_mimes` erlaubt, und jeden Namen, den WordPress beim Hochladen umbenennen würde
  (`sanitize_file_name()`), vor allem versteckte mittlere Endungen wie `bild.cgi.png` oder
  `foto.final.v2.jpg`, aber auch Leerzeichen und Sonderzeichen (`Mein Bild (1).jpg`) – solche
  Dateien vorher umbenennen. Den Inhalt prüft er nach dem Upload noch einmal gegen die Endung.
- Die Uploads kommen vor dem Code auf die Site; fehlende Ordner (`JJJJ/MM`) legt der Agent mit den
  Rechten des Elternordners an (höchstens `0755`, Dateien höchstens `0644`). Scheitert der
  Code-Tausch, nimmt er sie wieder weg.
- Liegen alle Dateien schon auf der Site und gibt es keinen Code, ist nichts zu pushen.
- Nicht unterstützt: `wp-content/uploads` als Symlink, ein abweichendes Upload-Verzeichnis
  (`UPLOADS`, `upload_path`) und Multisite – der Agent lehnt dann ab.

**Ablauf.**

1. Probelauf: Der Agent meldet pro Einheit, welche Dateien er braucht, ob sich die Einheit auf
   der Site seit deinem letzten Pull geändert hat und welche Version dort liegt.
2. Die CLI prüft, ob `rescue.php` erreichbar ist – ab Agent 0.5.1 über einen kurzlebigen Stub
   `wpsync-rescue-<zufall>.php` im Webroot, damit ein gesperrter Plugin-Ordner nicht stört –,
   und ruft Startseite, Login und – bei
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
Legt er WordPress lahm, geht die CLI über `rescue.php` (direkt im Plugin-Ordner oder über den
Stub im Webroot, der bis etwa 10 Minuten nach der Bestätigung liegen bleibt): Das
Skript lädt kein WordPress, prüft einen Schlüssel, den nur dein Mac aus dem Pairing-Secret
ableiten kann, und rollt nur unbestätigte Pushes zurück – Inhalte, Code und Uploads, in dieser
Reihenfolge ([Rücknahme ohne WordPress](#rücknahme-ohne-wordpress)). Ein Push lässt sich nur
zurückrollen, solange kein späterer Push dieselbe Einheit getauscht hat. Läuft für denselben Push
gerade eine andere Rücknahme oder sein Commit, antworten Agent und `rescue.php` mit „busy“
(HTTP 423); die CLI wiederholt dann dreimal im Abstand von 2 Sekunden.
Die Uploads eines Pushs nimmt die Rücknahme nach dem Code zurück – auch über `rescue.php`: Sie
löscht genau die Dateien, die der Push angelegt hat, und die Ordner, die er dafür angelegt hat,
wenn sie leer sind. Wurde eine Datei seither auf der Site geändert, bleibt sie liegen; die
Ausgabe nennt sie (`--json`: `warnings: ["upload_changed_since_push"]`).

**Was ein Push nie tut.**

- Er aktiviert nichts: Ein neues Plugin liegt danach inaktiv auf der Site.
- Er löscht nichts: Ein lokal entferntes Plugin bleibt auf der Site bestehen.
- Er schreibt keine Datenbank. Unter `wp-content/uploads/` legt er nur neue Dateien aus
  `--uploads` an – er ersetzt oder löscht dort nie eine fremde Datei.
- Er überträgt nie den Agent selbst, den lokalen Mail-Riegel (`00-local-mailguard.php`), den
  Staging-Riegel (`00-wpsync-staging.php`), Dateien in `mu-plugins`, die mit `wpsync` beginnen, `.git`, `*.log`, `.env*`, `.DS_Store`
  und Symlinks. Was davon auf der Site liegt, bleibt beim Tausch unverändert stehen.

**Grenzen.**

- **Datenbank-Migrationen:** Ändert sich die Versionsnummer eines Plugins, kann es beim nächsten
  Aufruf seine Tabellen umbauen. Ein Rollback nimmt nur Dateien zurück. Die CLI zeigt den
  Versionswechsel und verlangt eine eigene Bestätigung (`--yes` allein genügt nicht, zusätzlich
  `--allow-version-change`). Vorher ein Backup der Datenbank anlegen.
- **Kein Rückweg, kein Push:** Ist `rescue.php` nicht erreichbar, pusht die CLI nicht. Sperrt ein
  Sicherheits-Plugin (z. B. Solid/iThemes Security „Disable PHP in Plugins“) PHP unter
  `wp-content/plugins/`, legt der Agent ab 0.5.1 für die Dauer des Pushs einen Stub im Webroot
  an. Darf der Webserver dort nicht schreiben oder liegt `wp-content` nicht direkt im Webroot,
  bleibt nur die Ausnahme für `wpsync-agent/rescue.php`; die CLI nennt dann erkannte
  Sicherheits-Plugins.
- **Unterbrochener Push:** Bricht die CLI nach dem Tausch ab (Absturz, SIGKILL), bleibt der neue
  Stand unbestätigt live und blockiert weitere Pushes (Exit 42). Der nächste Aufruf nennt die
  Auswege: `wpsync pushes <site> --confirm <id>` oder `wpsync rollback <site> <id>`. SIGTERM
  bricht nur vor dem Tausch ab (Exit 30, nichts getauscht); ab dem Tausch läuft der Push bis zur
  Bestätigung oder zum Rollback weiter, höchstens 10 Minuten nach dem Signal: ein Health-Check,
  der nach 7 Minuten nicht sauber durch ist, führt zum Rollback (Exit 43); hängt noch der Tausch
  selbst, endet der Push nach 10 Minuten mit Exit 42.
- **Schnappschuss gescheitert:** Scheitert nach einem bestätigten Push oder einem Rollback nur der
  Commit im internen Git, endet der Befehl trotzdem erfolgreich mit einer Meldung (`--json`:
  `warnings: ["snapshot_failed"]`). Bis CLI 0.4.0 war das Exit 1. Fehlen im gespeicherten
  Schnappschuss nur einzelne Dateien (nicht lesbar), heisst die Warnung `snapshot_incomplete` –
  auch beim Pull.
- **Der Tausch ist nicht atomar:** Zwischen den beiden `rename` fehlt das Verzeichnis für einen
  Moment. WordPress überspringt ein fehlendes aktives Plugin für diesen einen Request.
- **Dateibesitzer:** Gepushte Dateien gehören dem Benutzer, unter dem PHP läuft. Auf Hostern mit
  getrenntem FTP-Benutzer kann der sie danach eventuell nicht mehr ändern. Darf PHP das
  Verzeichnis nicht ersetzen, bricht der Push vor dem Upload ab.
- **Nicht unterstützt:** Einzeldatei-Plugins direkt unter `plugins/`, Drop-ins, Sprachdateien
  unter `languages/`, Multisite, ein verschobenes `wp-content/plugins`.

## Inhalte: Manifest, Baseline, Export

Grundlage des Inhalts-Pushs ([Inhalte pushen](#inhalte-pushen)), ab Agent 0.7.0: Der Pull hält
fest, welche Inhalte auf der Site liegen und wie sie lokal angekommen sind, und `content export`
rechnet dieselben Fingerabdrücke für die Arbeitskopie. Ein Aufrufer (etwa das Website Studio)
sieht daran, was sich lokal geändert hat, und baut daraus das Paket. Nichts in diesem Abschnitt
schreibt auf die Site, und nichts davon braucht ein Push-Fenster.

**`wpsync pull <site> --content`** holt zusätzlich zum normalen Pull ein **Manifest** der sieben
Inhaltstabellen (`posts`, `postmeta`, `terms`, `term_taxonomy`, `term_relationships`, `termmeta`,
`options`): je Zeile, je Meta-Paar `(Objekt, Schlüssel)` und je Zuordnung `(Objekt, Taxonomie)`
ein Fingerabdruck, keine Werte. Für Zeilen, die der Pull pseudonymisiert, gibt es auch keinen
Fingerabdruck.

Voraussetzungen, beide geprüft, bevor etwas eingerichtet oder geladen wird:

- Agent ≥ 0.7.0, sonst Exit 11 (`agent_outdated`).
- Das Pull-Profil zieht alle sieben Inhaltstabellen **mit Daten**, sonst Exit 2 – die Meldung
  nennt die fehlenden Tabellen; ändern mit `wpsync scan`.

Unter `<site>/.wpsync/content/` liegen danach:

| Datei | Inhalt |
|---|---|
| `manifest.jsonl` | Stand der Site: erst der Kopf `{"head": {…}}`, dann je Zeile `{"t","k","h"}` (Tabelle ohne Präfix, Schlüssel, Fingerabdruck). Ohne Fingerabdruck ist `h` `null` und `why` nennt den Grund: `unnormalizable` (ein Wert liess sich nicht normalisieren), `key_encoding` (der Schlüssel ist kein gültiges UTF-8) oder `pseudonymized` (der Pull pseudonymisiert die Zeile) |
| `map.json` | Domain-Abbildung des Pulls: `live` (`home`, `siteurl`), `local` (die lokale URL), `variants`, `canon_version`, `pulled_at` |
| `baseline.jsonl` | normalisierte Zeilen der lokalen Site direkt nach dem Pull, mit Werten und Fingerabdruck – dasselbe Format wie `content export` |
| `unfaithful.jsonl` | `{"t","k","why"}` je Schlüssel, den der Pull nicht treu übertragen hat – nicht pushbar |
| `env.json` | PHP-Version und Tabellenpräfix der Quelle, sonst nichts (keine URL, kein Secret) – damit `content export` im Container-Modus ohne Request an die Site läuft |
| `summary.json` | das Objekt `content` von `pull --json` (dort `reloaded` immer `false`); fehlt die Datei, gilt der ganze Stand als nicht aktuell |

Der Kopf des Manifests nennt `canon_version`, `list_version`, `variants`, `origins` (`home`,
`siteurl`), `id_max` (je Zähler-Tabelle `posts`, `terms`, `term_taxonomy` das grössere aus
höchster ID und `AUTO_INCREMENT − 1`), `engines`, `tables`, `prefix`, `charset`, `pseudonym`
(die Muster der Pseudonymisierung), `lists` (die Listen des Agents für den späteren
Inhalts-Push, als Daten) und `pushable`. `pushable: false` mit `why: "multisite"` oder
`why: "origin_mismatch"` (Schema, Host oder Port von `home` und `siteurl` weichen ab) heisst:
Das Manifest kommt trotzdem, einen Inhalts-Push wird diese Site nicht annehmen.
`tables` nennt die Inhaltstabellen, die das Pull-Profil mit Daten überträgt; nur für sie stehen
`engines` und `id_max` im Kopf und Zeilen im Manifest. `term_relationships` gehört nur dazu,
wenn auch `term_taxonomy` dabei ist.

Gründe in `unfaithful.jsonl`:

| `why` | Bedeutung |
|---|---|
| `differs` | lokal ein anderer Fingerabdruck als auf der Site – etwa was das Post-Setup lokal umstellt |
| `pseudonymized` | der Pull pseudonymisiert die Zeile; das Manifest nennt für sie keinen Abdruck des echten Werts |
| `unnormalizable` | der Wert auf der Site liess sich nicht normalisieren (das Manifest hat keinen Abdruck) |
| `key_encoding` | der Schlüssel auf der Site ist kein gültiges UTF-8 (das Manifest hat keinen Abdruck) |
| `unnormalizable_local` | der lokale Wert liess sich nicht normalisieren |
| `local_only` | die Zeile gibt es lokal, im Manifest nicht |

Eine Zeile, die im Manifest steht und lokal fehlt, zählt nicht dazu – das Pull-Profil filtert
Zeilen.

- **Pseudonymisiert** (`why: "pseudonymized"`) sind genau die Zeilen, an denen die Regeln der
  Pseudonymisierung etwas ersetzen: Beiträge der Typen `shop_order`, `shop_order_refund` und
  `shop_subscription` als Ganzes, Meta-Paare mit einem pseudonymisierten Schlüssel
  (`_billing_*`, `_shipping_*`, `_customer_ip_address`, … – an jedem Beitrag) und die Optionen
  `admin_email` und `new_admin_email`. Ein Abdruck über den echten Wert liesse sich offline
  erraten (IP, Postleitzahl, Telefon, E-Mail), deshalb fehlt er. Mit `--no-anonymize` zieht der
  Pull Klartext, und das Manifest nennt auch für diese Zeilen den Abdruck. Pushbar sind sie
  in keinem Fall.

- **Normalisiert** heisst: Die eigene Origin der Site ist durch einen Platzhalter ersetzt –
  `⟦wpsync:origin⟧` (Klartext), `⟦wpsync:origin:esc1⟧` (`https:\/\/…`) und `⟦wpsync:origin:esc2⟧`
  (`https:\\\/\\\/…`). Derselbe Inhalt hat damit auf Live, in der Staging-Kopie und in der
  Arbeitskopie denselben Fingerabdruck. Serialisierte Werte werden strukturerhaltend ersetzt –
  beurteilt wie `is_serialized()` von WordPress, also ohne Leerraum an den Rändern. Sieht ein
  Wert oder ein String darin serialisiert aus, lässt sich aber nicht lesen (falsche Länge, `C:`,
  tiefer als 64 Ebenen), und enthält er die Origin, gilt der ganze Wert als nicht normalisierbar.
  Normalisiert wird nur `home`.
- **Fingerabdruck** (`canon_version` 1): `sha256(Tabelle "\n" Schlüssel "\n" Werte)` über die
  normalisierten Werte. Nie im Abdruck sind `post_author`, `post_modified(_gmt)`, `guid`,
  `to_ping`, `pinged`, `comment_count`, `meta_id`, `term_taxonomy.count`, `option_id`,
  `autoload`. Meta-Werte und Zuordnungen zählen als sortierte Multimenge je Paar.
- **Umfang:** Das Manifest folgt dem Pull-Profil – abgewählte Beitragstypen fehlen. Transients
  (`_transient_*`, `_site_transient_*`) und `wpsync_*`-Optionen stehen weder im Manifest noch im
  Export.
- **Alle sieben oder keine:** Mit `--content` lädt der Pull **alle sieben** Inhaltstabellen neu,
  sobald sich eine auf der Site geändert hat, der Ordner fehlt oder unvollständig ist oder der
  Stand eine andere `canon_version` trägt – und baut Manifest, Baseline und `unfaithful.jsonl`
  neu. Die Baseline ist damit immer der Stand direkt nach dem Pull, für jede Tabelle. Lokale
  Änderungen an Inhalten gehen dabei verloren, wie bei jedem Pull einer geänderten Tabelle. Hat
  sich nichts geändert und ist der Stand aktuell, bleibt alles liegen.
- **Pull ohne `--content`:** Lädt er eine der sieben Inhaltstabellen neu, verwirft er den
  Inhaltsstand vor dem Import (`summary.json` wird entfernt) und sagt das – die Baseline gehörte
  sonst nicht mehr zur Arbeitskopie. Der nächste `pull --content` lädt alle sieben neu und
  meldet `reloaded: true`; bis dahin lehnt `content export` ab.
- **Ergebnis:** `pull --json` nennt zusätzlich `content: {rows, unfaithful, id_max,
  canon_version, reloaded}` – Zeilen im Manifest, Schlüssel in `unfaithful.jsonl`, die
  Zählerstände aus dem Kopf. `reloaded` sagt, ob **dieser** Pull die Inhaltstabellen neu geladen
  und die Baseline neu gebaut hat, der lokale Arbeitsstand also überschrieben wurde.
- **Scheitert der Bau** nach dem Laden der Tabellen (Manifest unvollständig, Export der lokalen
  Site gescheitert, andere `canon_version`), endet der Pull mit Exit 1 und `error.reason`
  (siehe [Server-Modus](#server-modus)); der Inhaltsstand gilt dann nicht als aktuell, und der
  nächste Pull lädt die Tabellen erneut.

**`wpsync content export <site>`** schreibt die normalisierten Zeilen und Fingerabdrücke der
**aktuellen** Arbeitskopie als JSON-Lines auf stdout – eine Zeile je Datensatz:

```json
{"t":"posts","k":"219","h":"<sha256>","row":{"post_title":"<base64>","post_parent":"<base64>"},"p":true}
{"t":"postmeta","k":"219\u0000_edit_lock","h":"<sha256>","row":{"values":["<base64>"]},"p":false,"why":"meta_key"}
```

- `t` ist die Tabelle ohne Präfix, `k` der Schlüssel: die ID (`posts`, `terms`,
  `term_taxonomy`), der Optionsname (`options`) oder `<Objekt-ID>\0<Meta-Schlüssel>` bzw.
  `<Objekt-ID>\0<Taxonomie>` für ein Paar.
- `row` trägt die Spalten des Abdrucks, Werte base64, `null` für NULL; bei einem Paar die
  sortierte Menge als `row.values` (Zuordnungen als `<term_taxonomy_id>:<term_order>`).
- `h` ist der Fingerabdruck – oder `null` ohne `row`, wenn sich die Zeile nicht abbilden liess.
- `p` sagt, ob die Zeile nach den Listen des Agents pushbar ist, **ohne** Projekt-Erweiterungen;
  bei `false` nennt `why` den Grund. Ob der Pull die Zeile treu übertragen hat, steckt nicht in
  `p` – das steht in `unfaithful.jsonl`.

| `why` | Bedeutung |
|---|---|
| `key` | die Objekt-ID im Schlüssel ist keine reine Zahl (`219abc`, `0219`, `0`), hat mehr als 18 Stellen oder einem Paar fehlt der Trenner `\0` |
| `post_type` | der Beitragstyp steht nicht auf der Liste (auch für Meta und Zuordnungen des Beitrags) |
| `taxonomy` | die Taxonomie steht nicht auf der Liste |
| `meta_key` | Meta-Schlüssel fest gesperrt |
| `meta_word` | Meta-Schlüssel nur über die Wortlisten gesperrt (Teilstring wie `token`, oder ein ganzes Namensglied wie `auth` in `_auth_code`, `auth2` oder `oAuth`) – per Projekt-Erweiterung ausnehmbar |
| `option` | die Option ist gesperrt oder steht nicht auf der Liste |
| `no_object` | der Beitrag bzw. Term zur Zeile fehlt lokal (verwaiste Meta-Zeile oder Zuordnung) |
| `unnormalizable` | der Wert liess sich nicht normalisieren; `h` ist `null` |
| `key_encoding` | der Schlüssel ist kein gültiges UTF-8; `h` ist `null` |

Gerechnet wird mit derselben PHP-Implementierung wie auf der Site – sie ist in die CLI
eingebettet und läuft per `wp eval-file -` in der lokalen Umgebung (DDEV oder Container-Modus),
ohne Plugins und Themes zu laden.

- **Offline:** Der Export sendet keinen Request an die Site, fasst auf dem Mac die Keychain nicht
  an und ändert weder die Site noch `.wpsync/content/`. Die lokale URL nimmt er aus `map.json` –
  geprüft, bevor sie WP-CLI erreicht: Ist sie keine http(s)-URL ohne Leerraum und Steuerzeichen,
  endet der Export mit Exit 20; neu bauen mit `wpsync pull <site> --content --full`.
- **stdout gehört den Daten:** Jede Meldung geht auf stderr, auch ohne `--json`. Mit `--json`
  folgt als letzte Zeile das übliche Ergebnisobjekt (`command: "content export"`,
  `data: {rows, canon_version}`); ein Aufrufer liest Zeilen, die mit `{"t":` beginnen, als Daten.
  Bei einem Fehler können vorher schon Datenzeilen geschrieben sein – dann zählt der Exit-Code.
- **Braucht einen aktuellen Inhaltsstand:** Fehlt er, ist er unvollständig oder verworfen, endet
  der Export mit Exit 2 und dem Hinweis auf `wpsync pull <site> --content`.
- **Container-Modus:** dieselben Schalter wie beim Pull (`--driver container --container …
  --docroot … --db-host … --db-name … --db-user … --local-url …`) und `--secret-stdin` mit
  Secret und DB-Passwort als erste und zweite Zeile, damit ein Aufrufer jedem Befehl denselben
  stdin geben kann; das Secret wird nicht benutzt. PHP-Version (für das WP-CLI-Image) und
  Tabellenpräfix kommen geprüft aus `env.json`. Ist die Datei nicht verwendbar: Exit 20, neu
  bauen mit `wpsync pull <site> --content --full`.
- **Site-Sperre:** Der Export hält die Sperre der Site; läuft gerade ein Pull, Push oder
  Rollback, endet er mit Exit 20 (`error.reason: "site_locked"`).
- Bricht das Skript ab, fehlt seine Schlusszeile oder ist eine Zeile länger als 256 MiB, ist die
  Ausgabe unvollständig: Exit 1, `error.reason: "content_export_failed"`.

**Doppelt escapte URLs.** Unabhängig von `--content` ersetzt **jeder** Pull ab dieser Version
auch doppelt escapte URLs (`https:\\\/\\\/…`, JSON in JSON) – bisher blieben sie auf die
Live-Domain gerichtet. `staging create` und `staging refresh` schreiben sie mit Agent ≥ 0.7.0
ebenfalls um.

## Inhalte pushen

Ab Agent 0.7.0 trägt ein Push einen **Satz aus drei Kanälen**: Code-Einheiten, neue Uploads und
**Inhalte** – ein Paket aus Datenbankzeilen der sieben Inhaltstabellen. Eine Push-ID, ein
Push-Fenster, ein Health-Check, eine Rücknahme für den ganzen Satz.

```sh
wpsync pull kunde --content                                    # Manifest und Baseline
wpsync content export kunde > export.jsonl                     # Arbeitsstand; Diff und Paket baut der Aufrufer
wpsync push kunde code --no-code --content package.jsonl --dry-run   # prüft das ganze Paket, ohne Push-Fenster
wpsync push kunde code --no-code --content package.jsonl --to staging
wpsync push kunde code themes/x --uploads liste.txt --content package.jsonl
wpsync rollback kunde <push-id>                                # nimmt den ganzen Satz zurück
```

Das Paket baut nicht wpsync, sondern der Aufrufer (das Website Studio) – aus dem Vergleich von
`content export` mit `baseline.jsonl`; die erwarteten Abdrücke kommen aus `manifest.jsonl`.
`--no-code` pusht keinen Code: der Satz besteht dann nur aus `--uploads` und `--content`.

**Der Inhaltskanal ist ein Administrator-Kanal.** Was er schreibt, ist ungefiltertes HTML, CSS
und JavaScript der Site (`_elementor_data`, `wp_template`, `custom_css`, Menüs). Er hat deshalb
genau die Autorisierung des Code-Pushs – signierter Request **und** offenes Push-Fenster dieses
Geräts, ohne schwächeren Weg – und dieselbe Sorgfalt verdient, wer ein Paket baut.

### Format `package.jsonl`

JSON-Lines, Zeilenende fest `\n`, JSON kompakt. Erste Zeile der Kopf:

```json
{"head":{"list_version":2,"extensions":{"post_types":[],"taxonomies":[],"meta_exceptions":[]},"corridor":{"offset":1000000,"posts":[1000001,1999999],"terms":[1000001,1999999],"term_taxonomy":[1000001,1999999]},"canon_version":1,"variants":["plain","esc1","esc2"],"home":"https://kunde.de","map_id":"<sha256 von map.json>","local_host":"kunde.ddev.site","rows":3,"sha256":"<sha256 aller Bytes nach der Kopfzeile>"}}
```

Danach je Zeile `{"op","table","key","expected","row"}`:

| `op` | `expected` | `row` |
|---|---|---|
| `update` | Abdruck der Zeile aus dem Manifest | die Spalten des Abdrucks wie im Export (base64), bei einem Paar `{"values":[…]}`; eine **leere Menge** löscht das Meta-Paar bzw. die Zuordnungen dieser Taxonomie |
| `insert` | `"absent"` | wie `update`. Neue Zeilen in `posts`, `terms`, `term_taxonomy` brauchen eine ID im Korridor; Meta, Zuordnungen und Optionen an bestehenden Objekten nicht |
| `trash` | Abdruck aus dem Manifest | – oder genau `{post_date, post_date_gmt}` (nur `posts`, nie Attachments). Der Agent tut, was WordPress beim Weg in den Papierkorb tut: `post_status = trash`, `__trashed` an `post_name`, der alte Name in `_wp_desired_post_slug`, dazu `_wp_trash_meta_status` und `_wp_trash_meta_time` |

Werte sind **normalisiert** – sie tragen die Platzhalter `⟦wpsync:origin⟧`, `:esc1`, `:esc2`
statt einer Domain; der Agent setzt die Adresse des Ziels ein, auf der Staging-Kopie die der
Kopie. Die Werte eines Meta-Paars schreibt der Agent in der Reihenfolge des Pakets; im Abdruck
zählen sie als sortierte Menge, eine reine Umsortierung ist deshalb weder Änderung noch Konflikt.
Optionen werden nie gelöscht. `home` ist `live.home` aus `map.json`.

**Neue IDs:** Der Korridor im Kopf kommt aus dem Paket und begrenzt allein nichts. Der Agent
prüft deshalb jede neue ID in `posts`, `terms` und `term_taxonomy` zusätzlich gegen das Ziel: sie
darf höchstens `limits.id_headroom` (2.000.000) über der höchsten ID liegen, die die Tabelle dort
schon vergeben hat – `max(MAX(id), AUTO_INCREMENT − 1)`, dieselbe Rechnung wie `id_max` im
Manifest-Kopf; auf der Staging-Kopie zählen deren Tabellen – und nie über 2^53 − 1. Sonst
`id_outside_corridor` mit `error.keys`. Zwei Millionen, weil neue Objekte der Arbeitskopie bei
`id_max` + 1.000.001 beginnen: so bleibt Platz für eine Million neuer IDs seit dem Pull. Der
Grund für die Grenze: ein Insert mit hoher ID verschiebt den `AUTO_INCREMENT` der Tabelle
dauerhaft; **auch eine Rücknahme setzt den Zähler nicht zurück** (InnoDB senkt ihn nicht, wenn
die Zeile wieder verschwindet).

**Papierkorb und Datum:** WordPress gibt einem nie veröffentlichten Entwurf (`post_date_gmt` =
`0000-00-00 00:00:00`) beim Weg in den Papierkorb das Datum des Verschiebens. Weichen
`post_date` oder `post_date_gmt` des lokal verschobenen Beitrags von der Baseline ab, trägt die
Zeile `trash` deshalb `row` mit genau diesen beiden Spalten (base64, `JJJJ-MM-TT hh:mm:ss`); der
Agent setzt sie zusammen mit Status und Name, und Export ↔ Baseline ist danach leer. Ohne
Abweichung fehlt `row`. Jede andere Spalte, nur eine der beiden oder ein anderes Format ist
`package_invalid`. Liegt der Beitrag auf dem Ziel schon im Papierkorb, schreibt `trash` nichts –
auch die Daten nicht.

**Papierkorb:** Für einen Beitrag, der lokal in den Papierkorb gegangen ist, gehört genau eine
Zeile ins Paket – `op: trash` für `posts`. Was WordPress dabei lokal am Beitrag hinterlässt (das
Suffix am Namen, `_wp_desired_post_slug`, `_wp_trash_meta_*`), schreibt der Agent auf dem Ziel
selbst; Zeilen dafür im Paket sind `package_invalid`. Die Antwort des Commits nennt die
geschriebenen Schlüssel, die CLI zieht Manifest und Baseline nach – die Arbeitskopie weicht
danach für diesen Beitrag nicht mehr von der Site ab.

### Was der Agent prüft

Im Probelauf (`--dry-run`) und beim Anwenden dasselbe, beim Anwenden unter
Sperre noch einmal. Der Probelauf braucht kein Push-Fenster – **ohne offenes Fenster prüft er
aber nur einen Teil**: die Antwort soll niemandem, der nur das Secret hat, verraten, welche
Beiträge, Terme und Dateien es auf der Site gibt. Was er deshalb offen lässt, ist kein Fehler,
sondern steht in einer eigenen Liste – getrennt von `content.conflicts` und einer Ablehnung:

```json
"content": {"partial": true, "unchecked_total": 2, "unchecked": [
  {"table": "postmeta", "key": "999\u0000_farbe", "check": "reference"},
  {"table": "postmeta", "key": "300\u0000_wp_attached_file", "check": "attachment_files"}
]}
```

- `reference`: die Zeile hängt an einem Objekt oder zeigt auf eines (Beitrag einer Meta oder
  Zuordnung, Term einer Term-Meta oder `term_taxonomy`-Zeile, `term_taxonomy`-Zeile einer
  Zuordnung, Beitrag oder Term aus einer Option), das nicht im Paket liegt und das es auf dem Ziel
  nicht gibt – **oder nur mit einem gesperrten Typ bzw. einer gesperrten Taxonomie**. Welches von
  beiden, sagt der Agent ohne Fenster bewusst nicht: ein solches Objekt gilt in jeder Prüfung als
  fehlend, die Antwort ist in beiden Fällen dieselbe. Sonst liessen sich die IDs von Bestellungen
  oder Formular-Einträgen abzählen. Mit Fenster wäre die Zeile `dangling_reference` oder
  `blocked_row`
- `attachment_files`: die Zeile nennt Dateien eines Attachments (`upload_missing` und die Prüfung
  ihrer Pfade entfallen)

Jede Zeile steht höchstens einmal in der Liste (`reference` vor `attachment_files`), in der
Reihenfolge des Pakets; höchstens 200 Einträge, `unchecked_total` nennt alle – wie `error.keys`
und `error.total`. `blocked_row` bleibt auch ohne Fenster, was sich allein aus der Zeile oder dem
Paket ergibt (gesperrter Meta-Schlüssel, gesperrte Option, Typ eines im Paket eingefügten
Objekts, Erweiterungen, Form des Schlüssels); `conflict` bleibt, wobei eine Zeile an einem
gesperrten Objekt wie eine an einem gelöschten erscheint. Ungeprüftes allein lässt den Probelauf
mit Exit 0 enden; ohne `--json` nennt eine Zeile die Zahl. Mit offenem Fenster ist `partial`
`false`, `unchecked` leer und die Prüfung vollständig; der echte Push läuft immer so. Die CLI legt das Paket dafür vor dem Begin auf der Site ab (`/content/stage`,
in Stücken, je Kopplung getrennt im geschützten Push-Arbeitsordner, adressiert über die sha256
der Datei; es verfällt nach dem bestätigten Push nach Live, spätestens nach 24 Stunden). Jede
Ablehnung endet mit Exit 1 und `error.reason`; `error.keys` nennt die betroffenen Zeilen als
`{table, key}`, nie einen Wert:

| `error.reason` | Bedeutung |
|---|---|
| `package_invalid` | Form, Prüfsumme, Zeilenende, doppelter Schlüssel, Platzhalter in unbekannter Form, Sprung in den Papierkorb ohne `op: trash`, `row` eines `trash` mit anderem als `post_date` und `post_date_gmt`, eine Zeile für `_wp_trash_meta_status`, `_wp_trash_meta_time` oder `_wp_desired_post_slug` an einem Beitrag mit `op: trash` |
| `baseline_outdated` | andere `canon_version` oder Varianten; lokal: das Paket gehört nicht zum Inhaltsstand dieses Site-Ordners (`map_id`) |
| `origin_mismatch` | das Paket ist für eine andere Adresse gebaut, oder `home` und `siteurl` der Site haben verschiedene Origins |
| `package_too_large` | mehr als `limits.max_rows` (5.000) Zeilen oder `limits.max_bytes` (8 MB), oder die Zeilen, die das Paket auf dem Ziel trifft, sind zusammen grösser als `limits.max_state_bytes` (64 MB; `error.state_bytes` nennt die Summe) – in mehreren Pushes übertragen |
| `engine_unsupported` | eine der sieben Inhaltstabellen des Ziels ist nicht InnoDB (`error.tables`; geprüft werden immer alle, auch vor einer Rücknahme), oder die Site verteilt ihre Datenbankabfragen über HyperDB bzw. LudicrousDB |
| `list_version_mismatch` | das Paket ist mit einer anderen Version der Listen gebaut als der des Agents |
| `blocked_row` | die Zeile steht auf der Sperrliste oder nicht auf der Whitelist des Agents; ein Name mit anderen Zeichen als `A–Z a–z 0–9 _ . : -`; auf dem Ziel gibt es denselben Schlüssel in anderer Gross-/Kleinschreibung; ein Attachment nennt eine Datei, die nicht unter `uploads` liegen darf |
| `unsafe_value` | ein Wert trägt ein serialisiertes Objekt (`O:`, `C:`, `E:` – auch verschachtelt), beginnt wie eines (auch mit Text dahinter, den `unserialize()` hinnähme) oder sieht serialisiert aus und lässt sich nicht lesen |
| `local_origin_in_package` | im Wert steckt noch der Host der Arbeitskopie (`local_host`), auch URL-kodiert |
| `pseudonym_in_package` | ein Wert trägt ein Pseudonym-Muster des Pulls; `error.keys[].pattern` nennt das Muster |
| `write_mismatch` | der Wert ergäbe auf dem Ziel einen anderen Abdruck als in der Arbeitskopie – etwa weil die Adresse des Ziels wörtlich darin steht; nach dem Schreiben liest der Agent zurück und prüft dasselbe noch einmal |
| `id_outside_corridor`, `id_taken` | ein neues Objekt liegt ausserhalb des ID-Korridors des Pakets, mehr als `limits.id_headroom` (2.000.000) über der höchsten ID, die das Ziel in dieser Tabelle schon vergeben hat, oder über 2^53 − 1 – bzw. seine ID ist auf dem Ziel belegt |
| `conflict` | die Zeile hat auf dem Ziel nicht mehr den Abdruck aus dem Manifest – **alle** betroffenen Schlüssel stehen in `error.keys` und im `plan`-Ereignis unter `content.conflicts`. Kein `--force` |
| `row_unfaithful` | die Zeile lässt sich auf dem Ziel nicht normalisieren |
| `dangling_reference` | eine Zeile hängt an einem Objekt, das es weder auf dem Ziel noch im Paket gibt (Meta ohne Beitrag, Zuordnung ohne Term, `page_on_front`, `site_icon`, `elementor_active_kit`, `theme_mods_*`) |
| `upload_missing` | eine Datei eines Attachments (`_wp_attached_file`, aus `_wp_attachment_metadata` `file`, `sizes.*.file`, `original_image`, aus `_wp_attachment_backup_sizes` `file` jedes Eintrags – die Schlüssel in jeder Gross-/Kleinschreibung) liegt weder auf dem Ziel noch in `--uploads`; `error.paths` nennt sie. Geprüft wird der Wert, wie er geschrieben wird; ein Pfad, der nicht unter `uploads` liegen darf, ist `blocked_row` |
| `author_unknown` | das Paket legt Beiträge an, das Push-Fenster wurde aber nicht im WP-Admin geöffnet – der Benutzer, der es öffnet, wird ihr Autor – oder diesen Benutzer gibt es beim Anwenden nicht mehr. Erst beim echten Push, nicht im Probelauf |
| `package_missing` | das Paket liegt nicht (mehr) auf der Site |
| `content_failed` | Datenbank oder Dateisystem haben versagt; nichts wurde übernommen. Einzige Ausnahme: nennt der Fehler eine Zeile (`error.keys`, in der Antwort des Agents `unrestored: true`), ging die Verbindung mitten in der Transaktion verloren, und genau diese Zeile liess sich nicht auf ihren Stand davor zurücksetzen – sie ist von Hand zu prüfen. Der Arbeitsordner dieses Pushs bleibt dann mit dem Vorher-Abbild auf dem Server liegen (Status `failed`, nicht aufgeräumt) |
| `changed_since_push` | nur bei `rollback`: siehe unten |
| `before_image_invalid` | nur bei `rollback`: das Vorher-Abbild des Pushs lässt sich nicht öffnen, wurde verändert oder passt nicht zu dem, was der Push geschrieben hat – nichts wird zurückgenommen |

Die **Listen** gehören dem Agent (`ContentLists`, Version im Manifest-Kopf unter `list_version`
und `lists`); die Angaben im Paket sind nur ein Abgleich. **Projekt-Erweiterungen**
(`extensions`) kommen aus dem Paket selbst und sind deshalb eng begrenzt: Beitragstypen,
Taxonomien und einzelne Ausnahmen von den Wortlisten für Meta-Schlüssel (`meta_word`) – nie Optionen,
nie Tabellen. Erwartete Abdrücke nimmt der Agent
nie auf Treu und Glauben: er rechnet den aktuellen Abdruck jeder Zeile selbst.

Was **keine** Erweiterung freischaltet (das Paket ist sonst `package_invalid`; die Listen stehen
im Manifest-Kopf unter `lists`):

- **Beitragstypen** der festen Liste `never_post_types` – Revisionen und andere Interna,
  Code-Träger (Snippet-Plugins, `custom-css-js`, Vorlagen von Oxygen, Bricks und Beaver Builder),
  Shop (WooCommerce, Easy Digital Downloads), Mitgliedschaft und Kurse (MemberPress, LearnDash),
  Formulare und ihre Einträge (Contact Form 7, WPForms, Forminator, Formidable, Fluent Forms,
  MC4WP), Weiterleitungen, geplante Aktionen –, dazu jeder Typ mit einem Präfix aus
  `never_post_type_prefixes` (`shop_`, `wc_`, `edd_`, `memberpress`, `sfwd-`, `llms_`, `tutor_`,
  `ld-`, `frm_`, `forminator_`, `wpforms`, `nf_`, `jp_`, `amp_`, `flamingo_`, `wpcode`) und jeder,
  dessen Name eines der Wörter aus `never_post_type_words` enthält (`snippet`, `code`, `redirect`,
  `webhook`, `payment`, `order`, `subscription`, `membership`, `coupon`). Was der Pull
  pseudonymisiert, ebenfalls nicht.
- **Taxonomien** aus `never_taxonomies` (`action-group`, `user-group`, `link_category`,
  `product_type`, `product_visibility`, `shop_order_status`) und jede mit `user`, `role` oder
  `cap` im Namen (`never_taxonomy_words`). Produktattribute (`pa_*`) und -kategorien sind nicht
  pauschal gesperrt.
- **Zuordnungen an Objekte, die keine Beiträge sind:** eine Zuordnung (`term_relationships`)
  braucht immer einen Beitrag erlaubten Typs auf dem Ziel oder im Paket. Für eine Taxonomie aus
  einer Erweiterung prüft der Agent zusätzlich, wofür die Site sie registriert hat: ist sie (auch)
  für Benutzer registriert, ist jede ihrer Zeilen `blocked_row`; ist sie nicht für den Typ des
  Beitrags registriert, ist die Zuordnung `blocked_row`. In `term_relationships` liesse sich die
  ID eines Benutzers sonst nicht von der eines Beitrags unterscheiden.

Die Erweiterungen, mit denen ein Paket gebaut ist, bleiben **sichtbar**: im Probelauf
(`content.extensions` im `plan`-Ereignis), im Datensatz des Pushs an der Einheit `content`
(`wpsync pushes --json`: `units[].extensions` mit `post_types`, `taxonomies`,
`meta_exceptions`; ohne `--json` hinter der Einheit) und im WP-Admin in der Liste der Pushes.
Das Feld fehlt, wenn ein Paket keine Erweiterung nennt. Ob eine Site Erweiterungen überhaupt
zulässt, entscheidet bisher allein das Paket – eine Freigabe auf der Site gibt es noch nicht.

### Anwenden, Health-Check, Rücknahme

- **Reihenfolge:** Uploads → Code → Inhalte, in einem Commit. Die Inhalte kommen in **einer
  Transaktion**; vorher schreibt der Agent das Vorher-Abbild der betroffenen Zeilen in den
  Arbeitsordner des Pushs (`content/before.json`), danach die neuen Abdrücke
  (`content/after.json`). Scheitert etwas, bleibt keine Zeile, und Code und Uploads werden
  zurückgetauscht.
- **Die Abbilder liegen geschützt:** `before.json` trägt Inhalte der Site, und die Rücknahme
  schreibt zurück, was darin steht. Mit einem Schlüssel der Installation (`WPSYNC_KEY`, sonst
  `AUTH_KEY` und `SECURE_AUTH_KEY` aus `wp-config.php` – derselbe wie für die Pairing-Secrets,
  nie aus der Datenbank) legt der Agent beide Dateien verschlüsselt und authentisiert ab
  (XSalsa20-Poly1305, mit der PHP-Erweiterung `sodium`), ohne die Erweiterung lesbar, aber mit
  einem HMAC-SHA256; der Schlüssel jeder Datei ist an den Push und an ihren Namen gebunden.
  Dateien und Ordner sind nur für den Besitzer lesbar (0600/0700). Die Rücknahme prüft beides
  vor dem Lesen, dazu die Form jedes Schlüssels, und nimmt nur Schlüssel zurück, die `after.json`
  als vom Push geschrieben nennt – sonst `before_image_invalid`, nichts wird geschrieben. Sie
  geht weiter ohne Gerät über den WP-Admin. Werden `WPSYNC_KEY` oder die Salts nach einem Push
  geändert (auch Sicherheits-Plugins erneuern Salts), lassen sich die Inhalte dieses Pushs nicht
  mehr zurücknehmen; ein eigener `WPSYNC_KEY` vermeidet das. Ohne jeden Schlüssel (keine Salts,
  kein `WPSYNC_KEY`) bleiben die Abbilder Klartext ohne Schutz – die Admin-Seite warnt dann
  ohnehin. `rescue.php` liest die Abbilder nur mit den Dateischlüsseln aus dem Umschlag des
  Pushs und nimmt ein Klartext-Abbild nie an
  ([Rücknahme ohne WordPress](#rücknahme-ohne-wordpress)).
- **Was der Agent selbst setzt:** `post_modified` (Zeit des Pushs), bei neuen Beiträgen
  `post_author` (wer das Push-Fenster geöffnet hat) und `guid`, bei neuen Optionen `autoload`.
  `post_author` und `guid` bestehender Beiträge bleiben.
- **Messwert** (`data.content: {"rows","seconds"}`): Zeilen des Pakets und die Sekunden, die der
  Agent für das Anwenden in seiner Transaktion gebraucht hat; ohne `--json` die Zeile „Inhalte:
  N Zeilen in X s angewandt“. Fehlt im Probelauf und sobald die Inhalte zurückgenommen sind.
- **Nacharbeiten** (`data.post_actions: [{"step","ok"}]`): Object-Cache der betroffenen Objekte,
  Elementor-CSS, Yoast-Indexables, bekannte Cache-Plugins (WP Rocket, W3 Total Cache, LiteSpeed,
  SG Optimizer, Breeze), Rewrite-Regeln bei Bedarf, Term-Zähler, eine Revision je geänderter
  Seite – jeder Schritt nur, wenn das Plugin da ist. Ein Fehlschlag (`ok: false`) ist kein
  Fehler des Pushs.
- **Health-Check:** zusätzlich die veröffentlichten Seiten, die das Paket ändert (höchstens 10).
  Wird die Site schlechter, geht die Rücknahme **zuerst über den Agent** (Inhalte → Code →
  Uploads, mit Nacharbeiten). Lehnt er ab (etwa `changed_since_push`), bleibt der Satz
  ganz. Antwortet er nicht, mit einem Serverfehler oder mit einer fremden Seite, nimmt `rescue.php`
  den Satz ohne WordPress zurück – ab Agent 0.8.0 samt Inhalten: Exit 43, `via: "rescue"`, ohne
  Warnung ([Rücknahme ohne WordPress](#rücknahme-ohne-wordpress)). Kann `rescue.php` die Inhalte
  nicht zurücknehmen (älterer Agent, kein Umschlag, seit dem Push geänderte Zeilen), gehen Code und
  Uploads trotzdem zurück, und das Ergebnis sagt es: Exit 43 mit
  `warnings: ["content_not_rolled_back"]` und, soweit `rescue.php` einen Grund nennt,
  `content_error`. Der Push
  bleibt dann offen (weitere Pushes: Exit 42); `wpsync rollback <site> <push-id>` holt die
  Inhalte nach, sobald WordPress wieder antwortet. Geht das nicht mehr (`changed_since_push`)
  oder sollen die Inhalte bleiben, schliesst `wpsync pushes <site> --confirm <push-id>` den Push
  ab – nicht als bestätigt, sondern als zurückgerollt: Code und Uploads sind zurück, die Inhalte
  bleiben stehen (`status: "rolled_back"`, `warnings: ["content_kept"]`). Das Vorher-Abbild wird
  dabei aufgeräumt; zurücknehmen lassen sich diese Inhalte danach nicht mehr.
- **`rollback`:** stellt das Vorher-Abbild her – an bestehenden Zeilen nur die Spalten, die der
  Push geschrieben hat (Kommentarzahl, Autor, `guid`, `autoload` bleiben, wie sie inzwischen
  sind) –, löscht eingefügte Objekte samt Meta und
  Zuordnungen und holt Beiträge aus dem Papierkorb – aber nur, wenn jede betroffene Zeile noch
  den Abdruck trägt, den der Push hinterlassen hat, **und** an den eingefügten Beiträgen, Termen
  und `term_taxonomy`-Zeilen nichts hängt, was nicht vom Push stammt: weitere Meta-Schlüssel,
  Zuordnungen (an einer eingefügten `term_taxonomy` die anderer Beiträge), Kommentare, Revisionen
  und Kindseiten (`post_parent`), weitere Taxonomien eines Terms, Kind-Terme. Sonst
  `error.reason: "changed_since_push"` mit `error.keys` – auch für das Dazugekommene (Kommentare
  als `{"table":"comments","key":"<post-id>"}`, eine Zuordnung ohne `term_taxonomy`-Zeile als
  `"<post-id>\u0000"`): **nichts** wird zurückgenommen, auch Code und Uploads nicht – solange der
  Agent antwortet, geht die CLI nie an ihm vorbei über `rescue.php`. (Antwortet er nicht, gelten
  im Notfallweg eigene Regeln: [Rücknahme ohne WordPress](#rücknahme-ohne-wordpress).) Nicht als Änderung zählt Meta, die WordPress, Elementor oder der Agent selbst an
  einen neuen Beitrag hängen und die kein Paket schreiben darf – die feste Sperrliste
  (`_edit_lock`, `_edit_last`, `_wp_old_slug`, `_wp_trash_meta_*`, `_elementor_css` …) und der
  oEmbed-Cache (`_oembed_*`); sie geht mit dem Beitrag. Scheitert umgekehrt die Rücknahme des Codes, nachdem die Inhalte schon zurück
  sind, sagt die Meldung das; ein zweiter `rollback` fasst die Datenbank nicht mehr an und holt
  nur Code und Uploads nach.
- **Manifest und Baseline:** Nach einem bestätigten Push nach Live schreibt die CLI die neuen
  Abdrücke nach `manifest.jsonl` und die Zeilen des Pakets nach `baseline.jsonl` – das Gepushte
  ist danach keine lokale Änderung mehr. `rollback` nimmt das zurück. Bei `--to staging` bleiben
  beide unverändert: sie beschreiben Live, und dasselbe Paket geht danach nach Live.
  Die Antwort des Commits ist dabei das Wort der Site, und die CLI prüft sie gegen das Paket:
  Abdrücke nimmt sie nur für Schlüssel des Pakets an und für die drei Meta-Paare, die der Agent
  beim Papierkorb selbst schreibt (`_wp_trash_meta_status`, `_wp_trash_meta_time`,
  `_wp_desired_post_slug` an einem Beitrag mit `op: trash`) – nennt die Antwort einen anderen
  Schlüssel, bleiben Manifest und Baseline unangetastet und das Ergebnis trägt
  `warnings: ["content_state_failed"]` (dann `wpsync pull <site> --content`). Nennt die Antwort
  eine andere Zahl angewandter Zeilen, als das Paket hat, wird der Satz nicht bestätigt, sondern
  zurückgenommen – wie wenn der Agent die Inhalte gar nicht angewandt hätte.
- **Staging:** dieselben Abdrücke gelten auf der Kopie; der Agent schreibt nur in ihre Tabellen
  und setzt ihre Adressen ein. Nacharbeiten gibt es dort nur, soweit sie sich mit SQL und Dateien
  sagen lassen. `staging refresh` und `staging delete` verwerfen ein dort angewandtes Paket.
- **Sperren:** Die Transaktion liest jede betroffene Zeile mit Sperre. Sie wartet dabei höchstens
  10 Sekunden auf eine Sperre, die jemand anderes hält (`innodb_lock_wait_timeout` der Sitzung,
  soweit der Server es zulässt; danach gilt wieder der Wert der Site). Läuft die Zeit ab, endet
  der Push mit `content_failed` – nichts ist geschrieben, Code und Uploads gehen zurück.
- **Grenzen:** Kein Multisite, nur InnoDB, eine Datenbankverbindung: Lesen unter Sperre und
  Schreiben müssen auf demselben Server landen. Mit Drop-ins, die Abfragen auf mehrere Server
  verteilen, ist das nicht garantiert – HyperDB und LudicrousDB lehnt der Agent ab
  (`engine_unsupported`), andere Datenbank-Proxys erkennt er nicht und unterstützt er nicht.
  Abfrage-Caches bittet er für den Request um Zurückhaltung (`DONOTCACHEDB`), und die Frage nach
  der Sitzungsmarke ist jedes Mal ein anderer Text – ein Cache kann sie nicht beantworten. Eine
  erweiterte `wpdb`-Klasse (Query Monitor u. a.) ist kein Hindernis. Benutzer, Kommentare und Plugin-Tabellen pusht der
  Kanal nie.
  Steht die Adresse der Live-Site wörtlich in einem lokalen Wert, lehnt der Agent ihn ab
  (`write_mismatch`).

### Rücknahme ohne WordPress

Legt ein Push WordPress lahm, antwortet der Agent nicht mehr – auch dann nicht, wenn der
**Inhalt** der Auslöser ist (eine kaputte Option, ein serialisierter Wert, ein Template). Ab
Agent 0.8.0 nimmt `rescue.php` deshalb den ganzen Satz zurück, ohne WordPress zu laden: **Inhalte →
Code → Uploads**, mit derselben Logik wie der Agent (Abdruckvergleich, Sperren, eine Transaktion).

- **Der Umschlag.** Beim echten Begin eines Pushs mit Inhalten legt der Agent
  `<arbeitsordner>/<push-id>/rescue.sealed` ab: die Verbindung zur Datenbank, so wie WordPress sie
  gerade aufgebaut hat (Host, Port, Socket, Benutzer, Passwort, Zeichensatz, `sql_mode` der
  Sitzung), Präfix und Adressen des Ziels und die Dateischlüssel der beiden Abbilder dieses Pushs.
  `rescue.php` liest **nie** `wp-config.php` – Bedrock, `.env`, `getenv()` und `WP_HOME` als
  Konstante brauchen deshalb keine Sonderbehandlung. Der Umschlag ist verschlüsselt und
  authentisiert (XSalsa20-Poly1305 mit `sodium`, sonst AES-256-GCM mit `openssl`) mit einem
  Schlüssel, der aus dem Rollback-Schlüssel des Pushs abgeleitet ist; auf dem Server liegt nur
  dessen Hash. Öffnen kann ihn also nur, wer pushen darf. Er verschwindet nach gelungener
  Rücknahme, bei `confirm`, mit dem Arbeitsordner und spätestens nach 24 Stunden.
- **Vor dem Push geprüft.** Der Agent probiert die Verbindung des Umschlags beim Begin aus (zweite
  Verbindung, Datenbank, Sitzung, die sieben Tabellen) und antwortet mit `rescue.db: {ok, reason?}`.
  Gibt es keinen Umschlag, wird **trotzdem gepusht** – die CLI sagt „Notfall-Rücknahme der Inhalte
  nicht möglich (<grund>) – bei einem Ausfall gehen nur Code und Uploads zurück“ und das Ergebnis
  trägt `warnings: ["rescue_db_unavailable"]`. Mit `--require-rescue-db` wird dann nicht gepusht:
  Exit 1, `error.reason: "rescue_db_unavailable"`, `error.detail: <grund>`.

  | Grund | Bedeutung |
  |---|---|
  | `no_crypto` | PHP hat weder `sodium` noch `openssl` mit `aes-256-gcm` |
  | `driver` | WordPress spricht die Datenbank nicht direkt über `mysqli` an (Drop-in `db.php`, anderer Treiber) |
  | `no_image_key` | Die Installation hat keinen Schlüssel (`WPSYNC_KEY` bzw. Salts): die Abbilder lägen als Klartext da |
  | `probe_failed` | Die Probe scheiterte – oder der Arbeitsordner lässt sich nicht sperren (`flock`) |
  | `write_failed` | Der Umschlag liess sich nicht schreiben |
  | `agent_outdated` | (nur CLI, mit `--require-rescue-db`) der Agent ist älter als 0.8.0 |

- **Reihenfolge und Wiederholung.** `rescue.php` prüft zuerst den Schlüssel; bis dahin lädt es
  nichts ausser zwei Klassen, liest keinen Umschlag und öffnet keine Datenbankverbindung. Ein
  bestätigter oder von einem späteren Push überholter Push wird abgelehnt, bevor die Datenbank
  berührt wird. Dann die Inhalte, ihr Ausgang steht in `rescue.json`, dann Code, dann Uploads.
  Jeder Aufruf mit richtigem Schlüssel ist wiederholbar: scheitert der Code nach gelungener
  Rücknahme der Inhalte, überspringt der nächste Aufruf die Datenbank.
- **Wenn die Inhalte nicht zurückgehen**, gehen Code und Uploads trotzdem zurück – der Rückweg ist
  nie schlechter als ohne Inhalte. Das Ergebnis trägt `warnings: ["content_not_rolled_back"]` und
  `content_error: {code, keys?, total?}`; der Push bleibt offen wie oben beschrieben.

  | `content_error.code` | Bedeutung |
  |---|---|
  | `changed_since_push` | Eine Zeile, die der Push geschrieben hat, wurde seither geändert (`keys`). In der Datenbank wurde nichts angefasst. Es gibt kein `--force` |
  | `before_image_invalid` | Das Vorher-Abbild fehlt nicht, lässt sich aber nicht öffnen oder wurde verändert |
  | `engine_unsupported` | Eine der sieben Tabellen ist nicht (mehr) InnoDB |
  | `content_failed` | Die Datenbank hat versagt (Sperre, Verbindung verloren) – nichts ist geschrieben |
  | `rescue_db_unavailable` | Kein Umschlag, oder er passt nicht zu Push, Schlüssel oder Ordner |
  | `db_unreachable` | Die Datenbank war mit den Daten des Umschlags nicht erreichbar |

- **Was an neuen Objekten hängt.** Über den Agent lehnt die Rücknahme ab, sobald an einem vom
  Push eingefügten Beitrag oder Term etwas hängt, das nicht vom Push stammt. Im Notfallweg –
  die Site ist unten – blockiert das nicht: `rescue.php` nimmt zurück, was der Push geschrieben
  hat, und **lässt das Fremde stehen** (verwaiste Meta, Zuordnungen, Kommentare, Kindseiten). Das
  Ergebnis nennt es: `warnings: ["content_left_extra"]`, `content_left: [{table, key}]`
  (höchstens 200, `content_left_total`). Gelöscht oder überschrieben wird nur, was der Push selbst
  geschrieben hat – auch Meta, die WordPress an den neuen Beitrag gehängt hat (`_edit_lock` …),
  bleibt dann verwaist liegen.
- **Persistenter Object-Cache.** Mit Redis oder Memcached hält `alloptions` den gepushten Wert,
  auch wenn die Datenbank schon zurück ist. Sagt `rescue.php` deshalb `cache: "stale"` (Live, es
  wurden Zeilen zurückgeschrieben, `wp-content/object-cache.php` liegt da), ruft die CLI einen
  zweiten, getrennten Schritt (`action=cache`): er lädt WordPress mit `SHORTINIT` – ohne Plugins,
  Themes und mu-plugins – und ruft `wp_cache_flush()`. Geleert wird der ganze Cache der Site;
  teilen sich mehrere Sites eine Redis-Instanz ohne eigenes Präfix, trifft es auch sie. Scheitert
  der Schritt (Drop-in defekt, Cache-Server weg, Wartungsmodus), bleibt die Rücknahme gültig, und
  die CLI meldet `warnings: ["object_cache_stale"]`: „Der Object-Cache der Site trägt noch den
  gepushten Stand – beim Hoster leeren, falls die Site nicht antwortet.“
- **Nacharbeiten.** Elementor-CSS, Indexables, Cache-Plugins, Rewrite-Regeln, Term-Zähler und
  Revisionen brauchen WordPress. Der Agent holt sie nach, sobald WordPress wieder lädt (beim
  nächsten Seitenaufruf, für eine Staging-Kopie beim nächsten wpsync-Aufruf oder im täglichen
  Cron); bis dahin kann ein Seiten-Cache alte Seiten zeigen und Elementor-CSS den gepushten Stand
  tragen. Danach steht der Push als zurückgerollt im Protokoll, `pushes --json` nennt an der
  Einheit `content` `via: "rescue"` und `post_actions`, und Arbeitsordner, Abbilder und Umschlag
  sind weg.
- **`wpsync rollback`** geht denselben Weg: Agent zuerst, ohne Antwort `rescue.php`. Sind die
  Inhalte zurück, setzt die CLI Manifest, Baseline und Journal zurück wie bei der Rücknahme über
  den Agent.
- **Grenzen.** Kein Überschreiben geänderter Zeilen: ist der Inhalt die Ursache des Ausfalls
  **und** wurde eine seiner Zeilen seit dem Push geändert, bleibt die Site unten, bis jemand die
  Zeile von Hand richtet. Bestätigte Pushes nimmt `rescue.php` nie zurück. Der Umschlag gilt
  24 Stunden – ein Push, der länger unbestätigt liegt, hat danach nur noch den Rückweg für Code
  und Uploads. Multisite, HyperDB/LudicrousDB und andere Treiber als `mysqli`: wie beim Push
  nicht unterstützt. Der Cache-Schritt setzt das Standardlayout voraus (`wp-load.php` im Webroot).

| CLI | Agent | Verhalten |
|---|---|---|
| ≥ 0.8.0 | 0.7.x | kein `rescue.db`; `rescue.php` nimmt nur Code und Uploads zurück (`content_not_rolled_back`) |
| 0.7.x | ≥ 0.8.0 | die CLI bittet nicht um die Inhalte: wie oben; der Umschlag liegt ungenutzt und verfällt |

## Staging auf dem Server

Eine Kopie der Live-Site auf dem Server des Kunden – mit dessen PHP, Datenbank und Plugins –,
um gepushten Code zu testen, bevor er nach Live geht. Agent 0.5.0 oder neuer.

Alle Staging-Befehle ausser `status` brauchen ein offenes Push-Fenster (WP-Admin → Werkzeuge →
wpsync), genau wie ein Push.

```sh
wpsync staging create kunde            # Kopie anlegen (anonymisiert, nach Pull-Profil)
wpsync staging open kunde              # Einmal-Link: Zugang + Anmeldung als Admin wpsync
wpsync push kunde code themes/x --to staging
wpsync push kunde code themes/x        # nach dem Test: derselbe Push nach Live
wpsync staging refresh kunde [--code]  # Datenbank (und Code) neu von Live
wpsync staging status kunde
wpsync staging delete kunde
```

**Wie die Kopie aussieht.** Ordner `wpsync-staging-<zufall>/` im WordPress-Verzeichnis, Tabellen
mit eigenem Präfix `stg<zufall>_` in derselben Datenbank, eigene `wp-config.php` (eigene Salts,
Cookies nur unter dem Staging-Pfad, kein Cache, kein WP-Cron, keine Updates). Code und Datenbank
kommen nach dem Pull-Profil – `staging create` braucht also ein Profil aus `wpsync scan`. Die
Daten verlassen die Datenbank dabei nicht. Personenbezogene Daten sind pseudonymisiert wie beim
Pull (gleiche Pseudonyme); Klartext nur mit `--no-anonymize` und eigener Bestätigung.

**Was die Kopie nicht bekommt.**

- Uploads: fehlende Medien leitet die Kopie auf Live weiter (302).
- `wp-config.php` von Live und jede Datei, deren Name mit `wp-config` beginnt (Sicherungen wie
  `wp-config.php.bak`), `wp-admin/setup-config.php`, `.user.ini`.
- Jede `.htaccess` in einem Unterordner, die eine Rewrite-Direktive enthält: sie nähme ihrem
  Ordner die Zugangssperre der Kopie. Ein Push nach Staging entfernt eine solche Datei ebenfalls
  aus der Einheit; die CLI nennt sie. Ein Push nach Live überträgt sie unverändert.
- Den Agent, Drop-ins (`advanced-cache.php`, `object-cache.php`, `db.php` …), Symlinks,
  VCS-Ordner und alles unter `wp-content`, was auch der Pull auslässt (Logs, `.env*`, Dumps …).
- Plugins, die das Profil abwählt, sowie Caching-, SMTP- und Sicherheits-Plugins sind in der
  Kopie nicht aktiv; Tabellen, die das Profil ausschliesst, existieren dort leer.

**Zugang.** Ohne Zugangs-Cookie liefert die Kopie 403. `wpsync staging open` holt einen Link,
der 5 Minuten und genau einmal gilt, das Cookie setzt (12 Stunden) und als Staging-Admin `wpsync`
anmeldet. Einlösen lässt er sich nur am Einstieg der Kopie (`/wpsync-staging-<zufall>/`), an
keiner anderen Adresse. Es gibt kein Passwort; einem Kunden zeigt man Staging per Bildschirm.
Während ein Job läuft (`create`, `refresh`, `delete`), nach einem gescheiterten `refresh` oder
`delete` und nach dem Verfall liefert die Kopie für alles 403 – auch mit Cookie.

**Push-Fenster.** `staging create`, `refresh`, `delete` und `open` gehen nur, solange ein
Administrator das Push-Fenster des Geräts geöffnet hat; sonst Exit 40 und auf dem Server ändert
sich nichts. Grund: Wer Administrator der Kopie ist, kann dort PHP ausführen (etwa über ein
Snippet-Plugin, das mitkopiert wurde) – im selben Server-Benutzer und mit den
Datenbank-Zugangsdaten von Live. Ohne Fenster reicht ein entwendetes Pairing-Secret dafür nicht.
`staging status` und das Fortsetzen eines laufenden Jobs brauchen kein Fenster; ein neuer Job
startet ohne Fenster nie.

**Eigenes Browserprofil.** Die Kopie liegt unter derselben Adresse (Origin) wie Live. Ein Skript
in der Kopie – eingeschleust oder ungetesteter gepushter Code – kann im Browser deshalb alles,
was die Live-Sitzung desselben Browsers darf; getrennte Cookie-Pfade schützen davor nicht.
Die Kopie nie in einem Browser(-profil) öffnen, in dem jemand bei Live im WP-Admin angemeldet
ist: privates Fenster oder eigenes Profil, den Link dafür mit `wpsync staging open <site> --print`.
`staging open` erinnert jedes Mal daran.

**Riegel.** Mails gehen an `blocked@mailguard.invalid` (auch mit SMTP-Plugin). Anfragen an
Mail-, Zahlungs- und Newsletter-Dienste und an Live selbst werden abgelehnt, auch als Ziel einer
Weiterleitung; als Live zählt die Domain mit und ohne `www.`, über `http` wie `https`. Eine
Adresse, die sich nicht eindeutig lesen lässt, ist gesperrt. Serverseitig darf die Kopie von Live
nur Uploads abrufen, und die nur ohne Query-String. WooCommerce-Webhooks sind pausiert,
Online-Zahlungsarten deaktiviert (Überweisung, Scheck, Nachnahme bleiben), kein
Action-Scheduler-Runner, `noindex`. Lässt sich der Zugang nicht prüfen oder ist der Riegel
unvollständig, sperrt die Kopie.

**Kein Weg zurück.** Es gibt keinen Befehl von Staging nach Live. Ein Push nach Staging ändert
weder die Baseline noch das interne Git; nach dem Test geht derselbe lokale Stand per
`wpsync push` nach Live – mit Push-Fenster, Konfliktprüfung und Rollback wie immer. Auch der
Push nach Staging braucht ein offenes Push-Fenster.

**Mehrmals nach Staging pushen.** Nach einem bestätigten Push nach Staging merkt sich die CLI in
`.wpsync/staging-base.json`, welche Dateien die gepushten Einheiten in der Kopie jetzt haben
(Grösse und Zeitstempel, wie der Agent sie meldet). Der nächste Push nach Staging vergleicht
diese Einheiten damit statt mit der Baseline – so geht „pushen, testen, nachbessern, wieder
pushen“ ohne `--force`, und eine Änderung direkt in der Kopie wird weiterhin als Konflikt
gemeldet. Ein Push nach Live liest die Datei nie. Sie gilt für genau einen Code-Stand der Kopie:
nach `staging create` und `staging refresh --code` wird sie verworfen und es zählt wieder der
letzte Pull; ein `staging refresh` ohne `--code` lässt sie gelten. Ein `rollback` eines
Staging-Pushs setzt die Einträge auf den Stand davor zurück. Die Datei ist mit dem Pairing-Secret
versiegelt; ist sie beschädigt oder fremd, wird sie ignoriert und gelöscht.

**Rollback.** `wpsync rollback <site>` ohne Push-ID nimmt den neuesten Push nach **Live** zurück,
auch wenn zuletzt nach Staging gepusht wurde. Für die Kopie: `wpsync rollback <site> --to staging`
oder die Push-ID. Passt `--to` nicht zum Ziel des genannten Pushs, bricht die CLI ab, ohne etwas
zurückzurollen. Snapshots werden pro Ziel aufbewahrt; `staging refresh --code` und
`staging delete` verwerfen die Pushes nach Staging samt Snapshots.

**Unterbrochener Lauf.** Trifft `staging create`, `refresh` oder `delete` auf einen laufenden Job
derselben Art, den ein abgebrochener Lauf auf dem Server gelassen hat, setzt derselbe Befehl ihn
fort – solange sein letzter Schritt höchstens 10 Minuten her ist und er zum Aufruf passt (gleiche
Art, gleiche Einstellung zu `--no-anonymize`, nicht mehr in der Probe). Sonst endet der Befehl
mit Exit 44; `wpsync staging status <site>` zeigt den Job. Nach den 10 Minuten setzt kein Befehl
mehr fort, dann räumt `wpsync staging delete <site>` auf. Ob ein laufendes `refresh` den Code
einschliesst, ist dabei nicht zu erkennen: ein `refresh --code` kann ein `refresh` ohne Code
fortsetzen und umgekehrt.

**Verfall.** Nach 14 Tagen ohne Nutzung wird die Kopie gesperrt (alles 403), nicht gelöscht.
`wpsync staging open` entsperrt sie. Deaktivieren des Agents löscht die Kopie samt Tabellen.

**Voraussetzungen.** Apache oder LiteSpeed, die `.htaccess` auswerten (Rewrite, Zugriffssperre).
Vor dem Anlegen prüft die CLI das von aussen mit drei Proben; auf reinem nginx gibt es kein
Staging, die Meldung nennt die nötige Server-Regel. Nicht unterstützt: Multisite, WordPress mit
abweichender WordPress- und Website-Adresse, `wp-content` ausserhalb des WordPress-Ordners,
Tabellen-Präfixe wie `s`, `st`, `stg` und Tabellen, deren Name sich mit dem Staging-Präfix nicht
abbilden lässt, sofern das Profil sie kopiert.

**Bekannte Grenzen.**

- **Der Riegel erfasst nur WordPress-Wege:** die HTTP-API von WordPress und `wp_mail`/PHPMailer.
  Ein Plugin, das mit eigenem cURL oder Socket nach draussen spricht (so arbeiten einige
  Zahlungs-SDKs), geht daran vorbei. Die Liste der gesperrten Dienste ist eine Untergrenze:
  Gesperrt sind bekannte Mail-, Zahlungs- und Newsletter-Dienste. Eine Testbestellung auf
  Staging kann bei anderen angebundenen Diensten (Rechnungs-, Versand-, ERP-Schnittstellen)
  echte Vorgänge auslösen – solche Plugins vor dem Test in der Kopie deaktivieren.
- **Absolute Pfade in Plugin-Optionen:** Die Uploads der Kopie liegen immer in ihrem eigenen
  Ordner. Andere Plugin-Optionen mit absolutem Pfad auf Live (Log-, Cache-, Export-Ordner)
  werden nicht umgeschrieben; ein solches Plugin schreibt aus der Kopie in den Ordner von Live.
- **Erfundenes Zugangs-Cookie:** Der Webserver prüft nur, ob ein Cookie `wpsync_stg` mitkommt;
  den Wert prüft der Riegel, sobald WordPress lädt. Mit einem erfundenen Cookie sind deshalb
  statische Dateien der Kopie erreichbar und PHP-Dateien, die sich direkt aufrufen lassen, ohne
  WordPress zu laden – derselbe Code wie auf Live, keine Uploads, keine Datenbankinhalte.
  Nie ausgeliefert werden Dateien, deren Name mit `wp-config` oder `.env` beginnt, `.log`, `.sql`
  und `wpsync-staging.json`.
- **Login-Token im Access-Log:** Der Einmal-Link trägt sein Token in der Adresse; es steht nach
  dem Einlösen – verbraucht – im Access-Log des Webservers.
- **Live-URLs, die bleiben:** Kaputte serialisierte Werte werden nicht umgeschrieben und zeigen
  weiter auf Live – ab Agent 0.7.0 auch dann, wenn nur ein String **in** einem serialisierten
  Wert serialisiert aussieht, sich nicht lesen lässt und die Live-URL enthält; der ganze Wert
  bleibt dann unverändert. Bis Agent 0.6.0 galt das auch für doppelt escaptes JSON (`https:\\\/\\\/…`).
  `skipped_values` im Ergebnis zählt die übersprungenen serialisierten Werte und ist eine
  Obergrenze. Absolute Dateipfade werden nicht umgeschrieben.
- **Was als Live gilt:** nur die Domain der Site mit und ohne `www.` auf demselben Port. Andere
  Subdomains von Live und eine Umlaut-Domain in der jeweils anderen Schreibweise (Punycode) sind
  für den Riegel fremde Hosts und nicht gesperrt.
- **Gleicher Origin wie Live:** Die Kopie ist ein Unterordner der Live-Domain, keine eigene
  Subdomain; siehe „Eigenes Browserprofil“ oben.
- **Abbruch mitten im Schritt:** Stirbt PHP genau zwischen einem Datenbank-Schritt und dem
  Speichern des Fortschritts, wiederholt der nächste Aufruf diesen Schritt. Tabellen ohne
  Primärschlüssel ab 50 000 Zeilen können danach doppelte Zeilen enthalten.
- Übersprungene Dateien (siehe oben) zählt und meldet `staging create` nicht.
- Pusht ein zweiter Rechner nach Staging, kennt der erste dessen Stand nicht und bekommt beim
  nächsten Push einen Konflikt. Dasselbe gilt nach `wpsync pushes --confirm` für einen Push nach
  Staging: Er hinterlässt keinen gemerkten Stand.
- Backup-Plugins auf Live sichern die Staging-Tabellen und ggf. den Staging-Ordner mit.
- Sicherheits-Scanner auf Live (Wordfence u. ä.) können den zweiten WordPress-Core melden.
- Server-Caches vor PHP (LiteSpeed, Varnish, Hoster-Cache) dürfen den Staging-Pfad nicht cachen;
  die 403 ohne Cookie speichern gängige Caches nicht, geprüft ist das nicht für jeden Hoster.
- Das Datenbank-Kontingent ist von PHP aus nicht prüfbar; über 1 GB Bedarf fragt die CLI nach.
- Konstanten aus der Live-`wp-config.php` ausser Datenbank und Speicherlimit werden nicht übernommen.

---

## Sicherheit

- **Pairing:** Einmal-Code (8 Zeichen), 10 Minuten gültig, 5 Versuche. Danach ist der Code verbraucht.
- **Secret:** liegt lokal nur in der macOS-Keychain (`service=wpsync:<site>`), nie in einer Datei.
  Auf der Site steht es seit Agent 0.4.1 verschlüsselt in der Tabelle `wpsync_pairings`
  (libsodium `secretbox`, Format `v1:…`). Der Schlüssel liegt nicht in der Datenbank, sondern
  kommt aus `wp-config.php`: aus `WPSYNC_KEY` (mindestens 32 Zeichen), sonst aus `AUTH_KEY` und
  `SECURE_AUTH_KEY`. Wer nur die Datenbank lesen kann (SQL-Injection in einem anderen Plugin,
  Datenbank-Backup, Hosting-Panel), gewinnt daraus kein nutzbares Secret.
  - **Eigener Schlüssel empfohlen:** `define('WPSYNC_KEY', '…');` mit mindestens 32 zufälligen
    Zeichen (z. B. `openssl rand -hex 32`) in `wp-config.php`. Bestehende Kopplungen bleiben
    dabei gültig und werden bei der nächsten Benutzung mit dem neuen Schlüssel versiegelt.
  - **Salt-Rotation:** Ohne `WPSYNC_KEY` hängt der Schlüssel an den WordPress-Salts. Werden sie
    erneuert (auch Sicherheits-Plugins tun das), lassen sich die Secrets nicht mehr entschlüsseln:
    Die Geräte bekommen „unbekanntes Pairing“ und müssen neu gekoppelt werden. Dasselbe gilt,
    wenn `WPSYNC_KEY` geändert oder entfernt wird. Die Admin-Seite markiert solche Pairings mit
    „nicht entschlüsselbar – neu koppeln“.
  - **Ohne Schlüssel:** Fehlen die Salts oder stehen sie auf dem WordPress-Standardwert, und ist
    kein `WPSYNC_KEY` gesetzt, speichert der Agent das Secret wie bisher im Klartext und warnt
    auf der Admin-Seite. Die Spalte „Secret“ in der Geräteliste zeigt pro Pairing
    „verschlüsselt“, „Klartext“ oder „nicht entschlüsselbar“.
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
  Dasselbe Fenster brauchen `staging create`, `refresh`, `delete` und `open`: Administrator der
  Kopie zu sein, ist ebenfalls Code-Ausführung auf dem Server.
- **Push-Schutz im Agent, nicht in der CLI:** erlaubte Einheiten, verbotene Dateinamen,
  Hash-Prüfung vor dem Tausch und die Sperre „ein Push gleichzeitig" prüft der Server selbst.
- **Uploads:** Der Upload-Kanal ist kein zweiter Weg für Code. Erlaubt ist nur, was WordPress auf
  der Site für einen Benutzer ohne `unfiltered_html` zulässt; PHP in jeder Schreibweise,
  `.htaccess`, `.user.ini` und versteckte Dateien sperrt der Agent zusätzlich fest, ebenso aktive
  Typen (SVG, HTML, XML, JavaScript) unabhängig von `upload_mimes` und mittlere Endungen, die
  WordPress' `sanitize_file_name()` entschärfen würde (`bild.shtml.jpg`). Pfade bleiben
  unter `wp-content/uploads/` ohne Symlink auf dem Weg, nie in Staging- oder Push-Arbeitsordnern.
  Vorhandene Dateien ersetzt ein Push nie.
- **Inhalts-Push:** schreibt nur in die sieben Inhaltstabellen des Ziels – auf Staging nur in
  Tabellen, deren Namen der Staging-Guard geprüft hat – und nur Zeilen, die die Listen des
  **Agents** erlauben; jedes SQL geht durch `$wpdb->prepare`, Schlüssel werden bytegenau
  verglichen. Er ist ein Administrator-Kanal (ungefiltertes HTML und JavaScript) mit der
  Autorisierung des Code-Pushs: ohne offenes Push-Fenster weder Begin noch Commit. Serialisierte
  Objekte in Werten lehnt der Agent ab (`unsafe_value`), ebenso Pseudonyme und Reste der lokalen
  Adresse. Das Ablegen eines Pakets (`/content/stage`) braucht kein Fenster, schreibt aber nur
  in den geschützten Push-Arbeitsordner: höchstens fünf Dateien je Kopplung, je höchstens 16 MB,
  24 Stunden. Dort liegt auch das Vorher-Abbild eines Pushs (`before.json`, Inhalte der Site) –
  verschlüsselt und authentisiert mit einem Schlüssel aus `wp-config.php`, ohne die
  PHP-Erweiterung `sodium` mit einem HMAC, nur ohne jeden Schlüssel im Klartext; es wird mit dem
  Snapshot aufgeräumt. Das abgelegte Paket selbst liegt dort im Klartext (nur für den Besitzer
  lesbar): seine Prüfsumme adressiert es, und der Agent prüft es vor dem Anwenden noch einmal
  ganz. Fehlerantworten nennen Tabelle und
  Schlüssel, nie einen Wert. Auch ins Fehlerprotokoll des Servers schreibt der Kanal keine Werte:
  seine Abfragen und seine Nacharbeiten laufen mit unterdrücktem `$wpdb`-Fehler (sonst schriebe
  WordPress eine gescheiterte Abfrage samt allen Werten per `error_log()` mit); dort steht nur
  die Fehlernummer der Datenbank. Die Transaktion trägt eine Sitzungsmarke: baut WordPress eine
  verlorene Datenbankverbindung mittendrin neu auf, schreibt keine weitere Anweisung (jede ist an
  die Marke gebunden), es gibt keinen `COMMIT`, und der Push endet mit `content_failed`. Liess
  sich dabei eine einzelne Zeile nicht auf ihren Stand davor zurücksetzen, nennen `error.keys` und
  die Meldung sie, und der Arbeitsordner des Pushs bleibt samt Vorher-Abbild liegen, bis jemand
  die Zeile geprüft hat.
- **Inhalts-Manifest:** `/content/manifest` ist signiert wie jeder Request, liest nur und
  braucht kein Push-Fenster. Die Inhalte verlassen den Server dort nur als Fingerabdruck, ohne
  Werte, im Umfang des Pull-Profils (abgewählte Tabellen und Beitragstypen fehlen) und ohne
  Transients und `wpsync_*`-Optionen. Der Fingerabdruck ist ein ungesalzenes SHA-256: Für alles,
  was der Pull pseudonymisiert, liefert das Manifest deshalb gar keinen (`why: "pseudonymized"`),
  ausser der Pull läuft mit `--no-anonymize`. Für alle anderen Zeilen gilt: Wer das Manifest
  hat, kann kurze oder erratbare Werte am Abdruck wiedererkennen. Die Listen des Agents für den späteren Inhalts-Push
  stehen im Kopf als Daten; sie erweitern keine Rechte. Lokal liegen Manifest und Baseline unter
  `.wpsync/content/` neben dem Docroot; wpsync schreibt und liest sie, ohne einem Symlink zu
  folgen, und prüft die Werte aus `env.json`, bevor sie an Docker oder WP-CLI gehen. Die
  Baseline enthält die Inhalte der lokalen Datenbank im Klartext (nach der Pseudonymisierung
  des Pulls) – sie gehört wie der Dump nicht in ein Repo.
- **`rescue.php`:** kennt „ping", „rollback" und „cache", lädt für die Rücknahme kein WordPress,
  rollt nur unbestätigte Pushes zurück und sperrt nach 5 falschen Schlüsseln für 10 Minuten –
  falsche Schlüssel; der richtige gilt weiter, damit niemand den Notfallweg genau dann sperren
  kann, wenn er gebraucht wird. Fehlversuche stehen in einer eigenen Datei, nie im Datensatz der
  Rücknahme. Der Schlüssel ist pro Push
  aus dem Pairing-Secret abgeleitet und geht als POST-Formularfeld an das Skript, nie in der
  URL; auf dem Server liegt nur sein Hash.
- **`rescue.php` und die Datenbank (ab Agent 0.8.0):** Mit einem gültigen Schlüssel lässt sich
  genau eines erreichen – den einen unbestätigten Push ungeschehen machen. Geschrieben wird nur in
  Zeilen, deren Schlüssel im authentisierten Vorher-Abbild stehen, nur ihr Stand vor dem Push und
  nur, solange jede von ihnen noch den Abdruck des Pushs trägt; kein Parameter des Requests wählt
  Tabellen, Schlüssel oder Werte, und es gibt kein „force“. Vor der Schlüsselprüfung wird keine
  weitere Klasse geladen, kein Umschlag gelesen und keine Datenbankverbindung geöffnet.
  Zugangsdaten liest das Skript nie aus `wp-config.php`, sondern aus dem versiegelten Umschlag
  des Pushs (`rescue.sealed`, 0600), den nur der Rollback-Schlüssel öffnet: wer den Arbeitsordner
  lesen kann, findet Chiffrat und einen Hash – nicht mehr, als `wp-config.php` daneben ohnehin
  im Klartext zeigt. Der Umschlag eines Pushs nach Staging gilt nur im Ordner seiner Kopie und
  nur für Tabellen mit ihrem Präfix. Antworten und Fehlerprotokoll nennen nie Host, Benutzer,
  Passwort, Datenbankname, einen Wert oder Text des Datenbankservers – nur Codes, Tabellen und
  Schlüssel. `rescue.php`, die Rücknahme über den Agent und der laufende Commit schliessen sich
  über eine Sperrdatei je Push aus: ein Commit, den eine Rücknahme überholt hat, schreibt nichts
  mehr fest. Der Cache-Schritt lädt WordPress erst, wenn der Push ganz zurück ist (der Code ist
  dann wieder der alte), ohne Plugins, Themes und mu-plugins – Drop-ins in `wp-content`
  (`object-cache.php`, `db.php`, `advanced-cache.php`) lädt WordPress dabei wie in jedem Request.
- **Rescue-Stub:** `wpsync-rescue-<32 hex>.php` im Webroot enthält nur ein `require` auf
  `rescue.php` mit relativem Pfad. Er entsteht nur, wenn die CLI pushen will, liegt solange ein
  Push läuft oder unbestätigt ist und höchstens etwa 10 Minuten darüber hinaus; Deaktivieren des
  Plugins löscht ihn. Der Schlüsselschutz ist derselbe wie bei `rescue.php`.
- **Snapshots:** liegen in `wp-content/wpsync-push-<zufall>/` mit `.htaccess`-Sperre. Auf
  Servern ohne `.htaccess`-Auswertung schützt nur der zufällige Name.
- **Arbeitsordner auf nginx:** In diesem Ordner liegen bei einem Inhalts-Push auch das abgelegte
  Paket (`packages/<kopplung>/<sha256>.jsonl`, `<push-id>/content/package.jsonl`), die
  Abbilder `before.json`/`after.json` und der Umschlag `rescue.sealed`. nginx wertet keine
  `.htaccess` aus: dort hält sie nur der zufällige Ordnername (und bei den Abbildern und dem
  Umschlag die Verschlüsselung) vom Netz fern. Wer es fest
  haben will, sperrt den Ordner in der Server-Konfiguration:
  `location ~ ^/wp-content/wpsync-push-[^/]+/ { deny all; }` – `rescue.php` liegt im
  Plugin-Ordner und bleibt davon unberührt.
- **Grenzen der Inhaltsprüfung:**
  - `local_origin_in_package` und `pseudonym_in_package` sind ein Schutz gegen **Versehen** – ein
    Rest der lokalen Adresse oder ein pseudonymisierter Wert, der unbemerkt ins Paket gerutscht
    ist. Sie sind keine kodierungsfeste Sperre: der Agent sucht den Host wörtlich und bis zu
    dreifach URL-kodiert und die Pseudonym-Muster in ihrer bekannten Form. Wer einen Wert
    absichtlich anders kodiert (Base64, HTML-Entities, ein JSON in einem serialisierten String
    mit eigener Escapung, zerlegt über mehrere Felder), kommt daran vorbei. Das ist kein Loch im
    Rechtemodell – der Kanal ist ein Administrator-Kanal und darf solche Inhalte ohnehin
    schreiben –, aber auch keine Garantie, dass nie eine lokale Adresse live geht.
  - Ein **Fatal in einer Nacharbeit** nach dem `COMMIT` (ein Cache-Plugin, Elementor, eine
    Revision) beendet den Request, nachdem die Inhalte schon in der Datenbank stehen: die
    Antwort des Commits kommt nicht mehr an. Die CLI meldet dann den halben Zustand („der Tausch
    wurde nicht bestätigt, der Stand ist unklar“, mit dem Hinweis auf `wpsync pushes`), nie
    Erfolg; der Push bleibt getauscht und unbestätigt (weitere Pushes: Exit 42), bis
    `wpsync rollback` ihn zurücknimmt oder `wpsync pushes --confirm` ihn bestätigt. Die
    Nacharbeiten, die nicht mehr liefen, sind dann von Hand nachzuholen (Caches leeren).
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
│   ├── content/                     nur nach pull --content: Manifest, map.json, Baseline,
│   │                                unfaithful.jsonl, env.json, summary.json
│   └── db/pull.sql                  letzter Dump (wird beim nächsten Pull ersetzt)
└── .gitignore                       bis CLI 0.4.0 von wpsync geschrieben, seither ohne Wirkung
~/wpsync-sites/.wpsync-git/
├── <site>.git/                      internes Repo, Auto-Commit nach jedem Pull, Push und Rollback (nur für dich lesbar)
└── <site>.alt-<datum>.git/          früheres .git aus dem Site-Ordner, beim Update verschoben
```

Das interne Git versioniert `public/wp-content` ohne Uploads, Cache und Upgrade-Ordner, dazu
die Baseline – keine DB-Dumps. Es liegt bewusst nicht im Site-Ordner (siehe
[Sicherheit](#sicherheit)). wpsync liest die Dateien selbst, ohne einem Symlink zu folgen, und
übergibt sie git per `fast-import`; git bekommt den Site-Ordner nie als Arbeitsverzeichnis und
hat keinen Index. Ordner mit einem eigenen `.git` (etwa ein Plugin, das jemand als Git-Checkout
abgelegt hat) und Dateien, die wpsync nicht lesen darf, fehlen im Schnappschuss; die CLI nennt
sie, für nicht lesbare Dateien mit `--json` als `warnings: ["snapshot_incomplete"]`. Weil git
keinen Index mehr hat, liest jeder Schnappschuss den ganzen Code von `wp-content` – bei grossen
Sites einige Sekunden. Historie ansehen:

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
| „rescue.php ist nicht erreichbar“ | Agent auf 0.5.1 bringen (Stub im Webroot). Hilft das nicht: Webroot für den Webserver nicht beschreibbar und PHP unter `wp-content/plugins/` gesperrt → Ausnahme für `wpsync-agent/rescue.php` einrichten; die Meldung nennt erkannte Sicherheits-Plugins und ihre Einstellung |
| „der Notfallweg über rescue.php besteht nur bis kurz nach der Bestätigung …“ | Der Push ist bestätigt und der Stub aufgeräumt → im WP-Admin unter Werkzeuge → wpsync zurückrollen |
| „Notfall-Rücknahme der Inhalte nicht möglich (<grund>)“ | Der Push geht trotzdem; fällt die Site aus, gehen nur Code und Uploads zurück. Gründe und Abhilfe: [Rücknahme ohne WordPress](#rücknahme-ohne-wordpress) – meist fehlt `sodium`/`openssl` oder ein Schlüssel (`WPSYNC_KEY` bzw. Salts in `wp-config.php`). `--require-rescue-db` macht daraus einen Abbruch vor dem Tausch |
| „Nur Code und Uploads sind zurück – die Inhalte des Pushs stehen noch auf der Site (<code>)“ | `rescue.php` konnte die Inhalte nicht zurücknehmen. Sobald WordPress antwortet: `wpsync rollback <site> <id>`; bei `changed_since_push` die genannten Zeilen richten oder mit `wpsync pushes <site> --confirm <id>` abschliessen |
| „Der Object-Cache der Site trägt noch den gepushten Stand“ | Die Datenbank ist zurück, Redis/Memcached nicht geleert → im Panel des Hosters leeren (oder `wp cache flush`), falls die Site nicht antwortet; der Agent leert ihn beim nächsten Laden von WordPress ohnehin |
| „An eingefügten Objekten hing etwas, das nicht vom Push stammt – es blieb stehen“ | Der Push ist zurück; die genannten Zeilen (Meta, Zuordnungen, Kommentare, Kindseiten) gehören zu Objekten, die es nicht mehr gibt, und können auf der Site gelöscht werden |
| „Push … ist getauscht, aber nicht bestätigt“ | Site ansehen, dann `wpsync pushes <site> --confirm <id>` oder `wpsync rollback <site> <id>` |
| „der Webserver darf das Verzeichnis nicht ersetzen“ | Das Verzeichnis gehört einem anderen Benutzer als PHP (typisch nach FTP-Upload) → Besitzer oder Rechte auf dem Server anpassen |
| Push wurde automatisch zurückgerollt | Die Meldung nennt die Seite, die schlechter wurde. Lokal reparieren und erneut pushen; auf der Site ist der alte Stand live |
| „übersprungen: plugins/… – neu, nur mit ausdrücklicher Nennung“ | Das Verzeichnis stand in keinem Pull. Gewollt neu → `wpsync push <site> code plugins/<slug>`; sonst eine alte lokale Kopie, die liegen bleiben kann |

---

## Server-Modus

Für den Aufruf als Subprozess (Agentic OS, Website Studio), ab CLI 0.3.0. Auf dem Mac ändert
sich nichts ausser den Exit-Codes (siehe unten). Das Linux-Binary hängt an jedem GitHub-Release
als Asset `wpsync_<version>_linux_arm64` mit Prüfsumme `wpsync_<version>_linux_arm64.sha256`
(für 0.3.0: `wpsync_0.3.0_linux_arm64`); gebaut wird es reproduzierbar mit
`scripts/build-linux.sh` (siehe [Release](#release)).

| Schalter | Befehle | Wirkung |
|---|---|---|
| `--json` | `pair`, `scan`, `pull`, `status`, `unpair`, `doctor`, `version`, `staging`, `push`, `pushes`, `rollback`, `content export` | Ein JSON-Objekt je Zeile auf stdout, menschliche Meldungen auf stderr, keine Rückfragen. Letzte Zeile immer `{"event":"result","command":…,"ok":…,"exit_code":…,"data":…,"error":…}` |
| `--secret-stdin` | `scan`, `pull`, `status`, `staging`, `push`, `pushes`, `rollback`, `content export` | Kopplungs-Secret als erste Zeile von stdin; im Container-Modus von `pull` und `content export` DB-Passwort als zweite. Nie über Argumente oder Umgebungsvariablen |
| `--secret-out` | `pair --json` | Secret einmal im Ergebnis-JSON (`data.secret`), nichts in der Keychain |
| `--driver container` | `pull`, `status`, `list`, `stop`, `content export`; `push`, `pushes`, `rollback` mit `--docroot` und `--secret-stdin` | Vorhandener WordPress-Container bzw. Site-Ordner neben dem Docroot statt DDEV, siehe unten |
| `--server` | `doctor` | Nur Version, Mail-Riegel, Docker-CLI, `WPSYNC_CONFIG_DIR` |

`push`, `pushes` und `rollback` kennen `--json` (ab CLI 0.4.0) und laufen ab CLI 0.5.0 auch im
Container-Modus (siehe [Push im Container-Modus](#push-im-container-modus)). `trust` hat kein
`--json`. Die `staging`-Befehle brauchen keine lokalen Dateien und laufen mit `--secret-stdin`
auch im Container.

`pull --json` meldet Fortschritt als Zeilen, z. B. `{"event":"phase","name":"files","done":120,"total":17210}`.
Phasen: `delta`, `setup`, `files`, `db_download`, `db_import`, `postsetup`, `mailguard`, mit
`--content` zusätzlich `content`.

Das Ergebnis von `pull --json` (`data`) enthält `warnings`, sobald etwas ohne Abbruch scheiterte;
fehlt das Feld, gab es keine. Werte:

- `snapshot_failed` – Site, Datenbank und Baseline sind gezogen, nur der Schnappschuss im
  internen Git fehlt (Meldung auf stderr). Der nächste Pull committet wieder.
- `symlink_skipped` – Dateien unter einem symbolischen Link im Docroot wurden nicht geschrieben
  (Pfade auf stderr). Sie fehlen in der Baseline, der nächste Pull fragt sie erneut an.

**`pull --content --json`, `content export --json`** (ab Agent 0.7.0). Mit `--content` enthält
`data` von `pull` das Objekt `content`: `rows` (Zeilen im Manifest), `unfaithful` (Schlüssel in
`unfaithful.jsonl`), `id_max` (`posts`, `terms`, `term_taxonomy`), `canon_version` und
`reloaded` (dieser Pull hat die Inhaltstabellen neu geladen und die Baseline neu gebaut). Ohne
`--content` fehlt das Feld. `content export` schreibt seine Datenzeilen immer auf stdout und
Meldungen immer auf stderr; mit `--json` ist die letzte Zeile das Ergebnisobjekt mit
`command: "content export"` und `data: {rows, canon_version}`. Es gibt keine `phase`-Zeilen.
Format und Ablage: [Inhalte](#inhalte-manifest-baseline-export).

**`staging --json`** (ab CLI 0.4.0, Agent 0.5.0). `command` im Ergebnis ist `staging create`,
`staging refresh`, `staging open`, `staging status` oder `staging delete`. Mit `--json` (und ohne
Terminal) fragt kein Befehl nach: `refresh`, `delete`, `create --no-anonymize` und ein `create`
über 1 GB brauchen `--yes`, sonst Exit 2.

- `create`, `refresh`, `delete`: Fortschritt als `phase`-Zeilen wie beim Pull. Phasen über alle
  drei: `probe`, `files`, `remove-code`, `tables`, `anonymize`, `fixup`, `urls`, `settings`,
  `lock`, `drop`, `remove`. Ergebnis `data` von `create` und `refresh`: `url`, `prefix`, `anonymized`, `replaced` (Tabelle → geänderte Zeilen),
  `skipped_values`, `files`.
- `status`: `data` mit `exists`, `status` (`creating`, `ready`, `refreshing`, `locked`, `failed`,
  `deleting`), `url`, `prefix`, `created`, `copied_at`, `code_copied_at` (wann der Code der Kopie
  zuletzt von Live kam: `create`, `refresh --code`), `last_used`, `anonymized`, `db_bytes`, `job`,
  `error`, `pushes`. Leere Felder fehlen – ausser `exists` und `anonymized` ist jedes Feld
  optional. Exit 0 mit Kopie, 50 ohne, 53 gesperrt; `data` ist in allen drei Fällen gesetzt.
- `open`: `data.url` (der Einmal-Link, nicht protokollieren), `data.expires` und
  `data.warnings` (Liste von Hinweisen für den Menschen, derzeit: die Kopie nicht im selben
  Browserprofil wie eine Live-Anmeldung öffnen); es startet kein Browser.
- `create`, `refresh`, `delete`, `open` ohne offenes Push-Fenster: Exit 40 `push_window_closed`,
  auf dem Server ist nichts angelegt oder geändert. `status` braucht kein Fenster.
- `delete`: `data` = `{"site":…,"status":"deleted"}`.
- Ein leeres `url` in `create`, `refresh` oder `status` heisst: der Agent nannte eine Adresse
  ausserhalb der gekoppelten Site; die CLI gibt sie nicht weiter (Warnung auf stderr).

`wpsync status --json` enthält zusätzlich `staging` (`status`, `url`, `last_used`), wenn es eine
Kopie gibt.

**`push`, `pushes`, `rollback --json`.** `push` meldet die Ereignisse `plan`, `upload` (je
Einheit), `commit` und `health` als `{"event":…,"data":{…}}` und endet mit `data`: `push_id`,
`target` (`live`, `staging`), `status`, `units`. `status` ist `dry_run`, `confirmed`,
`rolled_back`, `committed` (getauscht, aber weder bestätigt noch zurückgerollt – die Site braucht
Aufmerksamkeit) oder leer (auf der Site wurde nichts geändert). `pushes` liefert `data.pushes`,
neueste zuerst, `pushes --confirm` `push_id` und `status` (`confirmed` – oder `rolled_back` mit
`warnings: ["content_kept"]`, siehe [Inhalte pushen](#anwenden-health-check-rücknahme)); `rollback` dieselben Felder wie `push`.
Ab CLI 0.5.0 zusätzlich: im `plan` `skipped_new` (lokal neue, nicht genannte Einheiten) und
`missing_locally` (lokal fehlende Einheiten, die auf der Site bleiben), beide `[]` wenn leer; im
Ergebnis von `push` `rescue_url`, sobald es eine `push_id` gibt; bei `push` und `rollback`
`warnings` (`snapshot_failed`), wenn nur der Schnappschuss im internen Git scheiterte, oder
(`snapshot_incomplete`, auch bei `pull`), wenn ihm nicht lesbare Dateien fehlen. Den
Rollback-Schlüssel nennt die CLI nie – der Rückweg ist `wpsync rollback <site> <push-id>`, das bei
stummem WordPress selbst über `rescue.php` geht.
`push` braucht mit `--json` `--yes`, bei geänderter Version zusätzlich `--allow-version-change`.
`--to` kennt nur `live` und `staging`, genau so geschrieben; alles andere ist Exit 2.
Ab CLI 0.6.0 zusätzlich: mit `--uploads` im `plan` das Objekt `uploads` (`need`, `same`,
`conflicts`, je `[]` wenn leer) und ein `upload`-Ereignis mit `unit: "uploads"`; im Ergebnis
`units` mit `"uploads"`. Nach einer Rücknahme wegen des Health-Checks nennt `push` je
verschlechterter Seite `health: [{"url", "before", "after"}]` (z. B. `"HTTP 200"` →
`"HTTP 500, Fehlermeldung von WordPress oder PHP"`), auch bei reinen Code-Pushs. `push` und
`rollback` melden in `warnings` zusätzlich `upload_changed_since_push`. Bei
`error.reason: "nothing_to_push"` nennt `error.skipped_new` die lokal neuen, nicht genannten
Einheiten (fehlt, wenn es keine gibt).
Ab CLI 0.8.0 zusätzlich (alles fehlt, wenn es nicht zutrifft): im `plan` eines Pushs mit
`--content` `rescue_db: {"ok", "reason"?}` (nur von Agent ≥ 0.8.0); im Ergebnis von `rollback`
und eines zurückgerollten `push` `via` (`"agent"` oder `"rescue"`), `content_error`
(`{"code", "keys"?, "total"?}`, neben `content_not_rolled_back`) und `content_left`
(`[{"table","key"}]`, höchstens 200) mit `content_left_total`; in `warnings`
`rescue_db_unavailable`, `object_cache_stale` und `content_left_extra`; in `pushes` an der
Einheit `content` `via: "rescue"`, `post_actions` und `left`/`left_total`. Bedeutung:
[Rücknahme ohne WordPress](#rücknahme-ohne-wordpress).

**Site-Lock.** Pro Site läuft nur ein `pull`, `push`, `rollback` oder `content export` gleichzeitig (`flock` auf
`<slug>/.wpsync/lock`, auf dem Mac `~/wpsync-sites/.wpsync-git/<site>.lock`). Ein zweiter endet
sofort mit Exit 20 (`local_env`, `error.reason: "site_locked"`; Aufrufer prüfen `reason`, nicht
den Meldungstext), ohne die
Quelle zu fragen. Der Lock gilt bis zum Prozessende, auch nach SIGKILL. Unter dem Lock räumen
die Befehle Reste eines gekillten Vorgängers weg: liegengebliebene git-Locks im internen Repo
und – beim Pull im Container-Modus – Hilfscontainer mit dem Label `wpsync.site=<Schlüssel>`.
Der Schlüssel sind die ersten 16 Hex-Zeichen von SHA-256 über den Pfad des Site-Ordners
(`<slug>/`); das Label `wpsync.site` ist für wpsync reserviert.

**Exit-Codes** (gelten für alle Befehle, auch ohne `--json`):

| Code | Name | Beispiel |
|---|---|---|
| 0 | ok | |
| 1 | unknown | auch: die Quelle meldet unzulässige Werte (Tabellenpräfix, `home`/`siteurl`, PHP-Version, Tabellennamen). Bei Staging, Push und Inhalten mit `error.reason`, siehe unten |
| 2 | usage | unbekannter Schalter, Rückfrage nötig, Jahresgrenze für Uploads im Container-Modus, ungültiger `--docroot`; `pull --content` mit einem Profil ohne alle sieben Inhaltstabellen; `content export` ohne aktuellen Inhaltsstand |
| 10 | agent_unreachable | Site oder Plugin nicht erreichbar |
| 11 | agent_outdated | Agent unter der Mindestversion; `error.installed`, `error.required` |
| 12 | pair_rejected | Pairing-Code falsch oder abgelaufen |
| 13 | auth_failed | Kopplung widerrufen |
| 14 | rate_limited | Server bremst oder sperrt; später fortsetzen |
| 20 | local_env | Container fehlt oder läuft nicht, Datenbank nicht erreichbar, `.ddev` weicht ab, `pull`/`push`/`rollback` der Site läuft bereits (`error.reason: "site_locked"`), `content export`: `env.json` nicht verwendbar |
| 21 | disk_full | lokal kein Platz – oder der Server meldet keinen (HTTP 507, bei `push` und `staging`) |
| 22 | postsetup_failed | Search-Replace oder Mail-Riegel gescheitert |
| 30 | interrupted | SIGTERM; der nächste Pull setzt fort. `push`: nur vor dem Tausch, danach läuft er zu Ende |
| 40 | push_window_closed | Push-Fenster geschlossen, auch beim Rollback eines bestätigten Pushs und bei `staging create`/`refresh`/`delete`/`open`; ab CLI 0.5.0 mit `error.admin_url` und – wenn `pair` es kannte – `error.device` |
| 41 | push_conflict | Einheit auf dem Server geändert |
| 42 | push_pending | ein unbestätigter Push blockiert – auch `staging refresh`/`delete` bei unbestätigtem Push nach Staging |
| 43 | push_rolled_back | Health-Check schlechter als vorher, zurückgerollt |
| 44 | busy | auf dem Server läuft ein anderer Push oder ein Staging-Job (HTTP 423) |
| 50 | staging_missing | keine Staging-Kopie vorhanden |
| 51 | staging_exists | `staging create`, obwohl es eine Kopie oder Reste einer abgebrochenen gibt |
| 52 | staging_unsupported | Probe von aussen gescheitert (nginx), Multisite, abweichende WordPress-/Website-Adresse, Tabellen-Präfix |
| 53 | staging_locked | Kopie nach Verfall gesperrt; `staging open` hebt die Sperre auf |

40–44 und 50–53 gibt es ab CLI 0.4.0; bis 0.3.1 endete `push` in diesen Fällen mit 1. Die lokale
Site-Sperre bleibt Exit 20 mit `error.reason: "site_locked"` – 44 meint nur die Sperre auf dem
Server. `push --to staging` gegen einen Agent unter 0.5.0 ist Exit 11, ebenso `push --uploads`
gegen einen Agent unter 0.6.0 und `pull --content` gegen einen Agent unter 0.7.0.

`error.reason` bei Exit 1 (Aufrufer prüfen `reason`, nicht den Meldungstext):

| `reason` | Bedeutung |
|---|---|
| `staging_failed` | Der Staging-Job ist gescheitert und hat aufgeräumt; Phase und Grund stehen in der Meldung |
| `staging_state` | Der Zustand der Kopie lässt den Aufruf nicht zu, z. B. `refresh`, `open` oder ein Push bei `failed` |
| `staging_copy` | Die Anfrage hat einen Agent in einer Staging-Kopie erreicht statt den der Live-Site |
| `foreign_url` | Der Agent nannte eine Adresse ausserhalb der gekoppelten Site (Probe, Login-Link) |
| `nothing_to_push` | `push`: lokal ist nichts geändert, oder nur neue Einheiten, die nicht genannt wurden, oder ohne Code liegen alle Dateien aus `--uploads` schon auf der Site |
| `not_writable` | `push`: der Webserver darf ein Verzeichnis nicht ersetzen |
| `rescue_unreachable` | `push`: `rescue.php` antwortet nicht – ohne Rückweg kein Push |
| `rescue_db_unavailable` | `push --content --require-rescue-db`: `rescue.php` könnte die Inhalte nicht ohne WordPress zurücknehmen; `error.detail` nennt den Grund (`no_crypto`, `driver`, `no_image_key`, `probe_failed`, `write_failed`, `agent_outdated`). Nichts wurde getauscht |
| `local_changed` | `push`: eine lokale Datei oder ein Verzeichnis änderte sich während des Pushs oder ist ein Symlink |
| `target_mismatch` | `push`/`rollback`: der Agent antwortet für ein anderes Ziel, oder der Push ging an das andere Ziel |
| `not_readable` | `push`: eine Datei oder ein Ordner einer zu pushenden Einheit ist für wpsync nicht lesbar; `error.path` nennt ihn relativ zum Docroot |
| `upload_exists` | `push --uploads`: auf der Site liegt am selben Pfad eine andere Datei – nichts übertragen, nichts getauscht, auch mit `--force` |
| `upload_type_blocked` | `push --uploads`: der Dateityp geht nie als Upload (PHP, `.htaccess`, `.user.ini`, versteckte Dateien, aktive Typen wie SVG/HTML, von WordPress nicht erlaubt), der Dateiname ist nicht WordPress-konform (Leerzeichen, Sonderzeichen, mittlere Endungen) oder der Inhalt passt nicht zur Endung |
| `conflict`, `blocked_row`, `id_taken`, … | `push --content` und `rollback`: der Agent oder die CLI lehnt die Inhalte ab – alle Gründe stehen unter [Inhalte pushen](#was-der-agent-prüft). Dazu `error.keys` (`[{"table","key"}]`, bei `pseudonym_in_package` mit `pattern`) und bei `upload_missing` `error.paths` |
| `manifest_incomplete` | `pull --content`: das Inhalts-Manifest des Agents ist unvollständig oder nicht in der erwarteten Form (Kopf fehlt, Seite bricht ab, Cursor rückt nicht vor) |
| `content_export_failed` | `pull --content`, `content export`: das Export-Skript in der lokalen Site lief nicht zu Ende (Abbruch, Schlusszeile fehlt, Zeilenzahl passt nicht), oder die lokale Umgebung kann kein Skript über stdin ausführen |
| `canon_version` | `pull --content`: der Agent rechnet Fingerabdrücke in einer anderen Form als diese CLI – CLI oder Agent aktualisieren |

**Container-Modus.** wpsync legt keine Container, Netze, Datenbanken oder Benutzer an. Der
Aufrufer startet einen `wordpress:php<x.y>-apache`-Container mit den Variablen `WORDPRESS_DB_*`
und `WORDPRESS_TABLE_PREFIX` (Präfix der Quelle) und den Labels `um.website-studio=1`,
`um.slug=<site>`. Vor dem Erst-Pull legt der Aufrufer den WordPress-Core in der Version der
Quelle in den Docroot (`wp core download`; das Netz der Site hat kein Internet, wpsync lädt
nichts – fehlt der Core, Exit 20) und mountet den Mail-Riegel read-only nach
`wp-content/mu-plugins/00-local-mailguard.php` (Quelle: `wpsync doctor --server`). wpsync führt
WP-CLI und den SQL-Import (`mariadb --binary-mode --local-infile=0`) in
`wordpress:cli-php<x.y>`-Containern im Netz dieses Containers aus und prüft nach jedem Pull, dass
der Riegel aktiv ist (sonst Exit 22). Einen Uploads-Proxy gibt es nicht; das Profil muss alle
Upload-Jahre ziehen (`scan … --uploads-since alle`).

Pflichten des Aufrufers:

- **Nur `--docroot` in den WordPress-Container mounten** (nach `/var/www/html`), nie den Ordner
  `<slug>/` darüber. Dort liegen Baseline, Dump und Historie, die PHP der Site nicht sehen darf.
- **Datenbank-Benutzer ohne `FILE`-Recht**, nur mit Rechten auf die eigene Datenbank.
- **Im Image des Aufrufers** `docker`-CLI und `git` ≥ 2.28, besser ≥ 2.40 (`GIT_ATTR_SOURCE`:
  dann wertet das interne Git keine `.gitattributes` der Site aus).
- **`--docroot`** ist ein absoluter Pfad `<slug>/<docroot>` in einem eigenen Site-Ordner: nicht
  `/`, nicht direkt unter `/`, nicht `.wpsync` oder `.git`, Ordnername nur aus `A–Z a–z 0–9 . _ -`.
  Sonst Exit 2.

wpsync startet WP-CLI und Import als `docker run --rm --init --name wpsync-<site>-<zufall>
--label wpsync.site=<Schlüssel des Site-Ordners> …`. Nach SIGTERM entfernt es den laufenden Hilfscontainer mit
`docker rm -f`; überlebt einer (SIGKILL), räumt ihn der nächste Pull der Site weg.

Der Docroot ist für die Site beschreibbar. Jede Dateioperation des Pulls ist auf den Docroot
selbst begrenzt (nie auf `<slug>/`), und wpsync folgt dort keinem Symlink: Dateien unter einem
Symlink werden übersprungen und als `symlink_skipped` gemeldet, Löschen lässt solche Pfade aus.

Ablage: Der Docroot ist `<slug>/<docroot>`, alles von wpsync liegt daneben unter `<slug>/.wpsync/`,
nichts davon im Docroot:

```
<slug>/
├── <docroot>/              WordPress (einziger Mount in den WP-Container)
└── .wpsync/
    ├── baseline.json       Stand des letzten Pulls bzw. bestätigten Live-Pushs
    ├── pushes/<id>.json    Journal je Push (0600): Baseline vorher, Rescue-URL, Salt
    ├── staging-base.json   Stempel der Pushes nach Staging (versiegelt)
    ├── lock                Site-Sperre für pull, push, rollback
    ├── content/            nur nach pull --content: Manifest, map.json, Baseline,
    │                       unfaithful.jsonl, env.json, summary.json
    ├── db/                 DB-Zwischenablage (Tabellen-Dumps des Pulls)
    └── history.git/        internes Git, Auto-Commit nach Pull, Live-Push und Rollback
```

Historie ansehen: `git --git-dir <slug>/.wpsync/history.git log`.

**`history.git`** schreibt wpsync ab CLI 0.5.0 ohne Arbeitsverzeichnis: Es liest die Dateien
über einen auf `wp-content` begrenzten Zugriff, folgt keinem Symlink und übergibt sie git per
`fast-import`. Tauscht die Site während des Schnappschusses einen Ordner gegen einen Symlink,
fehlen die betroffenen Dateien im Schnappschuss (Meldung auf stderr) – nichts von ausserhalb des
Docroot gelangt hinein. Bis CLI 0.4.0 konnte `git add` in diesem Fall Dateien ausserhalb des
Docroot lesen (Review 3, M-1).

Im Docroot schreibt wpsync nur unter `wp-content/` und – als einzige Datei ausserhalb davon –
`<docroot>/.htaccess`: Fehlt sie, legt wpsync bei Setup und jedem Folge-Pull den
WordPress-Standardblock an (`RewriteBase` aus dem Pfad von `--local-url`, sonst `/`). Ohne die
Regeln antwortet Apache auf `/wp-json/` mit 404, und der Elementor-Editor lädt nicht. Eine
vorhandene `.htaccess` (auch Ordner oder Symlink) bleibt unberührt; eigene Regeln legt der
Aufrufer vorab ab.

```sh
printf '%s\n%s\n' "$SECRET" "$DB_PASSWORD" | wpsync pull vorlage --json --yes --secret-stdin \
  --driver container --container ws-dev-vorlage --docroot /srv/ws/dev/vorlage/docroot \
  --db-host wp-mariadb --db-name ws_dev_vorlage --db-user ws_dev_vorlage \
  --local-url https://vorlage.dev.example
```

### Push im Container-Modus

Ab CLI 0.5.0, Agent ≥ 0.4.0 (nach Staging ≥ 0.5.0). Es gelten Push-Fenster, Konfliktprüfung,
Snapshot, Health-Check und `rescue.php` wie auf dem Mac; neu ist nur, wo die CLI ihre Dateien
findet und woher das Secret kommt.

    printf '%s\n' "$SECRET" | wpsync push kunde code themes/kunde-child \
        --driver container --docroot /srv/ws/dev/kunde/docroot --secret-stdin --to staging --json --yes
    printf '%s\n' "$SECRET" | wpsync push kunde code themes/kunde-child \
        --driver container --docroot /srv/ws/dev/kunde/docroot --secret-stdin --json --yes
    printf '%s\n' "$SECRET" | wpsync pushes kunde --driver container --docroot /srv/ws/dev/kunde/docroot --secret-stdin --json
    printf '%s\n' "$SECRET" | wpsync rollback kunde p_20261007_… --driver container --docroot /srv/ws/dev/kunde/docroot --secret-stdin --json

- **Schalter.** `--driver container`, `--docroot <abs>` und `--secret-stdin` gehören zusammen; jede
  andere Kombination ist Exit 2. `--container`, `--db-*`, `--local-url`, `--cli-image` gibt es bei
  diesen Befehlen nicht – der Push braucht weder WordPress-Container noch Datenbank und startet
  weder `docker` noch `ddev` noch WP-CLI. Gelesen wird nur Zeile 1 von stdin.
- **Ablage.** Site-Ordner ist der Elternordner von `--docroot`; Baseline, Journale,
  Staging-Stempel, Sperre und `history.git` liegen dort unter `.wpsync/` (siehe oben), die
  Site-Datei unter `WPSYNC_CONFIG_DIR`. Unter `WPSYNC_SITES_DIR` entsteht nichts. Pull und Push
  derselben Site schliessen sich über `<slug>/.wpsync/lock` aus (Exit 20, `site_locked`).
- **Keine Rückfragen.** Mit `--secret-stdin` fragt die CLI nie, auch nicht auf einem Terminal. Ohne
  `--yes` ist ein Push Exit 2, bei geänderter Plugin- oder Theme-Version zusätzlich ohne
  `--allow-version-change`; neue Einheiten gehen nur, wenn sie genannt werden.
- **`pushes`** nennt je Push zusätzlich `journal` (ob dieser Site-Ordner ein Journal dazu hat) und
  dessen `rescue_url`.
- **`rollback`** braucht im Container-Modus die Push-ID (ohne: Exit 2) – ohne ID wählte die CLI
  den neuesten Push über alle Kopplungen der Site.
- **Push-Fenster.** Exit 40 nennt `error.admin_url` (`…/wp-admin/tools.php?page=wpsync`) und das
  Gerät aus `pair --device` (`error.device`, fehlt bei Kopplungen vor CLI 0.5.0). Das Fenster
  öffnet immer ein Administrator im WP-Admin, nie der Aufrufer.
- **Abbruch.** SIGTERM vor dem Tausch: Exit 30, auf der Site ist nichts getauscht; ein schon
  angelegter Push verfällt dort nach höchstens 10 Minuten (bis dahin Exit 44 für den nächsten
  Push). Ab dem Tausch läuft der Push trotz SIGTERM bis zur Bestätigung oder zum Rollback weiter,
  höchstens 10 Minuten nach dem Signal: der Health-Check (bis zu 30 s je Seite, zwei
  Wiederholungen) endet nach 7 Minuten, ist er bis dahin nicht sauber, wird zurückgerollt
  (Exit 43); hängt der Tausch selbst noch, Exit 42. **Zwischen SIGTERM und SIGKILL 15 Minuten Zeit
  lassen.** Ein SIGKILL nach dem Tausch hinterlässt einen unbestätigten Push
  (Exit 42 beim nächsten Push; auflösen mit `rollback <id>` oder im WP-Admin). `rollback` läuft
  immer zu Ende.
- **Nicht lesbare Dateien.** Der Scan braucht nur `stat`. Ist in einer zu pushenden Einheit eine
  Datei oder ein Ordner nicht lesbar (etwa 0600 vom Site-Container), bricht der Push vor dem
  Begin ab: Exit 1, `error.reason: "not_readable"`, `error.path` relativ zum Docroot. Nicht
  lesbare Dateien anderer Einheiten stören nicht.
- **mtimes der Arbeitskopie.** wpsync erkennt Änderungen an Grösse und mtime gegen die Baseline.
  mtimes aus `baseline.json` nur setzen, wenn der wiederhergestellte Stand der eines Pulls oder
  Pushs ist; nach `wpsync rollback` die Arbeitskopie selbst auf den Stand vor dem Push
  zurücksetzen – die CLI ändert lokale Dateien nie.
- **Mail-Riegel.** Vom Push ausgenommen ist nur `mu-plugins/00-local-mailguard.php` (und alles,
  was in `mu-plugins` mit `wpsync` beginnt). Ein Riegel unter anderem Namen im Docroot ginge mit
  einem Push von `mu-plugins` nach Live.

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

# Staging-Kopie gegen eine Apache-Quelle mit PHP 7.4 (eigene DDEV-Projekte, braucht jq)
scripts/e2e-staging.sh

# Inhalte: pull --content und content export (eigene DDEV-Projekte, braucht jq; rund 3 Minuten)
scripts/e2e-content.sh

# Inhalts-Push: push --content nach Staging und Live, Rücknahme, Ablehnungen – gegen eine echte
# MariaDB (eigene DDEV-Projekte wpsync-e2e-cdb und -cdb-target, braucht jq; rund 6 Minuten)
scripts/e2e-content-push.sh

# Container-Modus: push, pushes und rollback als Linux-Binary in Wegwerf-Containern
# (eigenes DDEV-Projekt wpsync-e2e-cpush, braucht jq; rund 15 Minuten)
scripts/e2e-container-push.sh
```

Struktur:

```
cli/
├── cmd/wpsync/            Einstiegspunkt, Befehle
└── internal/
    ├── agentapi/          signierter HTTP-Client, Frame-Parser, Backoff, Mindestversion
    ├── baseline/          Stand des letzten Pulls
    ├── cliout/            Exit-Codes und JSON-Zeilen (Server-Modus)
    ├── container/         Laufzeittreiber Container-Modus (docker-CLI)
    ├── ddev/              Laufzeittreiber DDEV mit .ddev-Prüfung, Uploads-Proxy
    ├── keychain/          macOS-Keychain
    ├── localenv/          Treiber-Interface, list und stop
    ├── localgit/          internes Site-Git (ausserhalb des Site-Ordners)
    ├── mailguard/         mitgelieferter Mail-Riegel (mu-plugin) und Auswahl
    ├── profile/           Presets, Profil-Auflösung, Scope
    ├── pull/              Delta, Dateien, DB, Post-Setup, Status
    ├── push/              Push, Health-Check, Rollback, Journal, Staging-Stempel
    ├── scan/              Infosheet-Darstellung, Checkliste
    ├── secretstore/       Secret aus Keychain oder stdin
    ├── setup/             setup und doctor
    ├── sites/             Site-Konfiguration
    └── staging/           Staging-Befehle, Probe von aussen, Zugang ohne Browser
agent/
├── wpsync-agent.php       Plugin-Header, Bootstrap
├── src/                   Endpunkte, Signatur, Infosheet, Klassifizierung, Scope
├── staging/               Riegel der Staging-Kopie (mu-plugin, wird in die Kopie gelegt)
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
4. Linux-Binary für den OS-Container (Agentic OS): `scripts/build-linux.sh X.Y.Z` (arm64;
   `amd64` als zweites Argument). Gleiche Quelle und gleiche Go-Version ergeben dieselbe Prüfsumme.
5. `gh release create vX.Y.Z` mit den Tarballs, der Agent-ZIP, `checksums.txt`,
   `dist/wpsync_X.Y.Z_linux_arm64` und `dist/wpsync_X.Y.Z_linux_arm64.sha256`. Version, SHA-256,
   Go-Version und Commit aus der Ausgabe von Schritt 4 gehören in die Release-Notiz; das OS lädt
   das Binary per Release-Download und pinnt Version und Prüfsumme im Dockerfile.
6. Im Tap `UserMind2018/homebrew-tap` in `Formula/wpsync.rb` `url` auf den neuen Tag setzen
   und `sha256` anpassen (Befehl steht in der Tap-README). Die Formel baut das Agent-ZIP selbst:
   Sie muss dieselben Dateien einpacken wie `agent/build.sh` (seit 0.4.0 auch `rescue.php`).

---

## Lizenz

MIT – siehe [LICENSE](LICENSE).
