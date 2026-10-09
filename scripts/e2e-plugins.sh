#!/usr/bin/env bash
# E2E Plugins im Push (Spec Content-Push P4; AC-176–AC-182, AC-184–AC-188, AC-190, AC-192–AC-194, AC-196, AC-199,
# AC-202, AC-204–AC-209):
# --activate und --deactivate schalten Plugins im selben Satz – die Liste active_plugins schreibt der
# Agent selbst, in der Transaktion des DB-Schritts, ohne Code des Plugins zu laden; zurück geht sie
# als Delta, über den Agent und über rescue.php.
#
# Eigene Projekte wpsync-e2e-plg (Quelle: apache-fpm, PHP 8.2, MySQL 8.0) und wpsync-e2e-plg-target
# (lokal) unter ~/wpsync-e2e/plg; alle anderen E2E-Projekte bleiben unberührt.
#
# Wie WordPress hier ausfällt:
#   plg-fatal        wirft beim Laden – jeder Request der Site endet mit 500, auch der Agent
#   plg-admin-fatal  wirft nur im Admin-Kontext – das Frontend bleibt heil, admin-ajax.php nicht
#   hold             (mu-plugin e2e-ctl) /push/confirm wartet und lehnt ab: mit einem harten Abbruch der
#                    CLI gleich nach dem Tausch entsteht der getauschte, unbestätigte Push
# WP-CLI ist von allem ausgenommen.
#
# Voraussetzung: Docker, DDEV, jq (≥ 1.6), openssl, Go; Mac-Modus (das Pairing-Secret der eigenen
# Test-Site liegt in der Login-Keychain). Dauer rund 15 Minuten.
# Eine fehlgeschlagene Prüfung zählt und der Lauf geht weiter; nur was den Rest sinnlos macht,
# bricht ab. Die JSON-Zeilen der Befehle liegen danach unter ~/wpsync-e2e/plg/json. Am Ende werden
# beide Projekte gestoppt (nicht gelöscht); WPSYNC_E2E_KEEP=1 lässt sie laufen.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E2E="${WPSYNC_E2E_DIR:-$HOME/wpsync-e2e}/plg"
export WPSYNC_CONFIG_DIR="$E2E/config"
export WPSYNC_SITES_DIR="$E2E/sites"
SOURCE_NAME=wpsync-e2e-plg
TARGET=wpsync-e2e-plg-target
SRC="$E2E/source"
PUB="$SRC/public"
WPC="$PUB/wp-content"
CTL="$SRC/e2e-ctl"
SITE="$WPSYNC_SITES_DIR/$TARGET"
LWPC="$SITE/public/wp-content"
WPSYNC="$E2E/bin/wpsync" # eigener Build – nie eine installierte wpsync
JSON="$E2E/json"
PREFIX=e2e_
NO_KEY='put your unique phrase here' # so steht AUTH_KEY in wp-config-sample.php: kein Schlüssel der Installation

CHECKS=0
FAILED=0
RC=0
KEY_ID=""
HELD=""
T_START="$(date +%s)"

pass() { CHECKS=$((CHECKS + 1)); }
bad() { CHECKS=$((CHECKS + 1)); FAILED=$((FAILED + 1)); echo "FAIL: $*"; }
fail() { echo "FAIL: $*"; exit 1; }
eq() { if [ "$2" = "$3" ]; then pass; else bad "$1 (ist: $2, soll: $3)"; fi; } # eq <was> <ist> <soll>
ok() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then pass; else bad "$what"; fi; }
no() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$what"; else pass; fi; }
hasF() { grep -qF -- "$2" "$1"; } # hasF <datei> <text>
src() { (cd "$SRC" && ddev "$@"); }
http_url() { (cd "$1" && ddev describe -j | jq -r '.raw.httpurl'); }
code() { curl -s -o /dev/null -m 20 -w '%{http_code}' "$@" || true; }
last() { tail -n 1 "$JSON/$1.jsonl" | jq -r "$2"; }                                 # last <name> <jq>: über der Ergebniszeile
event() { jq -c "select(.event == \"$2\") | .data" "$JSON/$1.jsonl" | tail -n 1; } # event <name> <event>: letztes .data
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
# Die Datenbank der Quelle, an WordPress vorbei: der Agent merkt von diesen Blicken nichts.
sql() { src mysql -uroot -proot -D db "$@" 2>/dev/null; }
q() { sql -N -e "$1"; }
lopt() { q "SELECT option_value FROM ${2:-$PREFIX}options WHERE option_name = '$1'"; } # lopt <name> [präfix]
active() { lopt active_plugins "${1:-$PREFIX}"; }                                      # die Liste, Byte für Byte
is_active() { case "$(active "${2:-$PREFIX}")" in *"\"$1\""*) return 0 ;; esac; return 1; } # is_active <eintrag> [präfix]
# optsum [präfix]: alle Optionen eines Ziels ausser Transients, Cron und der Liste selbst – ein Push, der nur
# Plugins schaltet, ändert davon nichts ausser rewrite_rules (Nacharbeit) und dem Zustand des Agents.
# recovery_keys und recovery_mode_email_last_sent schreibt WordPress selbst, sobald ein Request mit einem Fatal endet
# (Wiederherstellungsmodus) – das ist die Site, die auf den Ausfall reagiert, nicht der Push (Lauf 3 hat es gezeigt).
optsum() {
  local p="${1:-$PREFIX}"
  q "SELECT option_name, HEX(option_value), autoload FROM ${p}options WHERE option_name NOT LIKE '\_transient\_%' AND option_name NOT LIKE '\_site\_transient\_%' AND option_name NOT LIKE 'wpsync\_%' AND option_name NOT IN ('active_plugins', 'rewrite_rules', 'cron', 'recently_activated', 'recovery_keys', 'recovery_mode_email_last_sent') ORDER BY 1" | shasum -a 256 | cut -d' ' -f1
}
# want_add <eintrag>: die Liste, wie activate_plugin() des Cores sie schriebe (anhängen, sort(), serialize()).
# optlist [präfix]: dieselben Optionen als Name und Abdruck – nur um bei einer Abweichung zu sagen, welche es ist.
optlist() {
  local p="${1:-$PREFIX}"
  q "SELECT option_name, MD5(option_value), autoload FROM ${p}options WHERE option_name NOT LIKE '\_transient\_%' AND option_name NOT LIKE '\_site\_transient\_%' AND option_name NOT LIKE 'wpsync\_%' AND option_name NOT IN ('active_plugins', 'rewrite_rules', 'cron', 'recently_activated', 'recovery_keys', 'recovery_mode_email_last_sent') ORDER BY 1"
}
optdiff() { optlist >"$E2E/opts.now"; echo "  abweichende Optionen (Name, Abdruck, autoload):"; diff "$E2E/opts.pulled" "$E2E/opts.now" | sed 's/^/    /' || true; }
want_add() { src wp eval "\$l = (array) get_option('active_plugins'); \$l[] = '$1'; sort(\$l); echo serialize(\$l);"; }
# want_drop <eintrag>: die Liste ohne den Eintrag, dicht und sortiert – wie ContentPlugins::pack() sie schreibt.
want_drop() { src wp eval "\$l = array_values(array_diff((array) get_option('active_plugins'), ['$1'])); sort(\$l); echo serialize(\$l);"; }
pending() { src wp eval 'echo json_encode(WpSync\Push::pending());'; }
record() { "$WPSYNC" pushes "$TARGET" --json 2>/dev/null | jq -r ".data.pushes[] | select(.push_id == \"$1\") | $2"; } # record <id> <jq>
work() { find "$WPC" -maxdepth 1 -name 'wpsync-push-*' -type d | head -1; }
CTL_IN=/var/www/html/e2e-ctl

