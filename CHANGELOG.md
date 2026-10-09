# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/de/). Tag = Version der CLI; die
Agent-Version steht pro Release dabei.

## [Unreleased] · CLI 0.9.0 · Agent 0.9.0

### Plugins im Push aktivieren und deaktivieren (Content-Push P4)

**Agent und CLI ändern sich.** Neue Mindestversion nur für die neuen Schalter: `--activate` und
`--deactivate` brauchen Agent ≥ 0.9.0 (sonst Exit 11, `error.required: "0.9.0"`, nichts
übertragen). Ohne die Schalter verhalten sich CLI ≥ 0.9.0 gegen ältere Agents und ältere CLIs gegen
Agent ≥ 0.9.0 wie bisher.

**Neu**
- `wpsync push … --activate plugins/<slug>` und `--deactivate plugins/<slug>` (mehrfach oder mit
  Komma): Der Agent schreibt `active_plugins` selbst – auf dem Server gebaut, in der Transaktion des
  DB-Schritts, mit oder ohne Inhalts-Paket, bytegleich mit dem, was `activate_plugin()` des Core
  schreibt. Im Commit läuft kein Code des Plugins. Eine Einheit aus `--activate` geht immer in den
  Satz; `--deactivate` braucht weder Einheit noch lokalen Ordner und geht auch mit `--no-code`
- Der Probelauf prüft Hauptdatei, `Requires PHP`, `Requires at least`, `Requires Plugins` gegen das
  Ziel und beim Deaktivieren `required_by` – auch ohne Push-Fenster; verbindlich noch einmal im
  Commit vor dem Tausch, an der gebauten Datei
- Nur in einem Push-Fenster, das ein Benutzer mit `activate_plugins` im WP-Admin geöffnet hat
  (`plugins_not_allowed` sonst); nie `plugins/wpsync-agent`, kein Theme, nicht `mu-plugins` (Exit 2)
- Der Agent schützt den Ordner, in dem er wirklich liegt (nicht nur den Namen `wpsync-agent`): nie
  als Schalter, nie als Einheit eines Pushs, und sein Eintrag wird nie aus `active_plugins` gestrichen
- Die Rücknahme eines **bestätigten** Pushs, der Plugins geschaltet hat, braucht dasselbe Recht
  (`plugins_not_allowed` sonst; auf der Admin-Seite fehlt der Knopf); ein unbestätigter Push geht
  immer zurück
- Rücknahme der Liste als **Delta** des authentisierten Vorher-Abbilds – über den Agent und über
  `rescue.php` ohne WordPress; fremde Änderungen an der Liste bleiben und sperren nichts
- Der Health-Check prüft zusätzlich `wp-admin/admin-ajax.php` (Plugins im Admin-Kontext)
- `rescue.hardening` nennt ein Sicherheits-Plugin auch dann, wenn der Satz es erst aktiviert
- Nacharbeit `plugins_effective`: der Agent liest nach dem Leeren des Object-Cache zurück, welche
  Liste WordPress als Nächstes lädt (alloptions, sonst Cache der Option, sonst die Zeile). Scheitert
  sie oder `object_cache`/`plugins_cache` – oder fehlt sie auf Live, obwohl etwas geschaltet wurde –,
  bestätigt die CLI einen Satz mit Plugin-Zustand nicht, sondern nimmt ihn zurück (Exit 43)
- `--to staging`: nur die Tabelle der Kopie; was die Kopie abschaltet, wird dort nicht aktiviert
  (`skipped`)
- `--json`: im `plan` `plugins` und `hooks_skipped`; im Ergebnis `plugins`, nach einer Rücknahme
  `plugins_back` und ggf. `plugins_not_restored`; Warnungen `deactivation_review`,
  `requirements_unchecked`, `activation_hooks_skipped`, `deactivation_hooks_skipped`,
  `plugins_not_restored`, `admin_check_skipped`; `error.reason` `plugins_invalid`, `plugins_requirements`,
  `plugins_not_allowed`, `plugins_unsupported`, `plugins_failed` mit `error.plugins`
- Agent und CLI nennen jeden Eintrag, den ein Push schaltet – auch mit Klammern oder Umlauten im
  Dateinamen (in der Ausgabe gequotet); gekappte Listen tragen `<liste>_total`, und
  `plugins_not_restored.unknown: true` sagt, wenn die Site die Einträge des Pushs nicht kennt
- Push-Protokoll (`wpsync pushes`) und Admin-Seite zeigen die Einheit `plugins` mit dem, was
  geschaltet wurde
- Eine Ablehnung der Inhalte nennt im Fehlerobjekt der CLI jetzt auch `error.total`,
  `error.state_bytes` und `error.tables`
- `scripts/e2e-plugins.sh`: E2E für den Plugin-Zustand; `scripts/e2e-rescue-db.sh` pusht Code,
  Uploads, Inhalte und Plugin-Zustand in einem Satz

**Geändert**
- Ein Satz mit Plugin-Zustand braucht den Umschlag für `rescue.php` – immer, auch ohne
  `--require-rescue-db` (`rescue_db_unavailable` mit `error.detail`, schon nach dem Probelauf)
- „Überholt“ zählt auch geschaltete Einheiten: ein späterer, noch stehender Push, der dieselbe
  Einheit tauscht oder schaltet, sperrt die Rücknahme des älteren (`rescue.json`: `switched`,
  `committed_at`). Gerechnet wird aus allen Datensätzen: jeder spätere Push sperrt, nicht nur der
  erste – auch einer, dessen Datenbank-Anteil nach `rescue.php` noch steht
- Ein Satz mit Plugin-Zustand hat einen Datenbank-Anteil: Die Rücknahme geht zuerst über den Agent
  und schickt `rescue.php` `content=1`, auch ohne Paket
- `--no-code` braucht `--uploads`, `--content` oder `--deactivate`
- Die CLI liest eine JSON-Antwort des Agents nur noch bis 256 MiB (nach dem Entpacken gezählt);
  darüber bricht der Befehl mit einem klaren Fehler ab. Dateien, Tabellen und das Inhalts-Manifest
  werden weiter gestreamt
