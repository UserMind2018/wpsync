# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/de/). Tag = Version der CLI; die
Agent-Version steht pro Release dabei.

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