# hold <name> <argumente von „push <ziel> code“ …>: pusht und bricht die CLI gleich nach dem Tausch hart ab –
# wie ein abgestürztes Gerät. Zurück bleibt ein getauschter, unbestätigter Push samt Journal; ID in HELD.
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
trust_ddev() { # gibt .ddev des Ziels frei – ohne Terminal nur über den Fingerprint des angezeigten Stands
  local fp
  fp="$({ "$WPSYNC" trust "$TARGET" </dev/null 2>&1 || true; } | sed -n 's/^Fingerprint: //p')"
  [ -n "$fp" ] || fail "wpsync trust zeigt keinen Fingerprint"
  "$WPSYNC" trust "$TARGET" --fingerprint "$fp" </dev/null >/dev/null
}
# Stellt her, was ein abgebrochener Lauf an der Quelle verstellt haben kann.
restore_source() {
  rm -f "$CTL/hold" "$CTL/needs" "$PUB/.maintenance" "$WPC/e2e-plg-loaded.log" "$WPC/object-cache.php" "$WPC/e2e-object-cache.ser"
  rm -rf "$WPC/plugins/plg-ok" "$WPC/plugins/plg-fatal" "$WPC/plugins/plg-admin-fatal" "$WPC/plugins/wp-rocket" "$WPC/plugins/plg-php99" "$WPC/plugins/plg-wp99" "$WPC/plugins/plg-needs" "$WPC/plugins/plg-nohead" "$WPC/plugins/plg-two"
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
    echo "E2E Plugins ABGEBROCHEN (Exit $rc) – $CHECKS Prüfungen bis dahin, $FAILED FAIL"
    exit "$rc"
  fi
  if [ "$FAILED" != 0 ]; then
    echo "E2E Plugins: $CHECKS Prüfungen, $FAILED FAIL ($(($(date +%s) - T_START)) s)"
    exit 1
  fi
  echo "E2E Plugins OK – $CHECKS Prüfungen grün, 0 FAIL ($(($(date +%s) - T_START)) s)"
}
trap finish EXIT

command -v jq >/dev/null || fail "jq fehlt"
mkdir -p "$E2E/bin" "$PUB" "$CTL" "$WPSYNC_SITES_DIR"
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
[ -s "$E2E/auth-key" ] || openssl rand -hex 32 >"$E2E/auth-key"
restore_source
if ! ddev wp core is-installed >/dev/null 2>&1; then
  ddev wp core install --url="$(http_url "$SRC")" --title="wpsync Plugins E2E" --admin_user=admin \
    --admin_password=admin --admin_email=e2e@example.invalid --skip-email
  ddev wp rewrite structure '/%postname%/' --hard
fi
ddev wp config set DISABLE_WP_CRON true --raw --type=constant
ddev wp config set WPSYNC_ALLOW_HTTP true --raw --type=constant
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

echo "== Fixtures der Quelle: Theme, mu-plugin e2e-ctl und fünf Plugins"
mkdir -p "$WPC/themes/e2e-theme" "$WPC/mu-plugins"
for p in e2e-health plg-old plg-solo plg-dep e2e-extra; do mkdir -p "$WPC/plugins/$p"; done
cat >"$WPC/mu-plugins/e2e-ctl.php" <<'PHP'
<?php
// hold: /push/confirm wartet und lehnt ab (nur auf der Quelle, gesteuert über /var/www/html/e2e-ctl).
if (defined('WP_CLI') && WP_CLI) {
    return;
}
add_filter('rest_pre_dispatch', static function ($result, $server, $request) {
    $hold = '/var/www/html/e2e-ctl/hold';
    if (strpos((string) $request->get_route(), '/push/confirm') !== false && is_file($hold)) {
        for ($i = 0; $i < 300 && is_file($hold); $i++) {
            usleep(100000);
            clearstatcache();
        }
        return new WP_Error('e2e_hold', 'e2e: confirm held', ['status' => 503]);
    }
    return $result;
}, 10, 3);
PHP
printf '/*\nTheme Name: E2E Theme\nVersion: 1.0\n*/\n' >"$WPC/themes/e2e-theme/style.css"
cat >"$WPC/themes/e2e-theme/index.php" <<'PHP'
<!doctype html><html><head><?php wp_head(); ?></head><body><p>e2e-marker v1</p><?php wp_footer(); ?></body></html>
PHP
printf '<?php\n/* Plugin Name: E2E Health\n * Version: 1.0 */\n' >"$WPC/plugins/e2e-health/e2e-health.php"
cat >"$WPC/plugins/plg-old/plg-old.php" <<'PHP'
<?php
/**
 * Plugin Name: PLG Alt
 * Version: 3.2.1
 */
register_deactivation_hook(__FILE__, static function () {
    update_option('plg_old_deactivated', 'ja');
});
PHP
printf '<?php\n/* Plugin Name: PLG Solo\n * Version: 1.1 */\nfunction plg_solo_fn() { return 1; }\n' >"$WPC/plugins/plg-solo/plg-solo.php"
# AC-205: solange <quelle>/e2e-ctl/needs liegt, ruft dieses mu-plugin eine Funktion von plg-solo – ohne das Plugin wirft jeder Request.
cat >"$WPC/mu-plugins/e2e-needs.php" <<'PHP'
<?php
if (defined('WP_CLI') && WP_CLI) {
    return;
}
add_action('plugins_loaded', static function () {
    if (is_file('/var/www/html/e2e-ctl/needs')) {
        plg_solo_fn();
    }
}, 0);
PHP
printf '<?php\n/**\n * Plugin Name: PLG Abhängig\n * Version: 1.0\n * Requires Plugins: plg-old\n */\n' >"$WPC/plugins/plg-dep/plg-dep.php"
printf '<?php\n/* Plugin Name: E2E Extra\n * Version: 1.0 */\n' >"$WPC/plugins/e2e-extra/e2e-extra.php"
ddev wp theme activate e2e-theme
ddev wp plugin deactivate --all --skip-plugins >/dev/null 2>&1 || true
ddev wp plugin activate e2e-health plg-old plg-solo plg-dep
ddev wp option delete plg_old_deactivated plg_ok_activated >/dev/null 2>&1 || true
# Ein Benutzer ohne das Recht activate_plugins (Redakteur) – vor dem Pull angelegt, damit user_count im Ausgangsstand steht.
ddev wp user get e2e-editor --field=ID >/dev/null 2>&1 || ddev wp user create e2e-editor e2e-editor@example.invalid --role=editor --user_pass=e2e-editor >/dev/null
EDITOR_ID="$(ddev wp user get e2e-editor --field=ID)"
# Was der Fatal-Error-Handler von WordPress in einem früheren Lauf hinterlassen hat (Wiederherstellungsmodus).
ddev wp option delete recovery_keys recovery_mode_email_last_sent >/dev/null 2>&1 || true