- Nach einer Rücknahme über `rescue.php` trägt das Ergebnis kein `post_actions` mehr (bisher standen
  dort die Nacharbeiten des Commits)
- Die Rückfrage vor einem Push nennt die Plugins, die abgeschaltet werden, beim Namen; ohne Terminal
  (Container-Modus, `--json`) gilt wie bisher `--yes`
- Der Plan nennt eine neue Einheit aus `--activate` nicht mehr „bleibt auf der Site inaktiv“; der
  Hinweis „Nach dem Test nach Live“ nach einem Push nach Staging nennt die Schalter mit

**Grenzen**
- Kein Downgrade des Agents auf 0.8.x, solange Pushes mit Plugin-Zustand offen oder aufbewahrt sind:
  der ältere Agent nimmt sie ohne die Liste zurück
- Die Aktivierungsroutine (`register_activation_hook`) läuft nicht – die CLI warnt mit
  `activation_hooks_skipped` (Textsuche im Code der Einheit); Deaktivierungsroutinen laufen nie.
  Keine Multisite, keine Themes, keine Einzeldatei-Plugins
- Solange der Commit eines Pushs nicht quittiert ist, zählt für die Liste der Abdruck statt des
  Deltas; ein von Hand hergestellter, bytegleicher Stand ist dann nicht vom Push zu unterscheiden
- Stirbt `rescue.php` zwischen dem COMMIT seiner Rücknahme und dem Vermerk, kann ein persistenter
  Object-Cache `active_plugins` im gepushten Stand behalten

## [0.8.0] – 2026-10-09 · Agent 0.8.0

**Agent und CLI ändern sich.** Keine neue Mindestversion: die CLI erkennt die Fähigkeit am Feld
`rescue.db` des Begin. CLI ≥ 0.8.0 gegen Agent 0.7.x und CLI 0.7.x gegen Agent ≥ 0.8.0 verhalten
sich wie bisher (`content_not_rolled_back`).

**Neu**
- `rescue.php` nimmt einen unbestätigten Push vollständig zurück, auch wenn WordPress nicht mehr
  lädt: **Inhalte → Code → Uploads**, mit derselben Logik wie der Agent (`ContentRollback` über
  `ContentSql`, jetzt auch auf einer eigenen `mysqli`-Verbindung). Die Rücknahme nach dem
  Health-Check endet dann mit Exit 43 **ohne** `content_not_rolled_back`, `wpsync rollback` mit
  Exit 0; Manifest, Baseline und Journal gehen zurück wie bei der Rücknahme über den Agent
- Versiegelter Umschlag `rescue.sealed` je Push mit Inhalten: Verbindungsdaten und Sitzung aus dem
  laufenden WordPress (kein Lesen von `wp-config.php`), verschlüsselt mit einem Schlüssel, der
  aus dem Rollback-Schlüssel des Pushs abgeleitet ist. Der Begin prüft die Verbindung mit einer
  Probe und nennt `rescue.db: {ok, reason?}` (`no_crypto`, `driver`, `no_image_key`,
  `probe_failed`, `write_failed`). Lebensdauer: bis zur Rücknahme, bis `confirm`, höchstens 24 h
- `wpsync push … --require-rescue-db`: ein Push mit Inhalten geht nur raus, wenn `rescue.php` sie
  zurücknehmen könnte – sonst Exit 1, `error.reason: "rescue_db_unavailable"`,
  `error.detail: <grund>`. Ohne das Flag wird gepusht und gewarnt
  (`warnings: ["rescue_db_unavailable"]`); das `plan`-Ereignis nennt `rescue_db`
- `rescue.php`, `action=cache`: leert nach einer Rücknahme der Inhalte einen persistenten
  Object-Cache (lädt WordPress mit `SHORTINIT`, ohne Plugins und Themes). Die CLI ruft ihn, wenn
  die Antwort `cache: "stale"` sagt; scheitert er, bleibt die Rücknahme gültig:
  `warnings: ["object_cache_stale"]`
- Nach dem Wiederanlauf von WordPress holt der Agent die Nacharbeiten nach (Marker
  `rescue.pending`, `init`), schliesst den Push ab und vermerkt es im Protokoll: Einheit `content`
  mit `via: "rescue"`, `post_actions`, `left`
- Ergebnis von `push` und `rollback`: `via` (`agent`/`rescue`), `content_error`
  (`{code, keys, total}` – warum `rescue.php` die Inhalte stehen liess), `content_left`,
  `content_left_total`; Warnung `content_left_extra`

**Geändert**
- Im Notfallweg blockiert „gewachsen“ nicht: hängt an einem vom Push eingefügten Objekt etwas,
  das nicht vom Push stammt, nimmt `rescue.php` die Zeilen des Pushs zurück und lässt das Fremde
  stehen (`content.left`, `content_left_extra`). Über den Agent bleibt es bei
  `changed_since_push`. Eine vom Push geschriebene Zeile, die sich geändert hat, lehnt auch
  `rescue.php` ab – dann gehen nur Code und Uploads zurück; es gibt kein `force`
- Fünf falsche Schlüssel sperren `rescue.php` nur noch für falsche Schlüssel (429); der richtige
  gilt weiter. Fehlversuche stehen in `rescue.tries`, nicht mehr in `rescue.json`
- `rescue.php`, `/push/rollback` und der Commit eines Pushs schliessen sich über eine Sperrdatei
  aus (`rescue.lock`): wer zu spät kommt, bekommt HTTP 423 (`busy` bzw. `wpsync_push_busy`); die CLI
  wiederholt dreimal im Abstand von 2 s. Ein Commit, den eine Rücknahme überholt hat, macht
  `ROLLBACK`
