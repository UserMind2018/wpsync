#!/usr/bin/env bash
# E2E DB-Rücknahme ohne WordPress (Spec Content-Push P3; AC-158, AC-160, AC-161–AC-166, AC-168,
# AC-169, AC-171, AC-173, R7, R9, R10, R11, R14, R15; Security-Review P3: M1, M2, N3, N7): rescue.php
# nimmt nach einem Push mit Inhalten
# auch die Datenbank-Zeilen zurück – über eine eigene mysqli-Verbindung aus dem versiegelten
# Umschlag des Pushs. Es ist der einzige Test, in dem MysqliLink und RescueDb eine echte
# Datenbank sehen.
#
# Eigene Projekte wpsync-e2e-rdb (Quelle: apache-fpm, PHP 8.2, MySQL 8.0 mit strenger globaler
# sql_mode, eigener Datenbankbenutzer, DB_HOST mit Port) und wpsync-e2e-rdb-target (lokal) unter
# ~/wpsync-e2e/rdb; alle anderen E2E-Projekte bleiben unberührt. Die Pakete baut das Skript selbst
# mit jq aus `content export` ↔ baseline.jsonl, wie e2e-content-push.sh.
#
# Wie WordPress hier ausfällt (mu-plugin e2e-ctl der Quelle, gesteuert über Dateien in
# <quelle>/e2e-ctl, also ausserhalb des Webroots und nie Teil eines Pulls):
#   armed   die Sicherung ist scharf: trägt die Option options_e2e_fuse den Wert „an“, wirft jeder
#           Request – der INHALT legt WordPress lahm, nicht der Code
#   mute    jeder Request wirft (auch die der Staging-Kopie)
#   hold    /push/confirm wartet und lehnt ab: zusammen mit einem harten Abbruch der CLI gleich nach
#           dem Tausch entsteht der getauschte, unbestätigte Push, den rescue.php zurücknehmen darf
# WP-CLI ist von allen dreien ausgenommen.
#
# Voraussetzung: Docker, DDEV, jq (≥ 1.6), openssl, Go; Mac-Modus (das Pairing-Secret der eigenen
# Test-Site liegt in der Login-Keychain). Dauer rund 15 Minuten.
# Eine fehlgeschlagene Prüfung zählt und der Lauf geht weiter; nur was den Rest sinnlos macht,
# bricht ab. Die JSON-Zeilen der Befehle und die Antworten von rescue.php liegen danach unter
# ~/wpsync-e2e/rdb/json. Am Ende werden beide Projekte gestoppt (nicht gelöscht);
# WPSYNC_E2E_KEEP=1 lässt sie laufen.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E2E="${WPSYNC_E2E_DIR:-$HOME/wpsync-e2e}/rdb"
export WPSYNC_CONFIG_DIR="$E2E/config"
export WPSYNC_SITES_DIR="$E2E/sites"
SOURCE_NAME=wpsync-e2e-rdb
TARGET=wpsync-e2e-rdb-target
SRC="$E2E/source"
PUB="$SRC/public"
WPC="$PUB/wp-content"
CTL="$SRC/e2e-ctl"
SITE="$WPSYNC_SITES_DIR/$TARGET"
LWPC="$SITE/public/wp-content"
CONTENT="$SITE/.wpsync/content"
WPSYNC="$E2E/bin/wpsync" # eigener Build – nie eine installierte wpsync
JSON="$E2E/json"
PKG="$E2E/pkg"
PREFIX=e2e_
# Verbindungsdaten, die sich in einer Antwort oder einem Protokoll wiederfinden liessen (AC-173):
# eigener Benutzer, eigenes Passwort, eigene Datenbank, der Host mit Port (E1).
DBNAME=e2e_rdb_data
DBUSER=e2e_rdb_user
DBPASS='e2e+Rdb/Geheim=7c41'
DBHOST="ddev-$SOURCE_NAME-db"
STRICT='ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE' # in der Reihenfolge, in der MySQL sie nennt
NO_KEY='put your unique phrase here' # so steht AUTH_KEY in wp-config-sample.php: kein Schlüssel der Installation
PNG_B64='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='

CHECKS=0
FAILED=0
RC=0
RCODE=0
KEY_ID=""
SECRET=""
HELD=""
STG_DIR=""
T_START="$(date +%s)"

pass() { CHECKS=$((CHECKS + 1)); }
bad() { CHECKS=$((CHECKS + 1)); FAILED=$((FAILED + 1)); echo "FAIL: $*"; }
fail() { echo "FAIL: $*"; exit 1; }
eq() { if [ "$2" = "$3" ]; then pass; else bad "$1 (ist: $2, soll: $3)"; fi; } # eq <was> <ist> <soll>
ok() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then pass; else bad "$what"; fi; }
no() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$what"; else pass; fi; }
hasF() { grep -qF -- "$2" "$1"; } # hasF <datei> <text>
contains() { case "$1" in *"$2"*) return 0 ;; esac; return 1; } # contains <text> <teilstring>
src() { (cd "$SRC" && ddev "$@"); }
tgt() { (cd "$SITE" && ddev "$@"); }
http_url() { (cd "$1" && ddev describe -j | jq -r '.raw.httpurl'); }
code() { curl -s -o /dev/null -m 20 -w '%{http_code}' "$@" || true; }
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
b64() { printf %s "$1" | openssl base64 -A; }
last() { tail -n 1 "$JSON/$1.jsonl" | jq -r "$2"; }                                 # last <name> <jq>: über der Ergebniszeile
event() { jq -c "select(.event == \"$2\") | .data" "$JSON/$1.jsonl" | tail -n 1; } # event <name> <event>: letztes .data
ans() { jq -r "$2" "$JSON/$1.json" 2>/dev/null; }                                   # ans <name> <jq>: über einer Antwort von rescue.php
window() { src wp eval "WpSync\\Store::setPushUntil('$KEY_ID', $1);" >/dev/null; }
open_window() { src wp eval "WpSync\\Admin::openWindow('$KEY_ID', 28800, 1);" >/dev/null; } # wie „Öffnen“ im WP-Admin als Benutzer 1
jrun() { # jrun <name> <befehl… --json>: stdout nach $JSON/<name>.jsonl, stderr nach .err, Exit nach RC
  local name="$1"
  shift
  set +e
  "$@" >"$JSON/$name.jsonl" 2>"$JSON/$name.err" </dev/null
  RC=$?
  set -e
  if jq -e -R 'fromjson | type == "object"' "$JSON/$name.jsonl" >/dev/null 2>&1 \
    && [ "$(last "$name" '[.event, .exit_code, .ok] | @tsv')" = "result"$'\t'"$RC"$'\t'"$([ "$RC" = 0 ] && echo true || echo false)" ]; then
    pass
  else
    bad "$name: stdout ist nicht zeilenweise JSON mit passendem Ergebnis (Exit $RC)"
    cat "$JSON/$name.jsonl" "$JSON/$name.err"
  fi
}
# Die Datenbank der Quelle, an WordPress vorbei und als root: Lesen, Fixtures, general_log. Nichts
# davon lädt WordPress – der Agent merkt von diesen Blicken nichts (kein init, kein Nachholen).
sql() { src mysql -uroot -proot -D "$DBNAME" "$@" 2>/dev/null; } # ohne den Hinweis des Clients zum Passwort auf der Kommandozeile
q() { sql -N -e "$1"; }
lpost() { q "SELECT $2 FROM ${3:-$PREFIX}posts WHERE ID = $1"; }                                  # lpost <id> <spalte> [präfix]
lmeta() { q "SELECT meta_value FROM ${PREFIX}postmeta WHERE post_id = $1 AND meta_key = '$2' ORDER BY meta_id LIMIT 1"; }
lopt() { q "SELECT option_value FROM ${2:-$PREFIX}options WHERE option_name = '$1'"; }           # lopt <name> [präfix]
# rowsum <id>: alles, was auf Live an einem Beitrag hängt, Byte für Byte – die ganze Zeile, seine Meta
# (je Schlüssel in ihrer Reihenfolge) und seine Zuordnungen.
rowsum() {
  q "SELECT * FROM ${PREFIX}posts WHERE ID = $1; SELECT meta_key, HEX(meta_value) FROM ${PREFIX}postmeta WHERE post_id = $1 ORDER BY BINARY meta_key, meta_id; SELECT term_taxonomy_id, term_order FROM ${PREFIX}term_relationships WHERE object_id = $1 ORDER BY 1" | shasum -a 256 | cut -d' ' -f1
}
# dbsum [präfix]: alles Pushbare eines Ziels, Byte für Byte – die sechs Inhaltstabellen ganz und die
# Optionen der Fixtures. Ohne Revisionen: die legen die Nacharbeiten an, sie gehören keinem Paket.
# Meta ohne Beitrag (was R15 stehen lässt) zählt nicht mit; das prüfen die Fälle selbst.
dbsum() {
  local p="${1:-$PREFIX}"
  q "SELECT * FROM ${p}posts WHERE post_type <> 'revision' ORDER BY ID;
     SELECT m.post_id, m.meta_key, HEX(m.meta_value) FROM ${p}postmeta m JOIN ${p}posts x ON x.ID = m.post_id WHERE x.post_type <> 'revision' ORDER BY m.post_id, BINARY m.meta_key, m.meta_id;
     SELECT * FROM ${p}terms ORDER BY term_id;
     SELECT * FROM ${p}term_taxonomy ORDER BY term_taxonomy_id;
     SELECT * FROM ${p}term_relationships ORDER BY object_id, term_taxonomy_id;
     SELECT term_id, meta_key, HEX(meta_value) FROM ${p}termmeta ORDER BY term_id, BINARY meta_key, meta_id;
     SELECT option_name, HEX(option_value), autoload FROM ${p}options WHERE option_name IN ('blogname', 'blogdescription', 'wpseo_social', 'options_e2e_fuse', 'options_e2e_neu') ORDER BY 1" | shasum -a 256 | cut -d' ' -f1
}
msum() { LC_ALL=C sort "$1" | shasum -a 256 | cut -d' ' -f1; } # Manifest/Baseline ohne Rücksicht auf die Reihenfolge
pending() { src wp eval 'echo json_encode(WpSync\Push::pending());'; }
record() { "$WPSYNC" pushes "$TARGET" --json 2>/dev/null | jq -r ".data.pushes[] | select(.push_id == \"$1\") | $2"; } # record <id> <jq>: Zeile des Protokolls
work() { find "$WPC" -maxdepth 1 -name 'wpsync-push-*' -type d | head -1; }                                            # Arbeitsordner von Live
# general_log der eigenen Datenbank: was WordPress und rescue.php dort tun, tun sie als $DBUSER;
# die Blicke dieses Skripts kommen als root und zählen nicht.
glog_on() { sql -e "SET GLOBAL log_output = 'TABLE'; SET GLOBAL general_log = 'ON'; TRUNCATE TABLE mysql.general_log"; }
glog_off() { sql -e "SET GLOBAL general_log = 'OFF'" >/dev/null 2>&1 || true; }
conns() { q "SELECT COUNT(*) FROM mysql.general_log WHERE command_type = 'Connect' AND user_host LIKE '%[${DBUSER}]%'"; } # Verbindungen der Site seit glog_on
# rlog: die Anweisungen der Sitzungen von rescue.php seit glog_on. RescueDb setzt die sql_mode mit
# „SET SESSION sql_mode = '…'“, wpdb ohne die Leerzeichen – daran sind seine Sitzungen zu erkennen.
rlog() {
  q "SELECT CONVERT(argument USING utf8mb4) FROM mysql.general_log WHERE command_type = 'Query' AND thread_id IN (SELECT thread_id FROM (SELECT thread_id FROM mysql.general_log WHERE user_host LIKE '%[${DBUSER}]%' AND argument LIKE 'SET SESSION sql_mode = %') AS rescue_threads)"
}
# Die Schalter des mu-plugins e2e-ctl, im Container gesetzt und gelöscht: ein rm auf dem Mac sieht
# der Container über das Bind-Mount erst einen Augenblick später – der nächste Request liefe noch
# mit dem alten Stand.
CTL_IN=/var/www/html/e2e-ctl
arm() { src exec touch "$CTL_IN/armed"; }
disarm() { src exec rm -f "$CTL_IN/armed"; }
mute() { src exec touch "$CTL_IN/mute"; }
unmute() { src exec rm -f "$CTL_IN/mute"; }

