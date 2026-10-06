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
# Gibt .ddev des Ziels frei – ohne Terminal nur über den Fingerprint des angezeigten Stands.
trust_ddev() {
  local fp
  # Ohne Terminal endet trust absichtlich mit Exit 1 und nennt nur den Fingerprint.
  fp="$({ "$WPSYNC" trust "$TARGET" </dev/null 2>&1 || true; } | sed -n 's/^Fingerprint: //p')"
  [ -n "$fp" ] || fail "SEC-101 wpsync trust zeigt keinen Fingerprint"
  "$WPSYNC" trust "$TARGET" --fingerprint "$fp" </dev/null >/dev/null
}

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

echo "== 1c-Fixtures in der Quelle"
if ! ddev wp user get erika --field=ID >/dev/null 2>&1; then
  ddev wp user create erika erika.mustermann@kunde-echt.example --role=subscriber \
    --first_name=Erika --last_name=Mustermann --display_name="Erika Mustermann" --user_pass=geheim-e2e >/dev/null
fi
ERIKA_ID="$(ddev wp user get erika --field=ID)"

cp "$ROOT/agent/dist/wpsync-agent.zip" public/wpsync-agent.zip
ddev wp plugin install /var/www/html/public/wpsync-agent.zip --force --activate
rm public/wpsync-agent.zip
ddev wp eval 'WpSync\Store::setState("infosheet", null); WpSync\Store::setState("infosheet_job", null);'
# 2a: Reste früherer Läufe – offene Pushes würden jeden neuen blockieren, e2e-new wäre nicht mehr neu.
ddev wp eval 'WpSync\Push::uninstall(); global $wpdb; $wpdb->query("DELETE FROM " . WpSync\Store::table("pushes")); WpSync\Store::setState("push_lock", null);'
rm -rf public/wp-content/plugins/e2e-new
# Auch die lokale Kopie: der Pull entfernt lokale Extra-Verzeichnisse nicht, ein Push nähme sie als neue Einheit mit.
rm -rf "$WPSYNC_SITES_DIR/$TARGET/public/wp-content/plugins/e2e-new"
# Ein Lauf mit einem Agent vor 0.4.0 (z. B. von main) liefert den Push-Arbeitsordner einer früheren
# 2a-Quelle mit aus; der Pull entfernt ihn lokal nie wieder, AC-71 prüft aber nur diesen Lauf.
rm -rf "$WPSYNC_SITES_DIR/$TARGET/public/wp-content/"wpsync-push-*
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
# wp-content/uploads ist gitignored und wird vom Baseline-Reset nicht erfasst – eine frühere
# Laufzeit dieses Skripts kann hier über den AC-16-Proxy bereits 2019er-Dateien zwischengespeichert
# haben. Ohne diesen Reset prüft AC-16 unten nur den alten Cache statt den frischen Full-Pull.
rm -rf "$WPSYNC_SITES_DIR/$TARGET/public/wp-content/uploads"
# SEC-101: Ein Ziel aus einem Lauf vor der .ddev-Prüfung braucht die einmalige Übernahme.
if [ -f "$WPSYNC_SITES_DIR/$TARGET/.ddev/config.yaml" ] && [ ! -f "$WPSYNC_CONFIG_DIR/ddev-state/$TARGET.json" ]; then
  if "$WPSYNC" pull "$TARGET" </dev/null >"$E2E/pull-takeover.log" 2>&1; then fail "SEC-101 AC-14 pull without takeover"; fi
  grep -q "wpsync trust $TARGET" "$E2E/pull-takeover.log" || fail "SEC-101 AC-14 no hint to wpsync trust"
  trust_ddev