- Was `rescue.php` an eingefügten Objekten stehen lässt, bleibt nicht folgenlos liegen – es hinge
  sich an ein neues Objekt mit derselben ID. Neu deshalb: **(a)** der Agent lehnt ein `insert`
  ab, an dessen ID auf dem Ziel noch etwas hängt (Meta, Zuordnungen, Kommentare, Kinder – auch
  Kind-Terme, deren `parent` auf die ID eines neuen Terms zeigt) –
  `error.reason: "id_has_leftovers"` (HTTP 409 `wpsync_content_id_has_leftovers`, Exit 1) mit den
  Resten in `error.keys`; nicht im Probelauf ohne offenes Push-Fenster. **(b)** Beim Wiederanlauf
  entfernt der Agent an jedem eingefügten Objekt, das weiterhin fehlt, die Meta und die
  Zuordnungen – an einem Beitrag nur die in Taxonomien, die für Beiträge gelten (`object_id` ist
  auch die ID von Benutzern und Links);
  das Protokoll nennt es als Nacharbeit `left_cleanup`. Kommentare und Kinder (Revisionen,
  Kindseiten) löscht er nicht – solange sie liegen, greift (a)
- `rescue.php` schickt die CLI ab jetzt `content=1`, wenn der Push Inhalte trug; ohne das Feld
  bleibt es bei Code und Uploads
- `wpsync rollback --json` und ein zurückgerollter `push --json` nennen `via`

**Behoben**
- `rescue.php`-Sperre als Blockade: wer die Push-ID kannte, konnte den Notfallweg mit fünf falschen
  Schlüsseln für 10 Minuten sperren – auch für den richtigen
- Ein aufgeräumter Rescue-Stub, an dessen Stelle der Server umleitet (3xx statt 404), ist jetzt
  „Notfallweg vorbei“ (`ErrRescueGone`) statt eines rohen HTTP-Fehlers
- Antworten von `rescue.php` über 4.000 Bytes (viele Schlüssel) liest die CLI jetzt vollständig
- Wettlauf `confirm` ↔ `rescue.php`: `/push/confirm` nimmt jetzt die Sperre des Pushs
  (`rescue.lock`) und prüft seinen Stand darunter neu. Ein Push kann nicht mehr „bestätigt“ in der
  Datenbank stehen, während `rescue.php` ihn zurücktauscht: läuft eine Rücknahme, wartet `confirm`
  bis zu 15 s und antwortet sonst mit HTTP 423 `wpsync_push_busy`; hat `rescue.php` den Push
  inzwischen zurückgenommen, antwortet es mit 409 `wpsync_push_state`. Die Rücknahme lehnt einen
  bestätigten Push unter der Sperre selbst ab (`rescue.php`: 409 `confirmed`; `/push/rollback`
  für einen eben erst bestätigten: 409 `wpsync_push_state`, danach gilt das Push-Fenster)
- Wettlauf `confirm` ↔ Rücknahme über den Agent: `/push/confirm` liest die Zeile des Pushs nach dem
  Warten auf die Sperre neu (auch wenn es keine Sperre mehr zu nehmen gab, weil der Ordner des
  Pushs schon weg ist) und bestätigt nur noch einen Push, der dann weiter unbestätigt ist – sonst
  409 `wpsync_push_state`. Beim Aufräumen eines Pushs schreibt der Agent erst das Protokoll, dann
  löscht er den Ordner
- Scheitern die Inhalte eines Commits, während eine Rücknahme desselben Pushs die Sperre länger als
  15 s hält, räumt der Commit nicht mehr ungesperrt daneben auf: er antwortet 409
  `wpsync_push_pending` (Exit 42), die laufende Rücknahme schliesst den Push ab
- Der Vermerk „von einem späteren Push überholt“ (`superseded_by`) wird nur noch unter der Sperre
  des betroffenen Pushs geschrieben und gelöst – nie neben dessen Rücknahme. Ein Vermerk, dessen
  späterer Push zurückgerollt ist oder nicht mehr existiert, sperrt den älteren nicht mehr
- Die CLI wertet eine Antwort von `rescue.php` nur noch als Rücknahme, wenn sie
  `status: "rolled_back"` trägt (das tut `rescue.php` seit Agent 0.4.0) – HTTP 200 mit `ok` allein
  genügt nicht mehr. Sonst: „ROLLBACK FEHLGESCHLAGEN“ (Health-Rücknahme) bzw. „Rollback über
  rescue.php fehlgeschlagen“ (`wpsync rollback`, Exit 1); Manifest, Baseline und Journal bleiben.
  `rescue.php` nennt in der Antwort einer Rücknahme ausserdem `push_id`; nennt sie einen anderen
  Push als den gefragten, gilt sie der CLI ebenfalls nicht (fehlt das Feld – Agent < 0.8.0 –, wie
  bisher)
- Ein Webroot, dessen Pfad `[`, `]`, `*` oder `?` enthält: `rescue.php` fand den Push nicht (404
  `unknown push`), der Agent weder den Marker `rescue.pending` noch – beim Deaktivieren – seine
  Arbeitsordner, und Plugin-Versionen blieben leer. Der Agent listet Ordner jetzt ohne `glob()`

**Sicherheit**
- Vor bestandener Schlüsselprüfung lädt `rescue.php` nichts ausser `PushSwap` und `PushRescue`,
  liest keinen Umschlag und öffnet keine Datenbankverbindung
- Geschrieben wird nur aus dem authentisierten Vorher-Abbild, nur in die sieben Tabellen mit dem
  Präfix aus dem Umschlag; der Umschlag einer Staging-Kopie gilt nur in ihrem Ordner und für ihr
  Präfix. Klartext-Abbilder nimmt `rescue.php` nie an
- Keine Antwort und keine Zeile im Fehlerprotokoll nennt Host, Benutzer, Passwort,
  Datenbankname, einen Wert oder Text des Datenbankservers
- Falsche Schlüssel schreiben nicht mehr in `rescue.json`
- Der Schlüssel des Umschlags entsteht aus den 32 Byte des Rollback-Schlüssels
  (`HMAC-SHA256(hex2bin(K), "wpsync-rescue-envelope-v1\0" + push_id)`), nicht aus seiner
  Hex-Schreibweise; als Rollback-Schlüssel gilt dem Umschlag nur, was genau 64 kleine Hex-Zeichen
  hat