# hash_of <datei> <tabelle> <objekt> [name]: Abdruck eines Schlüssels in einer JSON-Lines-Datei.
hash_of() {
  jq -r --arg t "$2" --arg o "$3" --arg n "${4:-}" '($o + (if $n == "" then "" else "\u0000" + $n end)) as $k | select(.t == $t and .k == $k) | .h' "$1"
}
# seal <name>: setzt den Kopf vor $PKG/<name>.body – Zeilenende \n, JSON kompakt, sha256 über den Rumpf.
seal() {
  local name="$1" body="$PKG/$1.body" rows digest
  rows="$(wc -l <"$body" | tr -d ' ')"
  digest="$(sha "$body")"
  jq -c -n --arg home "$(jq -r '.live.home' "$CONTENT/map.json")" --arg map "$(sha "$CONTENT/map.json")" --arg host "$LOCAL_HOST" \
    --argjson rows "$rows" --arg sha "$digest" --argjson lv "$LIST_VERSION" \
    '{head: {list_version: $lv, extensions: {post_types: [], taxonomies: [], meta_exceptions: []},
      corridor: {offset: 1000000, posts: [1000001, 9999999], terms: [1000001, 9999999], term_taxonomy: [1000001, 9999999]},
      canon_version: 1, variants: ["plain", "esc1", "esc2"], home: $home, map_id: $map, local_host: $host, rows: $rows, sha256: $sha}}' >"$PKG/$name.jsonl"
  cat "$body" >>"$PKG/$name.jsonl"
}
# pkg <name> [jq-Bedingung über .t und .k]: baut das Paket aus dem Export der Arbeitskopie gegen
# die Baseline – update, insert, trash und gelöschte Paare; expected kommt aus dem Manifest (wie
# e2e-content-push.sh, dort erklärt). Liefert die Zahl der Zeilen in ROWS.
pkg() {
  local name="$1" only="${2:-true}"
  "$WPSYNC" content export "$TARGET" >"$PKG/$name.export" 2>"$PKG/$name.export.err" </dev/null || fail "content export für $name (siehe $PKG/$name.export.err)"
  jq -c -n --slurpfile b "$CONTENT/baseline.jsonl" --slurpfile m "$CONTENT/manifest.jsonl" --slurpfile e "$PKG/$name.export" '
    def id: .t + "\u0001" + .k;
    def status: (.row.post_status // "") | @base64d;
    ($b | map(select(.t != null) | {key: id, value: .}) | from_entries) as $B
    | ($m | map(select(.t != null) | {key: id, value: .h}) | from_entries) as $M
    | ($e | map(select(.t != null) | {key: id, value: true}) | from_entries) as $E
    | ($e | map(select(.t == "posts") | {key: .k, value: true}) | from_entries) as $posts
    | ($e | map(select(.t == "posts" and status == "trash") | select($B[id] != null and ($B[id] | status) != "trash") | {key: .k, value: true}) | from_entries) as $trashed
    | (
        $e[] | select(.t != null and .p == true and .h != null) | select('"$only"')
        | select((.t == "postmeta" and ((.k | split("\u0000")) as $pair | $trashed[$pair[0]] == true
            and (["_wp_trash_meta_status", "_wp_trash_meta_time", "_wp_desired_post_slug"] | index($pair[1])) != null)) | not)
        | . as $x | $B[id] as $old
        | if $old == null then {op: "insert", table: .t, key: .k, expected: "absent", row: .row}
          elif $old.h == .h then empty
          elif .t == "posts" and status == "trash" and ($old | status) != "trash" then
            {op: "trash", table: .t, key: .k, expected: $M[id]}
            + (if .row.post_date != $old.row.post_date or .row.post_date_gmt != $old.row.post_date_gmt
               then {row: {post_date: .row.post_date, post_date_gmt: .row.post_date_gmt}} else {} end)
          else {op: "update", table: .t, key: .k, expected: $M[id], row: .row} end
      ),
      (
        $b[] | select(.t == "postmeta" and .p == true and .h != null) | select('"$only"')
        | select($E[id] == null) | select($posts[.k | split("\u0000")[0]] == true)
        | {op: "update", table: .t, key: .k, expected: $M[id], row: {values: []}}
      )' >"$PKG/$name.body"
  seal "$name"
  ROWS="$(wc -l <"$PKG/$name.body" | tr -d ' ')"
}
# Bedingungen für pkg: die Sicherung, und alles, was an einem Beitrag hängt.
FUSE='(.t == "options" and .k == "options_e2e_fuse")'
of() { printf '((.t == "posts" or .t == "postmeta" or .t == "term_relationships") and (.k | split("\\u0000")[0]) == "%s")' "$1"; }

# hold <name> <argumente von „push <ziel> code“ …>: pusht und bricht die CLI gleich nach dem Tausch
# hart ab – wie ein abgestürztes Gerät (U7). Zurück bleibt ein getauschter, unbestätigter Push samt
# Journal; seine ID steht danach in HELD. Solange hold liegt, käme auch ein schnellerer Lauf nicht
# über /push/confirm hinaus.
hold() {
  local name="$1" pid n=0
  shift
  HELD=""
  : >"$JSON/$name.jsonl"
  src exec touch "$CTL_IN/hold"
  "$WPSYNC" push "$TARGET" code "$@" --yes --json >"$JSON/$name.jsonl" 2>"$JSON/$name.err" </dev/null &
  pid=$!
  while ! grep -qF '"event":"commit"' "$JSON/$name.jsonl" && kill -0 "$pid" 2>/dev/null && [ "$n" -lt 1200 ]; do
    sleep 0.1
    n=$((n + 1))
  done
  kill -9 "$pid" 2>/dev/null || true
  wait "$pid" 2>/dev/null || true
  src exec rm -f "$CTL_IN/hold"
  HELD="$(event "$name" commit | jq -r '.push_id // empty' 2>/dev/null || true)"
  if [ -n "$HELD" ] && [ -f "$SITE/.wpsync/pushes/$HELD.json" ]; then
    pass
  else
    HELD=p_00000000_000000000000
    bad "$name: kein getauschter, unbestätigter Push"
    cat "$JSON/$name.jsonl" "$JSON/$name.err"
  fi
}
# rkey <push-id>: der Rescue-Key, wie die CLI ihn rechnet – aus dem Pairing-Secret und dem Salt des Journals.
rkey() {
  printf 'rescue:%s:%s' "$1" "$(jq -r .salt "$SITE/.wpsync/pushes/$1.json" 2>/dev/null)" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1
}
# rpost <name> <push-id> <key> <feld=wert…>: POST an rescue.php (die Rescue-URL des Journals). Die
# Antwort liegt danach in $JSON/<name>.json, der HTTP-Status in RCODE. Der Schlüssel steht nur im Rumpf.
rpost() {
  local name="$1" id="$2" key="$3" url field
  local args=(--data-urlencode "push_id=$2" --data-urlencode "key=$3")
  shift 3
  for field in "$@"; do args+=(--data-urlencode "$field"); done
  url="$(jq -r .rescue_url "$SITE/.wpsync/pushes/$id.json" 2>/dev/null || true)"
  RCODE="$(curl -s -m 120 -o "$JSON/$name.json" -w '%{http_code}' -X POST "${args[@]}" "$url" || true)"
}
# leaks <was> <datei…>: keine der Dateien nennt Zugangsdaten, Text des Datenbankservers oder SQL (AC-173).
leaks() {
  local what="$1" token
  shift
  for token in "$DBPASS" "$DBUSER" "$DBHOST" "$DBNAME" "Access denied" "mysqli" "${PREFIX}posts" "${PREFIX}options" "SQLSTATE"; do
    no "$what: nennt nicht „${token}“" grep -qF -- "$token" "$@"
  done
}
trust_ddev() { # gibt .ddev des Ziels frei – ohne Terminal nur über den Fingerprint des angezeigten Stands
  local fp
  fp="$({ "$WPSYNC" trust "$TARGET" </dev/null 2>&1 || true; } | sed -n 's/^Fingerprint: //p')"
  [ -n "$fp" ] || fail "wpsync trust zeigt keinen Fingerprint"
  "$WPSYNC" trust "$TARGET" --fingerprint "$fp" </dev/null >/dev/null
}
# Stellt her, was ein abgebrochener Lauf an der Quelle verstellt haben kann.
restore_source() {
  rm -f "$CTL/armed" "$CTL/mute" "$CTL/hold" "$PUB/.maintenance" "$WPC/object-cache.php" "$WPC/e2e-object-cache.ser"
  (cd "$SRC" && ddev mysql -uroot -proot -e "ALTER USER '$DBUSER'@'%' IDENTIFIED BY '$DBPASS'; SET GLOBAL general_log = 'OFF'") >/dev/null 2>&1 || true
  if [ -s "$E2E/auth-key" ] && [ -f "$PUB/wp-config.php" ]; then
    (cd "$SRC" && ddev wp config set AUTH_KEY "$(cat "$E2E/auth-key")" --type=constant) >/dev/null 2>&1 || true
  fi
}
finish() {
  local rc=$?
  trap - EXIT
  set +e
  if [ -f "$SRC/.ddev/config.yaml" ]; then
    restore_source
    [ -z "$KEY_ID" ] || window 0 2>/dev/null
    [ "${WPSYNC_E2E_KEEP:-}" = 1 ] || (cd "$SRC" && ddev stop >/dev/null 2>&1)
  fi
  if [ "${WPSYNC_E2E_KEEP:-}" != 1 ] && [ -f "$SITE/.ddev/config.yaml" ]; then (cd "$SITE" && ddev stop >/dev/null 2>&1); fi
  echo
  if [ "$rc" != 0 ]; then
    echo "E2E Rescue-DB ABGEBROCHEN (Exit $rc) – $CHECKS Prüfungen bis dahin, $FAILED FAIL"
    exit "$rc"
  fi
  if [ "$FAILED" != 0 ]; then
    echo "E2E Rescue-DB: $CHECKS Prüfungen, $FAILED FAIL ($(($(date +%s) - T_START)) s)"
    exit 1
  fi
  echo "E2E Rescue-DB OK – $CHECKS Prüfungen grün, 0 FAIL ($(($(date +%s) - T_START)) s)"
}
trap finish EXIT

command -v jq >/dev/null || fail "jq fehlt"
mkdir -p "$E2E/bin" "$PUB" "$CTL" "$WPSYNC_SITES_DIR"
rm -rf "$JSON" "$PKG"
mkdir -p "$JSON" "$PKG"
(cd "$ROOT/agent" && ./build.sh)
(cd "$ROOT/cli" && go build -o "$WPSYNC" ./cmd/wpsync)

echo "== Quelle (Apache, PHP 8.2, MySQL 8.0 mit strenger globaler sql_mode)"
cd "$SRC"
if [ ! -f .ddev/config.yaml ]; then
  ddev config --project-name="$SOURCE_NAME" --project-type=wordpress --docroot=public \
    --php-version=8.2 --database=mysql:8.0 --webserver-type=apache-fpm --performance-mode=none
fi
# AC-171: der Server ist streng. WordPress nimmt die strengen Modi für seine Sitzung heraus – genau
# das muss rescue.php für seine Sitzung auch tun (R6), sonst scheitert schon ein Entwurf.
mkdir -p .ddev/mysql
printf '[mysqld]\nsql_mode = %s\n' "$STRICT" >.ddev/mysql/e2e-strict.cnf
ddev start -y
ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS $DBNAME CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS '$DBUSER'@'%' IDENTIFIED BY '$DBPASS'; ALTER USER '$DBUSER'@'%' IDENTIFIED BY '$DBPASS'; GRANT ALL PRIVILEGES ON $DBNAME.* TO '$DBUSER'@'%'; SET GLOBAL general_log = 'OFF'"
eq "AC-171: die Datenbank der Quelle läuft global streng" "$(ddev mysql -uroot -proot -N -e 'SELECT @@GLOBAL.sql_mode')" "$STRICT"
[ -f public/wp-load.php ] || ddev wp core download --force
if grep -q '#ddev-generated' public/wp-config.php; then
  sed -i '' 's/#ddev-generated//' public/wp-config.php
fi
# Eigene Verbindungsdaten statt db/db/db: wp-config-ddev.php setzt nur, was noch nicht definiert ist.
sed -i '' "s/! defined( 'DB_USER' ) && //" public/wp-config.php
ddev wp config set DB_NAME "$DBNAME" --type=constant
ddev wp config set DB_USER "$DBUSER" --type=constant
ddev wp config set DB_PASSWORD "$DBPASS" --type=constant
ddev wp config set DB_HOST "$DBHOST:3306" --type=constant
ddev wp config set table_prefix "$PREFIX" --type=variable
[ -s "$E2E/auth-key" ] || openssl rand -hex 32 >"$E2E/auth-key"
restore_source
if ! ddev wp core is-installed >/dev/null 2>&1; then
  ddev wp core install --url="$(http_url "$SRC")" --title="wpsync Rescue-DB E2E" --admin_user=admin \
    --admin_password=admin --admin_email=e2e@example.invalid --skip-email
  ddev wp rewrite structure '/%postname%/' --hard
fi
ddev wp config set DISABLE_WP_CRON true --raw --type=constant
ddev wp config set WPSYNC_ALLOW_HTTP true --raw --type=constant
# Permalinks auf Apache: WP-CLI schreibt ohne apache_modules in seiner Konfiguration keine .htaccess.
if ! grep -qs '# BEGIN WordPress' "$PUB/.htaccess"; then
  cat >>"$PUB/.htaccess" <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
fi
SOURCE_URL="$(http_url "$SRC")"
eq "E1: WordPress spricht die Datenbank als eigener Benutzer über Host:Port" "$(ddev wp eval 'echo DB_USER . "@" . DB_HOST . "/" . DB_NAME;')" "$DBUSER@$DBHOST:3306/$DBNAME"

echo "== Fixtures: Theme, Plugin, mu-plugin e2e-ctl, Seiten und Optionen"
mkdir -p "$WPC/themes/e2e-theme" "$WPC/plugins/e2e-health" "$WPC/uploads/2026/10" "$WPC/mu-plugins"
cat >"$WPC/mu-plugins/e2e-ctl.php" <<'PHP'
<?php
// Steuert, wie WordPress im E2E ausfällt – über Dateien in /var/www/html/e2e-ctl (nur auf der Quelle).
if (defined('WP_CLI') && WP_CLI) {
    return;
}
$e2e_ctl = '/var/www/html/e2e-ctl';
if (is_file($e2e_ctl . '/mute')) {
    throw new Error('e2e: WordPress antwortet nicht');
}
if (is_file($e2e_ctl . '/armed') && get_option('options_e2e_fuse') === 'an') {
    throw new Error('e2e: der Inhalt legt WordPress lahm');
}
add_filter('rest_pre_dispatch', static function ($result, $server, $request) use ($e2e_ctl) {
    if (strpos((string) $request->get_route(), '/push/confirm') !== false && is_file($e2e_ctl . '/hold')) {
        for ($i = 0; $i < 300 && is_file($e2e_ctl . '/hold'); $i++) {
            usleep(100000);
            clearstatcache();
        }
        return new WP_Error('e2e_hold', 'e2e: confirm held', ['status' => 503]);
    }
    return $result;
}, 10, 3);
PHP
# N3: versiegelt den Umschlag eines Pushs neu, <tage> Tage alt – wie es nur kann, wer den Rescue-Key hat.
# Aufruf im Container: php reseal.php <arbeitsordner> <push-id> <key> <tage>
cat >"$CTL/reseal.php" <<'PHP'
<?php
define('WPSYNC_RESCUE', true);
$src = '/var/www/html/public/wp-content/plugins/wpsync-agent/src/';
require $src . 'PushSwap.php';
require $src . 'PushRescue.php';
require $src . 'RescueSeal.php';
list(, $work, $id, $key, $days) = $argv;
$data = WpSync\RescueSeal::open((string) WpSync\RescueSeal::read($work, $id), $key, $id);
if (!is_array($data)) {
    echo 'unreadable';
    exit(1);
}
unset($data['push_id']);
$data['created'] = time() - (int) $days * 86400;
echo WpSync\RescueSeal::put($work, $id, (string) WpSync\RescueSeal::seal($data, $key, $id)) ? 'resealed' : 'failed';
PHP
# N7: was an der Stelle des Rescue-Stubs mit HTTP 200 und "ok" antwortet, ohne rescue.php zu sein.
cat >"$CTL/fake-rescue.php" <<'PHP'
<?php
header('Content-Type: application/json');
echo '{"ok":true}';
PHP
printf '<?php\nerror_log("e2e-rdb log probe");\n' >"$PUB/e2e-log-probe.php"
printf '/*\nTheme Name: E2E Theme\nVersion: 1.0\n*/\n' >"$WPC/themes/e2e-theme/style.css"
cat >"$WPC/themes/e2e-theme/index.php" <<'PHP'
<!doctype html><html><head><?php wp_head(); ?></head><body>
<p>e2e-marker v1</p>
<?php while (have_posts()) { the_post(); the_title('<h1>', '</h1>'); the_content(); } ?>
<?php wp_footer(); ?></body></html>
PHP
printf '<?php\n// e2e theme\n' >"$WPC/themes/e2e-theme/functions.php"
printf '<?php\n/* Plugin Name: E2E Health\n * Version: 1.0 */\n' >"$WPC/plugins/e2e-health/e2e-health.php"
ddev wp theme activate e2e-theme
ddev wp plugin activate e2e-health
rm -rf "$WPC/uploads/2026/10/e2e-"*
ddev wp eval-file - <<'PHP'
<?php
global $wpdb;
$home = rtrim(home_url(), '/');
foreach ($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'e2e-%' OR ID > 1000000") as $old) {
    wp_delete_post((int) $old, true);
}
// Was ein früherer Lauf hinterlassen hat, ganz – auch Verwaistes (R15) und Revisionen.
$wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
$wpdb->query("DELETE m FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL");
$wpdb->query("DELETE c FROM {$wpdb->comments} c LEFT JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID WHERE p.ID IS NULL");
$wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id > 1000000 OR term_taxonomy_id > 1000000");
$wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 1");
$json  = wp_json_encode([['id' => 'a1', 'elType' => 'widget', 'settings' => ['link' => ['url' => $home . '/kontakt']]]]);
$json2 = wp_json_encode(['data' => $json]);
$ids   = [];
foreach (['a' => 'publish', 'b' => 'publish', 'entwurf' => 'draft', 'voll' => 'publish'] as $slug => $status) {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => $status, 'post_title' => 'E2E ' . strtoupper($slug), 'post_name' => 'e2e-' . $slug,
        'post_content' => '<a href="' . $home . '/kontakt">Kontakt</a>']);
    update_post_meta($id, '_elementor_data', wp_slash($json));
    update_post_meta($id, '_e2e_json2', wp_slash($json2));
    update_post_meta($id, '_e2e_weg', 'wird lokal entfernt');
    update_post_meta($id, '_e2e_ser', wp_slash(['url' => $home . '/kontakt', 'json' => $json, 'json2' => $json2, 'n' => 1]));
    $ids[$slug] = $id;
}
// AC-171: Werte, an denen eine strenge Sitzung oder ein falscher Zeichensatz scheitert – sie stehen
// VOR dem Push auf Live, die Rücknahme muss sie zurückschreiben. Ein Titel in voller Spaltenlänge
// (TEXT, 65.535 Bytes) mit einem 4-Byte-Zeichen am Ende, ein Name in voller Länge (200), ein Meta
// über 100 KB mit Emoji, \/ und Anführungszeichen. Der Entwurf trägt post_date_gmt 0000-00-00.
$wpdb->query($wpdb->prepare(
    "UPDATE {$wpdb->posts} SET post_title = %s, post_name = %s, post_excerpt = %s WHERE ID = %d",
    str_repeat('x', 65531) . '🚀',
    'e2e-voll-' . str_repeat('n', 191),
    'Auszug 🚀 Ünïcödé 日本語 – „Größe“',
    $ids['voll']
));
update_post_meta($ids['voll'], '_e2e_gross', wp_slash(str_repeat('Größe 🚀 \\/ "x" 日本語 ', 6000)));
update_post_meta($ids['voll'], '_e2e_emoji', '🚀 vorher, Ümläute und 日本語');
clean_post_cache($ids['voll']);
update_option('blogname', 'wpsync Rescue-DB E2E');
update_option('blogdescription', 'E2E Untertitel');
update_option('wpseo_social', ['og_default_image' => $home . '/wp-content/uploads/og.png', 'json' => $json, 'json2' => $json2]);
update_option('options_e2e_fuse', 'aus');
delete_option('options_e2e_neu');
update_option('e2e_rdb_ids', $ids);
PHP
IDS="$(ddev wp eval 'echo json_encode(get_option("e2e_rdb_ids"));')"
PAGE_A="$(jq -r '.a' <<<"$IDS")"
PAGE_B="$(jq -r '.b' <<<"$IDS")"
DRAFT="$(jq -r '.entwurf' <<<"$IDS")"
PAGE_FULL="$(jq -r '.voll' <<<"$IDS")"
for v in "$PAGE_A" "$PAGE_B" "$DRAFT" "$PAGE_FULL"; do
  case "$v" in '' | *[!0-9]*) fail "Fixtures nicht angelegt ($IDS)" ;; esac