fi
# SEC-131 AC-6: Ein Ziel aus einem Lauf vor der Umstellung hat noch <site>/.git – der Erst-Pull verschiebt es.
SEC131_MOVE=no
if [ -e "$WPSYNC_SITES_DIR/$TARGET/.git" ] && [ ! -e "$WPSYNC_SITES_DIR/.wpsync-git/$TARGET.git" ]; then SEC131_MOVE=yes; fi
"$WPSYNC" pull "$TARGET" --full | tee "$E2E/pull1.log"
if [ "$SEC131_MOVE" = yes ]; then
  grep -q "Bisheriges Site-Git verschoben nach" "$E2E/pull1.log" || fail "SEC-131 AC-6 no notice about the moved .git"
  ls -d "$WPSYNC_SITES_DIR/.wpsync-git/$TARGET".alt-*.git >/dev/null 2>&1 || fail "SEC-131 AC-6 old .git not moved aside"
fi

cd "$WPSYNC_SITES_DIR/$TARGET"
TARGET_URL="$(http_url)"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$TARGET_URL/")" = "200" ] || fail "target not 200"
grep -q "e2e_" public/wp-config.php || fail "AC-20 prefix"
ddev restart >/dev/null
grep -q "e2e_" public/wp-config.php || fail "AC-20 prefix lost after restart"
[ "$(ddev wp plugin list --status=active --field=name | grep -c password-protected || true)" = "0" ] || fail "AC-23"
[ "$(ddev mysql -N -e "SHOW TABLES LIKE '%wpsync%'" | wc -l | tr -d ' ')" = "0" ] || fail "AC-27"
# SEC-131: Das Schnappschuss-Repo liegt neben dem Site-Ordner; git nie im Site-Ordner aufrufen.
SNAP=(git --git-dir="$WPSYNC_SITES_DIR/.wpsync-git/$TARGET.git")
[ ! -e .git ] || fail "SEC-131 AC-1 .git in site folder"
[ "$("${SNAP[@]}" log --oneline | wc -l | tr -d ' ')" -ge 1 ] || fail "AC-28"
if "${SNAP[@]}" ls-files | grep -q "uploads/"; then fail "AC-28 uploads tracked"; fi

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

echo "== AC-32: keine echten Personendaten lokal und im Dump"
real="$(ddev mysql -N -e "SELECT COUNT(*) FROM e2e_users WHERE user_email NOT LIKE '%@example.invalid'")"
[ "$real" = "0" ] || fail "AC-32 $real users with a real e-mail"
hashes="$(ddev mysql -N -e "SELECT COUNT(*) FROM e2e_users WHERE user_login <> 'wpsync' AND user_pass <> '!wpsync-anonymized'")"
[ "$hashes" = "0" ] || fail "AC-32 $hashes password hashes pulled"
if grep -rqi "mustermann\|kunde-echt\|geheim-e2e" .wpsync/db; then fail "AC-32 plain personal data left the server"; fi
ddev wp option get admin_email | grep -Eq '^user-[0-9a-f]{16}@example\.invalid$' || fail "AC-32 admin_email not pseudonymized"
ERIKA_PSEUDO="$(ddev mysql -N -e "SELECT user_email FROM e2e_users WHERE ID = $ERIKA_ID")"
echo "$ERIKA_PSEUDO" | grep -Eq '^user-[0-9a-f]{16}@example\.invalid$' || fail "AC-32 pseudonym is '$ERIKA_PSEUDO'"
grep -q "Login: wpsync / wpsync" "$E2E/pull1.log" || fail "AC-34 login hint missing"

echo "== AC-34: lokaler Admin kann sich anmelden, übernommene Konten nicht"
ddev wp user check-password wpsync wpsync || fail "AC-34 local admin cannot log in"
[ "$(ddev wp user get wpsync --field=roles)" = "administrator" ] || fail "AC-34 local admin is no administrator"
[ "$(ddev wp user list --field=user_login | grep -c '^admin$' || true)" = "0" ] || fail "AC-34 original login still present"