- Harte Altersgrenze des Umschlags: älter als 7 Tage wird er nie mehr angewandt
  (`content_error.code: "rescue_db_unavailable"`) – unabhängig vom Verfall nach 24 h, den die
  tägliche Wartung des Agents besorgt und der ausbleibt, solange WordPress unten ist. Das Alter
  steht authentisiert im Umschlag; einer, der mehr als fünf Minuten in der Zukunft liegt, gilt
  ebenfalls nicht
- Lässt sich ein Push nicht sperren (kein `flock`), schliesst `rescue.php` seinen DB-Anteil nie
  ab – auch nicht, wenn noch kein Vorher-Abbild liegt: `content_error.code:
  "rescue_db_unavailable"`, Code und Uploads gehen wie bisher zurück
- Der Cache-Schritt von `rescue.php` (`cache: "flushed"`) und der Agent beim Wiederanlauf
  (`post_actions`) schreiben ihren Vermerk nur noch unter der Sperre des Pushs in `rescue.json`;
  ist der Push gerade gesperrt, holt der Agent die Nacharbeiten beim nächsten Seitenaufruf nach
- Cache-Schritt von `rescue.php`: bevor WordPress geladen wird, verschwindet der Schlüssel aus
  `$_POST` und `$_REQUEST`; ein `Location`-Header, den `wp-config.php` oder ein Drop-in setzt,
  verlässt den Server nicht
- Die Datenbankverbindung von `rescue.php` verbietet `LOAD DATA LOCAL INFILE`
  (`MYSQLI_OPT_LOCAL_INFILE = 0`), bevor sie aufgebaut wird

## [0.7.0] – 2026-10-09 · Agent 0.7.0

**Agent und CLI ändern sich** (Agent 0.7.0). `pull --content` und `push --content` brauchen
Agent ≥ 0.7.0; ohne `--content` gilt alles wie bisher – bis auf die doppelt escapten URLs unter
„Geändert“.

### Neu
- `wpsync push <site> code … --content <package.jsonl>`: Inhalte als dritter Kanal eines Pushs –
  ein Paket aus Zeilen der sieben Inhaltstabellen (`update`, `insert` mit fester ID, `trash` –
  der Agent tut dabei, was WordPress beim Weg in den Papierkorb tut, samt `__trashed` am Namen
  und `_wp_desired_post_slug`; `trash` darf `row` mit genau `post_date` und `post_date_gmt`
  tragen – das Datum, das WordPress einem nie veröffentlichten Entwurf beim Verschieben gibt),
  transaktional angewandt als letzter Schritt des Commits (Uploads → Code → Inhalte), mit
  Vorher-Abbild, Nacharbeiten (`data.post_actions`), Messwert (`data.content: {rows, seconds}`)
  und Health-Check der geänderten Seiten.
  `--dry-run` prüft das Paket ohne Push-Fenster (vollständig nur mit offenem Fenster, siehe
  „Sicherheit“); das `plan`-Ereignis nennt `content: {rows, conflicts, limits, partial,
  unchecked, unchecked_total}`. Auch
  nach `--to staging`
- `--no-code`: ein Satz nur aus `--uploads` und `--content`
- `wpsync rollback` nimmt den ganzen Satz zurück (Inhalte → Code → Uploads) – oder nichts, wenn
  sich Zeilen seit dem Push geändert haben (`changed_since_push`). Antwortet WordPress nicht,
  nimmt `rescue.php` Code und Uploads zurück und das Ergebnis nennt
  `warnings: ["content_not_rolled_back"]`; ein späterer `rollback` holt die Inhalte nach – oder
  `pushes --confirm` schliesst den Push als zurückgerollt ab und lässt die Inhalte stehen
  (`status: "rolled_back"`, `warnings: ["content_kept"]`). Die Rücknahme schreibt an bestehenden
  Zeilen nur zurück, was der Push geschrieben hat
- Nach einem bestätigten Inhalts-Push nach Live zieht die CLI `manifest.jsonl` und
  `baseline.jsonl` nach; `rollback` nimmt das zurück
- Agent: Endpunkt `/content/stage` (Paket in Stücken, je Kopplung, 24 h), Prüfungen eines Pakets
  gegen die Listen des Agents, Sperre von serialisierten Objekten (`unsafe_value`), von Resten
  der lokalen Adresse und von Pseudonymen; `rescue.json` kennt den DB-Anteil eines Pushs. Die
  Transaktion ist an ihre Datenbankverbindung gebunden (Sitzungsmarke): nach einem Reconnect
  schreibt nichts mehr und es gibt keinen `COMMIT`. Sites hinter HyperDB/LudicrousDB lehnt der
  Agent ab (`engine_unsupported`)
- `error.reason` bei Exit 1 für Inhalte: `package_invalid`, `baseline_outdated`,
  `origin_mismatch`, `package_too_large`, `engine_unsupported`, `blocked_row`,
  `list_version_mismatch`, `unsafe_value`, `local_origin_in_package`, `pseudonym_in_package`,
  `write_mismatch`, `id_outside_corridor`, `id_taken`, `conflict`, `row_unfaithful`,
  `dangling_reference`, `upload_missing`, `author_unknown`, `package_missing`,
  `content_failed`, `changed_since_push` – dazu `error.keys` und `error.paths`
- `wpsync pull <site> --content`: Manifest der sieben Inhaltstabellen (Fingerabdruck je Zeile,
  Meta-Paar und Zuordnung, keine Werte; für Zeilen, die der Pull pseudonymisiert, auch kein
  Fingerabdruck – `h: null`, `why: "pseudonymized"`), `map.json`, Baseline, `unfaithful.jsonl`
  (Gründe `differs`, `pseudonymized`, `unnormalizable`, `key_encoding`, `unnormalizable_local`,
  `local_only`), `env.json` und
  `summary.json` unter `<site>/.wpsync/content/`; Ergebnis zusätzlich
  `content: {rows, unfaithful, id_max, canon_version, reloaded}`, Phase `content`. Lädt alle
  sieben Inhaltstabellen neu, sobald sich eine geändert hat oder der Stand fehlt. Braucht
  Agent ≥ 0.7.0 (sonst Exit 11) und ein Profil mit allen sieben Tabellen samt Daten (sonst Exit 2)