done
eq "AC-171 Fixture: der Titel füllt die Spalte (65.535 Bytes) und endet auf ein 4-Byte-Zeichen" "$(lpost "$PAGE_FULL" "CONCAT(LENGTH(post_title), ' ', HEX(RIGHT(post_title, 1)))")" "65535 F09F9A80"
eq "AC-171 Fixture: der Name füllt die Spalte (200 Zeichen)" "$(lpost "$PAGE_FULL" "CHAR_LENGTH(post_name)")" 200
ok "AC-171 Fixture: ein Meta über 100 KB" test "$(q "SELECT LENGTH(meta_value) FROM ${PREFIX}postmeta WHERE post_id = $PAGE_FULL AND meta_key = '_e2e_gross'")" -gt 100000
eq "AC-171 Fixture: der Entwurf trägt das Null-Datum" "$(lpost "$DRAFT" post_date_gmt)" "0000-00-00 00:00:00"
# Gegenprobe: eine Sitzung mit der sql_mode des Servers kann dieses Datum nicht schreiben.
no "AC-171 Gegenprobe: in einer strengen Sitzung scheitert das Null-Datum" sql -e "UPDATE ${PREFIX}posts SET post_date_gmt = '0000-00-00 00:00:00' WHERE ID = $DRAFT"
WP_MODE="$(ddev wp eval 'global $wpdb; echo $wpdb->get_var("SELECT @@SESSION.sql_mode");')"
no "AC-171: die Sitzung von WordPress ist nicht streng" contains "$WP_MODE" "STRICT"

echo "== Agent 0.8.0; die Installation hat (noch) keinen Schlüssel – AUTH_KEY wie in wp-config-sample.php"
cp "$ROOT/agent/dist/wpsync-agent.zip" public/wpsync-agent.zip
ddev wp plugin install /var/www/html/public/wpsync-agent.zip --force --activate
rm public/wpsync-agent.zip
# Reste früherer Läufe: Kopie, offene Pushes, Sperren, Pairings.
ddev wp eval 'WpSync\Staging::uninstall(); WpSync\Push::uninstall(); global $wpdb; $wpdb->query("DELETE FROM " . WpSync\Store::table("pushes")); $wpdb->query("DELETE FROM " . WpSync\Store::table("pairings")); WpSync\Store::setState("push_lock", null); WpSync\Store::setState("infosheet", null);'
if find "$PUB" -maxdepth 1 -name 'wpsync-staging-*' | grep -q . || [ -n "$(q "SHOW TABLES LIKE 'stg%'")" ]; then
  find "$PUB" -maxdepth 1 -name 'wpsync-staging-*' -exec rm -rf {} +
  for t in $(q "SHOW TABLES LIKE 'stg%'"); do sql -e "DROP TABLE \`$t\`"; done
fi
find "$PUB" -maxdepth 1 -name 'wpsync-rescue-*.php' -delete
ddev wp config set AUTH_KEY "$NO_KEY" --type=constant
eq "ohne Schlüssel: SecretKey::fromConfig() ist leer" "$(ddev wp eval 'echo count(WpSync\SecretKey::fromConfig());')" 0
CODE="$(ddev wp wpsync pair-code | tail -1)"
"$WPSYNC" unpair "$TARGET" >/dev/null 2>&1 || true
rm -rf "$SITE/.wpsync/content" "$SITE/.wpsync/pushes" "$SITE/.wpsync/staging-base.json"
"$WPSYNC" pair "$SOURCE_URL" "$CODE" --name "$TARGET" --insecure
KEY_ID="$(awk '/^key_id:/ { print $2 }' "$WPSYNC_CONFIG_DIR/sites/$TARGET.yaml")"
SECRET="$(security find-generic-password -s "wpsync:$TARGET" -a "$TARGET" -w)"
[ -n "$SECRET" ] || fail "kein Pairing-Secret der Test-Site in der Keychain"
"$WPSYNC" scan "$TARGET" --refresh --preset vollstaendig --uploads-since alle
if [ -f "$SITE/.ddev/config.yaml" ] && [ ! -f "$WPSYNC_CONFIG_DIR/ddev-state/$TARGET.json" ]; then trust_ddev; fi
jrun pull "$WPSYNC" pull "$TARGET" --content --full --yes --json
[ "$RC" = 0 ] || fail "pull --content (Exit $RC, siehe $JSON/pull.err)"
LOCAL_URL="$(last pull '.data.local_url')"
LOCAL_HOST="$(printf %s "$LOCAL_URL" | sed -E 's#^https?://##; s#/.*$##')"
LIST_VERSION="$(head -n 1 "$CONTENT/manifest.jsonl" | jq -r '.head.list_version')"
ok "lokale Arbeitskopie hat die Seite" test -n "$(tgt wp post get "$PAGE_A" --field=post_title --skip-plugins --skip-themes)"
eq "Pull: der Titel in voller Spaltenlänge kam bytegleich an" "$(tgt mysql -N -e "SELECT MD5(post_title) FROM ${PREFIX}posts WHERE ID = $PAGE_FULL")" "$(lpost "$PAGE_FULL" "MD5(post_title)")"
# Der ID-Korridor des Studios: neue Objekte der Arbeitskopie beginnen bei 1.000.001 über dem Maximum von Live.
for table in posts terms term_taxonomy; do
  max="$(head -n 1 "$CONTENT/manifest.jsonl" | jq -r ".head.id_max.$table")"
  # Die Arbeitskopie läuft auf MySQL 8.0: ein ALTER in der strengen Sitzung des Clients scheitert an den Null-Daten der Spaltenvorgaben.
  tgt mysql -e "SET SESSION sql_mode = ''; ALTER TABLE ${PREFIX}${table} AUTO_INCREMENT = $((max + 1000001))"
done
cp "$CONTENT/manifest.jsonl" "$E2E/manifest.pulled"
cp "$CONTENT/baseline.jsonl" "$E2E/baseline.pulled"
cp "$SITE/.wpsync/baseline.json" "$E2E/files.pulled"
open_window
DB_PULLED="$(dbsum)"