echo "== Agent $(sed -n "s/^const WPSYNC_VERSION = '\(.*\)';/\1/p" "$ROOT/agent/wpsync-agent.php")"
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
sql -e "DROP TABLE IF EXISTS ${PREFIX}plg_ok" || true
# Gekoppelt wird ohne Schlüssel der Installation (AUTH_KEY wie in wp-config-sample.php): das Pairing liegt dann im
# Klartext und gilt weiter, wenn die Installation danach einen Schlüssel bekommt – so lässt sich AC-181 prüfen.
ddev wp config set AUTH_KEY "$NO_KEY" --type=constant
CODE="$(ddev wp wpsync pair-code | tail -1)"
"$WPSYNC" unpair "$TARGET" >/dev/null 2>&1 || true
rm -rf "$SITE/.wpsync/pushes" "$SITE/.wpsync/staging-base.json"
"$WPSYNC" pair "$SOURCE_URL" "$CODE" --name "$TARGET" --insecure
KEY_ID="$(awk '/^key_id:/ { print $2 }' "$WPSYNC_CONFIG_DIR/sites/$TARGET.yaml")"
"$WPSYNC" scan "$TARGET" --refresh --preset vollstaendig --uploads-since alle
if [ -f "$SITE/.ddev/config.yaml" ] && [ ! -f "$WPSYNC_CONFIG_DIR/ddev-state/$TARGET.json" ]; then trust_ddev; fi
jrun pull "$WPSYNC" pull "$TARGET" --full --yes --json
[ "$RC" = 0 ] || fail "pull (Exit $RC, siehe $JSON/pull.err)"
cp "$SITE/.wpsync/baseline.json" "$E2E/files.pulled"
LIST_PULLED="$(active)"
OPTS_PULLED="$(optsum)"
optlist >"$E2E/opts.pulled"
ok "Ausgangsstand: plg-old, plg-solo, plg-dep und der Agent sind aktiv" sh -c "printf %s '$LIST_PULLED' | grep -q 'plg-old/plg-old.php' && printf %s '$LIST_PULLED' | grep -q 'wpsync-agent/wpsync-agent.php'"

echo "== Lokale Plugins der Arbeitskopie – sie kommen erst mit einem Push auf die Site"
for p in plg-ok plg-fatal plg-admin-fatal plg-php99 plg-wp99 plg-needs plg-nohead plg-two wp-rocket; do mkdir -p "$LWPC/plugins/$p"; done
cat >"$LWPC/plugins/plg-ok/plg-ok.php" <<'PHP'
<?php
/**
 * Plugin Name: PLG OK
 * Version: 1.0.0
 */
// Jeder Request, der das Plugin lädt, hinterlässt seine Adresse – der Commit darf nicht darunter sein (AC-178).
@file_put_contents(WP_CONTENT_DIR . '/e2e-plg-loaded.log', (defined('WP_CLI') && WP_CLI ? 'cli' : (string) ($_SERVER['REQUEST_URI'] ?? '?')) . "\n", FILE_APPEND);
register_activation_hook(__FILE__, static function () {
    global $wpdb;
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}plg_ok (id INT PRIMARY KEY)");
    update_option('plg_ok_activated', 'ja');
});
PHP
mkdir -p "$LWPC/plugins/plg-ok/inc"
printf '<?php\n// Teil von PLG OK\n' >"$LWPC/plugins/plg-ok/inc/teil.php"
cat >"$LWPC/plugins/plg-fatal/plg-fatal.php" <<'PHP'
<?php
/**
 * Plugin Name: PLG Fatal
 * Version: 1.0
 */
if (!(defined('WP_CLI') && WP_CLI)) {
    throw new Error('e2e: plg-fatal legt WordPress lahm');
}
PHP
cat >"$LWPC/plugins/plg-admin-fatal/plg-admin-fatal.php" <<'PHP'
<?php
/**
 * Plugin Name: PLG Admin-Fatal
 * Version: 1.0
 */
if (is_admin() && !(defined('WP_CLI') && WP_CLI)) {
    throw new Error('e2e: plg-admin-fatal bricht nur im Admin-Kontext');
}
PHP
printf '<?php\n/**\n * Plugin Name: PLG PHP 99\n * Version: 1.0\n * Requires PHP: 99.0\n */\n' >"$LWPC/plugins/plg-php99/plg-php99.php"
printf '<?php\n/**\n * Plugin Name: PLG WP 99\n * Version: 1.0\n * Requires at least: 99.0\n */\n' >"$LWPC/plugins/plg-wp99/plg-wp99.php"
printf '<?php\n/**\n * Plugin Name: PLG Braucht\n * Version: 1.0\n * Requires Plugins: gibt-es-nicht\n */\n' >"$LWPC/plugins/plg-needs/plg-needs.php"
printf '<?php\n// kein Kopf\n' >"$LWPC/plugins/plg-nohead/plg-nohead.php"
printf '<?php\n/* Plugin Name: PLG Zwei A */\n' >"$LWPC/plugins/plg-two/a.php"
printf '<?php\n/* Plugin Name: PLG Zwei B */\n' >"$LWPC/plugins/plg-two/b.php"
printf '<?php\n/* Plugin Name: WP Rocket (E2E-Attrappe)\n * Version: 9.9 */\n' >"$LWPC/plugins/wp-rocket/wp-rocket.php"

# clean <was>: der Stand der Site ist wieder der des Pulls – Liste, Optionen, Code, kein offener Push.
clean() {
  eq "$1: die Liste Byte für Byte wie nach dem Pull" "$(active)" "$LIST_PULLED"
  eq "$1: die übrigen Optionen wie nach dem Pull" "$(optsum)" "$OPTS_PULLED"
  [ "$(optsum)" = "$OPTS_PULLED" ] || optdiff
  for p in plg-ok plg-fatal plg-admin-fatal wp-rocket; do
    no "$1: der Ordner $p ist nicht auf der Site" test -e "$WPC/plugins/$p"
  done
  eq "$1: kein offener Push" "$(pending)" "null"
  ok "$1: Baseline der Dateien wie nach dem Pull" cmp -s "$E2E/files.pulled" "$SITE/.wpsync/baseline.json"
  eq "$1: die Site antwortet" "$(code "$SOURCE_URL/")" 200
}

