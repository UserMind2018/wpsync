# wpsync – WordPress Live → Lokal

`wpsync` holt eine produktive WordPress-Site in ein lokales DDEV-Projekt – schonend für den
Server, fortsetzbar nach Abbrüchen und so, dass lokal keine Mail an echte Empfänger rausgeht.

Das Projekt besteht aus zwei Teilen:

| Teil | Ordner | Läuft auf | Aufgabe |
|---|---|---|---|
| **CLI** `wpsync` | `cli/` (Go) | deinem Mac | koppeln, scannen, ziehen, lokales DDEV-Projekt einrichten |
| **Agent** `wpsync-agent` | `agent/` (PHP-Plugin) | der Live-Site | signierte, read-only Endpunkte für Infosheet, Delta, DB und Dateien |

Der Agent schreibt nichts an der Site außer seinen eigenen Tabellen/Optionen (`wpsync_*`).
Es gibt in dieser Version **nur Pull** (Live → Lokal), keinen Push.

---

## Inhalt

- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Schnellstart](#schnellstart)
- [Befehle](#befehle)
- [Pull-Profile](#pull-profile)
- [Was beim Pull passiert](#was-beim-pull-passiert)
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
| `wpsync pair <url> <code> [--name n] [--device d]` | Koppelt eine Site. Folgt Redirects und speichert die kanonische URL; das Secret landet in der macOS-Keychain. Der Name wird sonst aus der URL abgeleitet (`www.example.com` → `example-com`). |
| `wpsync unpair <site>` | Entfernt Konfiguration und Keychain-Eintrag lokal. Das Pairing danach im WP-Admin widerrufen. |
| `wpsync list` | Gekoppelte Sites, letzter Pull, lokale URL. |
| `wpsync scan <site> [--refresh] [--preset p] [--uploads-since JJJJ]` | Holt das Infosheet (Plugins, Tabellen mit Einstufung, Post-Typen, Uploads pro Jahr, Auffälligkeiten) und fragt im Terminal Preset und Checkliste ab. Ohne Terminal: `--preset`. `--refresh` lässt die Site das Infosheet neu erstellen (nötig, wenn WP-Cron aus ist). Speichert das Profil. |
| `wpsync pull <site> [--full] [--yes] [--dry-run]` | Zieht nach Profil. Ohne Profil Abbruch mit Hinweis auf `scan`. `--full` ignoriert die Baseline, `--yes` behandelt neue Tabellen/Plugins nach der Preset-Regel ohne Rückfrage, `--dry-run` zeigt nur an (wie `status`). |
| `wpsync status <site>` | Was sich seit dem letzten Pull auf der Site geändert hat – Dateien und Tabellen, ohne Inhalte zu übertragen. |
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
`termmeta`, `options`, `users` und `usermeta` kommen **immer mit Daten**. `users`/`usermeta`
enthalten damit personenbezogene Daten – eine Anonymisierung ist noch nicht eingebaut.

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

### Uploads-Proxy

Nicht gezogene Upload-Jahre fehlen lokal nicht sichtbar: nginx im DDEV-Container holt eine
fehlende Datei beim ersten Aufruf von der Live-Site (höchstens 2 Requests/s, jede Datei nur
einmal) und legt sie lokal ab.

---

## Was beim Pull passiert

1. **Infosheet** holen und mit dem Profil abgleichen.
2. **Erstlauf:** DDEV-Projekt mit PHP- und DB-Version der Quelle, WordPress-Core in exakt der
   Version der Site, `wp-config.php` von der Site, local-mailguard eingebunden.
3. **Delta-Check** gegen die Baseline – seitenweise, nur für den Profil-Umfang.
4. **Dateien:** nur neue und geänderte. Ein abgebrochener Download wird fortgesetzt.
5. **Datenbank:** nur geänderte Tabellen. Kleine gebündelt, große per Keyset-Cursor – alles in
   eine SQL-Datei, ein Import. Fortsetzbar pro Tabelle.
6. **Post-Setup:**
   - Search-Replace der Live-URL (Klartext und JSON-escaped), danach ein zweiter Durchlauf
     mit geladenen Plugins für plugin-serialisierte Objekte (z. B. Borlabs Cookie)
   - `WP_ENVIRONMENT_TYPE=local`, `DISABLE_WP_CRON`
   - Mail-, Security-, Zugriffsschutz- und Caching-Plugins sowie abgewählte Plugins deaktivieren
   - Drop-ins `advanced-cache.php` und `object-cache.php` entfernen
   - Uploads-Proxy einrichten
7. **Mailguard-Pflichtprüfung:** Ist im Container kein aktiver `wp_mail`-Filter nachweisbar,
   bricht der Pull ab und das Projekt wird gestoppt.
8. **Baseline** speichern und Git-Commit im Site-Ordner.

Ein Folge-Pull ohne Änderungen auf der Site kostet wenige Requests.

---

## Sicherheit

- **Pairing:** Einmal-Code (8 Zeichen), 10 Minuten gültig, 5 Versuche. Danach ist der Code verbraucht.
- **Secret:** liegt lokal nur in der macOS-Keychain (`service=wpsync:<site>`), nie in einer Datei.
- **Signatur:** jeder Request ist per HMAC signiert, mit Zeitstempel und Nonce gegen Replays.
  Nicht signierte Requests bekommen `401`.
- **Widerruf:** Werkzeuge → wpsync → Gerät widerrufen. Danach ist das Secret wertlos.
- **Read-only:** Der Agent liest Dateien und Datenbank; er schreibt nur in eigene
  `wpsync_*`-Tabellen und -Optionen.
- **Feste Ausschlüsse (serverseitig):** eigene Tabellen/Optionen, `wp-content/cache`,
  `upgrade`, `wflogs`, bekannte Backup-Ordner, `.git`, Symlinks, `*.log`, Dateien > 256 MB.
- **Zugriffsschutz:** Signierte wpsync-Routen passieren das Plugin „Password Protected“.
- **Keine Mails lokal:** Ohne aktiven local-mailguard läuft kein Pull. Grund: Ein Prod-Dump
  bringt oft SMTP-Plugins mit echten Zugangsdaten mit.

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
~/wpsync-sites/<site>/
├── .ddev/                           DDEV-Konfiguration inkl. Mailguard-Mount
├── public/                          WordPress
├── .wpsync/
│   ├── baseline.json                Stand des letzten Pulls
│   └── db/pull.sql                  letzter Dump (wird beim nächsten Pull ersetzt)
└── .git/                            internes Repo, Auto-Commit nach jedem Pull
```

Das interne Git versioniert `public/wp-content` ohne Uploads, Cache und Upgrade-Ordner, dazu
die Baseline – keine DB-Dumps.

| Umgebungsvariable | Standard | Wofür |
|---|---|---|
| `WPSYNC_CONFIG_DIR` | `~/.config/wpsync` | Ablage der Site-Konfigurationen |
| `WPSYNC_SITES_DIR` | `~/wpsync-sites` | Ablage der lokalen Projekte |
| `WPSYNC_MAILGUARD` | – | eigener Mail-Riegel statt des mitgelieferten (siehe [Mail-Riegel](#mail-riegel)) |

---

## Fehlerbehebung

| Symptom | Ursache / Lösung |
|---|---|
| `*.ddev.site` löst nicht auf | `wpsync setup` fehlt oder der Router blockiert DNS-Rebind → `wpsync doctor` |
| „noch kein Pull-Profil – zuerst wpsync scan …“ | einmal `wpsync scan <site>` |
| `401` beim Pull | Pairing auf der Site widerrufen → `wpsync unpair <site>`, neu koppeln |
| „Der Server antwortet nicht mehr – vermutlich eine IP-Sperre“ | fail2ban/WAF hat gesperrt. Genannte IP entsperren und whitelisten, `User-Agent: wpsync/*` freigeben, ggf. `rps` senken |
| Infosheet veraltet / fehlt | WP-Cron ist auf der Site aus → `wpsync scan <site> --refresh` |
| Pull bricht mit Mailguard-Fehler ab | `wpsync doctor` zeigt, welche Datei gesucht wird – meist zeigt `WPSYNC_MAILGUARD` ins Leere. Hat die Site eigene Mail-Wege außerhalb von `wp_mail`, greift der Riegel dort nicht |
| Bilder fehlen lokal | Jahr liegt vor `uploads.since`; der Proxy lädt beim ersten Aufruf nach. Dauerhaft: `since` im Profil anpassen und erneut ziehen |

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
    ├── localgit/          internes Site-Git
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
5. Im Tap `UserMind2018/homebrew-tap` Tag und Revision in `Formula/wpsync.rb` anheben.