echo "== AC-16: fehlendes Upload-Jahr kommt über den Proxy"
[ ! -f public/wp-content/uploads/2019/01/wpsync-proxy.txt ] || fail "AC-16 2019 was pulled despite --uploads-since"
# *.ddev.site zeigt im Container auf 127.0.0.1 – für den Test die Quelle über den DDEV-Router erreichbar machen
# SEC-101 AC-13: eine eigene Compose-Datei bricht den Pull ab, bis sie freigegeben ist. Die
# Zeitmarke macht die Datei in jedem Lauf neu, auch wenn ein früherer Lauf sie freigegeben hat.
cat > .ddev/docker-compose.e2e-source.yaml <<YAML
# e2e $(date +%s)
services:
  web:
    external_links:
      - "ddev-router:${SOURCE_NAME}.ddev.site"
YAML
if "$WPSYNC" pull "$TARGET" </dev/null >"$E2E/pull-untrusted.log" 2>&1; then fail "SEC-101 AC-13 pull ran with an untrusted compose file"; fi
grep -q "docker-compose.e2e-source.yaml" "$E2E/pull-untrusted.log" || fail "SEC-101 AC-13 abort does not name the file"
if "$WPSYNC" trust "$TARGET" --yes </dev/null >/dev/null 2>&1; then fail "SEC-101 AC-13 --yes approved .ddev"; fi
trust_ddev
ddev restart >/dev/null
body="$(curl -s "$TARGET_URL/wp-content/uploads/2019/01/wpsync-proxy.txt")"
[ "$body" = "wpsync-proxy-ok" ] || fail "AC-16 proxy returned '$body'"
# nginx legt die Datei im Container ab; über den Bind-Mount kommt sie auf dem Host verzögert an.
for _ in $(seq 20); do [ -f public/wp-content/uploads/2019/01/wpsync-proxy.txt ] && break; sleep 0.5; done
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

echo "== AC-38: Klartext nur mit --no-anonymize und Bestätigung"
if "$WPSYNC" pull "$TARGET" --no-anonymize </dev/null >/dev/null 2>&1; then fail "AC-38 plain pull without confirmation"; fi
"$WPSYNC" pull "$TARGET" --no-anonymize --yes | tee "$E2E/pull3.log"
grep -q "im Klartext (--no-anonymize)" "$E2E/pull3.log" || fail "AC-38 no plain-text warning"
plain="$(ddev mysql -N -e "SELECT user_email FROM e2e_users WHERE ID = $ERIKA_ID")"
[ "$plain" = "erika.mustermann@kunde-echt.example" ] || fail "AC-38 plain pull delivered '$plain'"

echo "== AC-33/AC-38: der nächste Pull pseudonymisiert wieder – mit demselben Pseudonym"
"$WPSYNC" pull "$TARGET" --yes | tee "$E2E/pull4.log"
again="$(ddev mysql -N -e "SELECT user_email FROM e2e_users WHERE ID = $ERIKA_ID")"
[ "$again" = "$ERIKA_PSEUDO" ] || fail "AC-33 pseudonym changed from '$ERIKA_PSEUDO' to '$again'"
if grep -rqi "kunde-echt" .wpsync/db; then fail "AC-38 plain dump still on disk after the anonymized pull"; fi
ddev wp user check-password wpsync wpsync || fail "AC-34 local admin lost after re-anonymizing"

echo "== 2a: Push Code"
KEY_ID="$(awk '/^key_id:/ { print $2 }' "$WPSYNC_CONFIG_DIR/sites/$TARGET.yaml")"
src() { (cd "$E2E/source" && "$@"); }
window() { src ddev wp eval "WpSync\\Store::setPushUntil('$KEY_ID', $1);" >/dev/null; }
PLUGIN=public/wp-content/plugins/e2e-objects/e2e-objects.php
SRC_PLUGIN="$E2E/source/$PLUGIN"
home_status() { curl -s -o /dev/null -w '%{http_code}' "$SOURCE_URL/"; }
HOME_BEFORE="$(home_status)"