echo "== AC-181: ohne Umschlag für rescue.php geht ein Satz mit Plugin-Zustand nicht raus"
open_window
src wp config set AUTH_KEY "$NO_KEY" --type=constant >/dev/null
if [ "$(src wp eval 'echo count(WpSync\SecretKey::fromConfig());')" = 0 ]; then
  jrun nokey-dry "$WPSYNC" push "$TARGET" code --activate plugins/plg-ok --dry-run --json
  eq "ohne Schlüssel, Probelauf: schon hier Exit 1, rescue_db_unavailable, no_image_key" "$RC $(last nokey-dry '[.error.reason, .error.detail] | join(" ")')" "1 rescue_db_unavailable no_image_key"
  jrun nokey "$WPSYNC" push "$TARGET" code --activate plugins/plg-ok --yes --json
  eq "ohne Schlüssel der Installation: Exit 1, rescue_db_unavailable, no_image_key" "$RC $(last nokey '[.error.reason, .error.detail] | join(" ")')" "1 rescue_db_unavailable no_image_key"
  jrun nokey-deact "$WPSYNC" push "$TARGET" code --no-code --deactivate plugins/plg-solo --yes --json
  eq "ohne Schlüssel, nur --deactivate: Exit 1, rescue_db_unavailable – ohne --require-rescue-db" "$RC $(last nokey-deact '[.error.reason, .error.detail] | join(" ")')" "1 rescue_db_unavailable no_image_key"
  no "ohne Umschlag: nichts übertragen" test -e "$WPC/plugins/plg-ok"
  eq "ohne Umschlag: nichts geändert, kein Push offen" "$(active) $(pending)" "$LIST_PULLED null"
else
  echo "SKIP: AC-181 – die Installation hat neben AUTH_KEY einen weiteren Schlüssel (SecretKey::fromConfig() nicht leer)"
fi
src wp config set AUTH_KEY "$(cat "$E2E/auth-key")" --type=constant >/dev/null
eq "ab hier hat die Installation einen Schlüssel; das Pairing gilt weiter" "$(src wp eval 'echo count(WpSync\SecretKey::fromConfig());')" 1
window 0

echo "== Belege aus Task 0, die ein laufendes WordPress brauchen"
curl -s -m 20 -o "$JSON/ajax.body" -w '%{http_code}' "$SOURCE_URL/wp-admin/admin-ajax.php" >"$JSON/ajax.code" || true
eq "Beleg Nr. 1: admin-ajax.php ohne action antwortet 400 mit „0“" "$(cat "$JSON/ajax.code") $(cat "$JSON/ajax.body")" "400 0"
ok "Beleg Nr. 3: der Core dieser Installation führt die Cache-Gruppe plugins als nicht persistent" grep -Eq "wp_cache_add_non_persistent_groups\( *array\( *'counts', *'plugins'" "$PUB/wp-includes/load.php"
eq "Beleg: der Core sortiert die Liste beim Aktivieren" "$(src wp eval '$l = (array) get_option("active_plugins"); $s = $l; sort($s); echo $l === $s ? "sortiert" : "unsortiert";')" "sortiert"

echo "== AC-199, AC-208: der Probelauf plant – auch ohne Push-Fenster"
window 0
jrun dry "$WPSYNC" push "$TARGET" code --activate plugins/plg-ok --deactivate plugins/plg-solo --dry-run --json
eq "Probelauf ohne Fenster: Exit 0" "$RC" 0
eq "Probelauf: der Plan nennt das neue Plugin mit Namen und Version" "$(event dry plan | jq -r '.plugins.activate[0] | [.unit, .state, .file, .name, .version, .requirements.checked] | join(" ")')" "plugins/plg-ok new plg-ok/plg-ok.php PLG OK 1.0.0 head"
eq "Probelauf: das abzuschaltende Plugin mit Namen, Version, Dateien" "$(event dry plan | jq -r '.plugins.deactivate[0] | [.unit, .state, .name, .version, (.files | join(","))] | join(" ")')" "plugins/plg-solo active PLG Solo 1.1 plg-solo/plg-solo.php"
eq "Probelauf: die Seite im Admin-Kontext gehört zum Health-Check" "$(event dry plan | jq -r '.plugins.health_urls[0]')" "$SOURCE_URL/wp-admin/admin-ajax.php"
eq "Probelauf: rescue_db ok" "$(event dry plan | jq -c '.rescue_db')" '{"ok":true}'
ok "Probelauf: Warnung deactivation_review" sh -c "tail -n 1 '$JSON/dry.jsonl' | jq -e '.data.warnings | index(\"deactivation_review\")'"
eq "Probelauf: nichts geändert" "$(active)" "$LIST_PULLED"
no "Probelauf: nichts übertragen" test -e "$WPC/plugins/plg-ok"

echo "== AC-179, AC-199, AC-206, AC-207: was schon der Probelauf ablehnt"
jrun dry-php "$WPSYNC" push "$TARGET" code --activate plugins/plg-php99 --dry-run --json
eq "Requires PHP 99.0: Exit 1 mit reason und Einheit" "$RC $(last dry-php '[.error.reason, .error.plugins[0].unit, .error.plugins[0].why, .error.plugins[0].needs] | join(" ")')" "1 plugins_requirements plugins/plg-php99 requires_php 99.0"
jrun dry-wp "$WPSYNC" push "$TARGET" code --activate plugins/plg-wp99 --dry-run --json
eq "Requires at least 99.0: Exit 1, requires_wp" "$RC $(last dry-wp '[.error.reason, .error.plugins[0].unit, .error.plugins[0].why, .error.plugins[0].needs] | join(" ")')" "1 plugins_requirements plugins/plg-wp99 requires_wp 99.0"
jrun dry-needs "$WPSYNC" push "$TARGET" code --activate plugins/plg-needs --dry-run --json
eq "Requires Plugins, das auf dem Ziel fehlt: Exit 1, requires_plugins" "$RC $(last dry-needs '[.error.reason, .error.plugins[0].unit, .error.plugins[0].why, .error.plugins[0].needs] | join(" ")')" "1 plugins_requirements plugins/plg-needs requires_plugins gibt-es-nicht"
jrun dry-nohead "$WPSYNC" push "$TARGET" code --activate plugins/plg-nohead --dry-run --json
eq "ohne Plugin-Kopf: plugins_invalid, no_plugin_file" "$RC $(last dry-nohead '[.error.reason, .error.plugins[0].why] | join(" ")')" "1 plugins_invalid no_plugin_file"
jrun dry-two "$WPSYNC" push "$TARGET" code --activate plugins/plg-two --dry-run --json
eq "zwei Plugin-Köpfe: plugins_invalid, ambiguous" "$RC $(last dry-two '[.error.reason, .error.plugins[0].why] | join(" ")')" "1 plugins_invalid ambiguous"
jrun dry-dep "$WPSYNC" push "$TARGET" code --no-code --deactivate plugins/plg-old --dry-run --json
eq "vorausgesetzt von plg-dep: plugins_requirements, required_by" "$RC $(last dry-dep '[.error.reason, .error.plugins[0].unit, .error.plugins[0].why] | join(" ")')" "1 plugins_requirements plugins/plg-old required_by"
for wrong in "--activate themes/e2e-theme" "--deactivate mu-plugins" "--deactivate plugins/wpsync-agent" "--activate plugins/gibt-es-nicht" "--activate plugins/plg-ok --deactivate plugins/plg-ok" "--no-code --activate plugins/plg-ok"; do
  # shellcheck disable=SC2086
  jrun dry-wrong "$WPSYNC" push "$TARGET" code $wrong --dry-run --json
  eq "falscher Aufruf ($wrong): Exit 2" "$RC" 2
