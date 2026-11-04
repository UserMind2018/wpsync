#!/usr/bin/env bash
# E2E Nachtbericht: `wpsync activity` gegen eine echte WordPress-Site (DDEV) – Protokoll des Agents,
# Quellen der Ereignisse (WP-CLI, Auto-Update, an WordPress vorbei), Bereinigung, Grenzen, Aufräumen.
# Keine echte Kundenseite, kein Netz nötig: es wird nichts gezogen und nichts gepusht.
#
# Eigenes Projekt wpsync-e2e-act (Quelle: apache-fpm, PHP 8.2, MySQL 8.0) unter ~/wpsync-e2e/act;
# alle anderen E2E-Projekte bleiben unberührt.
#
# Voraussetzung: Docker, DDEV, jq (≥ 1.6), zip, Go; Mac-Modus (das Pairing-Secret der eigenen Test-Site
# liegt in der Login-Keychain). Eine fehlgeschlagene Prüfung zählt und der Lauf geht weiter; nur was
# den Rest sinnlos macht, bricht ab. Die JSON-Zeilen der Befehle liegen danach unter
# ~/wpsync-e2e/act/json. Am Ende wird das Projekt gestoppt (nicht gelöscht); WPSYNC_E2E_KEEP=1 lässt
# es laufen.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E2E="${WPSYNC_E2E_DIR:-$HOME/wpsync-e2e}/act"
export WPSYNC_CONFIG_DIR="$E2E/config"
export WPSYNC_SITES_DIR="$E2E/sites"
SOURCE_NAME=wpsync-e2e-act
TARGET=wpsync-e2e-act # eine Site genügt: es wird nichts gezogen
SRC="$E2E/source"
PUB="$SRC/public"
WPC="$PUB/wp-content"
WPSYNC="$E2E/bin/wpsync" # eigener Build – nie eine installierte wpsync
JSON="$E2E/json"
PREFIX=e2e_
AGENT_VERSION="$(sed -n "s/^const WPSYNC_VERSION = '\(.*\)';/\1/p" "$ROOT/agent/wpsync-agent.php")"

CHECKS=0
FAILED=0
RC=0
T_START="$(date +%s)"

pass() { CHECKS=$((CHECKS + 1)); }
bad() { CHECKS=$((CHECKS + 1)); FAILED=$((FAILED + 1)); echo "FAIL: $*"; }
fail() { echo "FAIL: $*"; exit 1; }
eq() { if [ "$2" = "$3" ]; then pass; else bad "$1 (ist: $2, soll: $3)"; fi; } # eq <was> <ist> <soll>
ok() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then pass; else bad "$what"; fi; }
no() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$what"; else pass; fi; }
src() { (cd "$SRC" && ddev "$@"); }
http_url() { (cd "$1" && ddev describe -j | jq -r '.raw.httpurl'); }
last() { tail -n 1 "$JSON/$1.jsonl" | jq -r "$2"; } # last <name> <jq>: über der Ergebniszeile
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
# Die Datenbank der Quelle, an WordPress vorbei: der Agent merkt von diesen Blicken nichts.
sql() { src mysql -uroot -proot -D db "$@" 2>/dev/null; }
q() { sql -N -e "$1"; }
finish() {
  local rc=$?
  trap - EXIT
  set +e
  rm -f "$SRC"/act-*.zip "$SRC/auto.php"
  if [ -f "$SRC/.ddev/config.yaml" ] && [ "${WPSYNC_E2E_KEEP:-}" != 1 ]; then (cd "$SRC" && ddev stop >/dev/null 2>&1); fi
  if [ "$rc" != 0 ] && [ "$FAILED" = 0 ]; then
    echo
    echo "E2E Nachtbericht ABGEBROCHEN (Exit $rc) – $CHECKS Prüfungen bis dahin, $FAILED fehlgeschlagen"
  fi
  exit "$rc" # die letzte Zeile ist sonst die Zählung: „… Prüfungen, N fehlgeschlagen, … s“
}
trap finish EXIT

command -v jq >/dev/null || fail "jq fehlt"
command -v zip >/dev/null || fail "zip fehlt"
mkdir -p "$E2E/bin" "$PUB" "$WPSYNC_SITES_DIR"
rm -rf "$JSON"
mkdir -p "$JSON"
(cd "$ROOT/agent" && ./build.sh)
(cd "$ROOT/cli" && go build -o "$WPSYNC" ./cmd/wpsync)