echo "== Lokale Änderungen der Arbeitskopie – aus ihnen entstehen alle Pakete dieses Laufs"
TITLE_A='E2E A geändert 🚀 Ünïcödé 日本語'
NEW_ID="$(tgt wp post create --post_type=page --post_status=publish --post_title="E2E Neu" --post_name=e2e-neu --porcelain --skip-plugins --skip-themes)"
ok "die neue Seite liegt im Korridor" test "$NEW_ID" -gt 1000000
tgt wp eval-file - "$PAGE_A" "$PAGE_B" "$DRAFT" "$PAGE_FULL" "$NEW_ID" --skip-plugins --skip-themes <<'PHP'
<?php
list($a, $b, $draft, $full, $new) = array_map('intval', $args);
$home    = rtrim(home_url(), '/');
$widgets = [];
for ($i = 0; $i < 1000; $i++) {
    $widgets[] = ['id' => sprintf('w%04d', $i), 'elType' => 'widget', 'settings' => ['link' => ['url' => $home . '/neu'], 'text' => 'Größe ' . $i . ' 🚀']];
}
$json   = wp_json_encode($widgets);
$json2  = wp_json_encode(['data' => $json]);
$small  = wp_json_encode([['link' => $home . '/neu']]);
$small2 = wp_json_encode(['data' => $small]);
// Seite A: der reiche Satz – Emoji, über 100 KB, \/ und \\\/, serialisiert, mehrere Werte, ein Meta entfernt.
wp_update_post(['ID' => $a, 'post_title' => 'E2E A geändert 🚀 Ünïcödé 日本語', 'post_content' => '<a href="' . $home . '/neu">Neu</a> – „Größe“ 🚀']);
update_post_meta($a, '_elementor_data', wp_slash($json));
update_post_meta($a, '_e2e_json2', wp_slash($json2));
update_post_meta($a, '_e2e_ser', wp_slash(['url' => $home . '/neu', 'json' => $small, 'json2' => $small2, 'n' => 2, 'text' => 'Ä 🚀', 'tief' => ['liste' => [$home . '/a', $home . '/b']]]));
delete_post_meta($a, '_e2e_weg');
add_post_meta($a, '_e2e_multi', 'eins');
add_post_meta($a, '_e2e_multi', 'zwei');
add_post_meta($a, '_e2e_multi', 'eins');
update_post_meta($a, '_e2e_emoji', 'Wert mit 🚀, Ümläuten und 日本語');
update_option('blogdescription', 'Ünterzeile 🚀');
update_option('wpseo_social', ['og_default_image' => $home . '/wp-content/uploads/og-neu.png', 'json' => $small, 'json2' => $small2]);
update_option('options_e2e_neu', $home . '/neu');
// Die Sicherung: mit diesem Wert auf Live wirft dort jeder Request, solange sie scharf ist.
update_option('options_e2e_fuse', 'an');
wp_update_post(['ID' => $b, 'post_title' => 'E2E B geändert']);
// AC-171: die Zeilen mit den Grenzwerten bekommen harmlose Werte – die Rücknahme schreibt die Grenzwerte zurück.
wp_update_post(['ID' => $full, 'post_title' => 'E2E VOLL kurz', 'post_excerpt' => 'kurz']);
update_post_meta($full, '_e2e_gross', 'klein');
update_post_meta($full, '_e2e_emoji', 'nachher');
wp_trash_post($draft);
update_post_meta($new, '_wp_page_template', 'default');
update_post_meta($new, '_e2e_emoji', 'neu 🚀');
PHP
THEME="$LWPC/themes/e2e-theme/index.php"
sed -i '' -E 's/e2e-marker v[0-9]+/e2e-marker v2/' "$THEME"
mkdir -p "$LWPC/uploads/2026/10"
printf %s "$PNG_B64" | openssl base64 -d -A >"$LWPC/uploads/2026/10/e2e-satz.png"
printf '2026/10/e2e-satz.png\n' >"$E2E/uploads.txt"
HEALTH="$LWPC/plugins/e2e-health/e2e-health.php"
cp -p "$HEALTH" "$E2E/e2e-health.good"
# state <was>: der Stand der Site ist wieder der des Pulls – Code, Upload, Datenbank, kein offener Push.
state_clean() {
  ok "$1: der Code ist der alte" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"
  no "$1: der Upload ist weg" test -e "$WPC/uploads/2026/10/e2e-satz.png"
  eq "$1: die Datenbank Byte für Byte wie nach dem Pull" "$(dbsum)" "$DB_PULLED"
  eq "$1: Manifest wie nach dem Pull" "$(msum "$CONTENT/manifest.jsonl")" "$(msum "$E2E/manifest.pulled")"
  eq "$1: Baseline der Inhalte wie nach dem Pull" "$(msum "$CONTENT/baseline.jsonl")" "$(msum "$E2E/baseline.pulled")"
  ok "$1: Baseline der Dateien wie nach dem Pull" cmp -s "$E2E/files.pulled" "$SITE/.wpsync/baseline.json"
}
# finished <was> <push-id>: WordPress hat wieder geladen und der Agent hat nachgeholt (§8, AC-168).
finished() {
  local w
  w="$(work)"
  eq "$1: Zeile im Protokoll ist rolled_back" "$(record "$2" .status)" rolled_back
  eq "$1: die Einheit content nennt via rescue" "$(record "$2" '.units[] | select(.path == "content") | .via')" rescue
  no "$1: der Ordner des Pushs mit Abbildern und Umschlag ist weg" test -e "$w/$2"
  no "$1: der Marker rescue.pending ist weg" test -e "$w/rescue.pending"
  eq "$1: kein offener Push" "$(pending)" "null"
}

echo "== AC-160/R12: ohne Schlüssel der Installation kein Umschlag (no_image_key) – Warnung, mit --require-rescue-db Abbruch"
pkg klein "$FUSE or ((.t == \"posts\") and .k == \"$PAGE_B\")"
eq "Paket klein: die Sicherung und der Titel von Seite B" "$ROWS" 2
jrun nokey-dry "$WPSYNC" push "$TARGET" code --no-code --content "$PKG/klein.jsonl" --dry-run --json
eq "no_image_key Probelauf: Exit 0" "$RC" 0
eq "no_image_key Probelauf: der Plan nennt rescue_db mit dem Grund" "$(event nokey-dry plan | jq -c '.rescue_db')" '{"ok":false,"reason":"no_image_key"}'
eq "no_image_key Probelauf: Warnung rescue_db_unavailable" "$(last nokey-dry '.data.warnings | join(",")')" rescue_db_unavailable
ok "no_image_key Probelauf: der Hinweis nennt die Folge" hasF "$JSON/nokey-dry.err" "bei einem Ausfall gehen nur Code und Uploads zurück"
jrun nokey-require-dry "$WPSYNC" push "$TARGET" code --no-code --content "$PKG/klein.jsonl" --dry-run --require-rescue-db --json
eq "--require-rescue-db im Probelauf: Exit 1" "$RC" 1
eq "--require-rescue-db im Probelauf: reason und detail" "$(last nokey-require-dry '.error.reason + " " + .error.detail')" "rescue_db_unavailable no_image_key"
jrun nokey-require "$WPSYNC" push "$TARGET" code themes/e2e-theme --uploads "$E2E/uploads.txt" --content "$PKG/klein.jsonl" --yes --require-rescue-db --json
eq "--require-rescue-db: Exit 1" "$RC" 1
eq "--require-rescue-db: reason und detail" "$(last nokey-require '.error.reason + " " + .error.detail')" "rescue_db_unavailable no_image_key"
no "--require-rescue-db: es wurde nichts getauscht" hasF "$JSON/nokey-require.jsonl" '"event":"commit"'
state_clean "--require-rescue-db"
eq "--require-rescue-db: kein offener Push" "$(pending)" "null"
# Ohne das Flag wird gepusht (R12). Legt der Inhalt dann WordPress lahm, gehen Code und Uploads zurück,
# die Inhalte bleiben – und mit ihnen der Ausfall: die Grenze, die P3 für diese Sites nicht hebt.
arm
jrun nokey "$WPSYNC" push "$TARGET" code themes/e2e-theme --uploads "$E2E/uploads.txt" --content "$PKG/klein.jsonl" --yes --json
eq "no_image_key: Exit 43" "$RC" 43
PUSH_NOKEY="$(last nokey '.data.push_id')"
eq "no_image_key: zurückgerollt über rescue.php" "$(last nokey '.data.status + " " + .data.via')" "rolled_back rescue"
eq "no_image_key: Warnungen" "$(last nokey '.data.warnings | sort | join(",")')" "content_not_rolled_back,rescue_db_unavailable"
eq "no_image_key: content_error.code" "$(last nokey '.data.content_error.code')" rescue_db_unavailable
ok "no_image_key: der Code ist zurück" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"
no "no_image_key: der Upload ist zurück" test -e "$WPC/uploads/2026/10/e2e-satz.png"
eq "no_image_key: die Inhalte stehen noch" "$(lopt options_e2e_fuse) $(lpost "$PAGE_B" post_title)" "an E2E B geändert"
no "no_image_key: im Arbeitsordner liegt kein Umschlag" test -e "$(work)/$PUSH_NOKEY/rescue.sealed"
ok "no_image_key: die Abbilder liegen als Klartext da – aus denen schriebe rescue.php nie zurück" jq -e . "$(work)/$PUSH_NOKEY/content/before.json"
eq "no_image_key: die Site bleibt unten, solange der Inhalt steht (Grenze)" "$(code "$SOURCE_URL/")" 500
disarm
eq "no_image_key: ohne die Sicherung antwortet WordPress wieder" "$(code "$SOURCE_URL/")" 200
jrun nokey-rollback "$WPSYNC" rollback "$TARGET" "$PUSH_NOKEY" --json
eq "no_image_key: wpsync rollback über den Agent holt die Inhalte nach (Exit 0)" "$RC" 0
eq "no_image_key: über den Agent, ohne Warnung" "$(last nokey-rollback '.data.via + " " + (.data | has("warnings") | tostring)')" "agent false"
state_clean "no_image_key nachgeholt"
eq "no_image_key nachgeholt: kein offener Push" "$(pending)" "null"

echo "== Ab hier hat die Installation einen Schlüssel; das Pairing (im Klartext abgelegt) gilt weiter"
src wp config set AUTH_KEY "$(cat "$E2E/auth-key")" --type=constant
eq "mit Schlüssel: SecretKey::fromConfig() liefert einen" "$(src wp eval 'echo count(WpSync\SecretKey::fromConfig());')" 1
jrun key-dry "$WPSYNC" push "$TARGET" code --no-code --content "$PKG/klein.jsonl" --dry-run --require-rescue-db --json
eq "mit Schlüssel: Probelauf mit --require-rescue-db geht durch" "$RC" 0
eq "mit Schlüssel: der Plan nennt rescue_db ok" "$(event key-dry plan | jq -c '.rescue_db')" '{"ok":true}'
eq "mit Schlüssel: keine Warnung" "$(last key-dry '.data | has("warnings")')" false

echo "== AC-158: der INHALT legt WordPress lahm – Health-Check, Rücknahme über rescue.php samt Datenbank"
pkg kern "$FUSE or $(of "$PAGE_A") or (.t == \"options\" and (.k == \"blogdescription\" or .k == \"wpseo_social\" or .k == \"options_e2e_neu\"))"
eq "Paket kern: Beitrag, fünf Meta, ein gelöschtes Paar, drei Optionen, die Sicherung" "$ROWS" 11
SUM_A="$(rowsum "$PAGE_A")"
arm
eq "AC-158: vor dem Push antwortet die Site (die Sicherung ist scharf, der Wert steht noch nicht)" "$(code "$SOURCE_URL/")" 200
T0="$(date +%s)"
jrun kern "$WPSYNC" push "$TARGET" code themes/e2e-theme --uploads "$E2E/uploads.txt" --content "$PKG/kern.jsonl" --yes --require-rescue-db --json
echo "INFO: Push, Health-Check und Rücknahme über rescue.php: $(($(date +%s) - T0)) s"
cat "$JSON/kern.err"
eq "AC-158: Exit 43" "$RC" 43
PUSH_KERN="$(last kern '.data.push_id')"
eq "AC-158: Status und Weg" "$(last kern '.data.status + " " + .data.via')" "rolled_back rescue"
eq "AC-158: ohne Warnung – auch kein content_not_rolled_back" "$(last kern '.data | has("warnings")')" false
eq "AC-158: kein content_error" "$(last kern '.data | has("content_error")')" false
eq "AC-158: der Health-Check sah alle Seiten ausfallen" "$(event kern health | jq -r '.worse | length > 0')" true
ok "AC-158: der Agent antwortete nicht" hasF "$JSON/kern.err" "der Agent antwortet nicht – nehme den Weg über rescue.php"
ok "AC-158: Meldung der CLI" contains "$(last kern '.error.message')" "zurückgerollt (über rescue.php, Inhalte eingeschlossen)"
eq "AC-158: die Site antwortet wieder" "$(code "$SOURCE_URL/")" 200
eq "AC-158: auch die gepushte Seite" "$(code "$SOURCE_URL/e2e-a/")" 200
eq "AC-158: Seite A Byte für Byte wie vor dem Push – Emoji, 100 KB, serialisiert, mehrere Werte, das gelöschte Paar" "$(rowsum "$PAGE_A")" "$SUM_A"
eq "AC-158: die eingefügte Option ist wieder weg" "$(q "SELECT COUNT(*) FROM ${PREFIX}options WHERE option_name = 'options_e2e_neu'")" 0
eq "AC-158: die Sicherung steht wieder auf aus" "$(lopt options_e2e_fuse)" aus
state_clean "AC-158"
finished "AC-168" "$PUSH_KERN"
ok "AC-168: die Nacharbeiten sind nachgeholt, der Object-Cache dabei" test "$(record "$PUSH_KERN" '.units[] | select(.path == "content") | .post_actions | map(select(.step == "object_cache" and .ok)) | length')" = 1
no "AC-168: nirgends im Arbeitsordner liegt noch ein Umschlag" sh -c "find '$(work)' -name rescue.sealed | grep -q ."