done
eq "Ablehnungen: nichts geändert" "$(active)" "$LIST_PULLED"

echo "== AC-180: ohne Fenster und ohne Öffner wird nicht geschaltet"
jrun nowindow "$WPSYNC" push "$TARGET" code --activate plugins/plg-ok --yes --json
eq "ohne Fenster: Exit 40" "$RC" 40
window "$(($(date +%s) + 3600))" # ein Fenster ohne Öffner (nicht über den WP-Admin geöffnet)
jrun noopener "$WPSYNC" push "$TARGET" code --activate plugins/plg-ok --yes --json
eq "Fenster ohne Öffner: Exit 1, plugins_not_allowed" "$RC $(last noopener '.error.reason')" "1 plugins_not_allowed"
no "ohne Öffner: nichts übertragen" test -e "$WPC/plugins/plg-ok"
eq "ohne Öffner: nichts geändert, kein Push offen" "$(active) $(pending)" "$LIST_PULLED null"
# Ein Öffner ohne das Recht activate_plugins (ein Redakteur): das Fenster ist offen, geschaltet wird trotzdem nicht.
src wp eval "WpSync\\Admin::openWindow('$KEY_ID', 28800, $EDITOR_ID);" >/dev/null
jrun nocap "$WPSYNC" push "$TARGET" code --activate plugins/plg-ok --yes --json
eq "Öffner ohne activate_plugins: Exit 1, plugins_not_allowed" "$RC $(last nocap '.error.reason')" "1 plugins_not_allowed"
jrun nocap-deact "$WPSYNC" push "$TARGET" code --no-code --deactivate plugins/plg-solo --yes --json
eq "Öffner ohne activate_plugins, nur --deactivate: Exit 1, plugins_not_allowed" "$RC $(last nocap-deact '.error.reason')" "1 plugins_not_allowed"
no "Öffner ohne Recht: nichts übertragen" test -e "$WPC/plugins/plg-ok"
eq "Öffner ohne Recht: nichts geändert, kein Push offen" "$(active) $(pending)" "$LIST_PULLED null"

open_window # ab hier ein Fenster, das Benutzer 1 (Administrator) im WP-Admin geöffnet hat

echo "== AC-176, AC-177, AC-178, AC-193, AC-194: aktivieren – die Liste wie vom Core, ohne Plugin-Code im Commit"
WANT="$(want_add plg-ok/plg-ok.php)"
rm -f "$WPC/e2e-plg-loaded.log"
jrun act "$WPSYNC" push "$TARGET" code --activate plugins/plg-ok --yes --json
eq "aktivieren: Exit 0, bestätigt" "$RC $(last act '.data.status')" "0 confirmed"
ok "aktivieren: der Plan nennt die Einheit neu – nicht „bleibt auf der Site inaktiv“" sh -c "grep -qF 'plugins/plg-ok – 2 von 2 Dateien zu übertragen (neu)' '$JSON/act.err' && ! grep -F 'plugins/plg-ok –' '$JSON/act.err' | grep -qF 'inaktiv'"
eq "aktivieren: data.plugins nennt die Einheit" "$(last act '.data.plugins | [(.activated | join(",")), (.deactivated | length), (.skipped | length)] | join(" ")')" "plugins/plg-ok 0 0"
eq "AC-177: die Liste ist bytegleich zu der, die activate_plugin() schriebe" "$(active)" "$WANT"
ok "aktivieren: WordPress hält das Plugin für aktiv" src wp plugin is-active plg-ok
ok "aktivieren: der Code liegt auf der Site" test -f "$WPC/plugins/plg-ok/inc/teil.php"
ok "AC-178: das Plugin wurde danach regulär geladen (Health-Check)" test -s "$WPC/e2e-plg-loaded.log"
no "AC-178: im Request des Commits wurde es nicht geladen" grep -q 'push.*commit' "$WPC/e2e-plg-loaded.log"
eq "AC-194: die Aktivierungsroutine lief nicht (Variante A)" "$(lopt plg_ok_activated)$(q "SHOW TABLES LIKE '${PREFIX}plg_ok'")" ""
ok "AC-194: Warnung activation_hooks_skipped" sh -c "tail -n 1 '$JSON/act.jsonl' | jq -e '.data.warnings | index(\"activation_hooks_skipped\")'"
eq "aktivieren: die übrigen Optionen sind unberührt" "$(optsum)" "$OPTS_PULLED"
[ "$(optsum)" = "$OPTS_PULLED" ] || optdiff
# Beleg Nr. 2 (Task 0) am echten Core: dasselbe Plugin über WP-CLI aus- und wieder eingeschaltet ergibt Byte für Byte
# dieselbe Liste, die der Push geschrieben hat. Was die Aktivierungsroutine dabei anlegt, geht gleich wieder.
src wp plugin deactivate plg-ok >/dev/null
src wp plugin activate plg-ok >/dev/null
eq "Beleg Nr. 2: wp plugin activate schreibt bytegleich die Liste des Pushs" "$(active)" "$WANT"
eq "Beleg Nr. 2 (Gegenprobe): über den Core lief die Aktivierungsroutine" "$(lopt plg_ok_activated)" "ja"
sql -e "DELETE FROM ${PREFIX}options WHERE option_name IN ('plg_ok_activated', 'recently_activated'); DROP TABLE IF EXISTS ${PREFIX}plg_ok" || true
src wp cache flush >/dev/null 2>&1 || true
eq "AC-193: die Nacharbeiten nennen plugins_cache und rewrite_rules" "$(last act '.data.post_actions | map(select(.step == "plugins_cache" or .step == "rewrite_rules") | .ok) | join(",")')" "true,true"
ACT_ID="$(last act '.data.push_id')"
eq "V12: das Protokoll nennt die Einheit plugins mit dem Eintrag" "$(record "$ACT_ID" '.units[] | select(.path == "plugins") | .activated | join(",")')" "plg-ok/plg-ok.php"
jrun act-again "$WPSYNC" push "$TARGET" code --activate plugins/plg-ok --yes --json
eq "AC-182: ein schon aktives Plugin ist unchanged, die Liste bleibt" "$RC $(last act-again '.data.plugins.unchanged | join(",")') $(active)" "0 plugins/plg-ok $WANT"
jrun act-again-back "$WPSYNC" rollback "$TARGET" "$(last act-again '.data.push_id')" --json
eq "AC-182: die Rücknahme eines Satzes, der nichts änderte, lässt das Plugin aktiv" "$RC $(active)" "0 $WANT"