- `wpsync content export <site>`: normalisierte Zeilen und Fingerabdrücke der Arbeitskopie als
  JSON-Lines auf stdout – dieselbe PHP-Implementierung wie auf der Site, per `wp eval-file -`;
  je Zeile `p` (pushbar laut Listen des Agents, ohne Projekt-Erweiterungen) und sonst `why`
  (`key`, `post_type`, `taxonomy`, `meta_key`, `meta_word`, `option`, `no_object`, `unnormalizable`,
  `key_encoding`). Läuft ohne Request an die Site, auch im Container-Modus (PHP-Version und
  Tabellenpräfix aus `env.json`); Meldungen immer auf stderr, mit `--json` das Ergebnisobjekt
  als letzte Zeile. Braucht einen aktuellen Inhaltsstand (sonst Exit 2)
- Agent: Endpunkt `/content/manifest` (JSON-Lines, seitenweise mit Zeitbudget und höchstens rund
  20 000 Datensätzen je Seite, im Umfang des
  Pull-Profils, ohne Transients und `wpsync_*`-Optionen); im Kopf `id_max` und `engines` (nur
  für Tabellen im Umfang des Profils), die Listen
  des Agents und die Pseudonym-Muster, dazu `pushable: false` mit `why` bei Multisite oder
  abweichender WordPress-/Website-Adresse
- `error.reason` bei Exit 1: `manifest_incomplete`, `content_export_failed`, `canon_version`

### Geändert
- Listen des Agents für Inhalte in Version 2 (`list_version`): mehr Sperrwörter für
  Meta-Schlüssel und Optionen (`passwd`, `credential`, `apikey`, `api-key`, `webhook`, `oauth`;
  als ganzes Namensglied – zwischen `_ - . :`, Ziffern oder an einer camelCase-Grenze – `pass`,
  `pwd`, `auth`, `salt`, `sk`, `private`), Objekt-IDs höchstens 18 Stellen, Prüfung auch in
  Kleinschreibung, feste Liste von Beitragstypen, die keine Projekt-Erweiterung freigibt. `p` und
  `why` in `content export` folgen den neuen Listen
- Jeder Pull ersetzt auch doppelt escapte URLs (`https:\\\/\\\/…`, JSON in JSON); `staging create`
  und `staging refresh` schreiben sie jetzt ebenfalls um (bisher blieben sie auf Live gerichtet)
- Ein Pull ohne `--content`, der eine der sieben Inhaltstabellen neu lädt, verwirft einen
  vorhandenen Inhaltsstand; der nächste `pull --content` baut ihn neu (`reloaded: true`)
- `staging create`/`staging refresh`: Serialisierte Werte mit Leerraum an den Rändern werden wie
  von WordPress (`is_serialized()`) als serialisiert erkannt und mit korrigierten Längen
  umgeschrieben (bisher als Text ersetzt und damit zerstört). Sieht ein String **in** einem
  serialisierten Wert serialisiert aus, lässt sich nicht lesen und enthält die Live-URL, bleibt
  der ganze Wert unverändert und zählt in `skipped_values` (bisher wurde er als Text ersetzt)
- Agent: Für eine Anfrage an den Agent gibt `$wpdb` keinen Datenbankfehler mehr aus. Mit
  `WP_DEBUG` und `WP_DEBUG_DISPLAY` stand er sonst samt Abfrage als HTML vor dem JSON der
  Antwort – die CLI konnte sie nicht lesen (ein abgelehnter Inhalts-Push galt als „Stand
  unklar“ statt `content_failed`), und die Meldung zeigte die Abfrage mit ihren Werten. Im
  Fehlerprotokoll des Servers steht der Fehler weiter

### Sicherheit
- CLI: Die lokale URL aus `map.json` wird geprüft, bevor sie als Argument an `wp eval-file`
  geht (http(s)-URL ohne Leerraum und Steuerzeichen, sonst Exit 20), und in Meldungen nur
  bereinigt genannt
- CLI: Zeilen des Manifests (Kopf 1 MiB, sonst 64 KiB), von `manifest.jsonl`/`baseline.jsonl`
  und des Exports (256 MiB) sind in der Länge begrenzt – eine Site oder ein Skript kann die CLI
  nicht mehr mit einer endlosen Zeile den Speicher füllen lassen (`manifest_incomplete` bzw.
  `content_export_failed`)
- Agent: `/content/manifest` liefert für Zeilen, die der Pull pseudonymisiert (Bestellungen,
  pseudonymisierte Meta-Schlüssel, `admin_email`/`new_admin_email`), keinen Fingerabdruck des
  echten Werts – ein ungesalzener Abdruck von IP, Postleitzahl, Telefon oder E-Mail liesse sich
  offline erraten. Mit `--no-anonymize` bleiben die Abdrücke
- Agent: Serialisierte Werte mit übergrossen Längenangaben oder tausenden verschachtelten
  Ebenen brechen weder das Manifest noch `staging create` ab – sie gelten als nicht lesbar
  (Tiefe insgesamt höchstens 64, verschachtelte serialisierte Strings zählen mit)
- Agent: Eine URL-Ersetzung macht aus einem ungültigen serialisierten Wert nie einen gültigen –
  weder mit Leerraum an den Rändern noch eine Ebene tiefer
- Agent: Neue IDs eines Inhalts-Pakets (`posts`, `terms`, `term_taxonomy`) prüft der Agent nicht
  mehr nur gegen den Korridor aus dem Paket, sondern gegen das Ziel: höchstens
  `limits.id_headroom` (2.000.000) über `max(MAX(id), AUTO_INCREMENT − 1)` der Tabelle – auf
  Staging der Kopie – und nie über 2^53 − 1, sonst `id_outside_corridor` mit `error.keys`. Ein
  `insert` mit einer ID wie 999999999999999999 verschob sonst den `AUTO_INCREMENT` von Live
  dauerhaft (auch eine Rücknahme setzt ihn nicht zurück). Zwei Millionen, weil neue Objekte
  der Arbeitskopie bei `id_max` + 1.000.001 beginnen. `limits` nennt die Grenze als
  `id_headroom`, auch im `plan`-Ereignis der CLI