echo "== AC-50: ohne Push-Fenster kein Push"
printf '\n// push %s\n' "$(date +%s)" >> "$PLUGIN"
window 0
if "$WPSYNC" push "$TARGET" code --yes >"$E2E/push0.log" 2>&1; then fail "AC-50 pushed without a window"; fi
grep -q "Push-Fenster ist geschlossen" "$E2E/push0.log" || fail "AC-50 no hint about the window"
if grep -q "// push" "$SRC_PLUGIN"; then fail "AC-50 source changed without a window"; fi
"$WPSYNC" push "$TARGET" code --dry-run | tee "$E2E/push-dry.log"
grep -q "plugins/e2e-objects – 1 von 1 Dateien" "$E2E/push-dry.log" || fail "dry run does not show the plan"

echo "== AC-54: Push bringt genau den lokalen Stand auf die Site"
window "time() + 900"
"$WPSYNC" push "$TARGET" code --yes | tee "$E2E/push1.log"
cmp -s "$PLUGIN" "$SRC_PLUGIN" || fail "AC-54 file differs after the push"
requests="$(grep -o '– [0-9]* Requests' "$E2E/push1.log" | grep -o '[0-9]*')"
[ "$requests" -le 5 ] || fail "push used $requests signed requests"
PUSH_ID="$(grep -o 'p_[0-9]\{8\}_[a-f0-9]\{12\}' "$E2E/push1.log" | head -1)"
[ "$(home_status)" = "$HOME_BEFORE" ] || fail "source answers differently after the push"
[ "$("${SNAP[@]}" log --oneline | grep -c "push $PUSH_ID")" = "1" ] || fail "push not recorded in the internal git"

echo "== AC-56: Folge-Pull überträgt die gepushten Dateien nicht"
"$WPSYNC" pull "$TARGET" --yes | tee "$E2E/pull5.log"
grep -q "Dateien: 0 neu/geändert" "$E2E/pull5.log" || fail "AC-56 pull transferred files after the push"

echo "== AC-71: Arbeitsordner und Protokoll verlassen den Server nicht"
if find public/wp-content -maxdepth 1 -name 'wpsync-push-*' | grep -q .; then fail "AC-71 push work dir was pulled"; fi
[ "$(ddev mysql -N -e "SHOW TABLES LIKE '%wpsync%'" | wc -l | tr -d ' ')" = "0" ] || fail "AC-71 wpsync tables were pulled"

echo "== U18: bestätigter Push lässt sich ohne Push-Fenster nicht zurückrollen"
window 0
if "$WPSYNC" rollback "$TARGET" >"$E2E/rollback0.log" 2>&1; then fail "U18 rolled back a confirmed push without a window"; fi
cat "$E2E/rollback0.log"
grep -q "Push-Fenster geschlossen" "$E2E/rollback0.log" || fail "U18 no hint about the window"
if grep -q "rescue.php" "$E2E/rollback0.log"; then fail "U18 the closed window was bypassed through rescue.php"; fi
grep -q "// push" "$SRC_PLUGIN" || fail "U18 source changed without a window"

echo "== AC-55: rollback stellt den alten Stand her"
window "time() + 900"
"$WPSYNC" rollback "$TARGET" | tee "$E2E/rollback1.log"
if grep -q "// push" "$SRC_PLUGIN"; then fail "AC-55 source still has the pushed state"; fi
grep -q "// push" "$PLUGIN" || fail "AC-55 rollback touched the local file"
"$WPSYNC" pushes "$TARGET" | tee "$E2E/pushes1.log"
grep -q "$PUSH_ID.*zurückgerollt" "$E2E/pushes1.log" || fail "AC-70 rollback missing in the push log"