echo "== AC-184, AC-193: die Rücknahme über den Agent"
jrun act-back "$WPSYNC" rollback "$TARGET" "$ACT_ID" --json
eq "Rücknahme: Exit 0 über den Agent" "$RC $(last act-back '.data.via')" "0 agent"
eq "Rücknahme: plugins_back nennt den Eintrag" "$(last act-back '.data.plugins_back.deactivated | join(",")')" "plg-ok/plg-ok.php"
ok "AC-193: auch die Rücknahme nennt die Nacharbeit plugins_cache" sh -c "tail -n 1 '$JSON/act-back.jsonl' | jq -e '.data.post_actions | map(.step) | index(\"plugins_cache\")'"
clean "Rücknahme der Aktivierung"

echo "== AC-202, AC-204, AC-207, AC-208: deaktivieren – ohne Einheit, ohne Deaktivierungs-Hook"
WANT="$(want_drop plg-solo/plg-solo.php)"
jrun deact "$WPSYNC" push "$TARGET" code --no-code --deactivate plugins/plg-solo --yes --json
eq "deaktivieren: Exit 0" "$RC $(last deact '.data.plugins.deactivated | join(",")')" "0 plugins/plg-solo"
eq "deaktivieren: die Liste ohne den Eintrag, dicht und sortiert" "$(active)" "$WANT"
ok "deaktivieren: der Ordner bleibt auf der Site" test -f "$WPC/plugins/plg-solo/plg-solo.php"
ok "deaktivieren: Warnung deactivation_review" sh -c "tail -n 1 '$JSON/deact.jsonl' | jq -e '.data.warnings | index(\"deactivation_review\")'"
jrun deact-back "$WPSYNC" rollback "$TARGET" "$(last deact '.data.push_id')" --json
eq "deaktivieren: die Rücknahme aktiviert wieder" "$RC $(last deact-back '.data.plugins_back.reactivated | join(",")')" "0 plg-solo/plg-solo.php"
clean "Rücknahme der Deaktivierung"
# plg-old registriert eine Deaktivierungsroutine; plg-dep setzt es voraus – also beide in einem Satz.
jrun deact-hook "$WPSYNC" push "$TARGET" code --no-code --deactivate plugins/plg-dep,plugins/plg-old --yes --json
eq "AC-207: beide in einem Satz, Exit 0" "$RC" 0
eq "AC-208: die Deaktivierungsroutine lief nicht" "$(lopt plg_old_deactivated)" ""
ok "AC-208: Warnung deactivation_hooks_skipped" sh -c "tail -n 1 '$JSON/deact-hook.jsonl' | jq -e '.data.warnings | index(\"deactivation_hooks_skipped\")'"
jrun deact-hook-back "$WPSYNC" rollback "$TARGET" "$(last deact-hook '.data.push_id')" --json
clean "Rücknahme zweier Deaktivierungen"

echo "== AC-185, AC-204, AC-209: das Delta – was ein Administrator seit dem Push geschaltet hat, bleibt"
jrun both "$WPSYNC" push "$TARGET" code --activate plugins/plg-ok --deactivate plugins/plg-solo --yes --json
eq "beides in einem Satz: Exit 0" "$RC $(last both '.data.plugins | [(.activated | join(",")), (.deactivated | join(","))] | join(" ")')" "0 plugins/plg-ok plugins/plg-solo"
src wp plugin activate e2e-extra >/dev/null   # der Administrator schaltet ein fremdes Plugin ein …
src wp plugin deactivate e2e-health >/dev/null # … und eines aus
jrun both-back "$WPSYNC" rollback "$TARGET" "$(last both '.data.push_id')" --json
eq "Delta: die Rücknahme geht trotz fremder Änderungen durch" "$RC" 0
ok "Delta: was der Administrator einschaltete, bleibt aktiv" is_active e2e-extra/e2e-extra.php
no "Delta: was er ausschaltete, bleibt aus" is_active e2e-health/e2e-health.php
no "Delta: das Plugin des Pushs ist wieder aus" is_active plg-ok/plg-ok.php
ok "Delta: was der Push abschaltete, läuft wieder" is_active plg-solo/plg-solo.php
src wp plugin deactivate e2e-extra >/dev/null
src wp plugin activate e2e-health >/dev/null
eq "Delta: nach dem Zurückstellen von Hand ist die Liste die des Pulls" "$(active)" "$LIST_PULLED"
# Gegenrichtung: der Administrator hat das Plugin des Pushs schon selbst abgeschaltet.
jrun self "$WPSYNC" push "$TARGET" code --activate plugins/plg-ok --yes --json
src wp plugin deactivate plg-ok >/dev/null
SELF_LIST="$(active)" # wie deactivate_plugins() sie hinterlässt: mit einer Lücke in den Schlüsseln
jrun self-back "$WPSYNC" rollback "$TARGET" "$(last self '.data.push_id')" --json
eq "Delta: schon von Hand abgeschaltet – nichts zu tun, Exit 0" "$RC $(last self-back '.data.plugins_back.deactivated | length')" "0 0"
eq "Delta (A21): wo nichts zu tun ist, ändert die Rücknahme kein Byte der Liste – auch die Lücke des Core bleibt" "$(active)" "$SELF_LIST"
# Für den Vergleich mit dem Stand des Pulls: dieselben Einträge, dicht nummeriert, wie ein activate_plugin() sie schriebe.
src wp eval 'update_option("active_plugins", array_values((array) get_option("active_plugins")));' >/dev/null
sql -e "DELETE FROM ${PREFIX}options WHERE option_name IN ('plg_ok_activated', 'recently_activated')" || true
clean "Delta"

echo "== AC-188: ein Fatal nur im Admin-Kontext – der Health-Check sieht ihn, die Rücknahme geht über den Agent"
jrun adminfatal "$WPSYNC" push "$TARGET" code --activate plugins/plg-admin-fatal --yes --json
eq "Admin-Fatal: Exit 43, über den Agent, ohne Warnung" "$RC $(last adminfatal '[.data.status, .data.via, (.data.warnings // [] | map(select(. == "content_not_rolled_back" or . == "plugins_not_restored")) | length | tostring)] | join(" ")')" "43 rolled_back agent 0"
ok "Admin-Fatal: der Health-Check nennt admin-ajax.php" sh -c "tail -n 1 '$JSON/adminfatal.jsonl' | jq -e '.data.health | map(.url) | join(\" \") | contains(\"admin-ajax.php\")'"
eq "Admin-Fatal: admin-ajax.php antwortet wieder wie vorher" "$(code "$SOURCE_URL/wp-admin/admin-ajax.php")" 400
clean "Admin-Fatal"