- Agent: Eine gescheiterte Abfrage des Inhaltskanals landet nicht mehr samt allen Werten im
  PHP-Fehlerprotokoll. `hide_errors()` schaltete nur die Ausgabe ab; `wpdb::print_error()`
  schrieb die ganze Abfrage weiter per `error_log()`. Schreiben, Lesen (auch unter Sperre),
  Rücknahme, Reparatur und die Nacharbeiten laufen jetzt mit `suppress_errors(true)` und
  stellen danach die Einstellung der Site wieder her; im Protokoll steht nur noch die
  Fehlernummer der Datenbank
- Agent: `rollback` löscht nicht mehr mit, was nach dem Push an einem **eingefügten** Beitrag,
  Term oder einer eingefügten `term_taxonomy`-Zeile entstanden ist. Hängt dort etwas, das der
  Push nicht geschrieben hat – Meta-Schlüssel, Zuordnungen (auch fremder Beiträge an der neuen
  `term_taxonomy`), Kommentare, Revisionen/Kindseiten, weitere Taxonomien, Kind-Terme –, endet
  die Rücknahme mit `changed_since_push` und nennt die Stellen in `error.keys` (Kommentare als
  `{table: "comments", key: "<post-id>"}`); nichts wird zurückgenommen. Meta der festen
  Sperrliste (`_edit_lock`, `_edit_last`, `_wp_old_slug`, `_wp_trash_meta_*`, `_elementor_css` …)
  und der oEmbed-Cache (`_oembed_*`) zählen nicht und gehen mit dem Beitrag
- Agent: Engere Grenzen für Projekt-Erweiterungen (`extensions`) eines Inhalts-Pakets,
  `list_version` bleibt 2. Die feste Liste `never_post_types` kennt weitere Code-Träger, Shop-,
  Mitgliedschafts-, Kurs-, Formular- und Weiterleitungs-Typen; dazu Präfixe (`shop_`, `wc_`,
  `edd_`, `memberpress`, `sfwd-`, `llms_`, `tutor_`, `ld-`, `frm_`, `forminator_`, `wpforms`,
  `nf_`, `jp_`, `amp_`, `flamingo_`) und Wörter im Namen (`snippet`, `code`, `redirect`,
  `webhook`, `payment`, `order`, `subscription`, `membership`, `coupon`). Neu eine Sperre für
  Taxonomien: `action-group`, `user-group`, `link_category`, `product_type`,
  `product_visibility`, `shop_order_status` und alles mit `user`, `role` oder `cap` im Namen.
  Ein Paket, das so etwas freischalten will, ist `package_invalid`. Eine Taxonomie aus einer
  Erweiterung, die die Site (auch) für Benutzer registriert hat, ist `blocked_row`, ebenso eine
  Zuordnung an einen Beitrag, für dessen Typ sie nicht registriert ist. Im Manifest-Kopf neu:
  `lists.never_post_type_words`, `lists.never_taxonomies`, `lists.never_taxonomy_words`
- Die Erweiterungen eines Pakets bleiben sichtbar: `content.extensions` in der Antwort des
  Begin und im `plan`-Ereignis, `units[].extensions` an der Einheit `content` in
  `wpsync pushes --json` (ohne `--json` hinter der Einheit) und in der Liste der Pushes im
  WP-Admin. Das Feld fehlt, wenn das Paket keine nennt
- Agent: Eine Transaktion des Inhaltskanals wartet höchstens 10 Sekunden auf eine fremde Sperre
  (`SET SESSION innodb_lock_wait_timeout`, ein Versuch; danach wieder der Wert der Site) – läuft
  die Zeit ab, endet der Push mit `content_failed`, nichts ist geschrieben. Und was die Prüfung
  vom Ziel liest (das Vorher-Abbild), ist in der Summe auf `limits.max_state_bytes` (64 MB)
  begrenzt, im Probelauf wie beim Anwenden: sonst `package_too_large` mit `error.state_bytes`,
  bevor etwas geschrieben wird
- Agent: `before.json` und `after.json` eines Inhalts-Pushs liegen nicht mehr als ungeschützter
  Klartext im Arbeitsordner. Mit einem Schlüssel der Installation (`WPSYNC_KEY`, sonst die
  Salts aus `wp-config.php`) sind sie verschlüsselt und authentisiert (mit der PHP-Erweiterung
  `sodium`), sonst mit einem HMAC-SHA256 versehen; an Push und Dateinamen gebunden, Modus 0600,
  Ordner 0700. `rollback` prüft das vor dem Lesen, dazu die Form jedes Schlüssels, und schreibt
  nur Schlüssel zurück, die `after.json` als vom Push geschrieben nennt – sonst der neue Grund
  `before_image_invalid` (409, nichts wird zurückgenommen). Die Rücknahme über den WP-Admin
  geht weiter ohne Gerät. Werden `WPSYNC_KEY` oder die Salts nach einem Push geändert, lässt
  sich dessen Inhalt nicht mehr zurücknehmen. Ein beschädigtes Vorher-Abbild meldete bisher
  `content_failed`. Abgelegte Pakete und ihre Kopie im Push sind nur noch für den Besitzer
  lesbar; scheitert das Schreiben, steht kein Pfad im Fehlerprotokoll
