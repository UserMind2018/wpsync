#!/usr/bin/env bash
# E2E Stufe 2b: Staging-Kopie gegen eine Apache-Quelle (DDEV apache-fpm wertet .htaccess aus).
# Eigene Projekte wpsync-e2e-staging (Quelle, PHP 7.4) und wpsync-e2e-stgtarget (lokal) unter
# ~/wpsync-e2e/staging – die geteilte Umgebung aus e2e-local.sh bleibt unberührt (Plan 2b, V19).
#
# Voraussetzung: Docker/DDEV wie für e2e-local.sh, dazu jq. Dauer rund 20 Minuten.
# Eine fehlgeschlagene Prüfung zählt und der Lauf geht weiter; nur was den Rest sinnlos macht,
# bricht ab. Die JSON-Zeilen der Befehle liegen danach unter ~/wpsync-e2e/staging/json (ohne Token).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E2E="${WPSYNC_E2E_DIR:-$HOME/wpsync-e2e}/staging"
export WPSYNC_CONFIG_DIR="$E2E/config"
export WPSYNC_SITES_DIR="$E2E/sites"
SOURCE_NAME=wpsync-e2e-staging
TARGET=wpsync-e2e-stgtarget
WPSYNC="$E2E/bin/wpsync" # eigener Build – nie eine installierte wpsync
SRC="$E2E/source"
PUB="$SRC/public"
LOCAL="$WPSYNC_SITES_DIR/$TARGET"
THEME="$LOCAL/public/wp-content/themes/e2e-theme"
STAMPS="$LOCAL/.wpsync/staging-base.json" # gemerkte Stempel der Pushes nach Staging
GITDIR="$WPSYNC_SITES_DIR/.wpsync-git/$TARGET.git" # internes Git der lokalen Site
JSON="$E2E/json"
KEY=00000000000000e2
SECRET="$(printf 'ab%.0s' $(seq 32))"
FORGED="$(printf 'a%.0s' $(seq 64))"
SINK=blocked@mailguard.invalid
FAST=(--rps 20) # der erste create läuft mit dem Standard (1 Request/s) und wird gemessen
# WP-CLI merkt sich jedes deaktivierte Plugin in dieser Option – zählt nur beim Deaktivieren nicht.
DEACTIVATED='^option:recently_activated[[:space:]]'

CHECKS=0
FAILED=0
MARK=v1
NGINX=no
KEY_ID=""
RC=0

