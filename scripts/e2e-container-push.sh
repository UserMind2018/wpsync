#!/usr/bin/env bash
# E2E Container-Push: push, pushes und rollback im Container-Modus (Spec Container-Push, AC-110–131).
# Die CLI läuft als Linux-Binary in Wegwerf-Containern wie im OS-Container des Website Studios:
# push, pushes und rollback in einem Image nur mit git (kein docker, ddev, WP-CLI – AC-114), der
# Pull im Container-Modus in einem Image mit docker-CLI und Docker-Socket. Site-Ordner im
# Studio-Layout <slug>/docroot, <slug>/.wpsync, WPSYNC_CONFIG_DIR=<slug>/wpsync.
# Quelle ist ein eigenes DDEV-Projekt wpsync-e2e-cpush (apache-fpm, für Staging), die lokale Site
# ein WordPress- und ein MariaDB-Container im eigenen Netz wpsync-e2e-cpush. Die geteilten Projekte
# wpsync-e2e-source/-target und wpsync-e2e-staging/-stgtarget bleiben unberührt.
#
# Voraussetzung: Docker (OrbStack), DDEV, jq, openssl, Go. Dauer rund 15 Minuten.
# Eine fehlgeschlagene Prüfung zählt und der Lauf geht weiter; nur was den Rest sinnlos macht,
# bricht ab. Die JSON-Zeilen liegen danach unter ~/wpsync-e2e/cpush/json.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E2E="${WPSYNC_E2E_DIR:-$HOME/wpsync-e2e}/cpush"
SOURCE_NAME=wpsync-e2e-cpush
SRC="$E2E/source"
PUB="$SRC/public"
WPC="$PUB/wp-content"
SLUG=vorlage
SITE="$E2E/studio/$SLUG"   # Site-Ordner im Layout des Studios
DOCROOT="$SITE/docroot"
CONFIG="$SITE/wpsync"      # WPSYNC_CONFIG_DIR
MACSITES="$E2E/mac-sites"  # WPSYNC_SITES_DIR: muss leer bleiben (AC-115)
GITDIR="$SITE/.wpsync/history.git"
BASE="$SITE/.wpsync/baseline.json"
STAMPS="$SITE/.wpsync/staging-base.json"
BIN="$E2E/bin/wpsync"      # Linux-Binary, eigener Build
JSON="$E2E/json"
NET=wpsync-e2e-cpush
DB=wpsync-e2e-cpush-db
WP=ws-e2e-cpush-vorlage
LOCK=wpsync-e2e-cpush-lock
RUNNER=wpsync-e2e-cpush-run
PORT=18083
LOCAL_URL="http://localhost:$PORT"
IMG_GIT=wpsync-e2e-cpush-git # alpine + git: push, pushes, rollback
IMG_CLI=wpsync-e2e-cpush-cli # docker-CLI + git: doctor --server, pull im Container-Modus
THEME_REL=themes/e2e-child
THEME="$DOCROOT/wp-content/$THEME_REL"
HEALTH_REL=plugins/e2e-health
HEALTH="$DOCROOT/wp-content/$HEALTH_REL/e2e-health.php"
DEVICE=agentic-os-e2e
PHP=8.3

CHECKS=0
FAILED=0
RC=0
KEY_ID=""
SECRET=""
DBPASS=""
MARK=1