- Agent: Der Probelauf eines Inhalts-Pakets ist ohne offenes Push-Fenster kein Weg mehr, die
  Site auszufragen. Ist das Fenster der Kopplung zu, behandelt er ein Objekt mit gesperrtem Typ
  oder gesperrter Taxonomie in jeder Prüfung wie ein fehlendes – die Antwort ist für „fehlt“ und
  „existiert, aber gesperrt“ dieselbe – und prüft die Dateien von Attachments (`upload_missing`)
  nicht. Was er deshalb offen lässt, ist kein Fehler: es steht in `content.unchecked` als
  `[{table, key, check}]` mit `check` `reference` (Verweis auf ein Objekt ausserhalb des Pakets,
  das fehlt oder gesperrt ist) oder `attachment_files`, höchstens 200 Einträge, dazu
  `unchecked_total`. `blocked_row` bleibt, was allein aus der Zeile oder dem Paket folgt. Die
  Antwort nennt `content.partial: true`; die CLI reicht `partial`, `unchecked` und
  `unchecked_total` im `plan`-Ereignis durch und sagt beides in je einer Zeile; der Probelauf
  endet damit nicht mit einem Fehler. Mit offenem Fenster unverändert vollständig, `unchecked`
  leer
- Agent: Die Dateiprüfung von Attachments erkennt ihre Meta-Schlüssel in jeder
  Gross-/Kleinschreibung (`_WP_Attached_File` umging sie; WordPress liest den Schlüssel
  trotzdem), prüft den Pfad am Wert, wie er geschrieben wird (nach dem Einsetzen der Adresse des
  Ziels), und nimmt `_wp_attachment_backup_sizes` dazu: `file` jedes Eintrags ist ein blosser
  Dateiname im Ordner der Datei des Attachments, sonst `blocked_row`; fehlt die Datei,
  `upload_missing`
- Agent: Die Engine-Prüfung gilt immer allen sieben Inhaltstabellen des Ziels, nicht nur denen,
  die das Paket nennt, und läuft auch vor einer Rücknahme: ist eine nicht InnoDB,
  `engine_unsupported` – nichts wird geschrieben bzw. zurückgenommen
- Agent: Die Frage, ob eine Transaktion noch auf ihrer Verbindung lebt, lässt sich nicht mehr
  aus einem Abfrage-Cache beantworten: sie trägt jedes Mal einen anderen Kommentar
  (`SELECT @wpsync_tx /* … */`), und der Inhaltskanal setzt `DONOTCACHEDB` für den Request.
  Erweiterte `wpdb`-Klassen (Query Monitor u. a.) bleiben erlaubt
- CLI: Was der Commit über die Inhalte antwortet, prüft die CLI gegen das Paket. Abdrücke
  (`content.after`) gelten nur für Schlüssel des Pakets und für die Papierkorb-Meta eines
  Beitrags mit `op: trash`; ein fremder Schlüssel lässt `manifest.jsonl` und `baseline.jsonl`
  unangetastet (`warnings: ["content_state_failed"]`). Weicht `content.rows` von der Zeilenzahl
  des Pakets ab, wird der Satz nicht bestätigt, sondern zurückgenommen
- Agent: Ein Wert, der – ohne Leerraum am Rand – wie ein serialisiertes Objekt beginnt
  (`O:8:"stdClass":0:{}x`), ist `unsafe_value`, auch wenn er wegen eines Anhangs nicht als
  serialisiert gilt: PHPs `unserialize()` läse das Objekt trotzdem
- Agent: Unmittelbar vor dem Anlegen neuer Beiträge prüft der Agent, dass es den Benutzer, der
  das Push-Fenster geöffnet hat, noch gibt – sonst `author_unknown`, nichts wird geschrieben
  (bisher wäre ein gelöschter Benutzer Autor geworden)
- Agent: Endet ein Inhalts-Push mit `content_failed` und `unrestored: true` (eine Zeile liess
  sich nach einem Verbindungsverlust nicht zurücksetzen), bleibt sein Arbeitsordner samt
  Vorher-Abbild liegen: der Push steht als `failed` und nicht aufgeräumt im Protokoll, und das
  Aufräumen fasst ihn nicht an. Die Meldung nennt die Zeile und dass sie von Hand zu prüfen
  ist; die CLI sagt dasselbe statt „nichts wurde übertragen“

## [0.6.0] – 2026-10-09 · Agent 0.6.0

**Agent und CLI ändern sich.** Uploads pushen braucht Agent ≥ 0.6.0; ohne `--uploads` gilt alles
wie bisher.

### Neu
- `wpsync push <site> code … --uploads <liste>`: neue Dateien unter `wp-content/uploads/` im
  selben Push wie Code (Einheit `uploads`, nur hinzufügen). Der Agent meldet je Datei `need` oder
  `same`, bricht bei anderem Inhalt am selben Pfad ab (`upload_exists`), sperrt PHP, versteckte
  Dateien, von WordPress nicht erlaubte Typen, aktive Typen (SVG, HTML, XML, JavaScript – auch
  wenn `upload_mimes` sie erlaubt) und Namen, die WordPress umbenennen würde, etwa versteckte
  mittlere Endungen wie `bild.cgi.png` (`upload_type_blocked`), und prüft den Inhalt
  nach dem Upload. Commit Uploads → Code, Rücknahme Code → Uploads, auch über `rescue.php`;
  seither geänderte Dateien bleiben (`warnings: ["upload_changed_since_push"]`). Die Baseline
  kennt die neuen Dateien, ein Folge-Pull überträgt sie nicht noch einmal
- Agent: wer das Push-Fenster geöffnet hat, steht in `wpsync_pairings.push_opened_by` und im
  Protokoll jedes Pushs (`opened_by`, WP-Admin „Fenster von“)
- `push --json`: `health` je verschlechterter Seite nach einer Rücknahme; `error.skipped_new` bei
  `nothing_to_push`

## [0.5.1] – 2026-10-08 · Agent 0.5.1

**Agent und CLI ändern sich.** Push nach Live braucht weiter Agent ≥ 0.4.0, nach Staging ≥ 0.5.0;
den Rescue-Stub gibt es erst mit Agent 0.5.1 – ältere Agents liefern wie bisher
`rescue.php` im Plugin-Ordner.