pass() { CHECKS=$((CHECKS + 1)); }
bad() { CHECKS=$((CHECKS + 1)); FAILED=$((FAILED + 1)); echo "FAIL: $*"; }
fail() { echo "FAIL: $*"; exit 1; }
eq() { if [ "$2" = "$3" ]; then pass; else bad "$1 (ist: $2, soll: $3)"; fi; } # eq <was> <ist> <soll>
ok() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then pass; else bad "$what"; fi; }
no() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$what"; else pass; fi; }
has() { grep -q -- "$2" "$1"; }     # has <datei> <muster>
hasF() { grep -qF -- "$2" "$1"; }   # wörtlich
SQL() { (cd "$SRC" && ddev mysql -N -e "$1"); }
LSQL() { (cd "$LOCAL" && ddev mysql -N -e "$1"); }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
http_url() { ddev describe -j | jq -r '.raw.httpurl'; }
last() { tail -n 1 "$1" | jq -r "$2"; } # last <datei> <jq-Ausdruck> über der Ergebniszeile
stg() { (cd "$SRC" && ddev wp --path="/var/www/html/public/$STG_DIR" "$@"); }
window() { (cd "$SRC" && ddev wp eval "WpSync\\Store::setPushUntil('$KEY_ID', $1);" >/dev/null); }
mark() { sed -i '' -E "s/e2e-marker v[0-9]+/e2e-marker v$1/" "$THEME/index.php"; MARK="v$1"; } # mark <n>: lokaler Stand des Themes
git_state() { git --git-dir="$GITDIR" for-each-ref; git --git-dir="$GITDIR" rev-list --all --count; }
# in_git <muster>: Pfad im letzten Schnappschuss. Ohne grep -q: das bricht beim ersten Treffer ab,
# git bekäme SIGPIPE und unter pipefail zählte ein Treffer als Fehlschlag.
in_git() { git --git-dir="$GITDIR" ls-tree -r --name-only HEAD | grep -- "$1" >/dev/null; }
stamp_push() { jq -r --arg u "$1" '.units[$u].push_id // "-"' "$STAMPS"; } # stamp_push <einheit>: Push, dessen Stempel gemerkt sind
conflicts() { jq -c 'select(.event == "plan") | [.data.units[].conflicts[]]' "$1"; }
state_file() { echo "$PUB/$STG_DIR/wp-content/wpsync-staging.json"; }
edit_state() { # edit_state <jq-Filter>: Zustandsdatei der Kopie ändern, Rechte bleiben
  local file tmp
  file="$(state_file)"
  tmp="$(jq -c "$1" "$file")"
  printf '%s' "$tmp" > "$file"
}
run() { # run <log> <befehl…>: Exit-Code nach RC, Ausgabe nach $E2E/<log>
  local log="$1"
  shift
  set +e
  "$@" >"$E2E/$log" 2>&1
  RC=$?
  set -e
}
jrun() { # jrun <name> <befehl… --json>: stdout nach $JSON/<name>.jsonl (ohne Token), stderr nach .err
  local name="$1"
  shift
  set +e
  "$@" >"$E2E/json-raw.tmp" 2>"$JSON/$name.err"
  RC=$?
  set -e
  sed -E 's/(wpsync_login=)[0-9a-f]+/\1<token>/g' "$E2E/json-raw.tmp" >"$JSON/$name.jsonl"
  # Jede Zeile gültiges JSON, die letzte das Ergebnis mit demselben Exit-Code (T4).
  if jq -e -R 'fromjson | type == "object"' "$JSON/$name.jsonl" >/dev/null 2>&1 \
    && [ "$(last "$JSON/$name.jsonl" '[.event, .exit_code, .ok] | @tsv')" = "result"$'\t'"$RC"$'\t'"$([ "$RC" = 0 ] && echo true || echo false)" ]; then
    pass
  else
    bad "T4 $name: stdout ist nicht zeilenweise JSON mit passendem Ergebnis (Exit $RC)"
    cat "$JSON/$name.jsonl" "$JSON/$name.err"
  fi
}
signed() { # signed <route> <json-body> [curl-args…] – manipulierter Client mit festem Test-Pairing
  local route="$1" body="$2" ts nonce hash sig
  shift 2
  ts="$(date +%s)"
  nonce="$(openssl rand -hex 16)"
  hash="$(printf %s "$body" | openssl dgst -sha256 -r | cut -d' ' -f1)"
  sig="$(printf 'POST\n%s\n%s\n%s\n%s' "$route" "$ts" "$nonce" "$hash" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)"
  curl -s -X POST "$SOURCE_URL/?rest_route=$route" -H 'Content-Type: application/json' \
    -H "X-Wpsync-Key: $KEY" -H "X-Wpsync-Timestamp: $ts" -H "X-Wpsync-Nonce: $nonce" \
    -H "X-Wpsync-Signature: $sig" --data-binary "$body" "$@"
}
# Live-Tabellen: Prüfsumme und Zeilenzahl je Tabelle, Optionen einzeln. Nicht dabei: der Zustand
# des Agents (e2e_wpsync_*), Optionen, die WordPress bei jedem Besuch selbst schreibt (Transients,
# cron), und der Action Scheduler von WooCommerce: seine Warteschlange, seine Sperre und sein
# Migrationsstatus, den er bei jeder Deaktivierung irgendeines Plugins löscht und später neu setzt.
live_tables() { # live_tables <datei>
  local tables t
  tables="$(SQL "SHOW TABLES LIKE 'e2e%'" | grep -Ev '^e2e_(wpsync_.*|actionscheduler_.*|options)$')"
  {
    SQL "CHECKSUM TABLE $(printf '%s\n' "$tables" | paste -sd, -)"
    SQL "$(for t in $tables; do printf "SELECT 'rows:%s', COUNT(*) FROM \`%s\`\n" "$t" "$t"; done | paste -sd'|' - | sed 's/|/ UNION ALL /g')"
    SQL "SELECT CONCAT('option:', option_name), CRC32(option_value) FROM e2e_options WHERE option_name NOT LIKE '%transient%' AND option_name NOT LIKE 'wpsync%' AND option_name NOT IN ('cron', 'action_scheduler_lock_async-request-runner', 'action_scheduler_migration_status') ORDER BY option_name"
  } > "$1"
}
# Dateien des Live-Webroots mit Hash – ohne die Kopie, den Push-Arbeitsordner des Agents und Logs.
live_files() { # live_files <datei>
  (cd "$PUB" && find . \( -path './wpsync-staging-*' -o -path './wp-content/wpsync-push-*' -o -path ./wp-content/uploads/wc-logs \) -prune \
    -o -type f -print0 | sort -z | xargs -0 md5 -r) > "$1"
}
same() { # same <was> <datei-vorher> <datei-nachher> [Zeilen, die nicht zählen (grep -E)]
  local before="$2" after="$3"
  if [ -n "${4:-}" ]; then
    grep -vE "$4" "$2" > "$2.cmp" || true
    grep -vE "$4" "$3" > "$3.cmp" || true
    before="$2.cmp"
    after="$3.cmp"
  fi
  if cmp -s "$before" "$after"; then
    pass
  else
    bad "$1"
    diff "$before" "$after" | head -20 || true
  fi
}
no_staging_left() { # <wo>
  eq "$1: kein Staging-Ordner" "$(find "$PUB" -maxdepth 1 -name 'wpsync-staging-*' | wc -l | tr -d ' ')" 0
  eq "$1: keine Staging-Tabelle" "$(SQL "SHOW TABLES LIKE 'stg%'" | wc -l | tr -d ' ')" 0
}
locked_out() { # locked_out <wo> [curl-args…]: jede Adresse der Kopie 403, auch statische Dateien (V7)
  local where="$1" p
  shift
  for p in "" wp-login.php wp-admin/ wp-admin/install.php wp-includes/version.php wp-includes/css/buttons.css \
    readme.html wp-content/themes/e2e-theme/style.css wp-content/plugins/e2e-rewrite/sub/open.php "?wpsync_login=$FORGED"; do
    eq "$where /$p" "$(code "$@" "$STG_URL/$p")" 403
  done
}
mails() { (cd "$SRC" && ddev exec curl -s http://localhost:8025/api/v1/messages); }
mails_clear() { (cd "$SRC" && ddev exec curl -s -X DELETE http://localhost:8025/api/v1/messages >/dev/null); }
only_sink() { # only_sink <mindestens>: jede Mail im Mailpit geht allein an die Sink-Adresse
  mails | jq -e --argjson n "$1" --arg sink "$SINK" \
    '(.messages | length) >= $n and all(.messages[]; ([.To[]?.Address] == [$sink]) and ((.Cc // []) | length == 0) and ((.Bcc // []) | length == 0))'
}
finish() {
  local rc=$?
  trap - EXIT
  set +e
  if [ -f "$SRC/.ddev/config.yaml" ] && cd "$SRC"; then
    ddev wp config delete WPSYNC_TEST_FAIL_PHASE --type=constant >/dev/null 2>&1
    if [ "$NGINX" = yes ]; then
      ddev config --webserver-type=apache-fpm >/dev/null 2>&1
      ddev restart >/dev/null 2>&1
    fi
    [ -z "$KEY_ID" ] || window 0 2>/dev/null
  fi
  rm -f "$E2E/json-raw.tmp"
  echo
  if [ "$rc" != 0 ]; then
    echo "E2E Staging ABGEBROCHEN (Exit $rc) – $CHECKS Prüfungen bis dahin, $FAILED FAIL"
    exit "$rc"
  fi
  if [ "$FAILED" != 0 ]; then
    echo "E2E Staging: $CHECKS Prüfungen, $FAILED FAIL"
    exit 1
  fi
  echo "E2E Staging OK – $CHECKS Prüfungen grün, 0 FAIL"
}
trap finish EXIT

command -v jq >/dev/null || fail "jq fehlt"
mkdir -p "$E2E/bin" "$SRC/public" "$SRC/.e2e"
rm -rf "$JSON"
mkdir -p "$JSON"
(cd "$ROOT/agent" && ./build.sh)
(cd "$ROOT/cli" && go build -o "$WPSYNC" ./cmd/wpsync)

echo "== Quelle (Apache, PHP 7.4)"
cd "$SRC"
if [ ! -f .ddev/config.yaml ]; then
  ddev config --project-name="$SOURCE_NAME" --project-type=wordpress --docroot=public \
    --php-version=7.4 --database=mariadb:10.11 --webserver-type=apache-fpm --performance-mode=none
fi
ddev config --webserver-type=apache-fpm >/dev/null # ein abgebrochener AC-84-Lauf hinterlässt nginx-fpm
ddev start -y
if ! ddev wp core is-installed >/dev/null 2>&1; then
  ddev wp core download --force
  sed -i '' 's/#ddev-generated//' public/wp-config.php
  ddev wp config set table_prefix e2e_ --type=variable
  ddev wp core install --url="$(http_url)" --title="wpsync Staging E2E" --admin_user=admin \
    --admin_password=admin --admin_email=e2e@example.invalid --skip-email
  ddev wp rewrite structure '/%postname%/' --hard
  ddev wp plugin install woocommerce --version=9.4.3 --activate
  # Sonst leitet WooCommerce den ersten Admin-Aufruf (auch der Kopie) in den Einrichtungsassistenten.
  ddev wp transient delete _wc_activation_redirect >/dev/null 2>&1 || true
  ddev wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
fi
ddev wp config set WPSYNC_ALLOW_HTTP true --raw --type=constant
ddev wp config set DISABLE_WP_CRON true --raw --type=constant
ddev wp config delete WPSYNC_TEST_FAIL_PHASE --type=constant >/dev/null 2>&1 || true
SOURCE_URL="$(http_url)"
eq "NFA: Quelle läuft mit PHP 7.4" "$(ddev exec php -v | awk 'NR == 1 { print substr($2, 1, 3) }')" 7.4

echo "== Fixtures"
WPC="$PUB/wp-content"
mkdir -p "$WPC/themes/e2e-theme" "$WPC/plugins/e2e-excluded" "$WPC/plugins/e2e-health" \
  "$WPC/plugins/e2e-rewrite/sub/keep" "$WPC/uploads/2020/01"
printf '/*\nTheme Name: E2E Theme\nVersion: 1.0\n*/\n' > "$WPC/themes/e2e-theme/style.css"
cat > "$WPC/themes/e2e-theme/index.php" <<'PHP'
<!doctype html><html><head><?php wp_head(); ?></head><body>
<a href="<?php echo esc_url(home_url('/kontakt/')); ?>">Kontakt</a>
<img src="<?php echo esc_url(content_url('uploads/2020/01/live-only.jpg')); ?>" alt="">
<p>e2e-marker v1</p>
<?php wp_footer(); ?></body></html>
PHP
printf '<?php\n// e2e theme\n' > "$WPC/themes/e2e-theme/functions.php"
ddev wp theme activate e2e-theme
printf '<?php\n/* Plugin Name: E2E Excluded */\n' > "$WPC/plugins/e2e-excluded/e2e-excluded.php"
printf '<?php\n/* Plugin Name: E2E Health\n * Version: 1.0 */\n' > "$WPC/plugins/e2e-health/e2e-health.php"
# Ein Plugin-Unterordner, der sich mit eigener .htaccess aus den Rewrite-Regeln darüber nimmt.
printf '<?php\n/* Plugin Name: E2E Rewrite\n * Version: 1.0 */\n' > "$WPC/plugins/e2e-rewrite/e2e-rewrite.php"
printf 'RewriteEngine On\nRewriteRule ^nothing$ - [L]\n' > "$WPC/plugins/e2e-rewrite/sub/.htaccess"
printf '<?php echo "e2e-open";\n' > "$WPC/plugins/e2e-rewrite/sub/open.php"
printf 'Options -Indexes\n' > "$WPC/plugins/e2e-rewrite/sub/keep/.htaccess"
mkdir -p "$WPC/plugins/e2e-rewrite/base"
printf 'RewriteBase /\n' > "$WPC/plugins/e2e-rewrite/base/.htaccess" # ohne RewriteEngine, wirkt genauso
printf '<?php echo "e2e-base";\n' > "$WPC/plugins/e2e-rewrite/base/open.php"
printf 'DB_PASSWORD=live-secret-e2e\n' > "$WPC/plugins/e2e-rewrite/.env"
ddev wp plugin activate e2e-excluded e2e-health e2e-rewrite
# Wie ein Shared Host mit knappem Zeitlimit: jeder Request des Agents arbeitet höchstens 3 s
# (Budget = 60 %), ein Staging-Job läuft also über mehrere Requests. Mit dem DDEV-Standard (600 s)
# wäre die kleine Testsite in einem einzigen Schritt kopiert.
mkdir -p "$WPC/mu-plugins"
printf "<?php\n// E2E: knappes Zeitlimit für Web-Requests\nif (PHP_SAPI !== 'cli') {\n    @ini_set('max_execution_time', '5');\n}\n" > "$WPC/mu-plugins/e2e-limits.php"
printf 'live-only' > "$WPC/uploads/2020/01/live-only.jpg"
# Erlaubter Pfad (Uploads von Live), der auf einen gesperrten weiterleitet.
printf '<?php header("Location: http://" . $_SERVER["HTTP_HOST"] . "/wp-json/", true, 302);\n' > "$WPC/uploads/2020/01/e2e-redirect.php"
printf '<?php // Drop-in, wird nie kopiert (AC-96)\n' > "$WPC/advanced-cache.php"
printf 'e2e live log\n' > "$WPC/debug.log"
# Alte Konfiguration und .env mit Zugangsdaten von Live im Webroot.
printf "<?php\ndefine('DB_PASSWORD', 'live-secret-e2e');\n" > "$PUB/wp-config-old.php"
printf 'DB_PASSWORD=live-secret-e2e\n' > "$PUB/.env"
if ! ddev wp user get erika --field=ID >/dev/null 2>&1; then
  ddev wp user create erika erika.mustermann@kunde-echt.example --role=customer \
    --first_name=Erika --last_name=Mustermann --user_pass=geheim-e2e >/dev/null
fi
ERIKA_ID="$(ddev wp user get erika --field=ID)"
PAGE_ID="$(ddev wp post list --post_type=page --name=kontakt --field=ID)"
if [ -z "$PAGE_ID" ]; then
  PAGE_ID="$(ddev wp post create --post_type=page --post_title=Kontakt --post_name=kontakt --post_status=publish --porcelain)"
fi
ddev wp eval "update_post_meta($PAGE_ID, '_elementor_data', wp_slash(wp_json_encode([['settings' => ['url' => home_url('/kontakt/')]]])));"
ddev wp eval 'update_option("e2e_serialized", ["url" => home_url("/x"), "list" => [home_url()]]);'
if [ "$(ddev wp eval 'echo count(wc_get_orders(["limit" => 1]));')" = "0" ]; then
  ddev wp eval '$o = wc_create_order(["customer_id" => '"$ERIKA_ID"']); $o->set_billing_email("erika.mustermann@kunde-echt.example"); $o->set_billing_first_name("Erika"); $o->save();'
  ddev wp eval '$w = new WC_Webhook(); $w->set_name("e2e"); $w->set_topic("order.created"); $w->set_delivery_url("https://hooks.example.invalid/e2e"); $w->set_status("active"); $w->save();'
fi
ddev wp option update woocommerce_stripe_settings '{"enabled":"yes","title":"Stripe"}' --format=json
ddev wp option update woocommerce_bacs_settings '{"enabled":"yes"}' --format=json
ddev wp option update woocommerce_gateway_order '{"stripe":0,"bacs":1}' --format=json
eq "Fixture: PHP-Datei im Plugin-Unterordner ist auf Live direkt erreichbar" "$(curl -s "$SOURCE_URL/wp-content/plugins/e2e-rewrite/sub/open.php")" e2e-open
eq "Fixture: Weiterleitung aus den Uploads von Live" "$(code "$SOURCE_URL/wp-content/uploads/2020/01/e2e-redirect.php")" 302

echo "== Agent, Pairing, Erst-Pull"
cp "$ROOT/agent/dist/wpsync-agent.zip" public/wpsync-agent.zip
ddev wp plugin install /var/www/html/public/wpsync-agent.zip --force --activate
rm public/wpsync-agent.zip
# NFA: der Agent samt Riegel ist PHP-7.4-Syntax (bisher nur von Hand geprüft).
ok "NFA: php -l (7.4) über alle Dateien des Agents" ddev exec 'for f in $(find public/wp-content/plugins/wpsync-agent -name "*.php"); do php -l "$f" >/dev/null || exit 1; done'
# Reste früherer Läufe: Kopie, offene Pushes, Sperren.
ddev wp eval 'WpSync\Staging::uninstall(); WpSync\Push::uninstall(); global $wpdb; $wpdb->query("DELETE FROM " . WpSync\Store::table("pushes")); WpSync\Store::setState("push_lock", null);'
if find "$PUB" -maxdepth 1 -name 'wpsync-staging-*' | grep -q . || [ -n "$(SQL "SHOW TABLES LIKE 'stg%'")" ]; then
  echo "WARN: Reste einer früheren Kopie, die Staging::uninstall() nicht mehr kennt – werden entfernt"
  find "$PUB" -maxdepth 1 -name 'wpsync-staging-*' -exec rm -rf {} +
  for t in $(SQL "SHOW TABLES LIKE 'stg%'"); do SQL "DROP TABLE \`$t\`"; done
fi
CODE="$(ddev wp wpsync pair-code | tail -1)"
"$WPSYNC" unpair "$TARGET" >/dev/null 2>&1 || true
rm -f "$STAMPS" # Stempel einer Kopie aus einem früheren Lauf
"$WPSYNC" pair "$SOURCE_URL" "$CODE" --name "$TARGET" --insecure
KEY_ID="$(awk '/^key_id:/ { print $2 }' "$WPSYNC_CONFIG_DIR/sites/$TARGET.yaml")"
"$WPSYNC" scan "$TARGET" --refresh --preset vollstaendig --exclude-plugin e2e-excluded
"$WPSYNC" pull "$TARGET" --full --yes
grep -q 'e2e-marker v1' "$LOCAL/public/wp-content/themes/e2e-theme/index.php" || fail "lokale Kopie hat nicht den Stand von Live"
SQL "REPLACE INTO e2e_wpsync_pairings (key_id, secret, device, created, push_until) VALUES ('$KEY', '$SECRET', 'sec-curl', UNIX_TIMESTAMP(), 0)"
# Live-Sitzung für AC-92 – vor dem Vergleichsstand, weil die Anmeldung usermeta schreibt.
LIVEJAR="$E2E/live.txt"
rm -f "$LIVEJAR"
curl -s -o /dev/null -c "$LIVEJAR" -b 'wordpress_test_cookie=WP+Cookie+check' \
  --data 'log=admin&pwd=admin&testcookie=1' "$SOURCE_URL/wp-login.php"
LIVEAUTH="$(awk '$6 ~ /^wordpress_/ { printf "%s=%s; ", $6, $7 }' "$LIVEJAR")"
[ -n "$LIVEAUTH" ] || fail "Anmeldung auf Live für AC-92 gescheitert"

echo "== Zusatz 12: Vergleichsstand von Live vor dem ersten create"
live_tables "$E2E/inv-tables.0"
live_files "$E2E/inv-files.0"
jrun staging-status-missing-50 "$WPSYNC" staging status "$TARGET" --json
eq "6.4 status ohne Kopie: Exit 50" "$RC" 50
eq "6.4 status ohne Kopie: error.code" "$(last "$JSON/staging-status-missing-50.jsonl" .error.code)" staging_missing

echo "== AC-80: staging create lässt Live unverändert"
T0=$SECONDS
jrun staging-create "$WPSYNC" staging create "$TARGET" --yes --json
[ "$RC" = 0 ] || fail "staging create (Exit $RC)"
CREATE_SECONDS=$((SECONDS - T0))
CREATE_STEPS="$(grep -c '"event":"phase"' "$JSON/staging-create.jsonl" || true)"
# Ein Ereignis je Request, der den Job nicht beendet – mit dem Stand der Phase, in der er aufhörte.
ok "T4 Phasen-Ereignisse" test "$CREATE_STEPS" -ge 1
ok "T4 Phasen-Ereignisse nennen Phase und plausiblen Fortschritt" jq -e -s \
  '[.[] | select(.event == "phase")] | all(.[]; (.name | IN("files", "tables", "anonymize", "fixup", "urls", "settings")) and .done >= 0 and .done <= .total)' "$JSON/staging-create.jsonl"
STG_URL="$(last "$JSON/staging-create.jsonl" .data.url)"
STG_DIR="${STG_URL##*/}"
STG="$(last "$JSON/staging-create.jsonl" .data.prefix)"
[[ "$STG_DIR" =~ ^wpsync-staging-[a-f0-9]{12}$ && "$STG" =~ ^stg[a-f0-9]{6}_$ ]] || fail "create nennt keine Kopie: $STG_URL $STG"
eq "create: Adresse liegt auf der gekoppelten Site" "$STG_URL" "$SOURCE_URL/$STG_DIR"
eq "create: anonymisiert" "$(last "$JSON/staging-create.jsonl" .data.anonymized)" true
eq "create: keine unlesbaren serialisierten Werte" "$(last "$JSON/staging-create.jsonl" .data.skipped_values)" 0
live_tables "$E2E/inv-tables.1"
live_files "$E2E/inv-files.1"
same "AC-80 Live-Tabellen unverändert" "$E2E/inv-tables.0" "$E2E/inv-tables.1"
same "AC-80 Live-Dateien unverändert" "$E2E/inv-files.0" "$E2E/inv-files.1"
SC="$PUB/$STG_DIR"
ok "Theme kopiert" test -f "$SC/wp-content/themes/e2e-theme/index.php"
ok "Riegel liegt in der Kopie" test -f "$SC/wp-content/mu-plugins/00-wpsync-staging.php"
no "AC-90 abgewähltes Plugin kopiert" test -e "$SC/wp-content/plugins/e2e-excluded"
no "AC-90 abgewähltes Plugin auf Staging aktiv" stg plugin is-active e2e-excluded
ok "aktives Plugin bleibt auf Staging aktiv" stg plugin is-active e2e-health
no "5.10 Agent kopiert" test -e "$SC/wp-content/plugins/wpsync-agent"
no "5.10 Agent auf Staging aktiv" stg plugin is-active wpsync-agent
no "AC-96 Drop-in kopiert" test -e "$SC/wp-content/advanced-cache.php"
no "S3 Uploads kopiert" test -e "$SC/wp-content/uploads/2020"
no "V7 Installer kopiert" test -e "$SC/wp-admin/setup-config.php"
no "Log von Live kopiert" test -e "$SC/wp-content/debug.log"
ok "Tabellen kopiert" test "$(SQL "SELECT COUNT(*) FROM ${STG}posts")" -gt 0
eq "Tabellen: so viele wie Live (ohne die des Agents)" "$(SQL "SHOW TABLES LIKE '${STG}%'" | wc -l | tr -d ' ')" \
  "$(SQL "SHOW TABLES LIKE 'e2e%'" | grep -vc '^e2e_wpsync_')"
# Zusatz 1: Konfigurationsvarianten und .env mit Zugangsdaten von Live bleiben draussen.
for f in wp-config-old.php wp-config-sample.php wp-config-ddev.php .env wp-content/plugins/e2e-rewrite/.env; do
  no "Zusatz 1: $f in der Kopie" test -e "$SC/$f"
done
no "Zusatz 1: Zugangsdaten-Marke von Live irgendwo in der Kopie" grep -rqF live-secret-e2e "$SC"
ok "Kopie hat genau eine wp-config" test "$(find "$SC" -maxdepth 1 -iname 'wp-config*' | wc -l | tr -d ' ')" = 1
# Zusatz 2: eine .htaccess mit RewriteEngine nähme ihrem Ordner die Cookie-Sperre – nicht kopiert.
no "Zusatz 2: .htaccess mit RewriteEngine kopiert" test -e "$SC/wp-content/plugins/e2e-rewrite/sub/.htaccess"
no "Zusatz 2: .htaccess mit RewriteBase (ohne RewriteEngine) kopiert" test -e "$SC/wp-content/plugins/e2e-rewrite/base/.htaccess"
ok "Zusatz 2: PHP-Datei daneben kopiert" test -f "$SC/wp-content/plugins/e2e-rewrite/sub/open.php"
ok "Zusatz 2: .htaccess ohne Rewrite bleibt" test -f "$SC/wp-content/plugins/e2e-rewrite/sub/keep/.htaccess"
eq "Rechte der Zustandsdatei" "$(stat -f '%Lp' "$(state_file)")" 640
eq "5.3 wp-config.php der Kopie mit den Rechten der von Live" "$(stat -f '%Lp' "$SC/wp-config.php")" "$(stat -f '%Lp' "$PUB/wp-config.php")"
eq "5.5 Kopie für Suchmaschinen gesperrt" "$(SQL "SELECT option_value FROM ${STG}options WHERE option_name = 'blog_public'")" 0
COPY_KB="$(du -sk "$SC" | cut -f1)"

echo "== AC-83, Zusatz 9: Live liefert keine Staging-Tabelle und keinen Staging-Ordner"
"$WPSYNC" scan "$TARGET" --refresh --preset vollstaendig --exclude-plugin e2e-excluded --json > "$E2E/scan-staging.json"
# env.staging nennt die Kopie mit Absicht (5.9) – Tabellen, Grössen und Funde dürfen es nicht.
no "AC-83 Infosheet nennt die Kopie" sh -c "tail -n 1 '$E2E/scan-staging.json' | jq -c 'del(.. | .staging?)' | grep -qE 'wpsync-staging-|$STG'"
eq "5.9 env.staging im Infosheet" "$(last "$E2E/scan-staging.json" .data.infosheet.env.staging.status)" ready
"$WPSYNC" pull "$TARGET" --full --yes > "$E2E/pull2.log"
no "AC-83 Staging-Ordner gezogen" test -e "$LOCAL/public/$STG_DIR"
eq "AC-83 kein Staging-Ordner lokal" "$(find "$LOCAL/public" -name 'wpsync-staging-*' | wc -l | tr -d ' ')" 0
eq "AC-83 keine Staging-Tabelle lokal" "$(LSQL "SHOW TABLES LIKE 'stg%'" | wc -l | tr -d ' ')" 0
ok "AC-83 der Pull hat Dump und Baseline abgelegt" test -s "$LOCAL/.wpsync/baseline.json" -a -d "$LOCAL/.wpsync/db/tables"
no "AC-83 Dump, Dateiliste oder Baseline nennen die Kopie" grep -rqE "wpsync-staging-|$STG" "$LOCAL/.wpsync"
"$WPSYNC" status "$TARGET" --json > "$E2E/status.json"
no "AC-83 status nennt Staging-Tabellen oder -Pfade" sh -c "tail -n 1 '$E2E/status.json' | jq -c 'del(.data.staging)' | grep -qE 'wpsync-staging-|$STG'"
eq "5.9 wpsync status meldet die Kopie" "$(last "$E2E/status.json" .data.staging.status)" ready
jrun staging-create-exists-51 "$WPSYNC" staging create "$TARGET" --yes --json
eq "6.4 create bei bestehender Kopie: Exit 51" "$RC" 51
ok "create bei bestehender Kopie lässt sie stehen" test -f "$SC/wp-config.php"

echo "== AC-91, Zusatz 4: ohne Zugang 403"
for p in "" wp-login.php wp-admin/ wp-content/themes/e2e-theme/style.css wp-config.php \
  wp-content/plugins/e2e-rewrite/sub/open.php wp-content/plugins/e2e-rewrite/base/open.php; do
  eq "AC-91 /$p ohne Cookie" "$(code "$STG_URL/$p")" 403
done
# Der Name allein genügt nicht: den Wert prüft der Riegel.
for p in "" wp-login.php wp-admin/ "?p=1"; do
  eq "AC-91 /$p mit erfundenem Cookie x" "$(code -b 'wpsync_stg=x' "$STG_URL/$p")" 403
  eq "AC-91 /$p mit erfundenem Cookie (64 Hex)" "$(code -b "wpsync_stg=$FORGED" "$STG_URL/$p")" 403
done
eq "AC-91 erfundener Link" "$(code "$STG_URL/?wpsync_login=$FORGED")" 403
for p in wp-config.php wp-config-old.php wp-config.php.bak wp-config-sample.php .env .env.local wp-content/.env \
  wp-content/wpsync-staging.json wp-content/debug.log wp-content/dump.sql .htaccess; do
  eq "AC-91 /$p mit erfundenem Cookie" "$(code -b 'wpsync_stg=x' "$STG_URL/$p")" 403
done
echo "INFO: PHP-Datei ohne WordPress mit erfundenem Cookie-Wert: HTTP $(code -b 'wpsync_stg=x' "$STG_URL/wp-content/plugins/e2e-rewrite/sub/open.php") (die .htaccess prüft nur den Namen, den Wert prüft der Riegel)"

echo "== AC-92, Zusatz 5: Einmal-Link"
JAR="$E2E/jar.txt"
rm -f "$JAR"
LINK="$("$WPSYNC" staging open "$TARGET" --print)"
TOKEN="${LINK##*wpsync_login=}"
[[ "$LINK" == "$STG_URL/?wpsync_login="* && "$TOKEN" =~ ^[a-f0-9]{64}$ ]] || fail "open --print liefert keinen Link auf die Kopie"
eq "Zusatz 5: Link nicht an wp-login.php einlösbar" "$(code "$STG_URL/wp-login.php?wpsync_login=$TOKEN")" 403
eq "Zusatz 5: Link nicht an wp-admin einlösbar" "$(code "$STG_URL/wp-admin/?wpsync_login=$TOKEN")" 403
curl -s -D "$E2E/login.headers" -o /dev/null -c "$JAR" "$LINK"
eq "AC-92 Link meldet an (auch nach den Versuchen an anderer Stelle)" "$(awk 'NR == 1 { print $2 }' "$E2E/login.headers")" 302
ok "Zusatz 5: Referrer-Policy no-referrer" grep -qi '^referrer-policy: no-referrer' "$E2E/login.headers"
ok "Zusatz 5: Cache-Control no-store" grep -qi '^cache-control:.*no-store' "$E2E/login.headers"
ok "Zusatz 5: Ziel ist wp-admin der Kopie" grep -qi "^location: $STG_URL/wp-admin/" "$E2E/login.headers"
STGC="$(awk '$6 == "wpsync_stg" { print $7 }' "$JAR")"
[[ "$STGC" =~ ^[a-f0-9]{64}$ ]] || fail "der Link setzt kein Zugangs-Cookie"
eq "Zusatz 5: Pfad des Zugangs-Cookies" "$(awk '$6 == "wpsync_stg" { print $3 }' "$JAR")" "/$STG_DIR/"
eq "Zusatz 5: kein Cookie ausserhalb des Staging-Pfads" "$(awk -v p="/$STG_DIR/" '!/^# / && NF >= 7 && index($3, p) != 1' "$JAR" | wc -l | tr -d ' ')" 0
ok "Zusatz 5: Zugangs-Cookie ist HttpOnly" grep -q "^#HttpOnly_.*wpsync_stg" "$JAR"
eq "AC-92 Link gilt kein zweites Mal" "$(code "$LINK")" 403
eq "AC-86 Startseite mit Zugang" "$(code -b "$JAR" "$STG_URL/")" 200
eq "AC-86 wp-admin mit Zugang" "$(code -b "$JAR" "$STG_URL/wp-admin/")" 200
ok "AC-92 angemeldet als wpsync" sh -c "curl -s -b '$JAR' '$STG_URL/wp-admin/profile.php' | grep -q 'id=\"user_login\" value=\"wpsync\"'"
ok "AC-89 Staging-Admin" sh -c "cd '$SRC' && ddev wp --path=/var/www/html/public/$STG_DIR user get wpsync --field=roles | grep -q administrator"
ok "AC-89 kopierter Administrator behält die Rolle" sh -c "cd '$SRC' && ddev wp --path=/var/www/html/public/$STG_DIR user get 1 --field=roles | grep -q administrator"
no "AC-89 Staging-Admin auf Live angelegt" ddev wp user get wpsync --field=ID
# Abgelaufen: der Link aus --json, 5 Minuten später.
jrun staging-open "$WPSYNC" staging open "$TARGET" --print --json
eq "open --json" "$RC" 0
LINK2="$(last "$E2E/json-raw.tmp" .data.url)"
no "T1 Token in der gespeicherten JSON-Zeile" grep -qE 'wpsync_login=[0-9a-f]' "$JSON/staging-open.jsonl"
eq "T1 open --json schreibt nichts auf stderr" "$(wc -c < "$JSON/staging-open.err" | tr -d ' ')" 0
edit_state '.tokens |= map_values(now - 1 | floor)'
eq "AC-92 abgelaufener Link" "$(code "$LINK2")" 403
# Sitzungen sind getrennt: Live-Cookie auf Staging und umgekehrt.
STGAUTH="$(awk '$6 ~ /^wordpress_/ { printf "%s=%s; ", $6, $7 }' "$JAR")"
[ -n "$STGAUTH" ] || fail "der Link setzt keine Admin-Sitzung"
eq "AC-92 Live-Sitzung gilt auf Staging nicht" "$(code -H "Cookie: ${LIVEAUTH}wpsync_stg=$STGC" "$STG_URL/wp-admin/")" 302
eq "AC-92 Staging-Sitzung gilt auf Live nicht" "$(code -H "Cookie: $STGAUTH" "$SOURCE_URL/wp-admin/")" 302
# Zusatz 1 und 2 mit gültigem Zugang.
for p in wp-config.php wp-config-old.php wp-config.php.bak wp-config-sample.php .env .env.local wp-content/.env \
  wp-content/plugins/e2e-rewrite/.env wp-content/wpsync-staging.json wp-content/debug.log .htaccess; do
  eq "Zusatz 1: /$p mit gültigem Cookie" "$(code -b "$JAR" "$STG_URL/$p")" 403
done
eq "Zusatz 2: PHP-Datei im Unterordner mit gültigem Cookie" "$(curl -s -b "$JAR" "$STG_URL/wp-content/plugins/e2e-rewrite/sub/open.php")" e2e-open

# Die Kopie schreibt ihre eigene .htaccess fort (Permalinks speichern): die Sperre bleibt davor.
eq "5.4 Permalink-Seite der Kopie" "$(code -b "$JAR" "$STG_URL/wp-admin/options-permalink.php")" 200
echo "INFO: WordPress hat die .htaccess der Kopie ergänzt: $(grep -c '# BEGIN WordPress' "$SC/.htaccess" || true) Block(e)"
eq "5.4 nach dem Permalink-Flush ohne Cookie" "$(code "$STG_URL/")" 403
eq "5.4 nach dem Permalink-Flush mit erfundenem Cookie" "$(code -b 'wpsync_stg=x' "$STG_URL/wp-login.php")" 403
eq "5.4 nach dem Permalink-Flush wp-config.php mit gültigem Cookie" "$(code -b "$JAR" "$STG_URL/wp-config.php")" 403
eq "5.4 nach dem Permalink-Flush Seite mit gültigem Cookie" "$(code -b "$JAR" "$STG_URL/kontakt/")" 200

echo "== AC-93, AC-86: noindex, Adressen, Medien"
curl -s -D "$E2E/home.headers" -o "$E2E/home.html" -b "$JAR" "$STG_URL/"
ok "AC-93 Header" grep -qi '^x-robots-tag: noindex' "$E2E/home.headers"
ok "AC-93 meta" grep -q "name='robots' content='noindex, nofollow'" "$E2E/home.html"
ok "AC-93 Header auch an einer 403" sh -c "curl -s -D - -o /dev/null '$STG_URL/' | grep -qi '^x-robots-tag: noindex'"
ok "AC-86 Links zeigen auf die Kopie" grep -qF "$STG_URL/kontakt/" "$E2E/home.html"
# Jede Nennung der Live-Adresse in der Startseite geht in der Kopie weiter (auch als escaptes JSON
# und URL-kodiert) – bis auf den Hinweis der Admin-Leiste, der Live mit Absicht nennt.
HOST="${SOURCE_URL#*://}"
eq "AC-86 keine Adresse der Startseite zeigt auf Live" \
  "$(sed "s#Kopie von $SOURCE_URL##g" "$E2E/home.html" | grep -oE "${HOST//./\\.}((\\\\?/|%2F)[A-Za-z0-9_-]+)?" | grep -vc "wpsync-staging-" || true)" 0
ok "5.5 Hinweis in der Admin-Leiste nennt Live" grep -qF "STAGING – Kopie von $SOURCE_URL" "$E2E/home.html"
eq "AC-86 Seite der Kopie" "$(code -b "$JAR" "$STG_URL/kontakt/")" 200
eq "AC-86 fehlende Medien kommen von Live" "$(curl -s -o /dev/null -w '%{redirect_url}' -b "$JAR" "$STG_URL/wp-content/uploads/2020/01/live-only.jpg")" \
  "$SOURCE_URL/wp-content/uploads/2020/01/live-only.jpg"
# H1, Leitplanke 4: ein absolutes upload_path (wie von Live mitgebracht) zeigt nicht auf die Uploads
# von Live – und ein Medium, das auf Staging gelöscht wird, bleibt auf Live.
eq "Leitplanke 4: upload_path der Kopie geleert" "$(SQL "SELECT option_value FROM ${STG}options WHERE option_name = 'upload_path'")" ""
stg option update upload_path /var/www/html/public/wp-content/uploads >/dev/null
stg option update upload_url_path "$SOURCE_URL/wp-content/uploads" >/dev/null
eq "Leitplanke 4: Uploads der Kopie trotz absolutem upload_path" "$(stg eval '$u = wp_upload_dir(null, false); echo $u["basedir"], " ", $u["baseurl"];')" \
  "/var/www/html/public/$STG_DIR/wp-content/uploads $STG_URL/wp-content/uploads"
stg eval '$id = wp_insert_attachment(["post_mime_type" => "image/jpeg", "post_title" => "e2e-h1", "post_status" => "inherit"], "2020/01/live-only.jpg"); wp_delete_attachment($id, true);' >/dev/null
ok "Leitplanke 4: Medium auf Staging gelöscht, Datei auf Live bleibt" test -f "$WPC/uploads/2020/01/live-only.jpg"
stg option update upload_path '' >/dev/null
stg option update upload_url_path '' >/dev/null

echo "== AC-87: serialisierte Werte und Elementor-JSON"
eq "AC-87 serialisierte Option" "$(stg eval '$v = get_option("e2e_serialized"); echo is_array($v) && strpos($v["url"], "/'"$STG_DIR"'/x") !== false && strpos($v["list"][0], "'"$STG_DIR"'") !== false ? "ok" : "bad";')" ok
eq "AC-87 Elementor-JSON" "$(stg eval '$d = json_decode(get_post_meta('"$PAGE_ID"', "_elementor_data", true), true); echo is_array($d) && strpos($d[0]["settings"]["url"], "/'"$STG_DIR"'/kontakt/") !== false ? "ok" : "bad";')" ok
no "AC-87 guid der Seite leer" test -z "$(SQL "SELECT guid FROM ${STG}posts WHERE ID = $PAGE_ID")"
eq "AC-87 guid unverändert" "$(SQL "SELECT guid FROM ${STG}posts WHERE ID = $PAGE_ID")" "$(SQL "SELECT guid FROM e2e_posts WHERE ID = $PAGE_ID")"
eq "AC-87 Live behält seine Adresse" "$(ddev wp eval 'echo get_option("e2e_serialized")["url"];')" "$SOURCE_URL/x"

echo "== AC-88: anonymisiert, Pseudonyme wie lokal"
SQL "SELECT user_email FROM ${STG}users UNION ALL SELECT display_name FROM ${STG}users UNION ALL SELECT billing_email FROM ${STG}wc_orders UNION ALL SELECT email FROM ${STG}wc_order_addresses UNION ALL SELECT first_name FROM ${STG}wc_order_addresses UNION ALL SELECT meta_value FROM ${STG}usermeta UNION ALL SELECT option_value FROM ${STG}options WHERE option_name = 'admin_email'" > "$E2E/staging-pii.txt"
ok "AC-88 Abfrage der Staging-Tabellen liefert Zeilen" test "$(wc -l < "$E2E/staging-pii.txt")" -gt 10
no "AC-88 Klartext-Adresse oder -Name auf Staging" grep -qiE 'kunde-echt|mustermann|erika' "$E2E/staging-pii.txt"
ok "AC-88 Gegenprobe: Live enthält die Daten im Klartext" test "$(SQL "SELECT COUNT(*) FROM e2e_wc_order_addresses WHERE first_name = 'Erika'")" -ge 1
ERIKA_STG="$(SQL "SELECT user_email FROM ${STG}users WHERE ID = $ERIKA_ID")"
ok "AC-88 Pseudonym" sh -c "printf %s '$ERIKA_STG' | grep -Eq '^user-[0-9a-f]{16}@example\.invalid$'"
eq "AC-88 Pseudonyme wie beim lokalen Pull" "$ERIKA_STG" "$(LSQL "SELECT user_email FROM e2e_users WHERE ID = $ERIKA_ID")"
eq "AC-88 Live behält die echte Adresse" "$(SQL "SELECT user_email FROM e2e_users WHERE ID = $ERIKA_ID")" erika.mustermann@kunde-echt.example
eq "AC-88 kein Passwort-Hash von Live auf Staging" "$(SQL "SELECT COUNT(*) FROM ${STG}users WHERE user_login <> 'wpsync' AND user_pass <> '!wpsync-anonymized'")" 0

echo "== AC-94, Zusatz 6: Mails und ausgehende Anfragen"
mails_clear
stg eval 'wp_mail("erika.mustermann@kunde-echt.example", "e2e staging mail", "x", ["Cc: chef@kunde-echt.example", "Bcc: stille@kunde-echt.example"]);'
# Passwort-Reset über den Webserver, wie ihn ein Besucher der Kopie auslöst.
eq "AC-94 Passwort-Reset auf Staging" "$(code -b "$JAR" --data 'user_login=wpsync&wp-submit=1' "$STG_URL/wp-login.php?action=lostpassword")" 302
# WooCommerce-Bestellung auf Staging (schreibt nur in die Tabellen der Kopie).
stg eval '$o = wc_create_order(); $o->set_billing_email("kundin@kunde-echt.example"); $o->set_billing_first_name("Karla"); $o->save(); $o->update_status("processing");' >/dev/null
sleep 2
mails > "$E2E/mails.json"
ok "AC-94 jede Mail der Kopie geht nur an $SINK" only_sink 2
ok "AC-94 Mail an eine echt aussehende Adresse abgefangen" jq -e 'any(.messages[]; .Subject | contains("e2e staging mail") and contains("[STAGING → erika.mustermann@kunde-echt.example]"))' "$E2E/mails.json"
echo "INFO: Mails der Kopie im Mailpit: $(jq -c '[.messages[].Subject]' "$E2E/mails.json")"
cat > "$SRC/.e2e/outgoing.php" <<'PHP'
<?php
// wp eval-file outgoing.php <live-url>: was die Kopie nach draussen schicken darf.
$live = $args[0];
$say  = static function ($r) {
    return is_wp_error($r)
        ? 'error:' . $r->get_error_code() . ':' . $r->get_error_message()
        : 'open:' . wp_remote_retrieve_response_code($r) . ':' . substr(wp_remote_retrieve_body($r), 0, 40);
};
$urls = [
    'live-rest'       => $live . '/wp-json/',
    'live-home'       => $live . '/',
    'live-rest-route' => $live . '/?rest_route=/',
    'upload'          => $live . '/wp-content/uploads/2020/01/live-only.jpg',
    'upload-query'    => $live . '/wp-content/uploads/2020/01/live-only.jpg?rest_route=/',
    'upload-dots'     => $live . '/wp-content/uploads/../../wp-json/',
    'upload-redirect' => $live . '/wp-content/uploads/2020/01/e2e-redirect.php',
    'self'            => home_url('/?e2e=1'),
];
foreach ($urls as $name => $url) {
    echo $name, '=', $say(wp_remote_get($url, ['timeout' => 15])), "\n";
}
// Fremde Dienste: nur die Entscheidung des Riegels – keine Anfrage verlässt den Rechner.
$hosts = [
    'stripe'  => 'https://api.stripe.com/v1/charges',
    'paypal'  => 'https://api-m.paypal.com/v1/oauth2/token',
    'brevo'   => 'https://api.brevo.com/v3/smtp/email',
    'ses'     => 'https://email.eu-central-1.amazonaws.com/',
    'klaviyo' => 'https://a.api.klaviyo.com/api/events',
    'neutral' => 'https://example.com/',
];
foreach ($hosts as $name => $url) {
    $r = apply_filters('pre_http_request', false, [], $url);
    echo $name, '=', is_wp_error($r) ? 'blocked:' . $r->get_error_code() : 'open', "\n";
}
PHP
stg eval-file /var/www/html/.e2e/outgoing.php "$SOURCE_URL" > "$E2E/outgoing.txt" 2>"$E2E/outgoing.err" || { cat "$E2E/outgoing.err"; fail "ausgehende Anfragen der Kopie liessen sich nicht prüfen"; }
out() { sed -n "s/^$1=//p" "$E2E/outgoing.txt"; }
cat "$E2E/outgoing.txt"
for name in live-rest live-home live-rest-route upload-query upload-dots; do
  ok "Leitplanke 7: $name aus der Kopie gesperrt" grep -q "^$name=error:wpsync_staging_blocked:" "$E2E/outgoing.txt"
done
eq "Zusatz 6: Upload von Live ohne Query erlaubt" "$(out upload)" "open:200:live-only"
ok "Zusatz 6: Weiterleitung von erlaubt auf gesperrt endet als WP_Error" grep -q "^upload-redirect=error:.*Weiterleitung gesperrt" "$E2E/outgoing.txt"
ok "Zusatz 6: die Kopie darf sich selbst anfragen" grep -q "^self=open:" "$E2E/outgoing.txt"
for name in stripe paypal brevo ses klaviyo; do
  eq "AC-94 $name gesperrt" "$(out "$name")" blocked:wpsync_staging_blocked
done
eq "AC-94 fremder Host ohne Sperre bleibt offen" "$(out neutral)" open

echo "== AC-95, AC-96: Cron, Webhooks, Zahlungen, Caches, Sitzungen"
eq "AC-95 WP-Cron aus" "$(stg eval 'echo DISABLE_WP_CRON ? "off" : "on";')" off
eq "AC-95 Action-Scheduler-Runner aus" "$(stg eval 'echo apply_filters("action_scheduler_allow_async_request_runner", true) ? "on" : "off";')" off
eq "AC-95 Webhooks pausiert" "$(SQL "SELECT DISTINCT status FROM ${STG}wc_webhooks")" paused
eq "AC-95 Webhook auf Live bleibt aktiv" "$(SQL "SELECT DISTINCT status FROM e2e_wc_webhooks")" active
ok "AC-95 Stripe auf Staging aus" sh -c "cd '$SRC' && ddev wp --path=/var/www/html/public/$STG_DIR option get woocommerce_stripe_settings --format=json | grep -q '\"enabled\":\"no\"'"
ok "AC-95 Überweisung bleibt an" sh -c "cd '$SRC' && ddev wp --path=/var/www/html/public/$STG_DIR option get woocommerce_bacs_settings --format=json | grep -q '\"enabled\":\"yes\"'"
ok "AC-95 Stripe auf Live unberührt" sh -c "cd '$SRC' && ddev wp option get woocommerce_stripe_settings --format=json | grep -q '\"enabled\":\"yes\"'"
no "AC-96 Salts wie Live" test "$(stg eval 'echo AUTH_KEY;')" = "$(ddev wp eval 'echo AUTH_KEY;')"
eq "AC-96 Cookie-Pfad" "$(stg eval 'echo COOKIEPATH;')" "/$STG_DIR/"
no "AC-96 Cache-Salt leer" test -z "$(stg eval 'echo WP_CACHE_KEY_SALT;')"
eq "AC-96 kein WP_CACHE" "$(stg eval 'echo WP_CACHE ? "on" : "off";')" off
eq "5.3 Umgebung" "$(stg eval 'echo wp_get_environment_type();')" staging
jrun staging-status "$WPSYNC" staging status "$TARGET" --json
eq "status mit Kopie: Exit 0" "$RC" 0
eq "status: bereit" "$(last "$JSON/staging-status.jsonl" .data.status)" ready
DB_BYTES="$(last "$JSON/staging-status.jsonl" '.data.db_bytes // 0')"
CODE_AT="$(last "$JSON/staging-status.jsonl" '.data.code_copied_at // 0')"
COPIED_AT="$(last "$JSON/staging-status.jsonl" '.data.copied_at // 0')"
ok "status nennt code_copied_at" test "$CODE_AT" -gt 0

echo "== AC-97: Push nach Staging nur mit Fenster, Live unverändert"
cp "$LOCAL/.wpsync/baseline.json" "$E2E/baseline.before"
git_state > "$E2E/git.before"
mark 2
window 0
jrun push-staging-window-closed-40 "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes --json
eq "AC-97 ohne Fenster: Exit 40" "$RC" 40
ok "AC-97 ohne Fenster nichts auf Staging" grep -q 'e2e-marker v1' "$SC/wp-content/themes/e2e-theme/index.php"
window "time() + 900"
jrun push-staging "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes --json
eq "AC-97 Push nach Staging (erster, ohne --force)" "$RC" 0
cat "$JSON/push-staging.err"
PUSH_STG="$(last "$JSON/push-staging.jsonl" .data.push_id)"
eq "Push nach Staging: Ziel im Ergebnis" "$(last "$JSON/push-staging.jsonl" '.data.target + " " + .data.status')" "staging confirmed"
for event in plan upload commit health; do
  ok "T4 Ereignis $event" hasF "$JSON/push-staging.jsonl" "\"event\":\"$event\""
done
ok "AC-97 Staging gleicht dem lokalen Stand" cmp -s "$THEME/index.php" "$SC/wp-content/themes/e2e-theme/index.php"
ok "AC-97 Live unverändert" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"
ok "AC-105 Änderung auf Staging sichtbar" sh -c "curl -s -b '$JAR' '$STG_URL/' | grep -q 'e2e-marker v2'"
ok "AC-105 Live zeigt den alten Stand" sh -c "curl -s '$SOURCE_URL/' | grep -q 'e2e-marker v1'"
ok "6.2 Hinweis auf den Live-Push" hasF "$JSON/push-staging.err" "wpsync push $TARGET code themes/e2e-theme"
ok "V8 Arbeitsordner des Pushs liegt in der Kopie" sh -c "find '$SC/wp-content' -maxdepth 1 -name 'wpsync-push-*' | grep -q ."
eq "Zusatz 7: der erste Push nach Staging merkt sich die Stempel der Einheit" "$(stamp_push themes/e2e-theme)" "$PUSH_STG"

echo "== AC-98: Baseline unverändert, Live-Push schlägt dieselbe Einheit vor"
ok "AC-98 Baseline unverändert" cmp -s "$E2E/baseline.before" "$LOCAL/.wpsync/baseline.json"
run push-dry.log "$WPSYNC" push "$TARGET" code --dry-run
eq "AC-98 Probelauf nach Live" "$RC" 0
ok "AC-98 Live-Push schlägt die Einheit vor" hasF "$E2E/push-dry.log" "themes/e2e-theme"

echo "== AC-99: Syntaxfehler auf Staging wird zurückgerollt"
HEALTH="$LOCAL/public/wp-content/plugins/e2e-health/e2e-health.php"
cp -p "$HEALTH" "$E2E/e2e-health.good"
printf '\nthis is not php(\n' >> "$HEALTH"
cp "$STAMPS" "$E2E/stamps.before"
jrun push-staging-rolled-back-43 "$WPSYNC" push "$TARGET" code plugins/e2e-health --to staging --yes --json
eq "AC-99 zurückgerollt: Exit 43" "$RC" 43
cat "$JSON/push-staging-rolled-back-43.err"
no "AC-99 kaputter Code noch auf Staging" grep -q "this is not php" "$SC/wp-content/plugins/e2e-health/e2e-health.php"
eq "AC-99 Kopie wieder erreichbar" "$(code -b "$JAR" "$STG_URL/")" 200
eq "AC-99 Live erreichbar" "$(code "$SOURCE_URL/")" 200
no "AC-99 kaputter Code auf Live" grep -q "this is not php" "$WPC/plugins/e2e-health/e2e-health.php"
ok "Zusatz 7: ein zurückgerollter Push lässt die gemerkten Stempel byte-gleich" cmp -s "$E2E/stamps.before" "$STAMPS"
cp -p "$E2E/e2e-health.good" "$HEALTH"

echo "== AC-100: Riegel und Pfade ausserhalb der Kopie nicht pushbar (manipulierter Client)"
CONTENT='<?php // manipuliert'
SHA="$(printf %s "$CONTENT" | openssl dgst -sha256 -r | cut -d' ' -f1)"
push_body() { printf '{"target":"staging","dry":true,"force":true,"units":[{"path":"%s","base":{},"files":{"%s":{"size":%d,"sha256":"%s","mtime":1700000000}}}]}' "$1" "$2" "${#CONTENT}" "$SHA"; }
eq "AC-100 Gegenprobe: erlaubte Datei wird angenommen" "$(signed /wpsync/v1/push/begin "$(push_body themes/e2e-theme x.php)" -o /dev/null -w '%{http_code}')" 200
for unit in 'mu-plugins|00-wpsync-staging.php' 'mu-plugins|wpsync-staging/StagingAccess.php' 'mu-plugins|wpsync-staging/x.php' \
  'plugins/../../wp-content/plugins/x|x.php' 'plugins/wpsync-agent|x.php' "themes/e2e-theme|../../../../wp-config.php"; do
  path="${unit%%|*}"
  file="${unit##*|}"
  eq "AC-100 $path/$file abgelehnt" "$(signed /wpsync/v1/push/begin "$(push_body "$path" "$file")" -o /dev/null -w '%{http_code}')" 400
done
ok "AC-100 Riegel unverändert" cmp -s "$ROOT/agent/staging/00-wpsync-staging.php" "$SC/wp-content/mu-plugins/00-wpsync-staging.php"

echo "== Zusatz 7: wiederholte Pushes nach Staging ohne --force"
STGTHEME="$SC/wp-content/themes/e2e-theme"
window "time() + 900"
mark 3
jrun push-staging-second "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes --json
cat "$JSON/push-staging-second.err"
eq "Zusatz 7: zweiter Push nach Staging ohne --force" "$RC" 0
PUSH_STG2="$(last "$JSON/push-staging-second.jsonl" .data.push_id)"
eq "Zusatz 7: zweiter Push ohne Konflikt im Plan" "$(conflicts "$JSON/push-staging-second.jsonl")" '[]'
ok "Zusatz 7: Kopie hat den Stand des zweiten Pushs ($MARK)" cmp -s "$THEME/index.php" "$STGTHEME/index.php"
ok "Zusatz 7: Live unverändert" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"
ok "AC-98 Baseline auch nach dem zweiten Push byte-gleich" cmp -s "$E2E/baseline.before" "$LOCAL/.wpsync/baseline.json"
git_state > "$E2E/git.after"
same "AC-98 internes Git nach zwei Pushes nach Staging unverändert" "$E2E/git.before" "$E2E/git.after"
ok "Zusatz 7: .wpsync/staging-base.json liegt neben der Baseline" test -f "$STAMPS" -a ! -L "$STAMPS"
eq "Zusatz 7: gemerkt sind die Stempel des zweiten Pushs" "$(stamp_push themes/e2e-theme)" "$PUSH_STG2"

# Eine Datei der gepushten Einheit wird direkt in der Kopie geändert: der nächste Push meldet das.
printf '\n// direkt in der Kopie geändert\n' >> "$STGTHEME/functions.php"
mark 4
cp "$STAMPS" "$E2E/stamps.before"
jrun push-staging-conflict-41 "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes --json
cat "$JSON/push-staging-conflict-41.err"
eq "Zusatz 7: in der Kopie geänderte Datei: Exit 41" "$RC" 41
eq "Zusatz 7: Konflikt nennt genau die in der Kopie geänderte Datei" "$(conflicts "$JSON/push-staging-conflict-41.jsonl")" '["functions.php"]'
ok "Zusatz 7: Hinweis, dass ein Pull den Konflikt nicht löst" hasF "$JSON/push-staging-conflict-41.err" "in der Kopie geändert seit dem letzten Push nach Staging"
ok "Zusatz 7: abgelehnter Push ändert die Kopie nicht (index.php)" grep -q 'e2e-marker v3' "$STGTHEME/index.php"
ok "Zusatz 7: abgelehnter Push ändert die Kopie nicht (functions.php)" grep -q 'direkt in der Kopie geändert' "$STGTHEME/functions.php"
ok "Zusatz 7: abgelehnter Push lässt die gemerkten Stempel byte-gleich" cmp -s "$E2E/stamps.before" "$STAMPS"
jrun push-staging-force "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes --force --json
eq "Zusatz 7: derselbe Push mit --force" "$RC" 0
PUSH_FORCE="$(last "$JSON/push-staging-force.jsonl" .data.push_id)"
ok "Zusatz 7: --force bringt den lokalen Stand ($MARK)" cmp -s "$THEME/index.php" "$STGTHEME/index.php"
ok "Zusatz 7: --force überschreibt die Änderung in der Kopie" cmp -s "$THEME/functions.php" "$STGTHEME/functions.php"
# Danach geht es wieder ohne --force weiter.
mark 5
jrun push-staging-after-force "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes --json
eq "Zusatz 7: Push nach dem --force wieder ohne --force" "$RC" 0
PUSH_STG3="$(last "$JSON/push-staging-after-force.jsonl" .data.push_id)"
ok "Zusatz 7: Kopie hat den Stand $MARK" cmp -s "$THEME/index.php" "$STGTHEME/index.php"

# Rollback eines Pushs nach Staging: die Stempel gehen auf den Stand davor zurück.
jrun rollback-staging-id "$WPSYNC" rollback "$TARGET" "$PUSH_STG3" --json
eq "Zusatz 7: rollback <staging-id>" "$RC" 0
eq "Zusatz 7: rollback <staging-id>: Ziel im Ergebnis" "$(last "$JSON/rollback-staging-id.jsonl" '.data.push_id + " " + .data.target + " " + .data.status')" "$PUSH_STG3 staging rolled_back"
ok "Zusatz 7: Kopie nach dem Rollback auf dem Stand davor (v4)" grep -q 'e2e-marker v4' "$STGTHEME/index.php"
eq "Zusatz 7: gemerkt sind wieder die Stempel des Pushs davor" "$(stamp_push themes/e2e-theme)" "$PUSH_FORCE"
jrun push-staging-after-rollback "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes --json
cat "$JSON/push-staging-after-rollback.err"
eq "Zusatz 7: Push nach Staging nach dem Rollback ohne --force" "$RC" 0
PUSH_STG4="$(last "$JSON/push-staging-after-rollback.jsonl" .data.push_id)"
ok "Zusatz 7: Kopie hat wieder den Stand $MARK" cmp -s "$THEME/index.php" "$STGTHEME/index.php"
ok "AC-98 Baseline nach allen Pushes nach Staging byte-gleich" cmp -s "$E2E/baseline.before" "$LOCAL/.wpsync/baseline.json"
git_state > "$E2E/git.after"
same "AC-98 internes Git nach allen Pushes nach Staging unverändert" "$E2E/git.before" "$E2E/git.after"
ok "Zusatz 7: Live nach allen Pushes nach Staging unverändert" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"

echo "== Zusatz 8: derselbe Stand nach Live, rollback pro Ziel (V10)"
# Live wird gegen die Baseline geprüft, nie gegen die Staging-Stempel: mit denen (Stand $MARK der
# Kopie) meldete der Agent jede Datei von Live als geändert.
cp "$STAMPS" "$E2E/stamps.before"
jrun push-live "$WPSYNC" push "$TARGET" code themes/e2e-theme --yes --json
eq "Zusatz 8: Push nach Live ohne --force" "$RC" 0
PUSH_LIVE="$(last "$JSON/push-live.jsonl" .data.push_id)"
eq "Zusatz 8: Ziel im Ergebnis" "$(last "$JSON/push-live.jsonl" '.data.target + " " + .data.status')" "live confirmed"
eq "Zusatz 8: Plan gegen die Baseline: kein Konflikt, eine Datei zu übertragen" \
  "$(jq -c 'select(.event == "plan") | [.data.units[] | [.path, .conflicts, .upload]]' "$JSON/push-live.jsonl")" '[["themes/e2e-theme",[],1]]'
ok "Zusatz 8: Live hat den lokalen Stand" cmp -s "$THEME/index.php" "$WPC/themes/e2e-theme/index.php"
ok "Zusatz 8: Kopie bleibt bei $MARK" grep -q "e2e-marker $MARK" "$STGTHEME/index.php"
no "Zusatz 8: Baseline nach dem Live-Push unverändert" cmp -s "$E2E/baseline.before" "$LOCAL/.wpsync/baseline.json"
ok "Zusatz 8: der Live-Push lässt die Staging-Stempel byte-gleich" cmp -s "$E2E/stamps.before" "$STAMPS"
git_state > "$E2E/git.live"
no "Zusatz 8: internes Git nach dem Live-Push unverändert" cmp -s "$E2E/git.before" "$E2E/git.live"
ok "Zusatz 8: der Schnappschuss des Live-Pushs enthält die Baseline" in_git '^\.wpsync/baseline\.json$'
no "Zusatz 7: staging-base.json im internen Git" in_git 'staging-base'
no "Zusatz 7: staging-base.json in der Historie des internen Gits" sh -c "git --git-dir='$GITDIR' log --all --name-only --format= | grep -q 'staging-base'"
jrun pushes "$WPSYNC" pushes "$TARGET" --json
eq "pushes --json" "$RC" 0
eq "pushes: Ziel je Push" "$(last "$JSON/pushes.jsonl" "[.data.pushes[] | select(.push_id == \"$PUSH_STG\" or .push_id == \"$PUSH_LIVE\") | .target] | sort | join(\",\")")" "live,staging"
# Erwartete Ablehnung: die ID gehört einem Push nach Staging, --to nennt Live.
run rollback-wrong-target.log "$WPSYNC" rollback "$TARGET" "$PUSH_STG4" --to live
eq "Zusatz 8: rollback <staging-id> --to live abgelehnt" "$RC" 1
cat "$E2E/rollback-wrong-target.log"
ok "Zusatz 8: die Ablehnung nennt das Ziel des Pushs" hasF "$E2E/rollback-wrong-target.log" "ging nach Staging, nicht nach Live"
ok "Zusatz 8: danach Live unverändert" grep -q "e2e-marker $MARK" "$WPC/themes/e2e-theme/index.php"
ok "Zusatz 8: danach Kopie unverändert" grep -q "e2e-marker $MARK" "$STGTHEME/index.php"
ok "Zusatz 8: danach Staging-Stempel unverändert" cmp -s "$E2E/stamps.before" "$STAMPS"
jrun rollback "$WPSYNC" rollback "$TARGET" --json
eq "Zusatz 8: rollback ohne ID" "$RC" 0
eq "V10 rollback ohne ID nimmt den Live-Push" "$(last "$JSON/rollback.jsonl" '.data.push_id + " " + .data.target + " " + .data.status')" "$PUSH_LIVE live rolled_back"
ok "Zusatz 8: Live wieder auf dem alten Stand" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"
ok "Zusatz 8: Kopie vom Live-Rollback unberührt" grep -q "e2e-marker $MARK" "$STGTHEME/index.php"
ok "Zusatz 8: lokal nichts verändert" grep -q "e2e-marker $MARK" "$THEME/index.php"
ok "Zusatz 8: der Live-Rollback lässt die Staging-Stempel byte-gleich" cmp -s "$E2E/stamps.before" "$STAMPS"
# Der neueste Staging-Push zurück – obwohl der Live-Push jünger war –, danach wieder ohne --force.
jrun rollback-staging "$WPSYNC" rollback "$TARGET" --to staging --json
eq "rollback --to staging" "$RC" 0
eq "rollback --to staging nimmt den neuesten Push nach Staging" "$(last "$JSON/rollback-staging.jsonl" '.data.push_id + " " + .data.target + " " + .data.status')" "$PUSH_STG4 staging rolled_back"
ok "rollback --to staging: Kopie auf dem Stand davor (v4)" grep -q 'e2e-marker v4' "$STGTHEME/index.php"
ok "rollback --to staging: Live unberührt" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"
eq "rollback --to staging: gemerkt sind wieder die Stempel des Pushs davor" "$(stamp_push themes/e2e-theme)" "$PUSH_FORCE"
run push-staging-3.log "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes
cat "$E2E/push-staging-3.log"
eq "Push nach Staging nach rollback --to staging ohne --force" "$RC" 0
ok "Kopie hat den lokalen Stand $MARK" cmp -s "$THEME/index.php" "$STGTHEME/index.php"

echo "== Zusatz 2: eine gepushte .htaccess mit Rewrite-Direktiven öffnet den Unterordner nicht"
REWRITE="$LOCAL/public/wp-content/plugins/e2e-rewrite"
printf '\n// push %s\n' "$(date +%s)" >> "$REWRITE/e2e-rewrite.php"
ok "lokal liegen die .htaccess der Unterordner" test -f "$REWRITE/sub/.htaccess" -a -f "$REWRITE/base/.htaccess"
run push-rewrite.log "$WPSYNC" push "$TARGET" code plugins/e2e-rewrite --to staging --yes
cat "$E2E/push-rewrite.log"
eq "Zusatz 2: erster Push der Einheit nach Staging ohne --force (was die Kopie weglässt, ist kein Konflikt)" "$RC" 0
ok "Zusatz 2: Push kam an" cmp -s "$REWRITE/e2e-rewrite.php" "$SC/wp-content/plugins/e2e-rewrite/e2e-rewrite.php"
for d in sub base; do
  no "Zusatz 2: $d/.htaccess nach dem Push in der Kopie" test -e "$SC/wp-content/plugins/e2e-rewrite/$d/.htaccess"
  eq "Zusatz 2: $d/open.php nach dem Push ohne Cookie" "$(code "$STG_URL/wp-content/plugins/e2e-rewrite/$d/open.php")" 403
  ok "Zusatz 2: der Push nennt $d/.htaccess als nicht übernommen" hasF "$E2E/push-rewrite.log" "nicht in die Kopie übernommen: plugins/e2e-rewrite/$d/.htaccess"
done
ok "Zusatz 2: .htaccess ohne Rewrite kommt mit" test -f "$SC/wp-content/plugins/e2e-rewrite/sub/keep/.htaccess"
# Auch mit --force, und auch als zweiter Push derselben Einheit.
printf '// again\n' >> "$REWRITE/e2e-rewrite.php"
run push-rewrite-force.log "$WPSYNC" push "$TARGET" code plugins/e2e-rewrite --to staging --yes --force
eq "Zusatz 2: Push mit --force" "$RC" 0
for d in sub base; do
  eq "Zusatz 2: $d/open.php nach --force ohne Cookie" "$(code "$STG_URL/wp-content/plugins/e2e-rewrite/$d/open.php")" 403
done
ok "Zusatz 2: Live behält seine .htaccess" test -f "$WPC/plugins/e2e-rewrite/sub/.htaccess" -a -f "$WPC/plugins/e2e-rewrite/base/.htaccess"

echo "== AC-101: refresh"
SQL "UPDATE ${STG}options SET option_value = 'staging-only' WHERE option_name = 'blogdescription'"
SQL "INSERT INTO e2e_wpsync_pushes (push_id, key_id, device, target, status, units, created) VALUES ('p_20261006_aaaaaaaaaaaa', '$KEY_ID', 'e2e', 'staging', 'committed', '[]', UNIX_TIMESTAMP())"
run refresh-pending.log "$WPSYNC" staging refresh "$TARGET" --yes
eq "AC-101 unbestätigter Staging-Push blockiert refresh: Exit 42" "$RC" 42
run refresh-code-pending.log "$WPSYNC" staging refresh "$TARGET" --code --yes
eq "AC-101 unbestätigter Staging-Push blockiert refresh --code: Exit 42" "$RC" 42
run delete-pending.log "$WPSYNC" staging delete "$TARGET" --yes
eq "V9 unbestätigter Staging-Push blockiert delete: Exit 42" "$RC" 42
ok "V9 die Kopie steht nach dem abgelehnten delete" test -f "$SC/wp-config.php"
SQL "DELETE FROM e2e_wpsync_pushes WHERE push_id = 'p_20261006_aaaaaaaaaaaa'"
"$WPSYNC" scan "$TARGET" --preset ohne-transaktionen --exclude-plugin e2e-excluded >/dev/null
jrun staging-refresh "$WPSYNC" staging refresh "$TARGET" --yes --json "${FAST[@]}"
eq "AC-101 refresh" "$RC" 0
no "AC-101 Datenbank nicht aufgefrischt" test "$(SQL "SELECT option_value FROM ${STG}options WHERE option_name = 'blogdescription'")" = staging-only
ok "AC-101 gepushter Code bleibt" grep -q "e2e-marker $MARK" "$SC/wp-content/themes/e2e-theme/index.php"
eq "AC-90 abgewählte Tabelle existiert leer" "$(SQL "SELECT COUNT(*) FROM ${STG}wc_orders")" 0
ok "AC-90 Live behält die Bestellungen" test "$(SQL "SELECT COUNT(*) FROM e2e_wc_orders")" -gt 0
eq "5.4 nach refresh gilt das alte Cookie nicht mehr" "$(code -b "$JAR" "$STG_URL/")" 403
rm -f "$JAR"
[ "$(code -c "$JAR" "$("$WPSYNC" staging open "$TARGET" --print)")" = 302 ] || fail "Zugang nach refresh"
eq "Zugang nach refresh" "$(code -b "$JAR" "$STG_URL/")" 200
# Zusatz 7: ein refresh nur der Daten lässt den Code der Kopie stehen – die Stempel gelten weiter.
"$WPSYNC" staging status "$TARGET" --json > "$E2E/status-refreshed.json"
eq "Zusatz 7: refresh ohne --code lässt code_copied_at stehen" "$(last "$E2E/status-refreshed.json" .data.code_copied_at)" "$CODE_AT"
ok "Zusatz 7: refresh ohne --code setzt copied_at neu" test "$(last "$E2E/status-refreshed.json" .data.copied_at)" -gt "$COPIED_AT"
window "time() + 900"
mark 6
jrun push-staging-after-refresh "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes --json
cat "$JSON/push-staging-after-refresh.err"
eq "Zusatz 7: Push nach Staging nach refresh (ohne --code) ohne --force" "$RC" 0
no "Zusatz 7: refresh ohne --code verwirft die Stempel" hasF "$JSON/push-staging-after-refresh.err" "neu von Live"
ok "Zusatz 7: Kopie hat den Stand $MARK" cmp -s "$THEME/index.php" "$SC/wp-content/themes/e2e-theme/index.php"
eq "Zusatz 7: Stempel der anderen Einheit bleiben über den refresh" "$(jq -c '.units | keys' "$STAMPS")" '["plugins/e2e-rewrite","themes/e2e-theme"]'
# Abgebrochener refresh (V12): Tabellen weg, Kopie gesperrt, create bleibt verwehrt.
ddev wp config set WPSYNC_TEST_FAIL_PHASE tables --type=constant >/dev/null
run refresh-failed.log "$WPSYNC" staging refresh "$TARGET" --yes "${FAST[@]}"
ddev wp config delete WPSYNC_TEST_FAIL_PHASE --type=constant >/dev/null
no "V12 abgebrochener refresh endet mit Erfolg" test "$RC" = 0
ok "V12 Grund genannt" hasF "$E2E/refresh-failed.log" Testabbruch
eq "V12 Staging-Tabellen nach dem Abbruch weg" "$(SQL "SHOW TABLES LIKE 'stg%'" | wc -l | tr -d ' ')" 0
ok "V12 Ordner bleibt" test -d "$SC"
run status-failed.log "$WPSYNC" staging status "$TARGET" --json
eq "V12 Status failed" "$(last "$E2E/status-failed.log" .data.status)" failed
locked_out "V7 failed, gültiges Cookie von vor dem Abbruch" -b "$JAR"
locked_out "V7 failed, erfundenes Cookie" -b 'wpsync_stg=x'
locked_out "V7 failed, ohne Cookie"
jrun staging-create-failed-51 "$WPSYNC" staging create "$TARGET" --yes --json
eq "V12 create nach abgebrochenem refresh: Exit 51" "$RC" 51
run open-failed.log "$WPSYNC" staging open "$TARGET" --print
no "V12 kein Login-Link in eine gescheiterte Kopie" test "$RC" = 0
jrun staging-refresh-code "$WPSYNC" staging refresh "$TARGET" --code --yes --json "${FAST[@]}"
eq "AC-101 refresh --code (auch aus failed heraus)" "$RC" 0
ok "AC-101 --code ersetzt den gepushten Code" grep -q 'e2e-marker v1' "$SC/wp-content/themes/e2e-theme/index.php"
ok "AC-101 --code ersetzt auch das gepushte Plugin" cmp -s "$WPC/plugins/e2e-rewrite/e2e-rewrite.php" "$SC/wp-content/plugins/e2e-rewrite/e2e-rewrite.php"
eq "5.8 refresh --code verwirft die Pushes nach Staging" "$("$WPSYNC" pushes "$TARGET" --json | jq '[.data.pushes[] | select(.target == "staging" and (.pruned | not))] | length')" 0
no "5.8 Arbeitsordner der Pushes in der Kopie" sh -c "find '$SC/wp-content' -maxdepth 1 -name 'wpsync-push-*' | grep -q ."
"$WPSYNC" scan "$TARGET" --preset vollstaendig --exclude-plugin e2e-excluded >/dev/null
# Zusatz 7: nach refresh --code kam der Code neu von Live – die Stempel verfallen, es gilt die Baseline.
jrun staging-status-recopied "$WPSYNC" staging status "$TARGET" --json
ok "Zusatz 7: refresh --code setzt code_copied_at neu" test "$(last "$JSON/staging-status-recopied.jsonl" '.data.code_copied_at // 0')" -gt "$CODE_AT"
window "time() + 900"
run push-staging-after-code.log "$WPSYNC" push "$TARGET" code plugins/e2e-rewrite --to staging --yes
cat "$E2E/push-staging-after-code.log"
eq "Zusatz 7: Push nach refresh --code gegen die Baseline ohne --force" "$RC" 0
ok "Zusatz 7: Hinweis, dass die Stempel verworfen sind" hasF "$E2E/push-staging-after-code.log" "Der Code der Staging-Kopie kam seit dem letzten Push neu von Live"
eq "Zusatz 7: gemerkt ist nur noch die eben gepushte Einheit" "$(jq -c '.units | keys' "$STAMPS")" '["plugins/e2e-rewrite"]'
ok "Zusatz 7: Push nach refresh --code kam an" cmp -s "$REWRITE/e2e-rewrite.php" "$SC/wp-content/plugins/e2e-rewrite/e2e-rewrite.php"
ok "Zusatz 7: das Theme der Kopie bleibt auf dem Stand von Live" grep -q 'e2e-marker v1' "$SC/wp-content/themes/e2e-theme/index.php"

echo "== AC-102: Verfall nach 14 Tagen"
rm -f "$JAR"
[ "$(code -c "$JAR" "$("$WPSYNC" staging open "$TARGET" --print)")" = 302 ] || fail "Zugang nach refresh"
eq "Zugang nach refresh" "$(code -b "$JAR" "$STG_URL/")" 200
edit_state '.last_used = (now - 15 * 86400 | floor)'
ddev wp eval 'WpSync\Staging::maintain();'
jrun staging-status-locked-53 "$WPSYNC" staging status "$TARGET" --json
eq "AC-102 status: Exit 53" "$RC" 53
eq "AC-102 status: gesperrt" "$(last "$JSON/staging-status-locked-53.jsonl" '.data.status + " " + .error.code')" "locked staging_locked"
eq "AC-102 wpsync status meldet die Sperre" "$("$WPSYNC" status "$TARGET" --json | tail -n 1 | jq -r .data.staging.status)" locked
locked_out "AC-102 gesperrt, gültiges Cookie von vorher" -b "$JAR"
locked_out "AC-102 gesperrt, erfundenes Cookie" -b 'wpsync_stg=x'
jrun push-staging-locked-53 "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes --json
eq "V9 Push auf die gesperrte Kopie: Exit 53" "$RC" 53
eq "V9 abgelehnter Push hebt die Sperre nicht auf" "$("$WPSYNC" staging status "$TARGET" --json | tail -n 1 | jq -r .data.status)" locked
JAR2="$E2E/jar2.txt"
rm -f "$JAR2"
LINK="$("$WPSYNC" staging open "$TARGET" --print)"
run status-unlocked.log "$WPSYNC" staging status "$TARGET"
eq "AC-102 open hebt die Sperre auf" "$RC" 0
eq "AC-102 Link nach dem Entsperren" "$(code -c "$JAR2" "$LINK")" 302
eq "AC-102 altes Cookie gilt nach der Sperre nicht mehr" "$(code -b "$JAR" "$STG_URL/")" 403
ok "AC-102 Code während der Sperre unverändert" sh -c "curl -s -b '$JAR2' '$STG_URL/' | grep -q 'e2e-marker v1'"
ok "AC-102 Daten während der Sperre unverändert" test "$(SQL "SELECT COUNT(*) FROM ${STG}posts")" -gt 0

echo "== AC-103, Zusatz 3: delete entfernt alles und nur das – Symlinks werden nicht verfolgt"
ln -s ../../wp-content/themes/e2e-theme/style.css "$SC/wp-content/e2e-link-file"
ln -s ../../wp-content/uploads "$SC/wp-content/e2e-link-dir"
ln -s ../../../wp-content/plugins/e2e-health "$SC/wp-content/plugins/e2e-link-plugin"
ok "Zusatz 3: Links zeigen nach Live" test -f "$SC/wp-content/e2e-link-dir/2020/01/live-only.jpg" -a -f "$SC/wp-content/plugins/e2e-link-plugin/e2e-health.php"
live_tables "$E2E/inv-tables.2"
live_files "$E2E/inv-files.2"
jrun staging-delete "$WPSYNC" staging delete "$TARGET" --yes --json "${FAST[@]}"
eq "AC-103 delete" "$RC" 0
no_staging_left "AC-103"
live_tables "$E2E/inv-tables.3"
live_files "$E2E/inv-files.3"
same "AC-103 Live-Tabellen unverändert" "$E2E/inv-tables.2" "$E2E/inv-tables.3"
same "Zusatz 3: Live-Dateien unverändert (Ziele der Symlinks)" "$E2E/inv-files.2" "$E2E/inv-files.3"
run status-missing.log "$WPSYNC" staging status "$TARGET"
eq "6.4 status nach delete: Exit 50" "$RC" 50
run delete-missing.log "$WPSYNC" staging delete "$TARGET" --yes
eq "6.4 delete ohne Kopie: Exit 50" "$RC" 50
run push-staging-missing.log "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes
eq "6.4 Push ohne Kopie: Exit 50" "$RC" 50

echo "== AC-85: Abbruch in Phase 3, 4, 5, 7 räumt auf"
for phase in files tables anonymize urls; do
  ddev wp config set WPSYNC_TEST_FAIL_PHASE "$phase" --type=constant >/dev/null
  run "create-fail-$phase.log" "$WPSYNC" staging create "$TARGET" --yes "${FAST[@]}"
  ddev wp config delete WPSYNC_TEST_FAIL_PHASE --type=constant >/dev/null
  no "AC-85 $phase: create endet mit Erfolg" test "$RC" = 0
  ok "AC-85 $phase: Grund genannt" hasF "$E2E/create-fail-$phase.log" Testabbruch
  no_staging_left "AC-85 $phase"
  eq "AC-85 $phase: Status failed" "$("$WPSYNC" staging status "$TARGET" --json | tail -n 1 | jq -r '.data.status')" failed
done
live_tables "$E2E/inv-tables.4"
same "AC-85 Live-Tabellen nach vier Abbrüchen unverändert" "$E2E/inv-tables.3" "$E2E/inv-tables.4"

echo "== AC-84: ohne wirksame .htaccess kein Staging"
NGINX=yes
ddev config --webserver-type=nginx-fpm >/dev/null
ddev restart >/dev/null
jrun staging-create-unsupported-52 "$WPSYNC" staging create "$TARGET" --yes --json "${FAST[@]}"
NGINX_RC=$RC
ddev config --webserver-type=apache-fpm >/dev/null
ddev restart >/dev/null
NGINX=no
eq "AC-84 Exit 52" "$NGINX_RC" 52
ok "AC-84 Meldung nennt die nginx-Regel" sh -c "cat '$JSON/staging-create-unsupported-52.jsonl' '$JSON/staging-create-unsupported-52.err' | grep -qF 'location ^~'"
no_staging_left "AC-84"

echo "== Zusatz 4: während create ist jede Adresse der Kopie gesperrt (V7)"
"$WPSYNC" staging create "$TARGET" --yes --rps 0.1 >"$E2E/create-bg.log" 2>&1 & # 10 s zwischen den Schritten
BG=$!
SC=""
for _ in $(seq 600); do
  SC="$(find "$PUB" -maxdepth 1 -name 'wpsync-staging-*' | head -1)"
  if [ -n "$SC" ] && [ -f "$SC/wp-login.php" ]; then break; fi
  kill -0 "$BG" 2>/dev/null || break
  sleep 0.5
done
[ -n "$SC" ] && [ -f "$SC/wp-login.php" ] || { cat "$E2E/create-bg.log"; fail "Zusatz 4: create kam nicht bis zum Kopieren des Codes"; }
STG_DIR="${SC##*/}"
STG_URL="$SOURCE_URL/$STG_DIR"
ok "Zusatz 4: Installer-Dateien liegen schon da, wp-config.php noch nicht" test -f "$SC/wp-admin/install.php" -a ! -e "$SC/wp-config.php"
locked_out "V7 während create, erfundenes Cookie" -b 'wpsync_stg=x'
locked_out "V7 während create, erfundenes Cookie (64 Hex)" -b "wpsync_stg=$FORGED"
locked_out "V7 während create, ohne Cookie"
ok "Zusatz 4: der Job lief während der Prüfung noch" sh -c "kill -0 $BG && test ! -e '$SC/wp-config.php'"
BG_RC=0
wait "$BG" || BG_RC=$?
eq "create (mit --rps 0.1) zu Ende" "$BG_RC" 0
STG="$("$WPSYNC" staging status "$TARGET" --json | tail -n 1 | jq -r .data.prefix)"

echo "== 6.4: Exit 44 während eines Jobs, derselbe Befehl setzt ihn fort"
rm -f "$JAR"
[ "$(code -c "$JAR" "$("$WPSYNC" staging open "$TARGET" --print)")" = 302 ] || fail "Zugang zur neuen Kopie"
eq "Zugang zur neuen Kopie" "$(code -b "$JAR" "$STG_URL/")" 200
window "time() + 900"
run push-new-copy.log "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes
eq "erster Push in die neue Kopie ohne --force" "$RC" 0
ok "Zusatz 7: die Stempel der gelöschten Kopie sind verworfen" hasF "$E2E/push-new-copy.log" "Der Code der Staging-Kopie kam seit dem letzten Push neu von Live"
eq "Zusatz 7: gemerkt ist nur der Push in die neue Kopie" "$(jq -c '.units | keys' "$STAMPS")" '["themes/e2e-theme"]'
eq "manipulierter Client beginnt delete" "$(signed /wpsync/v1/staging/begin '{"op":"delete"}' -o /dev/null -w '%{http_code}')" 200
locked_out "V7 während delete, gültiges Cookie von vorher" -b "$JAR"
locked_out "V7 während delete, erfundenes Cookie" -b 'wpsync_stg=x'
jrun staging-refresh-busy-44 "$WPSYNC" staging refresh "$TARGET" --yes --json
eq "6.4 refresh während delete: Exit 44" "$RC" 44
jrun push-staging-busy-44 "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes --json
eq "V9 Push während eines Staging-Jobs: Exit 44" "$RC" 44
run open-busy.log "$WPSYNC" staging open "$TARGET" --print
no "kein Login-Link während delete" test "$RC" = 0
ok "die Kopie steht während der abgelehnten Befehle noch" test -d "$SC"
run delete-resume.log "$WPSYNC" staging delete "$TARGET" --yes "${FAST[@]}"
eq "delete setzt den liegengebliebenen Job fort" "$RC" 0
ok "delete nennt die Fortsetzung" hasF "$E2E/delete-resume.log" "wird fortgesetzt"
no_staging_left "delete nach Fortsetzung"
eq "5.8 delete verwirft die Pushes nach Staging" "$("$WPSYNC" pushes "$TARGET" --json | jq '[.data.pushes[] | select(.target == "staging" and (.pruned | not))] | length')" 0

echo "== AC-103, Zusatz 10: Deaktivieren des Agents löscht die Kopie"
run create-last.log "$WPSYNC" staging create "$TARGET" --yes "${FAST[@]}"
[ "$RC" = 0 ] || { cat "$E2E/create-last.log"; fail "create vor dem Deaktivieren"; }
ok "Kopie vor dem Deaktivieren" sh -c "find '$PUB' -maxdepth 1 -name 'wpsync-staging-*' | grep -q ."
window "time() + 900"
run push-before-deactivate.log "$WPSYNC" push "$TARGET" code themes/e2e-theme --to staging --yes
eq "Push nach Staging vor dem Deaktivieren" "$RC" 0
window 0
live_tables "$E2E/inv-tables.5"
live_files "$E2E/inv-files.5"
same "Zusatz 12: Live-Tabellen wie vor dem ersten create (bis vor dem Deaktivieren)" "$E2E/inv-tables.0" "$E2E/inv-tables.5"
same "Zusatz 12: Live-Dateien wie vor dem ersten create (bis vor dem Deaktivieren)" "$E2E/inv-files.0" "$E2E/inv-files.5"
ddev wp plugin deactivate wpsync-agent
no_staging_left "AC-103 Deaktivieren"
eq "Zusatz 10: Tabellen des Agents samt Datensatz weg" "$(SQL "SHOW TABLES LIKE 'e2e_wpsync%'" | wc -l | tr -d ' ')" 0
ddev wp plugin activate wpsync-agent >/dev/null
live_tables "$E2E/inv-tables.6"
live_files "$E2E/inv-files.6"
same "Zusatz 10: Live-Tabellen (Prüfsummen, Zeilenzahlen) unverändert" "$E2E/inv-tables.5" "$E2E/inv-tables.6" "$DEACTIVATED"
same "Zusatz 10: Live-Dateien unverändert" "$E2E/inv-files.5" "$E2E/inv-files.6"
KEY_ID="" # das Pairing ist mit den Tabellen gegangen

echo "== Zusatz 12: Live über den gesamten Lauf unverändert"
same "Zusatz 12: Live-Tabellen wie vor dem ersten create" "$E2E/inv-tables.0" "$E2E/inv-tables.6" "$DEACTIVATED"
same "Zusatz 12: Live-Dateien wie vor dem ersten create" "$E2E/inv-files.0" "$E2E/inv-files.6"
eq "Live erreichbar" "$(code "$SOURCE_URL/")" 200

echo
echo "Messwerte: staging create ${CREATE_SECONDS}s bei 1 Request/s und 3 s Zeitbudget, $((CREATE_STEPS + 1)) Schritt-Requests; Kopie $((COPY_KB / 1024)) MB Code, $((DB_BYTES / 1048576)) MB Datenbank; ${SECONDS}s gesamt"
