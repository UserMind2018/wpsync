#!/usr/bin/env bash
# E2E: DDEV-Quelle mit Agent → pair → scan → pull (Erst- und Folge-Pull) → status.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E2E="${WPSYNC_E2E_DIR:-$HOME/wpsync-e2e}"
export WPSYNC_CONFIG_DIR="$E2E/config"
export WPSYNC_SITES_DIR="$E2E/sites"
SOURCE_NAME=wpsync-e2e-source
TARGET=wpsync-e2e-target
WPSYNC="$ROOT/cli/bin/wpsync"
YEAR="$(date +%Y)"

http_url() { ddev describe -j | python3 -c 'import json,sys; print(json.load(sys.stdin)["raw"]["httpurl"])'; }
fail() { echo "FAIL: $*"; exit 1; }

(cd "$ROOT/agent" && ./build.sh)
(cd "$ROOT/cli" && go build -o bin/wpsync ./cmd/wpsync)

mkdir -p "$E2E/source/public"
cd "$E2E/source"
if [ ! -f .ddev/config.yaml ]; then
  ddev config --project-name="$SOURCE_NAME" --project-type=wordpress --docroot=public \
    --php-version=8.2 --database=mariadb:10.11 --performance-mode=none
fi
ddev start -y
if ! ddev wp core is-installed >/dev/null 2>&1; then
  ddev wp core download --force
  sed -i '' 's/#ddev-generated//' public/wp-config.php
  ddev wp config set table_prefix e2e_ --type=variable
  ddev wp core install --url="$(http_url)" --title="wpsync E2E" --admin_user=admin \
    --admin_password=admin --admin_email=e2e@example.invalid --skip-email
  ddev wp post generate --count=50 --post_type=page
  ddev wp plugin install password-protected --activate
  ddev wp option update password_protected_status 1
  ddev wp option update password_protected_password "$(printf %s e2e | md5)"
fi

echo "== 1b-Fixtures in der Quelle"
ddev wp config set DISABLE_WP_CRON true --raw --type=constant # AC-9: Infosheet ohne WP-Cron
ddev wp config set WPSYNC_ALLOW_HTTP true --raw --type=constant # SEC-03: die lokale Quelle läuft über http
if [ "$(ddev wp post list --post_type=revision --format=count)" = "0" ]; then
  for id in $(ddev wp post list --post_type=page --posts_per_page=5 --format=ids); do
    ddev wp post update "$id" --post_content="Revision $id" >/dev/null
  done
fi
ddev wp eval 'foreach (get_posts(["post_type" => "revision", "numberposts" => -1, "post_status" => "any"]) as $r) { update_post_meta($r->ID, "_e2e_revision_meta", "x"); }'
mkdir -p public/wp-content/plugins/e2e-excluded public/wp-content/plugins/e2e-objects
printf '<?php\n/* Plugin Name: E2E Excluded */\n' > public/wp-content/plugins/e2e-excluded/e2e-excluded.php
printf '<?php\n/* Plugin Name: E2E Objects */\nclass E2E_Object_Config { public $url = ""; }\n' > public/wp-content/plugins/e2e-objects/e2e-objects.php
ddev wp plugin activate e2e-excluded e2e-objects
ddev wp eval '$c = new E2E_Object_Config(); $c->url = home_url("/e2e"); update_option("e2e_object_config", $c);'
mkdir -p public/wp-content/uploads/2019/01
printf 'wpsync-proxy-ok' > public/wp-content/uploads/2019/01/wpsync-proxy.txt

cp "$ROOT/agent/dist/wpsync-agent.zip" public/wpsync-agent.zip
ddev wp plugin install /var/www/html/public/wpsync-agent.zip --force --activate
rm public/wpsync-agent.zip
ddev wp eval 'WpSync\Store::setState("infosheet", null); WpSync\Store::setState("infosheet_job", null);'
CODE="$(ddev wp wpsync pair-code | tail -1)"
SOURCE_URL="$(http_url)"

echo "== AC-25: Frontend bleibt geschützt"
status="$(curl -s -o /dev/null -w '%{http_code}' -H 'X-Wpsync-Signature: x' "$SOURCE_URL/?p=1")"
[ "$status" = "302" ] || fail "protected page returned $status"

echo "== SEC-03: pair über http nur mit --insecure"
if "$WPSYNC" pair "$SOURCE_URL" "$CODE" --name "${TARGET}0" 2>/dev/null; then fail "paired over http without --insecure"; fi

echo "== pair"
"$WPSYNC" unpair "$TARGET" >/dev/null 2>&1 || true
"$WPSYNC" pair "$SOURCE_URL" "$CODE" --name "$TARGET" --insecure

echo "== AC-3: Code ist verbraucht"
if "$WPSYNC" pair "$SOURCE_URL" "$CODE" --name "${TARGET}2" --insecure 2>/dev/null; then fail "code reused"; fi