echo "== AC-186: ein Fatal beim Laden – WordPress antwortet nicht mehr, rescue.php nimmt den ganzen Satz zurück"
jrun fatal "$WPSYNC" push "$TARGET" code --activate plugins/plg-fatal --yes --json
eq "Fatal: Exit 43, über rescue.php, DB-Anteil zurück" "$RC $(last fatal '[.data.status, .data.via, (.data.warnings // [] | map(select(. == "content_not_rolled_back" or . == "plugins_not_restored")) | length | tostring)] | join(" ")')" "43 rolled_back rescue 0"
eq "Fatal: plugins_back nennt den Eintrag" "$(last fatal '.data.plugins_back.deactivated | join(",")')" "plg-fatal/plg-fatal.php"
FATAL_ID="$(last fatal '.data.push_id')"
clean "Fatal"
eq "§8.4: der Agent holt nach – das Protokoll nennt die Einheit plugins mit via rescue" "$(record "$FATAL_ID" '[.status, (.units[] | select(.path == "plugins") | .via)] | join(" ")')" "rolled_back rescue"
no "Fatal: der Ordner des Pushs mit Abbildern und Umschlag ist weg" test -e "$(work)/$FATAL_ID"

echo "== AC-186 (zweiter Teil): White-Screen – die CLI ist weg, die Site unten; wpsync rollback geht über rescue.php"
hold white --activate plugins/plg-fatal
eq "White-Screen: die Site ist unten, das Plugin aktiv" "$(code "$SOURCE_URL/") $(is_active plg-fatal/plg-fatal.php && echo aktiv)" "500 aktiv"
ok "White-Screen: der Umschlag liegt im Arbeitsordner" test -s "$(work)/$HELD/rescue.sealed"
jrun white-back "$WPSYNC" rollback "$TARGET" "$HELD" --json
eq "White-Screen: Exit 0 über rescue.php, ohne Warnung" "$RC $(last white-back '[.data.via, (.data | has("warnings") | tostring)] | join(" ")')" "0 rescue false"
clean "White-Screen"
# Ein falscher Schlüssel schreibt nichts: derselbe Fall, aber rescue.php direkt mit einem fremden Key.
hold wrongkey --activate plugins/plg-fatal
RURL="$(jq -r .rescue_url "$SITE/.wpsync/pushes/$HELD.json")"
WRONG="$(curl -s -m 60 -o "$JSON/wrongkey.json" -w '%{http_code}' -X POST --data-urlencode "action=rollback" --data-urlencode "push_id=$HELD" --data-urlencode "key=$(openssl rand -hex 32)" --data-urlencode "content=1" "$RURL" || true)"
eq "falscher Rescue-Key: 403, die Liste bleibt" "$WRONG $(is_active plg-fatal/plg-fatal.php && echo aktiv)" "403 aktiv"
no "falscher Rescue-Key: die Antwort nennt keinen Eintrag der Liste" grep -q 'plg-' "$JSON/wrongkey.json"
jrun wrongkey-back "$WPSYNC" rollback "$TARGET" "$HELD" --json
clean "nach dem falschen Schlüssel"

echo "== AC-190: rescue.php ohne Umschlag – Code zurück, DB-Anteil bleibt (plugins_not_restored), der Agent schliesst danach ab"
hold kept --activate plugins/plg-fatal
src exec rm -f "/var/www/html/public/wp-content/$(basename "$(work)")/$HELD/rescue.sealed"
jrun kept-back "$WPSYNC" rollback "$TARGET" "$HELD" --json
eq "kept: Exit 0 über rescue.php, beide Warnungen" "$RC $(last kept-back '[.data.via, (.data.warnings | join(","))] | join(" ")')" "0 rescue content_not_rolled_back,plugins_not_restored"
no "kept: der Code der neuen Einheit ist zurück (weg)" test -e "$WPC/plugins/plg-fatal"
eq "kept: die Site lädt – der Eintrag zeigt auf eine Datei, die es nicht mehr gibt" "$(code "$SOURCE_URL/") $(is_active plg-fatal/plg-fatal.php && echo steht-noch)" "200 steht-noch"
jrun kept-finish "$WPSYNC" rollback "$TARGET" "$HELD" --json
eq "kept: wpsync rollback über den Agent schliesst ab" "$RC $(last kept-finish '[.data.via, (.data.plugins_back.deactivated | join(","))] | join(" ")')" "0 agent plg-fatal/plg-fatal.php"
clean "kept"

echo "== AC-205: White-Screen nach Deaktivieren – ein mu-plugin braucht das abgeschaltete Plugin"
src exec touch "$CTL_IN/needs"
eq "AC-205 Ausgang: mit dem Plugin antwortet die Site" "$(code "$SOURCE_URL/")" 200
jrun needs "$WPSYNC" push "$TARGET" code --no-code --deactivate plugins/plg-solo --yes --json
eq "AC-205: Exit 43 über rescue.php, ohne Warnung zum DB-Anteil" "$RC $(last needs '[.data.status, .data.via, (.data.warnings // [] | map(select(. == "content_not_rolled_back" or . == "plugins_not_restored")) | length | tostring)] | join(" ")')" "43 rolled_back rescue 0"
eq "AC-205: rescue.php hat das Plugin ohne WordPress wieder eingeschaltet" "$(last needs '.data.plugins_back.reactivated | join(",")') $(code "$SOURCE_URL/")" "plg-solo/plg-solo.php 200"
src exec rm -f "$CTL_IN/needs"
clean "AC-205"

echo "== AC-187: dasselbe mit persistentem Object-Cache – die CLI lässt ihn über action=cache leeren"
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
eq "Object-Cache: die Site antwortet mit dem Drop-in und hält ihn für persistent" "$(code "$SOURCE_URL/") $(src wp eval 'echo wp_using_ext_object_cache() ? "ext" : "intern";')" "200 ext"
src exec touch "$CTL_IN/needs"
jrun cache "$WPSYNC" push "$TARGET" code --no-code --deactivate plugins/plg-solo --yes --json
eq "AC-187: Exit 43 über rescue.php, ohne object_cache_stale" "$RC $(last cache '[.data.via, (.data.warnings // [] | map(select(. == "object_cache_stale" or . == "plugins_not_restored")) | length | tostring)] | join(" ")')" "43 rescue 0"
eq "AC-187: WordPress lädt wieder – mit dem Plugin, auch aus dem Cache" "$(code "$SOURCE_URL/") $(is_active plg-solo/plg-solo.php && echo aktiv)" "200 aktiv"
ok "AC-187: der Cache trägt die Liste mit dem Plugin" sh -c "grep -a -q 'plg-solo/plg-solo.php' '$WPC/e2e-object-cache.ser' || ! test -e '$WPC/e2e-object-cache.ser'"
src exec rm -f "$CTL_IN/needs" /var/www/html/public/wp-content/object-cache.php /var/www/html/public/wp-content/e2e-object-cache.ser
clean "AC-187"