### Behoben
- Push scheiterte mit `rescue_unreachable`, wenn ein Sicherheits-Plugin (Solid/iThemes Security
  „Disable PHP in Plugins“, Sucuri-Härtung) PHP unter `wp-content/plugins/` sperrt. Der Agent
  liefert `rescue.php` auf Wunsch der CLI jetzt über einen kurzlebigen Stub
  `wpsync-rescue-<32 hex>.php` im Live-Webroot – nur bei offenem Push-Fenster, auch für Pushes
  nach Staging. Er bleibt, solange ein Push läuft oder unbestätigt ist, und rund 10 Minuten
  darüber hinaus; Deaktivieren löscht ihn. Ist der Webroot nicht beschreibbar oder liegt
  `wp-content` nicht direkt darin, bleibt es bei der Plugin-URL
- Verwaiste Push-Arbeitsordner (abgebrochener Push, Zeile fehlt) werden nach 30 Minuten
  aufgeräumt – nur, wenn nie getauscht, zurückgerollt oder bestätigt; bei einem Datenbankfehler
  oder unlesbarer `rescue.json` bleibt alles liegen

### Neu
- `/push/begin`: Feld `rescue_stub` im Request, `rescue.hardening` (aktive Härtungs-Plugins) in
  der Antwort
- CLI: bei `rescue_unreachable` nennt der Hinweis erkannte Sicherheits-Plugins und ihre
  Einstellung (Reason und Exit-Code unverändert); `rollback` über einen schon aufgeräumten Stub
  erklärt, dass der Notfallweg nur bis kurz nach der Bestätigung besteht

### Geändert
- `rescue_url` im Ergebnis von `push` und in `pushes` zeigt bei Agent 0.5.1 auf den Stub im
  Webroot statt auf `wp-content/plugins/wpsync-agent/rescue.php`. Weicht die URL des echten Begin
  von der des Probelaufs ab, prüft die CLI sie vor dem ersten Upload; `push --dry-run` legt keinen
  Stub an

## [0.5.0] – 2026-10-08 · Agent 0.5.0

**Nur die CLI ändert sich.** Agent 0.5.0 bleibt; Push nach Live braucht weiter Agent ≥ 0.4.0,
nach Staging ≥ 0.5.0.

### Neu
- `push`, `pushes`, `rollback` im Container-Modus: `--driver container --docroot <abs>
  --secret-stdin` – Secret aus Zeile 1 von stdin, Baseline, Journale, Staging-Stempel, Sperre und
  `history.git` im Site-Ordner neben dem Docroot, keine Rückfragen, kein docker/ddev/WP-CLI.
  `rollback` dort nur mit Push-ID
- `--json` additiv: `plan.skipped_new`, `plan.missing_locally`; `rescue_url` im Ergebnis von
  `push`; `warnings` bei `push` und `rollback`; neue Warnung `snapshot_incomplete` bei `pull`,
  `push` und `rollback`; in `pushes` (Container-Modus) `journal` und
  `rescue_url`; bei Exit 40 `error.admin_url` und `error.device`; `error.reason` bei Exit 1:
  `nothing_to_push`, `not_writable`, `rescue_unreachable`, `local_changed`, `target_mismatch`,
  `not_readable` (mit `error.path`)
- `pair` merkt sich den Gerätenamen (`--device` bzw. Rechnername) in der Site-Datei

### Geändert
- Scheitert nach einem bestätigten Push oder Rollback nur der Schnappschuss im internen Git,
  endet der Befehl mit Exit 0 und `warnings: ["snapshot_failed"]` statt Exit 1 – auch auf dem Mac
- SIGTERM bricht `push` vor dem Tausch ab (Exit 30); ab dem Tausch läuft er zu Ende, höchstens
  10 Minuten nach dem Signal (sonst Rollback, Exit 43, bzw. Exit 42). Bisher wirkte SIGTERM bei
  `push` nicht
- Der Schnappschuss liest den Code von `wp-content` bei jedem Lauf ganz (kein git-Index mehr):
  Folgeläufe dauern bei grossen Sites einige Sekunden statt unter einer, der erste Lauf ist
  schneller
- Eine nicht lesbare Datei einer zu pushenden Einheit beendet den Push vor dem Begin mit
  `not_readable` statt „hat sich während des Pushs geändert“; nicht lesbare Ordner anderer
  Einheiten brechen den Scan nicht mehr ab
- git für das interne Repo nur noch aus absoluten `PATH`-Einträgen
- `pull --dry-run --json`: `data.pulled` ist `false` und `data.status` ist `"dry_run"` (wie bei
  `push --dry-run`); bisher stand dort `pulled: true`, sobald die Site schon einmal gezogen war.
  `status --json` bleibt unverändert, `last_pull` bleibt auch beim Trockenlauf

### Sicherheit
- Schnappschuss ohne Arbeitsverzeichnis (Review 3, M-1): wpsync liest `wp-content` selbst, ohne
  Symlinks zu folgen, und schreibt per `git fast-import`. Ein während des Schnappschusses gegen
  einen Symlink getauschter Ordner bringt keine Datei von ausserhalb des Docroot mehr in die
  Historie. Eine gegen eine FIFO getauschte Datei lässt den Schnappschuss und den Push nicht mehr
  hängen (Öffnen mit `O_NONBLOCK`, nur reguläre Dateien); nach einem abgebrochenen Import räumt
  wpsync `fast_import_crash_*` aus dem internen Repo weg. Gilt für Pull und Push; `<site>/.gitignore` und der Index des internen Repos werden
  nicht mehr geschrieben

## [0.4.0] – 2026-10-07 · Agent 0.5.0

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
- `staging create`, `refresh`, `delete` und `open` brauchen ein offenes Push-Fenster wie ein
  Push (sonst Exit 40, auf dem Server ändert sich nichts) – auch die Probeläufe. Administrator
  der Kopie zu sein, heisst PHP im selben Server-Benutzer wie Live auszuführen; ein entwendetes
  Pairing-Secret allein reicht dafür nicht mehr. `staging status` und das Fortsetzen eines
  laufenden Jobs gehen ohne Fenster
- `staging open` warnt, die Kopie nicht im selben Browserprofil zu öffnen, in dem man bei Live
  angemeldet ist: sie liegt auf demselben Origin wie Live (`--json`: `data.warnings`)
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