echo "== E1/AC-161/AC-162/R9/R11/AC-166: der Umschlag, falsche Schlüssel, ohne content=1, Wiederholung – direkt an rescue.php"
hold direkt --no-code --content "$PKG/klein.jsonl"
D="$(work)/$HELD"
KEY="$(rkey "$HELD")"
eq "direkt: der Inhalt steht, WordPress ist unten" "$(lopt options_e2e_fuse) $(code "$SOURCE_URL/")" "an 500"
ok "E1: der Begin hat den Umschlag angelegt" test -s "$D/rescue.sealed"
eq "E1: nur für den Besitzer lesbar" "$(src exec stat -c %a "/var/www/html/public/wp-content/${D#"$WPC"/}/rescue.sealed")" 600
eq "E1: versiegelt mit sodium" "$(head -n 1 "$D/rescue.sealed")" "wpsync-rescue:v1:sodium"
no "E1: kein Zugangsdatum steht lesbar darin" grep -qaF -e "$DBPASS" -e "$DBUSER" -e "$DBHOST" -e "$DBNAME" "$D/rescue.sealed"
eq "E1: der Umschlag ist von aussen nicht abrufbar" "$(code "$SOURCE_URL/wp-content/${D#"$WPC"/}/rescue.sealed")" 403
ok "E1: die Abbilder sind versiegelt" sh -c "head -c 15 '$D/content/before.json' | grep -qx 'wpsync-image:v1'"
WRONG="$(printf 'f%.0s' $(seq 64))"
glog_on
rpost wrong1 "$HELD" "$WRONG" action=rollback content=1
eq "AC-161: falscher Schlüssel: 403" "$RCODE $(ans wrong1 '.error')" "403 wrong key"
eq "AC-161: keine Verbindung zur Datenbank" "$(conns)" 0
ok "AC-161: der Umschlag liegt unberührt da" test -s "$D/rescue.sealed"
eq "AC-161: der Fehlversuch steht in rescue.tries, nicht in rescue.json" "$(jq -r .attempts "$D/rescue.tries") $(jq -r .attempts "$D/rescue.json")" "1 0"
for i in 2 3 4 5; do rpost "wrong$i" "$HELD" "$WRONG" action=rollback content=1; done
eq "AC-162: der fünfte falsche Schlüssel: noch 403" "$RCODE" 403
rpost wrong6 "$HELD" "$WRONG" action=rollback content=1
eq "AC-162: danach 429 für falsche Schlüssel" "$RCODE $(ans wrong6 '.error')" "429 locked"
rpost wrong-cache "$HELD" "$WRONG" action=cache
eq "AC-162: auch action=cache prüft den Schlüssel zuerst" "$RCODE" 429
eq "AC-161: nach sieben falschen Schlüsseln immer noch keine Verbindung" "$(conns)" 0
eq "AC-161: und nichts ist zurückgenommen" "$(lopt options_e2e_fuse) $(lpost "$PAGE_B" post_title)" "an E2E B geändert"
# R11: der richtige Schlüssel gilt trotz Sperre (R9) – ohne content=1 wie eine CLI 0.7.x: nur Code und Uploads.
rpost r11 "$HELD" "$KEY" action=rollback
eq "R9/R11: richtiger Schlüssel in der Sperre, ohne content=1: 200" "$RCODE $(ans r11 '.status')" "200 rolled_back"
eq "R11: die Antwort sagt nichts über content und warnt wie P2" "$(ans r11 '[has("content"), (.warnings | join(","))] | @csv')" 'false,"content_not_rolled_back"'
eq "R11: keine Verbindung zur Datenbank" "$(conns)" 0
eq "R11: die Inhalte stehen noch" "$(lopt options_e2e_fuse) $(lpost "$PAGE_B" post_title)" "an E2E B geändert"
ok "R11: der Umschlag bleibt liegen" test -s "$D/rescue.sealed"
# §4.1: Code und Uploads sind zurück, der DB-Anteil offen – mit content=1 läuft nur noch Schritt 6.
rpost nachholen "$HELD" "$KEY" action=rollback content=1
eq "§4.1: derselbe Push, jetzt mit content=1: 200" "$RCODE" 200
eq "§4.1: content.state rolled_back, ohne persistenten Object-Cache cache none, keine Warnung" "$(ans nachholen '[.content.state, .content.cache, has("warnings")] | @csv')" '"rolled_back","none",false'
eq "§4.1: genau eine Verbindung zur Datenbank" "$(conns)" 1
eq "R6: die Sitzung von rescue.php trägt die sql_mode der wpdb-Sitzung" "$(rlog | sed -n "s/^SET SESSION sql_mode = '\(.*\)'\$/\1/p" | tail -n 1)" "$WP_MODE"
ok "R6: Zeichensatz wie wpdb (SET NAMES utf8mb4)" sh -c "printf %s \"\$1\" | grep -q \"^SET NAMES 'utf8mb4'\"" _ "$(rlog)"
ok "D23: die Rücknahme lief in einer Transaktion und endete mit COMMIT" sh -c "printf %s \"\$1\" | grep -qx 'COMMIT'" _ "$(rlog)"
eq "§4.1: die Inhalte sind zurück" "$(lopt options_e2e_fuse) $(lpost "$PAGE_B" post_title)" "aus E2E B"
eq "§4.1: die Datenbank Byte für Byte wie nach dem Pull" "$(dbsum)" "$DB_PULLED"
no "R14: der Umschlag ist nach gelungener Rücknahme weg" test -e "$D/rescue.sealed"
ok "§8.1: der Marker rescue.pending liegt im Arbeitsordner" test -f "$(work)/rescue.pending"
eq "§7.4: rescue.json nennt via, post und cache" "$(jq -c '.content | [.state, .via, .post, .cache]' "$D/rescue.json")" '["rolled_back","rescue","pending","none"]'
# AC-166: Wiederholung – gleiche Antwort, ohne Datenbank.
BEFORE_REPEAT="$(conns)"
rpost wiederholung "$HELD" "$KEY" action=rollback content=1
eq "AC-166: zweiter Aufruf: 200, content.state rolled_back" "$RCODE $(ans wiederholung '.status + " " + .content.state')" "200 rolled_back rolled_back"
eq "AC-166: ohne Datenbankzugriff" "$(conns)" "$BEFORE_REPEAT"
rpost cache-none "$HELD" "$KEY" action=cache
eq "R8: ohne persistenten Object-Cache gibt es nichts zu leeren (409)" "$RCODE $(ans cache-none '.error')" "409 nothing to flush"
glog_off
leaks "AC-173 Antworten bis hier" "$JSON"/wrong*.json "$JSON/r11.json" "$JSON/nachholen.json" "$JSON/wiederholung.json"
eq "§8: der erste Seitenaufruf danach gelingt" "$(code "$SOURCE_URL/")" 200
finished "AC-168 nach direktem Aufruf" "$HELD"
state_clean "direkt"

echo "== AC-158: dasselbe über wpsync rollback <id> bei stummem WordPress"
hold stumm themes/e2e-theme --uploads "$E2E/uploads.txt" --content "$PKG/kern.jsonl"
eq "rollback: der Satz steht auf der Site, WordPress ist unten" "$(lpost "$PAGE_A" post_title) $(code "$SOURCE_URL/")" "$TITLE_A 500"
ok "rollback: Code und Upload des Satzes sind auf Live" sh -c "grep -q 'e2e-marker v2' '$WPC/themes/e2e-theme/index.php' && test -e '$WPC/uploads/2026/10/e2e-satz.png'"
jrun stumm-rollback "$WPSYNC" rollback "$TARGET" "$HELD" --json
cat "$JSON/stumm-rollback.err"
eq "AC-158 rollback: Exit 0" "$RC" 0
eq "AC-158 rollback: Status, Weg, Einheiten" "$(last stumm-rollback '.data.status + " " + .data.via + " " + (.data.units | join(","))')" "rolled_back rescue themes/e2e-theme,uploads,content"
eq "AC-158 rollback: ohne Warnung" "$(last stumm-rollback '.data | has("warnings")')" false
ok "AC-158 rollback: Weg über rescue.php" hasF "$JSON/stumm-rollback.err" "nehme den Weg über rescue.php"
eq "AC-158 rollback: die Site antwortet wieder" "$(code "$SOURCE_URL/e2e-a/")" 200
eq "AC-158 rollback: Seite A Byte für Byte wie vor dem Push" "$(rowsum "$PAGE_A")" "$SUM_A"
state_clean "AC-158 rollback"
finished "AC-168 nach wpsync rollback" "$HELD"

echo "== AC-171: Rücknahme unter strenger globaler sql_mode – Null-Datum, volle Spaltenlänge, 4-Byte-UTF-8, über 100 KB"
pkg streng "$FUSE or $(of "$PAGE_FULL") or $(of "$DRAFT")"
eq "Paket streng: die Seite mit den Grenzwerten, ihre zwei Meta, trash des Entwurfs, die Sicherung" "$(jq -r '.op + ":" + .table' "$PKG/streng.body" | LC_ALL=C sort | uniq -c | awk '{print $1 $2}' | paste -sd' ' -)" "1trash:posts 1update:options 2update:postmeta 1update:posts"
SUM_FULL="$(rowsum "$PAGE_FULL")"
SUM_DRAFT="$(rowsum "$DRAFT")"
jrun streng "$WPSYNC" push "$TARGET" code --no-code --content "$PKG/streng.jsonl" --yes --require-rescue-db --json
eq "AC-171: Exit 43, über rescue.php, ohne Warnung" "$RC $(last streng '.data.via + " " + (.data | has("warnings") | tostring)')" "43 rescue false"
eq "AC-171: der Entwurf trägt wieder das Null-Datum und seinen Status" "$(lpost "$DRAFT" "CONCAT(post_date_gmt, ' ', post_status, ' ', post_name)")" "0000-00-00 00:00:00 draft e2e-entwurf"
eq "AC-171: der Entwurf Byte für Byte wie vor dem Push (die Papierkorb-Meta sind weg)" "$(rowsum "$DRAFT")" "$SUM_DRAFT"
eq "AC-171: Titel in voller Spaltenlänge mit dem 4-Byte-Zeichen am Ende" "$(lpost "$PAGE_FULL" "CONCAT(LENGTH(post_title), ' ', HEX(RIGHT(post_title, 1)), ' ', CHAR_LENGTH(post_name))")" "65535 F09F9A80 200"
eq "AC-171: die Seite mit den Grenzwerten Byte für Byte wie vor dem Push (auch das Meta über 100 KB)" "$(rowsum "$PAGE_FULL")" "$SUM_FULL"
eq "AC-171: die Site antwortet wieder" "$(code "$SOURCE_URL/")" 200
state_clean "AC-171"
finished "AC-171" "$(last streng '.data.push_id')"

echo "== AC-163/R7: eine Zeile des Pushs wurde seither geändert – die Datenbank bleibt, Code und Uploads gehen zurück"
hold geaendert themes/e2e-theme --uploads "$E2E/uploads.txt" --content "$PKG/klein.jsonl"
PUSH_CHANGED="$HELD"
sql -e "UPDATE ${PREFIX}posts SET post_title = 'nach dem Push geändert' WHERE ID = $PAGE_B"
DB_CHANGED="$(dbsum)"
jrun geaendert-rollback "$WPSYNC" rollback "$TARGET" "$HELD" --json
cat "$JSON/geaendert-rollback.err"
eq "AC-163: Exit 0 mit Warnung" "$RC $(last geaendert-rollback '.data.warnings | join(",")')" "0 content_not_rolled_back"
eq "AC-163: content_error nennt changed_since_push und den Schlüssel" "$(last geaendert-rollback '.data.content_error | .code + " " + (.keys | map(.table + ":" + .key) | join(",")) + " " + (.total | tostring)')" "changed_since_push posts:$PAGE_B 1"
eq "AC-163: über rescue.php" "$(last geaendert-rollback '.data.via')" rescue
ok "AC-163: die CLI nennt den Grund und den Ausweg" sh -c "grep -qF 'changed_since_push' '$JSON/geaendert-rollback.err' && grep -qF 'wpsync rollback $TARGET $HELD' '$JSON/geaendert-rollback.err'"
eq "AC-163: in der Datenbank hat sich nichts geändert – auch die unveränderte Zeile des Pushs steht noch" "$(dbsum) $(lopt options_e2e_fuse)" "$DB_CHANGED an"
ok "AC-163: der Code ist zurück" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"
no "AC-163: der Upload ist zurück" test -e "$WPC/uploads/2026/10/e2e-satz.png"
eq "AC-163: rescue.json hält den Grund fest, der DB-Anteil bleibt offen" "$(jq -c '[.status, .content.state, .content.error]' "$(work)/$HELD/rescue.json")" '["rolled_back","applied","changed_since_push"]'
ok "AC-163: Umschlag und Vorher-Abbild bleiben liegen" sh -c "test -s '$(work)/$HELD/rescue.sealed' && test -s '$(work)/$HELD/content/before.json'"
eq "AC-163: die Site bleibt unten – der Inhalt ist die Ursache (Grenze, Q3)" "$(code "$SOURCE_URL/")" 500
disarm
eq "AC-163: der Push bleibt offen" "$(pending | jq -r .push_id)" "$HELD"
jrun geaendert-blockiert "$WPSYNC" push "$TARGET" code --no-code --content "$PKG/klein.jsonl" --dry-run --json
eq "AC-163: und blockiert weitere (Exit 42)" "$RC" 42
# WordPress ist wieder da: über den Agent bleibt es bei changed_since_push, bis die Zeile wieder den gepushten Stand trägt.
jrun geaendert-agent "$WPSYNC" rollback "$TARGET" "$HELD" --json
eq "AC-163: über den Agent weiter changed_since_push (Exit 1)" "$RC $(last geaendert-agent '.error.reason')" "1 changed_since_push"
sql -e "UPDATE ${PREFIX}posts SET post_title = 'E2E B geändert' WHERE ID = $PAGE_B"
jrun geaendert-nachholen "$WPSYNC" rollback "$TARGET" "$HELD" --json
eq "AC-163: mit dem gepushten Stand nimmt der Agent die Inhalte zurück (Exit 0, ohne Warnung)" "$RC $(last geaendert-nachholen '.data.via + " " + (.data | has("warnings") | tostring)')" "0 agent false"
state_clean "AC-163 nachgeholt"
eq "AC-163 nachgeholt: kein offener Push" "$(pending)" "null"
no "AC-163 nachgeholt: der Ordner des Pushs ist weg" test -e "$(work)/$PUSH_CHANGED"
arm

echo "== R15: an einer eingefügten Seite hängt Fremdes – der Agent lehnt ab, rescue.php nimmt den Push zurück und lässt es stehen"
pkg neu "$FUSE or $(of "$NEW_ID")"
eq "Paket neu: insert der Seite und ihrer zwei Meta, die Sicherung" "$(jq -r '.op + ":" + .table' "$PKG/neu.body" | LC_ALL=C sort | uniq -c | awk '{print $1 $2}' | paste -sd' ' -)" "2insert:postmeta 1insert:posts 1update:options"
hold gewachsen --no-code --content "$PKG/neu.jsonl"
eq "R15: die neue Seite steht auf Live" "$(lpost "$NEW_ID" post_name)" e2e-neu
sql -e "INSERT INTO ${PREFIX}postmeta (post_id, meta_key, meta_value) VALUES ($NEW_ID, '_e2e_fremd', 'gehört nicht zum Push'), ($NEW_ID, '_edit_lock', '1:1');
        INSERT INTO ${PREFIX}comments (comment_post_ID, comment_author, comment_date, comment_date_gmt, comment_content) VALUES ($NEW_ID, 'e2e', NOW(), UTC_TIMESTAMP(), 'nach dem Push');"
disarm
jrun gewachsen-agent "$WPSYNC" rollback "$TARGET" "$HELD" --json
eq "R15 über den Agent: changed_since_push (Exit 1)" "$RC $(last gewachsen-agent '.error.reason')" "1 changed_since_push"
eq "R15 über den Agent: genannt werden das fremde Meta und der Kommentar" "$(last gewachsen-agent '.error.keys | map(.table + ":" + (.key | gsub("\u0000"; "/"))) | sort | join(",")')" "comments:$NEW_ID,postmeta:$NEW_ID/_e2e_fremd"
eq "R15 über den Agent: nichts wurde zurückgenommen" "$(lpost "$NEW_ID" post_name) $(lopt options_e2e_fuse)" "e2e-neu an"
arm
mute # WordPress bleibt auch nach der Rücknahme stumm: der Agent holt noch nichts nach (kein push/list, kein init)
jrun gewachsen-rescue "$WPSYNC" rollback "$TARGET" "$HELD" --json
cat "$JSON/gewachsen-rescue.err"
eq "R15 über rescue.php: Exit 0, Warnung content_left_extra" "$RC $(last gewachsen-rescue '.data.via + " " + (.data.warnings | join(","))')" "0 rescue content_left_extra"
eq "R15: content_left nennt das Fremde" "$(last gewachsen-rescue '.data.content_left | map(.table + ":" + (.key | gsub("\u0000"; "/"))) | sort | join(",")')" "comments:$NEW_ID,postmeta:$NEW_ID/_e2e_fremd"
eq "R15: content_left_total" "$(last gewachsen-rescue '.data.content_left_total')" 2
eq "R15: die Zeilen des Pushs sind weg – die Seite und ihre zwei Meta" "$(q "SELECT (SELECT COUNT(*) FROM ${PREFIX}posts WHERE ID = $NEW_ID) + (SELECT COUNT(*) FROM ${PREFIX}postmeta WHERE post_id = $NEW_ID AND meta_key IN ('_wp_page_template', '_e2e_emoji'))")" 0
eq "R15: das Fremde steht noch, verwaist – auch das Meta der Sperrliste (P8)" "$(q "SELECT GROUP_CONCAT(meta_key ORDER BY meta_key) FROM ${PREFIX}postmeta WHERE post_id = $NEW_ID") $(q "SELECT COUNT(*) FROM ${PREFIX}comments WHERE comment_post_ID = $NEW_ID")" "_e2e_fremd,_edit_lock 1"
unmute
eq "R15: die Sicherung steht wieder auf aus, die Site antwortet" "$(lopt options_e2e_fuse) $(code "$SOURCE_URL/")" "aus 200"
finished "R15" "$HELD"
eq "R15: das Protokoll nennt, was stehen blieb" "$(record "$HELD" '.units[] | select(.path == "content") | .left_total')" 2
# M2: stehen gelassen ist nicht folgenlos – es hinge sich an das nächste Objekt mit dieser ID. Beim
# Wiederanlauf räumt der Agent Meta und Zuordnungen weg (auch die der Sperrliste); der Kommentar bleibt.
eq "M2: nach dem Wiederanlauf hängt an der ID kein Meta mehr, der Kommentar bleibt" "$(q "SELECT COUNT(*) FROM ${PREFIX}postmeta WHERE post_id = $NEW_ID") $(q "SELECT COUNT(*) FROM ${PREFIX}comments WHERE comment_post_ID = $NEW_ID")" "0 1"
eq "M2: das Protokoll nennt das Aufräumen als Nacharbeit left_cleanup" "$(record "$HELD" '.units[] | select(.path == "content") | .post_actions | map(select(.step == "left_cleanup") | .ok) | @csv')" "true"
# Solange der Kommentar an der ID hängt, lehnt der Agent ein neues Objekt mit dieser ID ab.
jrun belegte-id "$WPSYNC" push "$TARGET" code --no-code --content "$PKG/neu.jsonl" --yes --json
eq "M2: insert an einer ID mit Resten: Exit 1, id_has_leftovers" "$RC $(last belegte-id '.error.reason')" "1 id_has_leftovers"
eq "M2: genannt wird der Rest" "$(last belegte-id '.error.keys | map(.table + ":" + .key) | join(",")')" "comments:$NEW_ID"
eq "M2: nichts wurde übertragen, kein offener Push" "$(q "SELECT COUNT(*) FROM ${PREFIX}posts WHERE ID = $NEW_ID") $(lopt options_e2e_fuse) $(pending)" "0 aus null"
sql -e "DELETE FROM ${PREFIX}comments WHERE comment_post_ID = $NEW_ID"
jrun freie-id "$WPSYNC" push "$TARGET" code --no-code --content "$PKG/neu.jsonl" --dry-run --json
eq "M2: ohne den Rest ginge derselbe Satz (Probelauf, Exit 0)" "$RC" 0
state_clean "R15"

echo "== AC-173/AC-160: die Datenbank ist nicht erreichbar, dann eine fremde Sperre – keine Antwort, kein Protokoll nennt Zugangsdaten, SQL oder Werte"
LOG_SINCE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
curl -s -o /dev/null "$SOURCE_URL/e2e-log-probe.php"
hold unerreichbar themes/e2e-theme --content "$PKG/klein.jsonl"
KEY="$(rkey "$HELD")"
D="$(work)/$HELD"
# Das Passwort des Datenbankbenutzers ändert sich nach dem Begin: der Umschlag trägt das alte.
sql -e "ALTER USER '$DBUSER'@'%' IDENTIFIED BY 'anders-$RANDOM'"
rpost unerreichbar "$HELD" "$KEY" action=rollback content=1
sql -e "ALTER USER '$DBUSER'@'%' IDENTIFIED BY '$DBPASS'"
eq "AC-160: Datenbank nicht erreichbar: 200, content kept mit db_unreachable" "$RCODE $(ans unerreichbar '[.status, .content.state, .content.error.code, (.warnings | join(","))] | @csv')" '200 "rolled_back","kept","db_unreachable","content_not_rolled_back"'
ok "AC-160: der Code ist trotzdem zurück" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"
eq "AC-160: die Inhalte stehen noch, der Umschlag bleibt" "$(lopt options_e2e_fuse) $(test -s "$D/rescue.sealed" && echo da)" "an da"
# Jetzt erreichbar, aber eine fremde Sitzung hält eine Zeile des Pushs länger als die Wartezeit (10 s).
(sql -e "START TRANSACTION; SELECT ID FROM ${PREFIX}posts WHERE ID = $PAGE_B FOR UPDATE; SELECT SLEEP(25); ROLLBACK" >/dev/null 2>&1) &
LOCKER=$!
sleep 2
T0="$(date +%s)"
rpost gesperrt "$HELD" "$KEY" action=rollback content=1
WAITED=$(($(date +%s) - T0))
eq "N1: fremde Zeilensperre: 200, content kept mit content_failed" "$RCODE $(ans gesperrt '[.content.state, .content.error.code, (.content.error | has("unrestored"))] | @csv')" '200 "kept","content_failed",false'
ok "N1: rescue.php hat nicht auf das Ende der fremden Sitzung gewartet (${WAITED} s)" test "$WAITED" -lt 22
eq "N1: die Inhalte stehen unverändert" "$(lopt options_e2e_fuse) $(lpost "$PAGE_B" post_title)" "an E2E B geändert"
wait "$LOCKER" || true
docker logs --since "$LOG_SINCE" "ddev-$SOURCE_NAME-web" >"$E2E/web.log" 2>&1 || true
ok "AC-173: das Fehlerprotokoll des Webservers ist das geprüfte (die Probe steht darin)" grep -qF "e2e-rdb log probe" "$E2E/web.log"
ok "AC-173: protokolliert ist nur die Fehlernummer der Datenbank (1205)" grep -qF "wpsync: a query of the content channel failed (MySQL error 1205)" "$E2E/web.log"
leaks "AC-173 Antworten" "$JSON/unerreichbar.json" "$JSON/gesperrt.json"
leaks "AC-173 Fehlerprotokoll" "$E2E/web.log"
no "AC-173: kein Wert einer Zeile im Fehlerprotokoll oder in den Antworten" grep -qF -e "E2E B geändert" -e "$(b64 'E2E B geändert')" "$E2E/web.log" "$JSON/unerreichbar.json" "$JSON/gesperrt.json"
# Die Sperre ist weg: derselbe Aufruf holt die Inhalte nach.
rpost frei "$HELD" "$KEY" action=rollback content=1
eq "AC-160: ohne die Sperre gehen die Inhalte zurück" "$RCODE $(ans frei '.content.state')" "200 rolled_back"
eq "AC-160: die Site antwortet wieder" "$(code "$SOURCE_URL/")" 200
finished "AC-160" "$HELD"
state_clean "AC-160/AC-173"

echo "== R14: der Umschlag verfällt nach 24 Stunden – rescue.php lässt die Inhalte dann stehen"
hold verfallen --no-code --content "$PKG/klein.jsonl"
KEY="$(rkey "$HELD")"
D="$(work)/$HELD"
src exec touch -d '25 hours ago' "/var/www/html/public/wp-content/${D#"$WPC"/}/rescue.sealed"
src wp eval 'WpSync\Push::maintain();'
no "R14: der tägliche Lauf hat den alten Umschlag gelöscht" test -e "$D/rescue.sealed"
ok "R14: Vorher-Abbild und Datensatz bleiben" sh -c "test -s '$D/content/before.json' && test -s '$D/rescue.json'"
rpost verfallen "$HELD" "$KEY" action=rollback content=1
eq "AC-160: ohne Umschlag: content kept mit rescue_db_unavailable" "$RCODE $(ans verfallen '[.content.state, .content.error.code] | @csv')" '200 "kept","rescue_db_unavailable"'
eq "AC-160: die Inhalte stehen noch" "$(lopt options_e2e_fuse)" an
disarm
jrun verfallen-rollback "$WPSYNC" rollback "$TARGET" "$HELD" --json
eq "R14: der Agent nimmt die Inhalte zurück, sobald WordPress wieder lädt" "$RC $(last verfallen-rollback '.data.via')" "0 agent"
state_clean "R14"
arm

echo "== N3: harte Altersgrenze – ein Umschlag, der älter ist als 7 Tage, wird nie angewandt, auch wenn seine Datei frisch ist"
WORK_IN="/var/www/html/public/wp-content/$(basename "$(work)")"
# Gegenprobe zuerst: sechs Tage alt (und älter als die 24 Stunden, nach denen ihn nur der Cron löscht) gilt er noch.
hold alt6 --no-code --content "$PKG/klein.jsonl"
KEY="$(rkey "$HELD")"
eq "N3: der Umschlag ist neu versiegelt, sechs Tage alt" "$(src exec php "$CTL_IN/reseal.php" "$WORK_IN" "$HELD" "$KEY" 6)" resealed
rpost alt6 "$HELD" "$KEY" action=rollback content=1
eq "N3: sechs Tage alt: die Inhalte gehen zurück" "$RCODE $(ans alt6 '.content.state')" "200 rolled_back"
eq "N3: die Site antwortet wieder" "$(code "$SOURCE_URL/")" 200
finished "N3 sechs Tage" "$HELD"
state_clean "N3 sechs Tage"
hold alt8 --no-code --content "$PKG/klein.jsonl"
KEY="$(rkey "$HELD")"
D="$(work)/$HELD"
eq "N3: der Umschlag ist neu versiegelt, acht Tage alt" "$(src exec php "$CTL_IN/reseal.php" "$WORK_IN" "$HELD" "$KEY" 8)" resealed
src wp eval 'WpSync\Push::maintain();'
ok "N3: seine Datei ist frisch – der tägliche Lauf (24 h nach mtime) lässt ihn liegen" test -s "$D/rescue.sealed"
glog_on
rpost alt8 "$HELD" "$KEY" action=rollback content=1
eq "N3: acht Tage alt: 200, content kept mit rescue_db_unavailable" "$RCODE $(ans alt8 '[.status, .content.state, .content.error.code, (.warnings | join(","))] | @csv')" '200 "rolled_back","kept","rescue_db_unavailable","content_not_rolled_back"'
eq "N3: keine Verbindung zur Datenbank" "$(conns)" 0
glog_off
eq "N3: die Inhalte stehen noch" "$(lopt options_e2e_fuse) $(lpost "$PAGE_B" post_title)" "an E2E B geändert"
disarm
jrun alt8-rollback "$WPSYNC" rollback "$TARGET" "$HELD" --json
eq "N3: der Agent nimmt die Inhalte zurück, sobald WordPress wieder lädt" "$RC $(last alt8-rollback '.data.via')" "0 agent"
state_clean "N3 acht Tage"
arm

echo "== AC-165: ein bestätigter Push – rescue.php lehnt ab, ohne Umschlag und ohne Datenbank"
disarm
pkg bestaetigt ".t == \"posts\" and .k == \"$PAGE_B\""
jrun bestaetigt "$WPSYNC" push "$TARGET" code --no-code --content "$PKG/bestaetigt.jsonl" --yes --require-rescue-db --json
eq "AC-165: Push bestätigt" "$RC $(last bestaetigt '.data.status')" "0 confirmed"
PUSH_CONF="$(last bestaetigt '.data.push_id')"
D="$(work)/$PUSH_CONF"
no "AC-165: nach confirm liegt kein rescue.sealed mehr im Arbeitsordner" test -e "$D/rescue.sealed"
ok "AC-165: das Vorher-Abbild bleibt für die Rücknahme über den Agent" test -s "$D/content/before.json"
DB_CONF="$(dbsum)"
glog_on
rpost bestaetigt "$PUSH_CONF" "$(rkey "$PUSH_CONF")" action=rollback content=1
eq "AC-165: 409 confirmed" "$RCODE $(ans bestaetigt '.error')" "409 confirmed"
eq "AC-165: keine Verbindung zur Datenbank" "$(conns)" 0
glog_off
eq "AC-165: die Datenbank ist unberührt" "$(dbsum)" "$DB_CONF"
jrun bestaetigt-rollback "$WPSYNC" rollback "$TARGET" "$PUSH_CONF" --json
eq "AC-165: zurück geht ein bestätigter Push nur über den Agent (Exit 0)" "$RC $(last bestaetigt-rollback '.data.via')" "0 agent"
state_clean "AC-165"
arm

echo "== M1: confirm nimmt die Sperre des Pushs – während einer Rücknahme wird nichts bestätigt; den bestätigten Push lehnt rescue.php ab"
disarm
hold m1 --no-code --content "$PKG/bestaetigt.jsonl"
KEY="$(rkey "$HELD")"
D="$(work)/$HELD"
# Eine fremde Hand hält rescue.lock länger, als confirm wartet (15 s) – wie eine Rücknahme, die gerade läuft.
(src exec flock -x "/var/www/html/public/wp-content/${D#"$WPC"/}/rescue.lock" sleep 24 >/dev/null 2>&1) &
LOCKER=$!
sleep 1.5
T0="$(date +%s)"
jrun m1-confirm-belegt "$WPSYNC" pushes "$TARGET" --confirm "$HELD" --json
WAITED=$(($(date +%s) - T0))
eq "M1: confirm bei belegter Sperre: Exit 44 (busy)" "$RC $(last m1-confirm-belegt '.error.code')" "44 busy"
ok "M1: der Agent nennt wpsync_push_busy" contains "$(last m1-confirm-belegt '.error.message')" "wpsync_push_busy"
ok "M1: confirm hat auf die Sperre gewartet (${WAITED} s)" test "$WAITED" -ge 10
eq "M1: nichts ist bestätigt – weder rescue.json noch das Protokoll" "$(jq -r .status "$D/rescue.json") $(record "$HELD" .status)" "committed committed"
ok "M1: der Umschlag bleibt für die Rücknahme liegen" test -s "$D/rescue.sealed"
wait "$LOCKER" || true
jrun m1-confirm "$WPSYNC" pushes "$TARGET" --confirm "$HELD" --json
eq "M1: ist die Sperre frei, bestätigt confirm" "$RC $(last m1-confirm '.data.status') $(jq -r .status "$D/rescue.json")" "0 confirmed confirmed"
DB_M1="$(dbsum)"
rpost m1-rescue "$HELD" "$KEY" action=rollback content=1
eq "M1: den bestätigten Push lehnt rescue.php ab (409 confirmed)" "$RCODE $(ans m1-rescue '.error')" "409 confirmed"
eq "M1: die Datenbank ist unberührt, der Status bleibt" "$(dbsum) $(jq -r .status "$D/rescue.json")" "$DB_M1 confirmed"
jrun m1-rollback "$WPSYNC" rollback "$TARGET" "$HELD" --json
eq "M1: zurück geht er über den Agent (Exit 0)" "$RC $(last m1-rollback '.data.via')" "0 agent"
state_clean "M1"
arm

echo "== N7: HTTP 200 ohne status rolled_back ist für die CLI keine Rücknahme"
hold n7 themes/e2e-theme --content "$PKG/klein.jsonl"
STUB_NAME="$(basename "$(jq -r .rescue_url "$SITE/.wpsync/pushes/$HELD.json")")"
ok "N7: der Notfallweg dieses Pushs ist der Stub im Webroot" sh -c "printf %s '$STUB_NAME' | grep -Eq '^wpsync-rescue-[a-f0-9]{32}\.php\$' && test -f '$PUB/$STUB_NAME'"
# An der Stelle des Stubs antwortet etwas anderes mit 200 und "ok" – im Container getauscht (siehe arm/disarm).
src exec cp -p "/var/www/html/public/$STUB_NAME" "$CTL_IN/stub.keep"
src exec cp "$CTL_IN/fake-rescue.php" "/var/www/html/public/$STUB_NAME"
jrun n7-rollback "$WPSYNC" rollback "$TARGET" "$HELD" --json
src exec cp -p "$CTL_IN/stub.keep" "/var/www/html/public/$STUB_NAME"
src exec rm -f "$CTL_IN/stub.keep"
eq "N7: Exit 1" "$RC" 1
ok "N7: die CLI nennt den Weg und den fehlenden Status" sh -c "printf %s \"\$1\" | grep -F 'Rollback über rescue.php fehlgeschlagen' | grep -qF 'rolled_back'" _ "$(last n7-rollback '.error.message')"
no "N7: die CLI meldet den Push nicht als zurückgerollt" hasF "$JSON/n7-rollback.err" "ist zurückgerollt"
eq "N7: der Satz steht noch – Inhalte und Code" "$(lopt options_e2e_fuse) $(grep -c 'e2e-marker v2' "$WPC/themes/e2e-theme/index.php")" "an 1"
ok "N7: das Journal des Pushs bleibt für den nächsten Versuch" test -f "$SITE/.wpsync/pushes/$HELD.json"
jrun n7-rollback-echt "$WPSYNC" rollback "$TARGET" "$HELD" --json
eq "N7: mit dem echten rescue.php geht der Push zurück (Exit 0, über rescue.php, ohne Warnung)" "$RC $(last n7-rollback-echt '.data.via + " " + (.data | has("warnings") | tostring)')" "0 rescue false"
eq "N7: die Site antwortet wieder" "$(code "$SOURCE_URL/")" 200
finished "N7" "$HELD"
state_clean "N7"

echo "== R10: die Sperre des Pushs ist belegt – 423 busy, die CLI wiederholt; über den Agent wpsync_push_busy"
# Eine fremde Hand hält rescue.lock (flock im Container, dieselbe Sperre wie PHP): so sieht rescue.php
# eine laufende Rücknahme über den Agent oder einen Commit an seiner Naht.
hold belegt themes/e2e-theme --content "$PKG/klein.jsonl"
KEY="$(rkey "$HELD")"
D="$(work)/$HELD"
(src exec flock -x "/var/www/html/public/wp-content/${D#"$WPC"/}/rescue.lock" sleep 5 >/dev/null 2>&1) &
LOCKER=$!
sleep 1.5
rpost belegt "$HELD" "$KEY" action=rollback content=1
eq "R10: belegte Sperre: 423 busy" "$RCODE $(ans belegt '.error')" "423 busy"
eq "R10: nichts ist zurückgenommen – weder Inhalte noch Code" "$(lopt options_e2e_fuse) $(grep -c 'e2e-marker v2' "$WPC/themes/e2e-theme/index.php")" "an 1"
jrun belegt-rollback "$WPSYNC" rollback "$TARGET" "$HELD" --json
wait "$LOCKER" || true
eq "R10: die CLI wiederholt, bis die Sperre frei ist (Exit 0, über rescue.php, ohne Warnung)" "$RC $(last belegt-rollback '.data.via + " " + (.data | has("warnings") | tostring)')" "0 rescue false"
eq "R10: die Site antwortet wieder" "$(code "$SOURCE_URL/")" 200
finished "R10" "$HELD"
state_clean "R10"
hold belegt-agent --no-code --content "$PKG/klein.jsonl"
D="$(work)/$HELD"
disarm
# Erst länger belegt, als die CLI wiederholt (dreimal im Abstand von 2 s): dann zeigt sie den Fehler des Agents.
(src exec flock -x "/var/www/html/public/wp-content/${D#"$WPC"/}/rescue.lock" sleep 16 >/dev/null 2>&1) &
LOCKER=$!
sleep 1.5
jrun belegt-agent-busy "$WPSYNC" rollback "$TARGET" "$HELD" --json
eq "R10: über den Agent, Sperre bleibt belegt: Exit 44 (busy)" "$RC $(last belegt-agent-busy '.error.code')" "44 busy"
ok "R10: der Agent antwortet mit wpsync_push_busy (HTTP 423)" contains "$(last belegt-agent-busy '.error.message')" "HTTP 423 wpsync_push_busy"
eq "R10: nichts ist zurückgenommen, der Push bleibt offen" "$(lopt options_e2e_fuse) $(pending | jq -r .push_id)" "an $HELD"
wait "$LOCKER" || true
(src exec flock -x "/var/www/html/public/wp-content/${D#"$WPC"/}/rescue.lock" sleep 5 >/dev/null 2>&1) &
LOCKER=$!
sleep 1.5
T0="$(date +%s)"
jrun belegt-agent-rollback "$WPSYNC" rollback "$TARGET" "$HELD" --json
WAITED=$(($(date +%s) - T0))
wait "$LOCKER" || true
eq "R10: über den Agent wartet die CLI ebenfalls auf die Sperre (Exit 0, über den Agent)" "$RC $(last belegt-agent-rollback '.data.via')" "0 agent"
ok "R10: sie hat dafür mindestens eine Pause eingelegt (${WAITED} s)" test "$WAITED" -ge 2
state_clean "R10 über den Agent"
eq "R10 über den Agent: kein offener Push" "$(pending)" "null"
arm

echo "== Regression: ein reiner Code-Push – kein Umschlag, keine Datenbank, Rücknahme wie bisher"
printf '\nthis is not php(\n' >>"$HEALTH"
hold code plugins/e2e-health
D="$(work)/$HELD"
no "Code-Push: kein Umschlag im Arbeitsordner" test -e "$D/rescue.sealed"
eq "Code-Push: rescue.json hat keinen DB-Anteil" "$(jq -c '.content' "$D/rescue.json")" null
eq "Code-Push: der kaputte Code legt WordPress lahm" "$(code "$SOURCE_URL/")" 500
glog_on
rpost code "$HELD" "$(rkey "$HELD")" action=rollback content=1
eq "Code-Push: 200 rolled_back, auch mit content=1 ohne content in der Antwort" "$RCODE $(ans code '[.status, has("content"), has("warnings")] | @csv')" '200 "rolled_back",false,false'
eq "Code-Push: keine Verbindung zur Datenbank" "$(conns)" 0
glog_off
no "Code-Push: der kaputte Code ist von Live verschwunden" grep -q "this is not php" "$WPC/plugins/e2e-health/e2e-health.php"
eq "Code-Push: die Site antwortet wieder" "$(code "$SOURCE_URL/")" 200
eq "Code-Push: Zeile im Protokoll ist rolled_back, kein offener Push" "$(record "$HELD" .status) $(pending)" "rolled_back null"
# Und im ganzen Ablauf der CLI: Health-Check, rescue.php, Exit 43 – ohne ein Wort über Inhalte.
jrun code-health "$WPSYNC" push "$TARGET" code plugins/e2e-health --yes --json
eq "Code-Push über die CLI: Exit 43, zurückgerollt, ohne Warnung" "$RC $(last code-health '.data.status + " " + (.data | has("warnings") | tostring)')" "43 rolled_back false"
no "Code-Push über die CLI: die Meldung spricht nicht von Inhalten" contains "$(last code-health '.error.message')" "Inhalte"
eq "Code-Push über die CLI: die Site antwortet" "$(code "$SOURCE_URL/")" 200
cp -p "$E2E/e2e-health.good" "$HEALTH"
state_clean "Code-Push"

echo "== AC-169/R8: persistenter Object-Cache – cache stale, action=cache, und was ohne den Schritt bliebe"
# Ein Datei-Cache als Drop-in: die Funktionen des Core, nur dass die Gruppe options (alloptions,
# notoptions) einen Request überlebt. Für WordPress ist das ein persistenter Object-Cache.
disarm
sed 's/new WP_Object_Cache()/new E2E_File_Cache()/' "$PUB/wp-includes/cache.php" >"$E2E/object-cache.php"
grep -q 'new E2E_File_Cache()' "$E2E/object-cache.php" || fail "wp-includes/cache.php legt den Cache nicht mehr mit new WP_Object_Cache() an"
cat >>"$E2E/object-cache.php" <<'PHP'

class E2E_File_Cache extends WP_Object_Cache
{
    private $e2e_file;

    public function __construct()
    {
        parent::__construct();
        $this->e2e_file = WP_CONTENT_DIR . '/e2e-object-cache.ser';
        $raw            = @file_get_contents($this->e2e_file);
        $data           = is_string($raw) ? @unserialize($raw) : false;
        foreach (is_array($data) ? $data : [] as $key => $value) {
            $this->set($key, $value, 'options');
        }
        register_shutdown_function([$this, 'e2e_save']);
    }

    public function e2e_save()
    {
        $data = [];
        foreach (['alloptions', 'notoptions'] as $key) {
            $found = false;
            $value = $this->get($key, 'options', false, $found);
            if ($found) {
                $data[$key] = $value;
            }
        }
        if ($data === []) {
            @unlink($this->e2e_file);
        } else {
            @file_put_contents($this->e2e_file, serialize($data), LOCK_EX);
        }
    }

    public function flush()
    {
        @unlink($this->e2e_file);
        return parent::flush();
    }
}
PHP
cp "$E2E/object-cache.php" "$WPC/object-cache.php"
eq "Object-Cache: die Site antwortet mit dem Drop-in" "$(code "$SOURCE_URL/")" 200
eq "Object-Cache: WordPress hält ihn für persistent" "$(curl -s "$SOURCE_URL/?rest_route=/" -o /dev/null -w '%{http_code}') $(src wp eval 'echo wp_using_ext_object_cache() ? "ext" : "intern";')" "200 ext"
ok "Object-Cache: alloptions liegt in der Datei" test -s "$WPC/e2e-object-cache.ser"
arm
# Der ganze Ablauf der CLI: stale ⇒ action=cache ⇒ die Site antwortet.
jrun cache-kern "$WPSYNC" push "$TARGET" code --no-code --content "$PKG/klein.jsonl" --yes --require-rescue-db --json
cat "$JSON/cache-kern.err"
eq "AC-169: Exit 43, über rescue.php, ohne Warnung (auch kein object_cache_stale)" "$RC $(last cache-kern '.data.via + " " + (.data | has("warnings") | tostring)')" "43 rescue false"
eq "AC-169: die Site antwortet wieder – der Cache trägt den zurückgenommenen Wert" "$(code "$SOURCE_URL/")" 200
eq "AC-169: WordPress liefert die zurückgenommene Option (über den Cache gelesen)" "$(curl -s "$SOURCE_URL/e2e-b/" | grep -c 'E2E B</h1>') $(lopt options_e2e_fuse)" "1 aus"
finished "AC-169" "$(last cache-kern '.data.push_id')"
state_clean "AC-169"
# Schritt für Schritt: der Cache-Schritt scheitert (Wartungsmodus) – die Rücknahme gilt trotzdem,
# die CLI meldet object_cache_stale; ohne den Schritt bleibt die Site unten, mit ihm läuft sie.
hold cache-stale --no-code --content "$PKG/klein.jsonl"
KEY="$(rkey "$HELD")"
eq "AC-169: die Site ist unten, der Cache trägt den gepushten Wert" "$(code "$SOURCE_URL/") $(grep -a -c 's:16:"options_e2e_fuse";s:2:"an"' "$WPC/e2e-object-cache.ser")" "500 1"
printf '<?php $upgrading = time();\n' >"$PUB/.maintenance"
jrun cache-stale-rollback "$WPSYNC" rollback "$TARGET" "$HELD" --json
cat "$JSON/cache-stale-rollback.err"
eq "AC-169: Cache-Schritt gescheitert: Exit 0, Warnung object_cache_stale" "$RC $(last cache-stale-rollback '.data.via + " " + (.data.warnings | join(","))')" "0 rescue object_cache_stale"
ok "AC-169: die CLI nennt den Ausweg" hasF "$JSON/cache-stale-rollback.err" "beim Hoster leeren"
eq "AC-169: die Rücknahme gilt – die Datenbank ist zurück" "$(lopt options_e2e_fuse) $(dbsum)" "aus $DB_PULLED"
eq "AC-169: rescue.json: cache bleibt stale" "$(jq -r '.content.cache' "$(work)/$HELD/rescue.json")" stale
src exec rm -f /var/www/html/public/.maintenance
eq "AC-169: OHNE den Schritt bleibt die Site unten – der Cache liefert weiter den gepushten Wert" "$(code "$SOURCE_URL/") $(grep -a -c 's:16:"options_e2e_fuse";s:2:"an"' "$WPC/e2e-object-cache.ser")" "500 1"
rpost cache-wrong "$HELD" "$WRONG" action=cache
eq "R8: action=cache mit falschem Schlüssel: 403" "$RCODE" 403
eq "R8: und der Cache ist nicht geleert" "$(code "$SOURCE_URL/")" 500
rpost cache-flush "$HELD" "$KEY" action=cache
eq "R8: action=cache: 200 flushed" "$RCODE $(ans cache-flush '.cache')" "200 flushed"
ok "E14: der Schritt lief über den Stub im Webroot" sh -c "jq -r .rescue_url '$SITE/.wpsync/pushes/$HELD.json' | grep -Eq '/wpsync-rescue-[a-f0-9]{32}\.php\$'"
eq "R8: MIT dem Schritt läuft die Site wieder" "$(code "$SOURCE_URL/")" 200
rpost cache-again "$HELD" "$KEY" action=cache
ok "R8: ein zweites Leeren gibt es nicht (409 oder der Push ist schon abgeschlossen)" test "$RCODE" = 409 -o "$RCODE" = 404
finished "AC-169 Schritt für Schritt" "$HELD"
state_clean "AC-169 Schritt für Schritt"
src exec rm -f /var/www/html/public/wp-content/object-cache.php /var/www/html/public/wp-content/e2e-object-cache.ser
eq "Object-Cache: ohne Drop-in antwortet die Site" "$(code "$SOURCE_URL/")" 200

echo "== AC-164: Push nach Staging, WordPress stumm – rescue.php nimmt nur die Tabellen der Kopie zurück"
disarm
jrun staging-create "$WPSYNC" staging create "$TARGET" --yes --json --rps 20
[ "$RC" = 0 ] || fail "staging create (Exit $RC, siehe $JSON/staging-create.err)"
STG_URL="$(last staging-create '.data.url')"
STG_DIR="${STG_URL##*/}"
STG_PREFIX="$(q "SHOW TABLES LIKE 'stg%\_posts'" | sed 's/posts$//')"
ok "Staging: die Kopie hat ein eigenes Präfix" sh -c "printf %s '$STG_PREFIX' | grep -Eq '^stg[a-f0-9]{6}_\$'"
pkg staging "((.t == \"posts\") and .k == \"$PAGE_B\") or (.t == \"options\" and .k == \"blogdescription\")"
eq "Paket staging: der Titel von Seite B und eine Option" "$ROWS" 2
STG_BEFORE="$(dbsum "$STG_PREFIX")"
hold staging themes/e2e-theme --content "$PKG/staging.jsonl" --to staging
eq "Staging: der Satz steht in der Kopie" "$(lpost "$PAGE_B" post_title "$STG_PREFIX") $(lopt blogdescription "$STG_PREFIX")" "E2E B geändert Ünterzeile 🚀"
eq "Staging: Live trägt ihn nicht" "$(lpost "$PAGE_B" post_title) $(dbsum)" "E2E B $DB_PULLED"
SD="$(find "$PUB/$STG_DIR/wp-content" -maxdepth 1 -name 'wpsync-push-*' -type d | head -1)/$HELD"
ok "Staging: der Umschlag liegt im Arbeitsordner der Kopie" test -s "$SD/rescue.sealed"
mute
eq "Staging: WordPress ist stumm" "$(code "$SOURCE_URL/")" 500
glog_on
jrun staging-rollback "$WPSYNC" rollback "$TARGET" "$HELD" --json
cat "$JSON/staging-rollback.err"
eq "AC-164: Exit 0, über rescue.php, Ziel staging, ohne Warnung" "$RC $(last staging-rollback '.data.via + " " + .data.target + " " + (.data | has("warnings") | tostring)')" "0 rescue staging false"
eq "AC-164: die Tabellen der Kopie Byte für Byte wie vor dem Push" "$(dbsum "$STG_PREFIX")" "$STG_BEFORE"
eq "AC-164: Live ist unberührt" "$(dbsum)" "$DB_PULLED"
STG_LOG="$(rlog)"
ok "AC-164: rescue.php hat in Tabellen der Kopie geschrieben" sh -c "printf %s \"\$1\" | grep -Eq '^(UPDATE|INSERT INTO|DELETE FROM) .?${STG_PREFIX}'" _ "$STG_LOG"
no "AC-164: keine Anweisung von rescue.php nennt eine Tabelle von Live" sh -c "printf %s \"\$1\" | grep -q '${PREFIX}\\(posts\\|postmeta\\|options\\|terms\\|term_taxonomy\\|term_relationships\\|termmeta\\|comments\\)'" _ "$STG_LOG"
glog_off
ok "AC-164: der Code der Kopie ist zurück" grep -q 'e2e-marker v1' "$PUB/$STG_DIR/wp-content/themes/e2e-theme/index.php"
ok "AC-164: der Code von Live war nie betroffen" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"
unmute
eq "Staging: WordPress antwortet wieder" "$(code "$SOURCE_URL/")" 200
eq "§8.1: der nächste signierte Aufruf schliesst den Push nach Staging ab" "$(record "$HELD" '.status + " " + .target + " " + (.units[] | select(.path == "content") | .via)')" "rolled_back staging rescue"
no "Staging: der Ordner des Pushs in der Kopie ist weg" test -e "$SD"
eq "Staging: kein offener Push" "$(pending)" "null"
state_clean "AC-164"

echo "== Nicht im E2E"
echo "SKIP: E10 (Wettlauf Commit ↔ rescue.php, AC-167): bräuchte einen Commit, der an einer festen Stelle anhält – ein Trigger mit SLEEP hält die Transaktion an, nicht den Moment zwischen Vorher-Abbild und Naht; ohne Eingriff in den Agent nicht stabil. Deckt PushRescueFlowTest/ContentApplyGateTest."
echo "SKIP: E1 Socket/IPv6/TLS, E11 flock auf NFS, E13 PHP ohne sodium und 64-MB-Abbild, E8 echtes Redis: nicht mit einem DDEV-Projekt herstellbar bzw. Sache des Zielsystems."

echo "== Aufräumen auf der Quelle"
jrun staging-delete "$WPSYNC" staging delete "$TARGET" --yes --json
eq "Aufräumen: Staging-Kopie gelöscht" "$RC" 0
window 0