echo "== AC-192: Staging – nur die Tabelle der Kopie; was die Kopie abschaltet, bleibt aus"
jrun staging-create "$WPSYNC" staging create "$TARGET" --yes --json --rps 20
[ "$RC" = 0 ] || fail "staging create (Exit $RC, siehe $JSON/staging-create.err)"
STG_URL="$(last staging-create '.data.url')"
STG_DIR="${STG_URL##*/}"
STG_PREFIX="$(q "SHOW TABLES LIKE 'stg%\_posts'" | sed 's/posts$//')"
STG_LIST="$(active "$STG_PREFIX")"
eq "Beleg Nr. 1 (Kopie): admin-ajax.php der Kopie ohne Zugangs-Cookie antwortet 403" "$(code "$STG_URL/wp-admin/admin-ajax.php")" 403
jrun stg "$WPSYNC" push "$TARGET" code --to staging --activate plugins/plg-ok,plugins/wp-rocket --deactivate plugins/plg-solo --yes --json
eq "Staging: Exit 0" "$RC $(last stg '.data.target')" "0 staging"
ok "Staging: der Hinweis für den Push nach Live nennt die Schalter" hasF "$JSON/stg.err" "code plugins/plg-ok plugins/wp-rocket --activate plugins/plg-ok,plugins/wp-rocket --deactivate plugins/plg-solo"
eq "AC-192: wp-rocket wird in der Kopie nicht aktiviert" "$(last stg '.data.plugins | [(.activated | join(",")), (.skipped | join(","))] | join(" ")')" "plugins/plg-ok plugins/wp-rocket"
ok "Staging: plg-ok ist in der Kopie aktiv" is_active plg-ok/plg-ok.php "$STG_PREFIX"
no "Staging: wp-rocket ist in der Kopie nicht aktiv" is_active wp-rocket/wp-rocket.php "$STG_PREFIX"
no "Staging: plg-solo ist in der Kopie aus" is_active plg-solo/plg-solo.php "$STG_PREFIX"
ok "Staging: der Code von wp-rocket liegt trotzdem in der Kopie" test -f "$PUB/$STG_DIR/wp-content/plugins/wp-rocket/wp-rocket.php"
eq "AC-192: die Liste von Live ist unberührt" "$(active) $(optsum)" "$LIST_PULLED $OPTS_PULLED"
no "AC-192: kein Code auf Live" test -e "$WPC/plugins/plg-ok"
jrun stg-back "$WPSYNC" rollback "$TARGET" "$(last stg '.data.push_id')" --json
eq "Staging: die Rücknahme stellt die Liste der Kopie wieder her" "$RC $(active "$STG_PREFIX")" "0 $STG_LIST"
eq "Staging: Live blieb unberührt" "$(active)" "$LIST_PULLED"
jrun staging-delete "$WPSYNC" staging delete "$TARGET" --yes --json
eq "Aufräumen: Staging-Kopie gelöscht" "$RC" 0

echo "== AC-196: ein Agent vor 0.9.0 – Exit 11, nichts übertragen"
if git -C "$ROOT" rev-parse -q --verify 'refs/tags/v0.8.0' >/dev/null; then
  rm -rf "$E2E/old"
  mkdir -p "$E2E/old"
  git -C "$ROOT" archive v0.8.0 agent LICENSE | tar -x -C "$E2E/old"
  (cd "$E2E/old/agent" && ./build.sh >/dev/null)
  cp "$E2E/old/agent/dist/wpsync-agent.zip" "$PUB/wpsync-agent-old.zip"
  src wp plugin install /var/www/html/public/wpsync-agent-old.zip --force --activate >/dev/null
  rm "$PUB/wpsync-agent-old.zip"
  open_window
  jrun old "$WPSYNC" push "$TARGET" code --activate plugins/plg-ok --yes --json
  eq "alter Agent: Exit 11, agent_outdated, verlangt 0.9.0" "$RC $(last old '[.error.code, .error.required] | join(" ")')" "11 agent_outdated 0.9.0"
  jrun old-deact "$WPSYNC" push "$TARGET" code --no-code --deactivate plugins/plg-solo --yes --json
  eq "alter Agent, nur --deactivate: Exit 11" "$RC" 11
  eq "alter Agent: nichts geändert, nichts übertragen, kein Push offen" "$(active) $(test -e "$WPC/plugins/plg-ok" && echo da) $(pending)" "$LIST_PULLED  null"
  cp "$ROOT/agent/dist/wpsync-agent.zip" "$PUB/wpsync-agent.zip"
  src wp plugin install /var/www/html/public/wpsync-agent.zip --force --activate >/dev/null
  rm "$PUB/wpsync-agent.zip"
else
  echo "SKIP: AC-196 im E2E – der Tag v0.8.0 fehlt in diesem Checkout (git fetch --tags). Deckt TestRunRefusesAnAgentThatCannotSwitchPlugins."
fi

echo "== Nicht im E2E"
echo "SKIP: AC-183 (eine Transaktion – Paketzeile scheitert im COMMIT, Verbindungsverlust), AC-191 (Sperre R10), V1 (unquittierter COMMIT): nicht stabil herstellbar – decken ContentApplyPluginsTest, PushPluginsCommitTest und ContentRollbackPluginsChangedTest."
echo "SKIP: AC-180 Multisite, MyISAM (engine_unsupported), AC-210 (Kopie schaltet das Plugin ohnehin ab): Sache des Zielsystems bzw. der Liste DISABLED_ON_STAGING – decken PushPluginsPlanTest und ContentApplyPluginsTest."
echo "SKIP: rescue.php stirbt zwischen dem COMMIT der Rücknahme und dem Vermerk in rescue.json (die Wiederholung findet dann „nichts zu tun“, ohne Cache-Schritt): ein Prozess-Tod an genau dieser Stelle ist im E2E nicht herstellbar – bekannte Grenze, README."
echo "SKIP: Beleg Nr. 1, zweiter Teil (admin-ajax.php der Kopie MIT Zugangs-Cookie antwortet 400): den Cookie holt nur der Health-Check der CLI; belegt ist, dass der Push nach Staging mit dieser Seite im Health-Check durchgeht (Exit 0)."
echo "SKIP: AC-198, AC-211 (Abnahme mit Borlabs auf vorlage): nach dem Release, Task 26."

echo "== Aufräumen auf der Quelle"
window 0