pass() { CHECKS=$((CHECKS + 1)); }
bad() { CHECKS=$((CHECKS + 1)); FAILED=$((FAILED + 1)); echo "FAIL: $*"; }
fail() { echo "FAIL: $*"; exit 1; }
eq() { if [ "$2" = "$3" ]; then pass; else bad "$1 (ist: $2, soll: $3)"; fi; } # eq <was> <ist> <soll>
ok() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then pass; else bad "$what"; fi; }
no() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$what"; else pass; fi; }
hasF() { grep -qF -- "$2" "$1"; } # hasF <datei> <text>
match() { if printf %s "$2" | grep -Eq -- "$3"; then pass; else bad "$1 (ist: $2)"; fi; } # match <was> <ist> <regex>
stubs() { find "$PUB" -maxdepth 1 -name 'wpsync-rescue-*.php' | wc -l | tr -d ' '; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
last() { tail -n 1 "$JSON/$1.jsonl" | jq -r "$2"; } # last <name> <jq>: über der Ergebniszeile
event() { jq -c "select(.event == \"$2\") | .data" "$JSON/$1.jsonl" | tail -n 1; } # event <name> <event>
window() { (cd "$SRC" && ddev wp eval "WpSync\\Store::setPushUntil('$KEY_ID', $1);" >/dev/null); }
pushes_on_site() { (cd "$SRC" && ddev wp eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM " . WpSync\Store::table("pushes"));'); }
commits() { git --git-dir="$GITDIR" rev-list --count HEAD; }
unit_base() { jq -c --arg p "wp-content/$1/" '.files | with_entries(select(.key | startswith($p)))' "$BASE"; } # unit_base <einheit>
mark() { # mark <n>: lokaler Stand des Themes
  sed -i '' -E "s/e2e-marker v[0-9]+/e2e-marker v$1/" "$THEME/index.php"
  MARK="$1"
}
live_mark() { grep -o 'e2e-marker v[0-9]*' "$WPC/$THEME_REL/index.php"; }

# ws: wpsync im Wegwerf-Container ohne docker, ddev und WP-CLI; stdin wird durchgereicht.
ws() {
  docker run --rm -i --init ${WS_NAME:+--name "$WS_NAME"} --network ddev_default \
    --add-host "$SOURCE_HOST:$ROUTER_IP" -v "$E2E:$E2E" \
    -e WPSYNC_CONFIG_DIR="$CONFIG" -e WPSYNC_SITES_DIR="$MACSITES" "$IMG_GIT" "$BIN" "$@"
}
# wsd: wie ws, mit docker-CLI und Docker-Socket (gleicher Pfad auf dem Host und im Container).
wsd() {
  docker run --rm -i --init --network ddev_default --add-host "$SOURCE_HOST:$ROUTER_IP" \
    -v /var/run/docker.sock:/var/run/docker.sock -v "$E2E:$E2E" \
    -e WPSYNC_CONFIG_DIR="$CONFIG" -e WPSYNC_SITES_DIR="$MACSITES" "$IMG_CLI" "$BIN" "$@"
}
s1() { printf '%s\n' "$SECRET" | ws "$@"; }               # Secret als Zeile 1 von stdin
s2() { printf '%s\n%s\n' "$SECRET" "$DBPASS" | wsd "$@"; } # Pull: Secret und DB-Passwort
jrun() { # jrun <name> <befehl…>: stdout nach $JSON/<name>.jsonl, stderr nach .err, Exit nach RC
  local name="$1"
  shift
  set +e
  "$@" >"$JSON/$name.jsonl" 2>"$JSON/$name.err"
  RC=$?
  set -e
  # Jede Zeile gültiges JSON, die letzte das Ergebnis mit demselben Exit-Code.
  if jq -e -R 'fromjson | type == "object"' "$JSON/$name.jsonl" >/dev/null 2>&1 \
    && [ "$(last "$name" '[.event, .exit_code] | @tsv')" = "result"$'\t'"$RC" ]; then
    pass
  else
    bad "$name: stdout ist nicht zeilenweise JSON mit passendem Ergebnis (Exit $RC)"
    cat "$JSON/$name.jsonl" "$JSON/$name.err"
  fi
}
wait_for() { # wait_for <datei> <text>: höchstens 60 s
  local i
  for i in $(seq 600); do
    if grep -qF -- "$2" "$1" 2>/dev/null; then return 0; fi
    sleep 0.1
  done
  return 1
}
finish() {
  local rc=$?
  trap - EXIT
  set +e
  docker rm -f "$LOCK" "$RUNNER" "$WP" "$DB" >/dev/null 2>&1
  docker network rm "$NET" >/dev/null 2>&1
  if [ -f "$SRC/.ddev/config.yaml" ] && cd "$SRC"; then
    rm -f "$WPC/mu-plugins/e2e-fatal.php"
    rm -f "$WPC/plugins/.htaccess"
    [ ! -f "$WPC/plugins/wpsync-agent/rescue.php.off" ] || mv "$WPC/plugins/wpsync-agent/rescue.php.off" "$WPC/plugins/wpsync-agent/rescue.php"
    chmod u+w "$WPC/themes"
    [ -z "$KEY_ID" ] || window 0 2>/dev/null
  fi
  unset SECRET DBPASS
  echo
  if [ "$rc" != 0 ]; then
    echo "E2E Container-Push ABGEBROCHEN (Exit $rc) – $CHECKS Prüfungen bis dahin, $FAILED FAIL"
    exit "$rc"
  fi
  if [ "$FAILED" != 0 ]; then
    echo "E2E Container-Push: $CHECKS Prüfungen, $FAILED FAIL"
    exit 1
  fi
  echo "E2E Container-Push OK – $CHECKS Prüfungen grün, 0 FAIL"
}
trap finish EXIT

command -v jq >/dev/null || fail "jq fehlt"
docker rm -f "$LOCK" "$RUNNER" "$WP" "$DB" >/dev/null 2>&1 || true
docker network rm "$NET" >/dev/null 2>&1 || true
rm -rf "$E2E/studio" "$MACSITES" "$JSON" "$E2E/bin"
mkdir -p "$E2E/bin" "$SRC/public" "$CONFIG" "$DOCROOT" "$MACSITES" "$JSON"
(cd "$ROOT/agent" && ./build.sh)
ARCH="$(docker version -f '{{.Server.Arch}}')"
(cd "$ROOT/cli" && CGO_ENABLED=0 GOOS=linux GOARCH="$ARCH" go build -trimpath -o "$BIN" ./cmd/wpsync)
docker build -q -t "$IMG_GIT" - >/dev/null <<'DOCKERFILE'
FROM alpine:3.20
RUN apk add --no-cache git
DOCKERFILE
docker build -q -t "$IMG_CLI" - >/dev/null <<'DOCKERFILE'
FROM docker:27-cli
RUN apk add --no-cache git
DOCKERFILE
eq "AC-114: das Push-Image hat weder docker noch ddev noch WP-CLI" \
  "$(docker run --rm "$IMG_GIT" sh -c 'command -v docker ddev wp || true')" ""

echo "== Quelle (DDEV, Apache, PHP $PHP)"
cd "$SRC"
if [ ! -f .ddev/config.yaml ]; then
  ddev config --project-name="$SOURCE_NAME" --project-type=wordpress --docroot=public \
    --php-version="$PHP" --database=mariadb:10.11 --webserver-type=apache-fpm --performance-mode=none
fi
ddev start -y
if ! ddev wp core is-installed >/dev/null 2>&1; then
  ddev wp core download --force
  sed -i '' 's/#ddev-generated//' public/wp-config.php
  ddev wp core install --url="$(ddev describe -j | jq -r '.raw.httpurl')" --title="wpsync Container-Push E2E" \
    --admin_user=admin --admin_password=admin --admin_email=e2e@example.invalid --skip-email
fi
ddev wp config set WPSYNC_ALLOW_HTTP true --raw --type=constant
ddev wp config set DISABLE_WP_CRON true --raw --type=constant
SOURCE_URL="$(ddev describe -j | jq -r '.raw.httpurl')"
SOURCE_HOST="${SOURCE_URL#http://}"
ROUTER_IP="$(docker inspect -f '{{with index .NetworkSettings.Networks "ddev_default"}}{{.IPAddress}}{{end}}' ddev-router)"
[ -n "$ROUTER_IP" ] || fail "ddev-router nicht im Netz ddev_default"
WPVER="$(ddev wp core version)"

echo "== Fixtures"
mkdir -p "$WPC/$THEME_REL" "$WPC/$HEALTH_REL" "$WPC/plugins/e2e-gone"
printf '/*\nTheme Name: E2E Child\nVersion: 1.0\n*/\n' > "$WPC/$THEME_REL/style.css"
cat > "$WPC/$THEME_REL/index.php" <<'PHP'
<!doctype html><html><head><?php wp_head(); ?></head><body><p>e2e-marker v1</p><?php wp_footer(); ?></body></html>
PHP
printf '<?php\n/* Plugin Name: E2E Health\n * Version: 1.0 */\n' > "$WPC/$HEALTH_REL/e2e-health.php"
printf '<?php\n/* Plugin Name: E2E Gone\n * Version: 1.0 */\n' > "$WPC/plugins/e2e-gone/e2e-gone.php"
rm -rf "$WPC/mu-plugins" "$WPC/plugins/e2e-new"
ddev wp theme activate e2e-child
ddev wp plugin activate e2e-health

echo "== Agent"
cp "$ROOT/agent/dist/wpsync-agent.zip" public/wpsync-agent.zip
ddev wp plugin install /var/www/html/public/wpsync-agent.zip --force --activate
rm public/wpsync-agent.zip
# Reste früherer Läufe: Kopie, offene Pushes, Sperren, Pairings.
ddev wp eval 'WpSync\Staging::uninstall(); WpSync\Push::uninstall(); global $wpdb; $wpdb->query("DELETE FROM " . WpSync\Store::table("pushes")); $wpdb->query("DELETE FROM " . WpSync\Store::table("pairings")); WpSync\Store::setState("push_lock", null);'
find "$PUB" -maxdepth 1 -name 'wpsync-staging-*' -exec rm -rf {} +

echo "== Studio-Layout, Pairing, Scan"
wsd doctor --server --json > "$JSON/doctor.jsonl" || fail "doctor --server"
GUARD="$CONFIG/mailguard/00-local-mailguard.php"
[ -s "$GUARD" ] || fail "doctor --server hat den Mail-Riegel nicht abgelegt"
CODE="$(ddev wp wpsync pair-code | tail -1)"
ws pair "$SOURCE_URL" "$CODE" --name "$SLUG" --device "$DEVICE" --insecure --json --secret-out > "$E2E/pair.tmp" \
  || { cat "$E2E/pair.tmp"; fail "pair"; }
SECRET="$(tail -n 1 "$E2E/pair.tmp" | jq -r '.data.secret')"
tail -n 1 "$E2E/pair.tmp" | jq -c 'del(.data.secret)' > "$JSON/pair.jsonl"
rm -f "$E2E/pair.tmp"
[ -n "$SECRET" ] && [ "$SECRET" != null ] || fail "kein Secret aus pair --secret-out"
KEY_ID="$(awk '/^key_id:/ { print $2 }' "$CONFIG/sites/$SLUG.yaml")"
eq "C8: pair merkt sich das Gerät" "$(awk '/^device:/ { print $2 }' "$CONFIG/sites/$SLUG.yaml")" "$DEVICE"
jrun scan s1 scan "$SLUG" --json --secret-stdin --preset vollstaendig --uploads-since alle
[ "$RC" = 0 ] || fail "scan (Exit $RC)"

echo "== Lokale Site: WordPress- und DB-Container, Erst-Pull im Container-Modus"
docker run --rm --user 33:33 -v "$DOCROOT:/var/www/html" "wordpress:cli-php$PHP" \
  wp core download --version="$WPVER" --skip-content --force >/dev/null
DBPASS="$(openssl rand -hex 16)"
docker network create "$NET" >/dev/null
MARIADB_PASSWORD="$DBPASS" docker run -d --name "$DB" --network "$NET" -e MARIADB_RANDOM_ROOT_PASSWORD=1 \
  -e MARIADB_DATABASE=e2e_cpush -e MARIADB_USER=e2e_cpush -e MARIADB_PASSWORD mariadb:11 >/dev/null
until docker exec "$DB" healthcheck.sh --connect >/dev/null 2>&1; do sleep 1; done
WORDPRESS_DB_PASSWORD="$DBPASS" docker run -d --name "$WP" --network "$NET" \
  --label um.website-studio=1 --label um.env=e2e --label um.slug="$SLUG" -p "127.0.0.1:$PORT:80" \
  -v "$DOCROOT:/var/www/html" -v "$GUARD:/var/www/html/wp-content/mu-plugins/00-local-mailguard.php:ro" \
  -e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_NAME=e2e_cpush -e WORDPRESS_DB_USER=e2e_cpush \
  -e WORDPRESS_DB_PASSWORD -e WORDPRESS_TABLE_PREFIX=wp_ "wordpress:php$PHP-apache" >/dev/null
until [ -f "$DOCROOT/wp-config.php" ]; do sleep 1; done
PULL=(pull "$SLUG" --json --yes --secret-stdin --driver container --container "$WP" --docroot "$DOCROOT"
  --db-host "$DB" --db-name e2e_cpush --db-user e2e_cpush --local-url "$LOCAL_URL")
jrun pull-first s2 "${PULL[@]}"
[ "$RC" = 0 ] || fail "Erst-Pull im Container-Modus (Exit $RC)"
[ -f "$THEME/index.php" ] && hasF "$THEME/index.php" "e2e-marker v1" || fail "Theme nicht gezogen"
COMMITS0="$(commits)"
C=(--secret-stdin --driver container --docroot "$DOCROOT") # Schalter des Container-Modus

echo "== AC-112: falsch kombinierte Schalter"
i=0
for args in \
  "push $SLUG code --driver container --docroot $DOCROOT" \
  "push $SLUG code --secret-stdin" \
  "push $SLUG code --secret-stdin --driver container" \
  "push $SLUG code --secret-stdin --driver container --docroot relativ" \
  "push $SLUG code --secret-stdin --driver container --docroot $DOCROOT --container $WP" \
  "push $SLUG code --secret-stdin --driver container --docroot $DOCROOT --db-host $DB" \
  "pushes $SLUG --secret-stdin" \
  "rollback $SLUG --secret-stdin --driver container --docroot $DOCROOT"; do
  i=$((i + 1))
  # shellcheck disable=SC2086 # die Zeile ist absichtlich in Wörter zerlegt
  jrun "usage-$i" s1 $args --json
  eq "AC-112 $args: Exit 2" "$RC" 2
  eq "AC-112 $args: ohne data" "$(last "usage-$i" '.data')" null
done

echo "== Lokale Änderungen: Theme v2, neues Plugin (nicht genannt), Plugin lokal gelöscht"
mark 2
mkdir -p "$DOCROOT/wp-content/plugins/e2e-new"
printf '<?php\n/* Plugin Name: E2E New */\n' > "$DOCROOT/wp-content/plugins/e2e-new/e2e-new.php"
rm -rf "$DOCROOT/wp-content/plugins/e2e-gone"

echo "== AC-119: Probelauf ohne Fenster"
window 0
PUSHES0="$(pushes_on_site)"
jrun push-dry-run s1 push "$SLUG" code "${C[@]}" --dry-run --json
eq "AC-119 Probelauf: Exit 0" "$RC" 0
eq "AC-119 Probelauf: status" "$(last push-dry-run '.data.status')" dry_run
eq "AC-119 plan.window_open" "$(event push-dry-run plan | jq -r '.window_open')" false
eq "AC-119 plan.skipped_new" "$(event push-dry-run plan | jq -c '.skipped_new')" '["plugins/e2e-new"]'
eq "AC-119 plan.missing_locally" "$(event push-dry-run plan | jq -c '.missing_locally')" '["plugins/e2e-gone"]'
eq "AC-119 Probelauf ändert die Site nicht" "$(live_mark)" "e2e-marker v1"

echo "== AC-120: geschlossenes Fenster – Exit 40 mit admin_url und device"
jrun push-window-closed-40 s1 push "$SLUG" code "${C[@]}" --yes --json
eq "AC-120 Exit 40" "$RC" 40
eq "AC-120 error.admin_url" "$(last push-window-closed-40 '.error.admin_url')" "$SOURCE_URL/wp-admin/tools.php?page=wpsync"
eq "AC-120 error.device" "$(last push-window-closed-40 '.error.device')" "$DEVICE"
eq "AC-120 kein Push auf der Site" "$(pushes_on_site)" "$PUSHES0"
jrun staging-open-window-40 s1 staging open "$SLUG" --secret-stdin --json
eq "C8 staging open ohne Fenster: Exit 40" "$RC" 40
eq "C8 staging open: error.device" "$(last staging-open-window-40 '.error.device')" "$DEVICE"
window "time() + 28800"

echo "== AC-113/S-9: keine Rückfrage über stdin"
set +e
printf '%s\nj\n' "$SECRET" | ws push "$SLUG" code "${C[@]}" >"$E2E/push-no-yes.log" 2>&1
RC=$?
set -e
eq "AC-113 ohne --yes: Exit 2, die zweite stdin-Zeile bestätigt nichts" "$RC" 2
eq "AC-113 kein Push auf der Site" "$(pushes_on_site)" "$PUSHES0"

echo "== AC-117: Staging im Container-Modus"
cp "$BASE" "$E2E/baseline.before-staging"
jrun staging-create s1 staging create "$SLUG" --secret-stdin --yes --json
[ "$RC" = 0 ] || fail "staging create (Exit $RC)"
SC="$(find "$PUB" -maxdepth 1 -name 'wpsync-staging-*' | head -n 1)"
jrun push-staging s1 push "$SLUG" code "$THEME_REL" --to staging "${C[@]}" --yes --json
eq "AC-117 Push nach Staging: Exit 0" "$RC" 0
eq "AC-117 Ziel und Status" "$(last push-staging '.data.target + " " + .data.status')" "staging confirmed"
ok "AC-117 Kopie hat den lokalen Stand" grep -q 'e2e-marker v2' "$SC/wp-content/$THEME_REL/index.php"
eq "AC-117 Live unverändert" "$(live_mark)" "e2e-marker v1"
ok "AC-117 Baseline unverändert" cmp -s "$E2E/baseline.before-staging" "$BASE"
eq "AC-117 history.git unverändert" "$(commits)" "$COMMITS0"
ok "AC-117 Stempel im Site-Ordner" test -s "$STAMPS"
mark 3
jrun push-staging-second s1 push "$SLUG" code "$THEME_REL" --to staging "${C[@]}" --yes --json
eq "AC-117 zweiter Push nach Staging ohne --force: Exit 0" "$RC" 0
ok "AC-117 Kopie hat v3" grep -q 'e2e-marker v3' "$SC/wp-content/$THEME_REL/index.php"

echo "== AC-115/AC-121: Push nach Live"
BEFORE_LIVE="$(unit_base "$THEME_REL")"
jrun push-live s1 push "$SLUG" code "$THEME_REL" "${C[@]}" --yes --json
eq "AC-115 Push nach Live: Exit 0" "$RC" 0
PUSH_A="$(last push-live '.data.push_id')"
eq "AC-115 Status" "$(last push-live '.data.status')" confirmed
RESCUE_A="$(last push-live '.data.rescue_url')"
match "AC-121/AC-132 rescue_url ist der Stub im Webroot" "$RESCUE_A" "^$SOURCE_URL/wpsync-rescue-[a-f0-9]{32}\.php\$"
eq "AC-134 Stub bleibt nach confirm 10 Minuten" "$(stubs)" 1
eq "AC-127 Felder des Ergebnisses" "$(last push-live '.data | keys | join(" ")')" "push_id rescue_url status target units"
ok "AC-115 Live hat den lokalen Stand" cmp -s "$THEME/index.php" "$WPC/$THEME_REL/index.php"
no "AC-115 Baseline fortgeschrieben" test "$(unit_base "$THEME_REL")" = "$BEFORE_LIVE"
ok "AC-115 Commit in history.git" sh -c "git --git-dir='$GITDIR' log --format=%s | grep -q 'push $PUSH_A'"
eq "AC-115 nichts unter WPSYNC_SITES_DIR" "$(find "$MACSITES" -mindepth 1 | wc -l | tr -d ' ')" 0
ok "AC-115 Journal im Site-Ordner" test -s "$SITE/.wpsync/pushes/$PUSH_A.json"
jrun pushes s1 pushes "$SLUG" "${C[@]}" --json
eq "AC-121 pushes: eigener Push mit Journal" \
  "$(last pushes ".data.pushes[] | select(.push_id == \"$PUSH_A\") | [.journal, .rescue_url] | @tsv")" \
  "true"$'\t'"$RESCUE_A"
STG_ID="$(last push-staging-second '.data.push_id')"
mv "$SITE/.wpsync/pushes/$STG_ID.json" "$E2E/stg-journal.json" # wie ein Push von einem anderen Gerät
sleep 5 # Bind-Mount-Cache (OrbStack): der Container soll das verschobene Journal nicht mehr sehen
jrun pushes-foreign s1 pushes "$SLUG" "${C[@]}" --json
eq "AC-121 pushes: fremder Push ohne Journal und ohne URL" \
  "$(last pushes-foreign ".data.pushes[] | select(.push_id == \"$STG_ID\") | [.journal, has(\"rescue_url\")] | @tsv")" \
  "false"$'\t'"false"
mv "$E2E/stg-journal.json" "$SITE/.wpsync/pushes/$STG_ID.json"

echo "== AC-129 (lokal): Folge-Pull überträgt aus der Einheit nichts"
jrun status-after-push s1 status "$SLUG" --json --secret-stdin --driver container --docroot "$DOCROOT"
eq "AC-129 status: Theme nicht geändert" \
  "$(last status-after-push "[.data.files_changed[] | select(startswith(\"wp-content/$THEME_REL/\"))] | length")" 0
jrun pull-after-push s2 "${PULL[@]}"
eq "AC-129 Folge-Pull: Exit 0" "$RC" 0
ok "AC-129 lokaler Stand bleibt" grep -q "e2e-marker v$MARK" "$THEME/index.php"

echo "== AC-131 (Smoke): history.git enthält nur Code und Baseline"
eq "AC-131 Pfade in history.git" \
  "$(git --git-dir="$GITDIR" ls-tree -r --name-only HEAD | grep -cvE '^(\.gitignore|\.wpsync/baseline\.json|docroot/wp-content/.+)$' || true)" 0

echo "== AC-126 target_mismatch, AC-124: rollback <id>"
jrun rollback-target-mismatch s1 rollback "$SLUG" "$PUSH_A" --to staging "${C[@]}" --json
eq "AC-126 rollback --to staging eines Live-Pushs: Exit 1" "$RC" 1
eq "AC-126 reason target_mismatch" "$(last rollback-target-mismatch '.error.reason')" target_mismatch
jrun rollback s1 rollback "$SLUG" "$PUSH_A" "${C[@]}" --json
eq "AC-124 rollback <id>: Exit 0" "$RC" 0
eq "AC-124 Status" "$(last rollback '.data.push_id + " " + .data.status')" "$PUSH_A rolled_back"
eq "AC-124 Live wieder v1" "$(live_mark)" "e2e-marker v1"
eq "AC-124 Baseline der Einheit zurückgesetzt" "$(unit_base "$THEME_REL")" "$BEFORE_LIVE"
ok "AC-124 Rollback in history.git" sh -c "git --git-dir='$GITDIR' log -1 --format=%s | grep -q 'rollback $PUSH_A'"

echo "== AC-122: Konflikt"
cp -p "$WPC/$THEME_REL/style.css" "$E2E/style.css.live"
printf '/* auf dem Server geändert */\n' >> "$WPC/$THEME_REL/style.css"
jrun push-conflict-41 s1 push "$SLUG" code "$THEME_REL" "${C[@]}" --yes --json
eq "AC-122 Exit 41" "$RC" 41
eq "AC-122 Konflikt im plan" "$(event push-conflict-41 plan | jq -c '[.units[].conflicts[]]')" '["style.css"]'
cp -p "$E2E/style.css.live" "$WPC/$THEME_REL/style.css"

echo "== AC-126: Gründe bei Exit 1"
jrun push-nothing s1 push "$SLUG" code "$HEALTH_REL" "${C[@]}" --yes --json
eq "AC-126 nichts zu pushen: Exit 1" "$RC" 1
eq "AC-126 reason nothing_to_push" "$(last push-nothing '.error.reason')" nothing_to_push
mv "$WPC/plugins/wpsync-agent/rescue.php" "$WPC/plugins/wpsync-agent/rescue.php.off"
jrun push-rescue-unreachable s1 push "$SLUG" code "$THEME_REL" "${C[@]}" --yes --json
eq "AC-126 rescue.php fehlt: Exit 1" "$RC" 1
eq "AC-126 reason rescue_unreachable" "$(last push-rescue-unreachable '.error.reason')" rescue_unreachable
mv "$WPC/plugins/wpsync-agent/rescue.php.off" "$WPC/plugins/wpsync-agent/rescue.php"
chmod a-w "$WPC/themes"
if (cd "$SRC" && ddev exec test -w /var/www/html/public/wp-content/themes); then
  echo "SKIP: AC-126 not_writable – der Mount der Quelle ignoriert die Rechte (Unit-Test deckt den Fall)"
else
  jrun push-not-writable s1 push "$SLUG" code "$THEME_REL" "${C[@]}" --yes --json
  eq "AC-126 Verzeichnis nicht ersetzbar: Exit 1" "$RC" 1
  eq "AC-126 reason not_writable" "$(last push-not-writable '.error.reason')" not_writable
fi
chmod u+w "$WPC/themes"

echo "== AC-123: Syntaxfehler wird über den Health-Check aus dem Container zurückgerollt"
cp -p "$HEALTH" "$E2E/e2e-health.good"
printf '\nthis is not php(\n' >> "$HEALTH"
jrun push-rolled-back-43 s1 push "$SLUG" code "$HEALTH_REL" "${C[@]}" --yes --json
eq "AC-123 Live: Exit 43" "$RC" 43
eq "AC-123 Live: Status" "$(last push-rolled-back-43 '.data.status')" rolled_back
no "AC-123 kaputter Code auf Live" grep -q "this is not php" "$WPC/$HEALTH_REL/e2e-health.php"
eq "AC-123 Live erreichbar" "$(code "$SOURCE_URL/")" 200
jrun push-staging-rolled-back-43 s1 push "$SLUG" code "$HEALTH_REL" --to staging "${C[@]}" --yes --json
eq "AC-123 Staging: Exit 43" "$RC" 43
no "AC-123 kaputter Code auf Staging" grep -q "this is not php" "$SC/wp-content/$HEALTH_REL/e2e-health.php"
cp -p "$E2E/e2e-health.good" "$HEALTH"

echo "== AC-116: Schnappschuss scheitert – Push bleibt Exit 0 mit Warnung"
mv "$GITDIR/HEAD" "$E2E/HEAD.e2e"
ln -s "$E2E/HEAD.e2e" "$GITDIR/HEAD" # sanitizeRepo führt in einem Repo mit Symlink kein git aus
jrun push-snapshot-failed s1 push "$SLUG" code "$THEME_REL" "${C[@]}" --yes --json
eq "AC-116 Exit 0" "$RC" 0
eq "AC-116 warnings" "$(last push-snapshot-failed '.data.warnings | join(",")')" snapshot_failed
ok "AC-116 Journal angewandt" sh -c "jq -e .applied '$SITE/.wpsync/pushes/$(last push-snapshot-failed .data.push_id).json'"
ok "AC-116 Live hat den Stand" grep -q "e2e-marker v$MARK" "$WPC/$THEME_REL/index.php"
rm "$GITDIR/HEAD"
mv "$E2E/HEAD.e2e" "$GITDIR/HEAD"

echo "== B1/AC-132/AC-134: PHP unter wp-content/plugins gesperrt wie iThemes Security"
cat > "$WPC/plugins/.htaccess" <<'EOF'
# wie iThemes/Solid Security „Disable PHP in Plugins“
<FilesMatch "\.(php[1-7]?|pht|phtml?|phps)$">
Require all denied
</FilesMatch>
EOF
eq "B1 Sperre greift" "$(code -X POST --data action=ping "$SOURCE_URL/wp-content/plugins/wpsync-agent/rescue.php")" 403
mark 31
jrun push-b1 s1 push "$SLUG" code "$THEME_REL" "${C[@]}" --yes --json
eq "AC-132 Push trotz Sperre: Exit 0" "$RC" 0
eq "AC-132 Status" "$(last push-b1 '.data.status')" confirmed
ok "AC-132 Live hat v31" grep -q 'e2e-marker v31' "$WPC/$THEME_REL/index.php"
B1_URL="$(last push-b1 '.data.rescue_url')"
match "AC-132 rescue_url" "$B1_URL" "^$SOURCE_URL/wpsync-rescue-[a-f0-9]{32}\.php\$"
eq "AC-132 Stub antwortet trotz Sperre" "$(curl -s -X POST --data action=ping "$B1_URL")" '{"ok":true}'
no "AC-135 Stub nennt keinen Serverpfad" grep -q "$PUB" "$PUB/$(basename "$B1_URL")"
# 10 Minuten vorspulen: der Stub-Zeitstempel wird zurückdatiert, dann räumt der Cron-Lauf auf.
(cd "$SRC" && ddev wp eval '$s = WpSync\Store::getState("rescue_stub"); $s["touched"] = time() - 700; WpSync\Store::setState("rescue_stub", $s); WpSync\Push::maintain();' >/dev/null)
eq "AC-134 Stub nach dem Aufräumen weg" "$(stubs)" 0
eq "AC-134 Notfallweg danach 404" "$(code -X POST --data action=ping "$B1_URL")" 404
rm "$WPC/plugins/.htaccess"

echo "== S-8: der Mail-Riegel geht nie nach Live"
mkdir -p "$DOCROOT/wp-content/mu-plugins"
printf '<?php // e2e mu\n' > "$DOCROOT/wp-content/mu-plugins/e2e-mu.php"
jrun push-mu-plugins s1 push "$SLUG" code mu-plugins "${C[@]}" --yes --json
eq "S-8 Push mu-plugins: Exit 0" "$RC" 0
ok "S-8 e2e-mu.php auf Live" test -f "$WPC/mu-plugins/e2e-mu.php"
no "S-8 Mail-Riegel auf Live" test -e "$WPC/mu-plugins/00-local-mailguard.php"
ok "Schnappschuss wieder möglich" sh -c "git --git-dir='$GITDIR' log -1 --format=%s | grep -q 'push $(last push-mu-plugins .data.push_id)'"

echo "== AC-118: Pull und Push teilen die Sperre <slug>/.wpsync/lock"
docker run -d --rm --name "$LOCK" -v "$E2E:$E2E" "$IMG_GIT" flock "$SITE/.wpsync/lock" sleep 120 >/dev/null
sleep 1
mark 4
jrun push-locked s1 push "$SLUG" code "$THEME_REL" "${C[@]}" --yes --json
eq "AC-118 push während eines Vorgangs: Exit 20" "$RC" 20
eq "AC-118 reason site_locked" "$(last push-locked '.error.reason')" site_locked
jrun pull-locked s2 "${PULL[@]}"
eq "AC-118 pull während eines Vorgangs: Exit 20" "$RC" 20
eq "AC-118 pull: reason site_locked" "$(last pull-locked '.error.reason')" site_locked
docker rm -f "$LOCK" >/dev/null

echo "== AC-125: SIGTERM vor dem Begin – Exit 30, kein Push"
PUSHES1="$(pushes_on_site)"
set +e
printf '%s\n' "$SECRET" | WS_NAME="$RUNNER" ws push "$SLUG" code "$THEME_REL" "${C[@]}" --yes --json \
  >"$JSON/push-term-before.jsonl" 2>"$JSON/push-term-before.err" &
PID=$!
set -e
wait_for "$JSON/push-term-before.jsonl" '"event":"plan"' || bad "AC-125 kein plan"
docker kill --signal TERM "$RUNNER" >/dev/null
set +e
wait "$PID"
RC=$?
set -e
eq "AC-125 Exit 30" "$RC" 30
eq "AC-125 interrupted" "$(last push-term-before '.error.code')" interrupted
eq "AC-125 kein Push auf der Site" "$(pushes_on_site)" "$PUSHES1"

echo "== AC-125: SIGTERM nach dem Commit – der Push läuft zu Ende"
set +e
printf '%s\n' "$SECRET" | WS_NAME="$RUNNER" ws push "$SLUG" code "$THEME_REL" "${C[@]}" --yes --json \
  >"$JSON/push-term-after.jsonl" 2>"$JSON/push-term-after.err" &
PID=$!
set -e
wait_for "$JSON/push-term-after.jsonl" '"event":"commit"' || bad "AC-125 kein commit"
docker kill --signal TERM "$RUNNER" >/dev/null 2>&1 || true
set +e
wait "$PID"
RC=$?
set -e
eq "AC-125 nach dem Commit: Exit 0" "$RC" 0
eq "AC-125 bestätigt" "$(last push-term-after '.data.status')" confirmed
ok "AC-125 Live hat v4" grep -q 'e2e-marker v4' "$WPC/$THEME_REL/index.php"

echo "== O5/AC-124: SIGKILL nach dem Commit, Rollback über rescue.php bei stummem WordPress"
mark 5
set +e
printf '%s\n' "$SECRET" | WS_NAME="$RUNNER" ws push "$SLUG" code "$THEME_REL" "${C[@]}" --yes --json \
  >"$JSON/push-kill.jsonl" 2>"$JSON/push-kill.err" &
PID=$!
set -e
wait_for "$JSON/push-kill.jsonl" '"event":"commit"' || bad "SIGKILL-Lauf: kein commit"
docker kill "$RUNNER" >/dev/null 2>&1 || true
set +e
wait "$PID"
set -e
PUSH_K="$(jq -r 'select(.event == "commit") | .data.push_id' "$JSON/push-kill.jsonl")"
jrun push-pending-42 s1 push "$SLUG" code "$THEME_REL" "${C[@]}" --yes --json
eq "O5 nächster Push nach SIGKILL: Exit 42" "$RC" 42
printf '<?php throw new Error("e2e: WordPress antwortet nicht");\n' > "$WPC/mu-plugins/e2e-fatal.php"
eq "Fixture: WordPress antwortet mit 500" "$(code "$SOURCE_URL/")" 500
jrun rollback-rescue s1 rollback "$SLUG" "$PUSH_K" "${C[@]}" --json
eq "AC-124 rollback über rescue.php: Exit 0" "$RC" 0
ok "AC-124 Weg über rescue.php" hasF "$JSON/rollback-rescue.err" "rescue.php"
rm -f "$WPC/mu-plugins/e2e-fatal.php"
eq "AC-124 Live wieder v4" "$(live_mark)" "e2e-marker v4"
eq "AC-124 Live erreichbar" "$(code "$SOURCE_URL/")" 200

echo "== P1/AC-140: Uploads – neue Dateien hinzufügen, gleiche nicht übertragen"
PNG_B64='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
png() { printf %s "$PNG_B64" | openssl base64 -d -A > "$1"; [ -z "${2:-}" ] || printf %s "$2" >> "$1"; } # png <datei> [anhang]
here() { if [ -e "$1" ]; then echo da; else echo weg; fi; }
LUP="$DOCROOT/wp-content/uploads" # lokal
SUP="$WPC/uploads"                # Quelle (Live)
rm -rf "$SUP/2026/12" "$LUP/2026/12" "$SUP/2026/10/e2e-"* "$LUP/2026/10/e2e-"*
mkdir -p "$LUP/2026/10" "$LUP/2026/12" "$SUP/2026/10"
png "$LUP/2026/10/e2e-neu.png" neu
png "$LUP/2026/10/e2e-gleich.png"
cp -p "$LUP/2026/10/e2e-gleich.png" "$SUP/2026/10/e2e-gleich.png"
printf '# Liste wie vom Studio\n2026/10/e2e-neu.png\n\n2026/10/e2e-gleich.png\n' > "$E2E/uploads-a.txt"
# Nur Uploads: die genannte Einheit ist lokal unverändert, der Satz besteht nur aus uploads.
jrun push-uploads-dry s1 push "$SLUG" code "$HEALTH_REL" --uploads "$E2E/uploads-a.txt" "${C[@]}" --dry-run --json
eq "AC-140 Probelauf: Exit 0" "$RC" 0
eq "AC-140 plan.uploads" "$(event push-uploads-dry plan | jq -c '.uploads')" \
  '{"conflicts":[],"need":["2026/10/e2e-neu.png"],"same":["2026/10/e2e-gleich.png"]}'
eq "AC-140 Probelauf legt nichts an" "$(here "$SUP/2026/10/e2e-neu.png")" weg
(cd "$SRC" && ddev wp eval "WpSync\\Admin::openWindow('$KEY_ID', 28800, 1);" >/dev/null) # wie „Öffnen“ im WP-Admin als Benutzer 1
jrun push-uploads s1 push "$SLUG" code "$HEALTH_REL" --uploads "$E2E/uploads-a.txt" "${C[@]}" --yes --json
eq "AC-140 Push: Exit 0" "$RC" 0
eq "AC-140 Status und Einheiten" "$(last push-uploads '.data.status + " " + (.data.units | join(","))')" "confirmed uploads"
PUSH_U="$(last push-uploads '.data.push_id')"
ok "AC-140 neue Datei liegt auf Live" cmp -s "$LUP/2026/10/e2e-neu.png" "$SUP/2026/10/e2e-neu.png"
eq "AC-140 nur die neue Datei übertragen" "$(event push-uploads upload | jq -c '[.unit, .files]')" '["uploads",1]'
eq "AC-140 Baseline kennt die neue Datei" \
  "$(jq -r '.files["wp-content/uploads/2026/10/e2e-neu.png"].size' "$BASE")" "$(wc -c < "$LUP/2026/10/e2e-neu.png" | tr -d ' ')"
jrun pushes-opener s1 pushes "$SLUG" "${C[@]}" --json
eq "AC-145 Protokoll nennt den Öffner" "$(last pushes-opener ".data.pushes[] | select(.push_id == \"$PUSH_U\") | .opened_by")" 1

echo "== P1/AC-141: gleicher Pfad, anderer Inhalt – nichts wird getauscht, auch kein Code"
png "$SUP/2026/10/e2e-anders.png" live
cp -p "$SUP/2026/10/e2e-anders.png" "$E2E/e2e-anders.live"
png "$LUP/2026/10/e2e-anders.png" lokal
printf '2026/10/e2e-anders.png\n' > "$E2E/uploads-b.txt"
LIVE_BEFORE="$(live_mark)"
PUSHES2="$(pushes_on_site)"
mark 6
jrun push-upload-exists s1 push "$SLUG" code "$THEME_REL" --uploads "$E2E/uploads-b.txt" "${C[@]}" --yes --force --json
eq "AC-141 Exit 1, auch mit --force" "$RC" 1
eq "AC-141 reason upload_exists" "$(last push-upload-exists '.error.reason')" upload_exists
eq "AC-141 Konflikt im plan" "$(event push-upload-exists plan | jq -c '.uploads.conflicts')" '["2026/10/e2e-anders.png"]'
eq "AC-141 kein Code getauscht" "$(live_mark)" "$LIVE_BEFORE"
ok "AC-141 Datei auf Live unverändert" cmp -s "$E2E/e2e-anders.live" "$SUP/2026/10/e2e-anders.png"
eq "AC-141 kein Push auf der Site" "$(pushes_on_site)" "$PUSHES2"

echo "== P1/AC-143: Rücknahme über rescue.php – genau die hinzugefügten Dateien, geänderte bleiben"
png "$LUP/2026/12/e2e-rb-a.png" a
png "$LUP/2026/10/e2e-rb-b.png" b
printf '2026/12/e2e-rb-a.png\n2026/10/e2e-rb-b.png\n' > "$E2E/uploads-c.txt"
set +e
printf '%s\n' "$SECRET" | WS_NAME="$RUNNER" ws push "$SLUG" code "$HEALTH_REL" --uploads "$E2E/uploads-c.txt" "${C[@]}" --yes --json \
  >"$JSON/push-uploads-kill.jsonl" 2>"$JSON/push-uploads-kill.err" &
PID=$!
set -e
wait_for "$JSON/push-uploads-kill.jsonl" '"event":"commit"' || bad "AC-143 kein commit"
docker kill "$RUNNER" >/dev/null 2>&1 || true
set +e
wait "$PID"
set -e
PUSH_RB="$(jq -r 'select(.event == "commit") | .data.push_id' "$JSON/push-uploads-kill.jsonl")"
eq "AC-143 Fixture: beide Dateien auf Live" "$(here "$SUP/2026/12/e2e-rb-a.png") $(here "$SUP/2026/10/e2e-rb-b.png")" "da da"
printf 'seither geändert' >> "$SUP/2026/10/e2e-rb-b.png"
printf '<?php throw new Error("e2e: WordPress antwortet nicht");\n' > "$WPC/mu-plugins/e2e-fatal.php"
jrun rollback-uploads-rescue s1 rollback "$SLUG" "$PUSH_RB" "${C[@]}" --json
rm -f "$WPC/mu-plugins/e2e-fatal.php"
eq "AC-143 Rücknahme über rescue.php: Exit 0" "$RC" 0
ok "AC-143 Weg über rescue.php" hasF "$JSON/rollback-uploads-rescue.err" "rescue.php"
eq "AC-143 warnings" "$(last rollback-uploads-rescue '.data.warnings | join(",")')" upload_changed_since_push
eq "AC-143 Einheiten" "$(last rollback-uploads-rescue '.data.units | join(",")')" uploads
eq "AC-143 hinzugefügte Datei weg" "$(here "$SUP/2026/12/e2e-rb-a.png")" weg
eq "AC-143 vom Push angelegter Ordner weg" "$(here "$SUP/2026/12")" weg
eq "AC-143 geänderte Datei bleibt" "$(here "$SUP/2026/10/e2e-rb-b.png")" da
ok "AC-143 Meldung nennt die geänderte Datei" hasF "$JSON/rollback-uploads-rescue.err" "e2e-rb-b.png"
eq "AC-143 frühere Uploads bleiben" "$(here "$SUP/2026/10/e2e-neu.png")" da
eq "Live erreichbar" "$(code "$SOURCE_URL/")" 200

echo "== P1/AC-143: Rücknahme über den Agent"
jrun rollback-uploads s1 rollback "$SLUG" "$PUSH_U" "${C[@]}" --json
eq "AC-143 Rücknahme: Exit 0" "$RC" 0
eq "AC-143 neue Datei weg" "$(here "$SUP/2026/10/e2e-neu.png")" weg
eq "AC-143 gleiche Datei bleibt (sie gehörte nicht dem Push)" "$(here "$SUP/2026/10/e2e-gleich.png")" da
eq "AC-143 Baseline vergisst die Datei" "$(jq -r '.files | has("wp-content/uploads/2026/10/e2e-neu.png")' "$BASE")" false
eq "AC-143 ohne Warnung" "$(last rollback-uploads '.data | has("warnings")')" false
rm -rf "$SUP/2026/10/e2e-"* "$LUP/2026/10/e2e-"* "$LUP/2026/12"

echo "== AC-111: kein Secret, kein Rollback-Schlüssel, kein Salt"
for journal in "$SITE"/.wpsync/pushes/*.json; do
  id="$(jq -r .push_id "$journal")"
  salt="$(jq -r .salt "$journal")"
  key="$(printf 'rescue:%s:%s' "$id" "$salt" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)"
  no "AC-111 Rollback-Schlüssel von $id in Ausgaben" grep -rqF -- "$key" "$JSON" "$E2E/push-no-yes.log"
  no "AC-111 Salt von $id in Ausgaben" grep -rqF -- "$salt" "$JSON" "$E2E/push-no-yes.log"
done
no "AC-111 Secret in Ausgaben" grep -rqF -- "$SECRET" "$JSON" "$E2E/push-no-yes.log"
no "AC-111 Secret in Dateien des Site-Ordners" grep -rqF -- "$SECRET" "$SITE"

echo "== Aufräumen auf der Quelle"
jrun staging-delete s1 staging delete "$SLUG" --secret-stdin --yes --json
window 0