echo "== AC-57: Konflikt bricht ab, --force überschreibt"
printf '\n// server\n' >> "$SRC_PLUGIN"
if "$WPSYNC" push "$TARGET" code --yes >"$E2E/push2.log" 2>&1; then fail "AC-57 pushed over a server change"; fi
grep -q "e2e-objects.php" "$E2E/push2.log" || fail "AC-57 conflicting file not named"
grep -q "// server" "$SRC_PLUGIN" || fail "AC-57 server change lost without --force"
"$WPSYNC" push "$TARGET" code --yes --force | tee "$E2E/push3.log"
cmp -s "$PLUGIN" "$SRC_PLUGIN" || fail "AC-57 --force did not push"

echo "== AC-58: neue Einheit wird angelegt und bleibt inaktiv"
mkdir -p public/wp-content/plugins/e2e-new
printf '<?php\n/* Plugin Name: E2E New\n * Version: 1.0 */\n' > public/wp-content/plugins/e2e-new/e2e-new.php
if "$WPSYNC" push "$TARGET" code --yes >"$E2E/push4-unnamed.log" 2>&1; then fail "U14 pushed a new unit that was not named"; fi
cat "$E2E/push4-unnamed.log"
grep -q "übersprungen: plugins/e2e-new" "$E2E/push4-unnamed.log" || fail "U14 no hint about the skipped new unit"
grep -q "wpsync push $TARGET code plugins/e2e-new" "$E2E/push4-unnamed.log" || fail "U14 does not say how to push the new unit"
if [ -e "$E2E/source/public/wp-content/plugins/e2e-new" ]; then fail "U14 unnamed new unit reached the source"; fi
"$WPSYNC" push "$TARGET" code plugins/e2e-new --yes | tee "$E2E/push4.log"
grep -q "neu, bleibt auf der Site inaktiv" "$E2E/push4.log" || fail "AC-58 no hint for the new unit"
[ -f "$E2E/source/public/wp-content/plugins/e2e-new/e2e-new.php" ] || fail "AC-58 new unit missing on the source"
if src ddev wp plugin is-active e2e-new 2>/dev/null; then fail "AC-58 new plugin is active"; fi

echo "== AC-62: Versionswechsel braucht eine eigene Bestätigung"
printf '<?php\n/* Plugin Name: E2E New\n * Version: 1.1 */\n' > public/wp-content/plugins/e2e-new/e2e-new.php
if "$WPSYNC" push "$TARGET" code plugins/e2e-new --yes >"$E2E/push5.log" 2>&1; then fail "AC-62 version change with --yes alone"; fi
grep -qF '"1.0" → "1.1"' "$E2E/push5.log" || fail "AC-62 version change not shown"
"$WPSYNC" push "$TARGET" code plugins/e2e-new --yes --allow-version-change >/dev/null

echo "== AC-63/AC-64: Syntaxfehler in aktivem Plugin wird automatisch zurückgerollt"
cp -p "$PLUGIN" "$E2E/e2e-objects.good"
printf '\nthis is not php(\n' >> "$PLUGIN"
if "$WPSYNC" push "$TARGET" code plugins/e2e-objects --yes >"$E2E/push6.log" 2>&1; then fail "AC-63 broken push was confirmed"; fi
cat "$E2E/push6.log"
grep -q "zurückgerollt" "$E2E/push6.log" || fail "AC-63 no rollback reported"
if grep -q "this is not php" "$SRC_PLUGIN"; then fail "AC-63 broken code is still live"; fi
[ "$(home_status)" = "$HOME_BEFORE" ] || fail "AC-63 source is broken after the rollback (HTTP $(home_status))"
"$WPSYNC" pushes "$TARGET" | tee "$E2E/pushes2.log"
[ "$(grep -c "zurückgerollt" "$E2E/pushes2.log")" = "2" ] || fail "AC-63 push log does not show the automatic rollback"
cp -p "$E2E/e2e-objects.good" "$PLUGIN"
if "$WPSYNC" push "$TARGET" code plugins/e2e-objects --dry-run >"$E2E/push7.log" 2>&1; then fail "local copy should be unchanged again"; fi
grep -q "nichts zu pushen" "$E2E/push7.log" || fail "restored file still counts as changed"

window 0

echo "E2E OK"