echo "== Quelle (Apache, PHP 8.2, MySQL 8.0)"
cd "$SRC"
if [ ! -f .ddev/config.yaml ]; then
  ddev config --project-name="$SOURCE_NAME" --project-type=wordpress --docroot=public \
    --php-version=8.2 --database=mysql:8.0 --webserver-type=apache-fpm --performance-mode=none
fi
ddev start -y
[ -f public/wp-load.php ] || ddev wp core download --force
if grep -q '#ddev-generated' public/wp-config.php; then
  sed -i '' 's/#ddev-generated//' public/wp-config.php
fi
ddev wp config set table_prefix "$PREFIX" --type=variable
if ! ddev wp core is-installed >/dev/null 2>&1; then
  ddev wp core install --url="$(http_url "$SRC")" --title="wpsync Nachtbericht E2E" --admin_user=admin \
    --admin_password=admin --admin_email=e2e@example.invalid --skip-email
  ddev wp rewrite structure '/%postname%/' --hard
fi
ddev wp config set DISABLE_WP_CRON true --raw --type=constant
ddev wp config set WPSYNC_ALLOW_HTTP true --raw --type=constant
SOURCE_URL="$(http_url "$SRC")"

echo "== Ausgangsstand: ein Theme aktiv, ein zweites zum Wechseln, kein Rest früherer Läufe"
ddev wp plugin deactivate wpsync-agent >/dev/null 2>&1 || true # löscht die Tabelle des Agents: jeder Lauf beginnt leer
ddev wp plugin delete e2e-act >/dev/null 2>&1 || true
THEMES="$(ddev wp theme list --field=name)"
BASE_THEME="$(printf '%s\n' "$THEMES" | sed -n 1p)"
OTHER="$(printf '%s\n' "$THEMES" | sed -n 2p)"
[ -n "$OTHER" ] || fail "WordPress bringt weniger als zwei Themes mit"
ddev wp theme activate "$BASE_THEME" >/dev/null

echo "== Agent $AGENT_VERSION"
cp "$ROOT/agent/dist/wpsync-agent.zip" public/wpsync-agent.zip
ddev wp plugin install /var/www/html/public/wpsync-agent.zip --force --activate
rm public/wpsync-agent.zip
CODE="$(ddev wp wpsync pair-code | tail -1)"
"$WPSYNC" unpair "$TARGET" >/dev/null 2>&1 || true
"$WPSYNC" pair "$SOURCE_URL" "$CODE" --name "$TARGET" --insecure

# ---- Nachtbericht ----------------------------------------------------------------------------------
ACT() { jrun "$1" "$WPSYNC" activity "$TARGET" --json --since "${2:-24h}"; }
ev() { last "$1" "[.data.events[] | select($2)] | length"; } # ev <name> <jq-filter>: Anzahl passender Ereignisse
mkzip() { # mkzip <version>: Plugin e2e-act in dieser Version als ZIP im Projektordner (= /var/www/html im Container)
  local d="$E2E/zip/e2e-act"
  rm -rf "$E2E/zip" && mkdir -p "$d"
  printf '<?php\n/*\n * Plugin Name: E2E Act\n * Version: %s\n */\n' "$1" >"$d/e2e-act.php"
  (cd "$E2E/zip" && zip -qr "$SRC/act-$1.zip" e2e-act)
}

echo "== der erste Bericht setzt nur den Anfang des Protokolls"
ACT act-0
eq "erster Bericht ohne Ereignisse" "$(last act-0 '.data.events | length')" "0"
ok "log_since gesetzt" test "$(last act-0 '.data.log_since')" -gt 0
eq "Agent-Version im Bericht" "$(last act-0 '.data.agent_version')" "$AGENT_VERSION"

echo "== installieren über WP-CLI"
mkzip 1.0 && mkzip 1.1 && mkzip 1.2
src wp plugin install /var/www/html/act-1.0.zip >/dev/null
ACT act-1
eq "installed, cli" "$(ev act-1 '.kind == "installed" and .slug == "e2e-act" and .to == "1.0" and .source == "cli"')" "1"