echo "== AC-9: scan --refresh ohne WP-Cron"
"$WPSYNC" scan "$TARGET" --refresh --preset ohne-transaktionen --exclude-plugin e2e-excluded --uploads-since "$YEAR" | tee "$E2E/scan1.log"
grep -q "Infosheet wird erstellt" "$E2E/scan1.log" || fail "AC-9 no refresh"
grep -q "Infosheet erstellt in" "$E2E/scan1.log" || fail "AC-9 refresh did not finish"
grep -q "revision" "$E2E/scan1.log" || fail "AC-8 revisions not listed"

echo "== AC-7: scan mit aktuellem Infosheet = 1 Request"
"$WPSYNC" scan "$TARGET" | tee "$E2E/scan2.log"
grep -q "^Requests: 1$" "$E2E/scan2.log" || fail "AC-7 scan needed more than one request"

echo "== Erst-Pull"
"$WPSYNC" pull "$TARGET" --full | tee "$E2E/pull1.log"

cd "$WPSYNC_SITES_DIR/$TARGET"
TARGET_URL="$(http_url)"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$TARGET_URL/")" = "200" ] || fail "target not 200"
grep -q "e2e_" public/wp-config.php || fail "AC-20 prefix"
ddev restart >/dev/null
grep -q "e2e_" public/wp-config.php || fail "AC-20 prefix lost after restart"
[ "$(ddev wp plugin list --status=active --field=name | grep -c password-protected || true)" = "0" ] || fail "AC-23"
[ "$(ddev mysql -N -e "SHOW TABLES LIKE '%wpsync%'" | wc -l | tr -d ' ')" = "0" ] || fail "AC-27"
[ "$(git log --oneline | wc -l | tr -d ' ')" -ge 1 ] || fail "AC-28"
if git ls-files | grep -q "uploads/"; then fail "AC-28 uploads tracked"; fi

echo "== AC-14: keine Revisionen, keine verwaisten Metadaten"
[ "$(ddev wp post list --post_type=revision --format=count)" = "0" ] || fail "AC-14 revisions pulled"
orphans="$(ddev mysql -N -e 'SELECT COUNT(*) FROM e2e_postmeta m LEFT JOIN e2e_posts p ON p.ID = m.post_id WHERE p.ID IS NULL')"
[ "$orphans" = "0" ] || fail "AC-14 $orphans orphaned postmeta rows"

echo "== AC-15: abgewähltes aktives Plugin ist lokal deaktiviert"
[ ! -d public/wp-content/plugins/e2e-excluded ] || fail "AC-15 excluded plugin was pulled"
if ddev wp option get active_plugins --format=json | grep -q e2e-excluded; then fail "AC-15 excluded plugin still active"; fi
[ "$(curl -s -o /dev/null -w '%{http_code}' "$TARGET_URL/")" = "200" ] || fail "AC-15 site not 200"

echo "== AC-21: plugin-serialisiertes Objekt enthält die lokale URL"
url="$(ddev wp eval 'echo get_option("e2e_object_config")->url;')"
[ "$url" = "$TARGET_URL/e2e" ] || fail "AC-21 object url is '$url'"

echo "== AC-16: fehlendes Upload-Jahr kommt über den Proxy"
[ ! -f public/wp-content/uploads/2019/01/wpsync-proxy.txt ] || fail "AC-16 2019 was pulled despite --uploads-since"
# *.ddev.site zeigt im Container auf 127.0.0.1 – für den Test die Quelle über den DDEV-Router erreichbar machen
cat > .ddev/docker-compose.e2e-source.yaml <<YAML
services:
  web:
    external_links:
      - "ddev-router:${SOURCE_NAME}.ddev.site"
YAML
ddev restart >/dev/null
body="$(curl -s "$TARGET_URL/wp-content/uploads/2019/01/wpsync-proxy.txt")"
[ "$body" = "wpsync-proxy-ok" ] || fail "AC-16 proxy returned '$body'"
[ -f public/wp-content/uploads/2019/01/wpsync-proxy.txt ] || fail "AC-16 proxied file not stored locally"

echo "== Folge-Pull"
"$WPSYNC" pull "$TARGET" | tee "$E2E/pull2.log"
requests="$(grep -o '– [0-9]* Requests' "$E2E/pull2.log" | grep -o '[0-9]*')"
[ "$requests" -le 3 ] || fail "AC-12 follow-up pull used $requests requests"
[ -f public/wp-content/uploads/2019/01/wpsync-proxy.txt ] || fail "follow-up pull deleted a proxied upload"

echo "== AC-29: status zeigt Änderungen ohne Transfer"
(cd "$E2E/source" && ddev wp post create --post_type=page --post_title="Status $(date +%s)" --post_status=publish >/dev/null \
  && printf 'x' > public/wp-content/themes/status-test.txt)
"$WPSYNC" status "$TARGET" | tee "$E2E/status.log"
grep -q "e2e_posts" "$E2E/status.log" || fail "AC-29 changed table missing"
grep -q "wp-content/themes/status-test.txt" "$E2E/status.log" || fail "AC-29 changed file missing"
grep -q "keine Inhalte übertragen" "$E2E/status.log" || fail "AC-29 status transferred content"

echo "E2E OK"