echo "== Update und Aktivieren"
src wp plugin install /var/www/html/act-1.1.zip --force >/dev/null
src wp plugin activate e2e-act >/dev/null
ACT act-2
eq "updated 1.0 → 1.1" "$(ev act-2 '.kind == "updated" and .from == "1.0" and .to == "1.1" and .source == "cli"')" "1"
eq "activated" "$(ev act-2 '.kind == "activated" and .slug == "e2e-act"')" "1"

echo "== zweiter Bericht ohne Änderung bringt nichts Neues"
ACT act-3
eq "gleiche Zahl Ereignisse" "$(last act-3 '.data.events | length')" "$(last act-2 '.data.events | length')"

echo "== Auto-Update und Fehlschlag"
cat >"$SRC/auto.php" <<'PHP'
<?php
foreach (['file.php', 'misc.php', 'plugin.php', 'class-wp-upgrader.php'] as $f) {
    require_once ABSPATH . 'wp-admin/includes/' . $f;
}
do_action('pre_auto_update', 'plugin', (object) ['slug' => 'e2e-act'], ABSPATH);
$up = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
$up->install('/var/www/html/act-1.2.zip', ['overwrite_package' => true]);
do_action('automatic_updates_complete', ['plugin' => [(object) ['item' => (object) ['slug' => 'woocommerce'], 'result' => new WP_Error('download_failed', 'x'), 'name' => 'WooCommerce']]]);
PHP
src wp eval-file /var/www/html/auto.php >/dev/null
ACT act-4
eq "updated 1.1 → 1.2, auto" "$(ev act-4 '.kind == "updated" and .to == "1.2" and .source == "auto"')" "1"
eq "update_failed mit Code" "$(ev act-4 '.kind == "update_failed" and .slug == "woocommerce" and .detail == "download_failed" and .source == "auto"')" "1"

echo "== an WordPress vorbei geändert"
sed -i.bak 's/Version: 1.2/Version: 1.3/' "$WPC/plugins/e2e-act/e2e-act.php" && rm -f "$WPC/plugins/e2e-act/e2e-act.php.bak"
ACT act-5
eq "updated 1.2 → 1.3, detected" "$(ev act-5 '.kind == "updated" and .from == "1.2" and .to == "1.3" and .source == "detected"')" "1"

echo "== deaktivieren, löschen, Theme wechseln"
src wp plugin deactivate e2e-act >/dev/null
src wp plugin delete e2e-act >/dev/null
src wp theme activate "$OTHER" >/dev/null
ACT act-6
eq "deactivated" "$(ev act-6 '.kind == "deactivated" and .slug == "e2e-act"')" "1"
eq "deleted" "$(ev act-6 '.kind == "deleted" and .slug == "e2e-act" and .from == "1.3"')" "1"
eq "theme_switched" "$(ev act-6 ".kind == \"theme_switched\" and .to == \"$OTHER\"")" "1"

echo "== Spanne zu gross: Exit 2 ohne Anfrage"
set +e; "$WPSYNC" activity "$TARGET" --json --since 800h >/dev/null 2>&1; RC=$?; set -e
eq "Exit usage" "$RC" "2"

echo "== die Tabelle verlässt den Server nie"
no "wpsync_activity in dataTables" sh -c "cd '$SRC' && ddev wp eval 'echo json_encode(WpSync\\Store::dataTables());' | grep -q wpsync_activity"

echo "== Aufräumen nach 90 Tagen"
q "UPDATE ${PREFIX}wpsync_activity SET at = UNIX_TIMESTAMP() - 91*86400 WHERE kind = 'installed'"
src wp cron event run wpsync_activity_check >/dev/null
eq "alte Zeilen weg" "$(q "SELECT COUNT(*) FROM ${PREFIX}wpsync_activity WHERE kind = 'installed'")" "0"

echo "== Deaktivieren löscht die Tabelle"
src wp plugin deactivate wpsync-agent >/dev/null
eq "Tabelle weg" "$(q "SHOW TABLES LIKE '${PREFIX}wpsync_activity'")" ""
src wp theme activate "$BASE_THEME" >/dev/null

echo
echo "$CHECKS Prüfungen, $FAILED fehlgeschlagen, $(($(date +%s) - T_START)) s"
[ "$FAILED" = 0 ]
