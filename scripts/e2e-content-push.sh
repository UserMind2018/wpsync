#!/usr/bin/env bash
# E2E Inhalts-Push (Spec Content-Push P2b, §7; AC-147 Staging, AC-149–AC-155, AC-157): ein Satz aus
# Code, Uploads und Inhalten gegen eine eigene Apache-Quelle – Probelauf ohne Fenster, Staging,
# Live, Rücknahme über den Agent, über rescue.php und nachgeholt, dazu die Ablehnungen.
# Eigene Projekte wpsync-e2e-cdb (Quelle, apache-fpm wegen der Staging-Kopie) und
# wpsync-e2e-cdb-target (lokal) unter ~/wpsync-e2e/cdb; die geteilten E2E-Projekte bleiben
# unberührt. Die Pakete baut das Skript selbst mit jq aus `content export` ↔ baseline.jsonl –
# so, wie es das Website Studio tut.
#
# Es ist der einzige Test, in dem ContentSql eine echte Datenbank sieht: Transaktion und
# Sitzungsmarke, FOR UPDATE gegen eine zweite Sitzung, BINARY-Vergleiche, Zeichensätze, grosse
# Werte, ein Fehler mitten im Schreiben. Was der Agent auf Commit und Rollback antwortet, hält das
# mu-plugin e2e-tap der Quelle fest (content.after zeigt die CLI nicht).
#
# Voraussetzung: Docker, DDEV, jq (≥ 1.6), openssl, Go. Dauer rund 6 Minuten.
# Eine fehlgeschlagene Prüfung zählt und der Lauf geht weiter; nur was den Rest sinnlos macht,
# bricht ab. Die JSON-Zeilen der Befehle liegen danach unter ~/wpsync-e2e/cdb/json.
# Am Ende werden beide Projekte gestoppt (nicht gelöscht); WPSYNC_E2E_KEEP=1 lässt sie laufen.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E2E="${WPSYNC_E2E_DIR:-$HOME/wpsync-e2e}/cdb"
export WPSYNC_CONFIG_DIR="$E2E/config"
export WPSYNC_SITES_DIR="$E2E/sites"
SOURCE_NAME=wpsync-e2e-cdb
TARGET=wpsync-e2e-cdb-target
SRC="$E2E/source"
PUB="$SRC/public"
WPC="$PUB/wp-content"
SITE="$WPSYNC_SITES_DIR/$TARGET"
LWPC="$SITE/public/wp-content"
CONTENT="$SITE/.wpsync/content"
WPSYNC="$E2E/bin/wpsync" # eigener Build – nie eine installierte wpsync
JSON="$E2E/json"
PKG="$E2E/pkg"
PREFIX=e2e_
PNG_B64='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='

CHECKS=0
FAILED=0
RC=0
KEY_ID=""
STG_DIR=""

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
stg() { (cd "$SRC" && ddev wp --path="/var/www/html/public/$STG_DIR" "$@"); }
http_url() { (cd "$1" && ddev describe -j | jq -r '.raw.httpurl'); }
code() { curl -s -o /dev/null -m 10 -w '%{http_code}' "$@" || true; }
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
last() { tail -n 1 "$JSON/$1.jsonl" | jq -r "$2"; }                                   # last <name> <jq>: über der Ergebniszeile
event() { jq -c "select(.event == \"$2\") | .data" "$JSON/$1.jsonl" | tail -n 1; }   # event <name> <event>: letztes .data
window() { src wp eval "WpSync\\Store::setPushUntil('$KEY_ID', $1);" >/dev/null; }    # window 0: schliessen (ohne Öffner)
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
# Rohwerte der Datenbank, ohne Plugins und Themes: post <wo> <id> <spalte>, meta <wo> <id> <schlüssel>, opt <wo> <name>
wp_in() { local where="$1"; shift; case "$where" in src) src wp "$@" ;; stg) stg "$@" ;; *) tgt wp "$@" ;; esac; }
post() { wp_in "$1" eval "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare(\"SELECT $3 FROM {\$wpdb->posts} WHERE ID = %d\", $2));" --skip-plugins --skip-themes; }
meta() { wp_in "$1" eval "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare(\"SELECT meta_value FROM {\$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1\", $2, '$3'));" --skip-plugins --skip-themes; }
opt() { wp_in "$1" eval "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare(\"SELECT option_value FROM {\$wpdb->options} WHERE option_name = %s\", '$2'));" --skip-plugins --skip-themes; }
esc1() { printf %s "$1" | sed 's#/#\\/#g'; }       # https:\/\/host
esc2() { printf %s "$1" | sed 's#/#\\\\\\/#g'; }   # https:\\\/\\\/host
b64() { printf %s "$1" | openssl base64 -A; }
# Abdruck eines Schlüssels in einer JSON-Lines-Datei. Bash hält kein NUL in einer Variablen: ein
# Paar bekommt Objekt und Name getrennt, jq setzt den Schlüssel zusammen.
hash_of() { # hash_of <datei> <tabelle> <objekt> [name]
  jq -r --arg t "$2" --arg o "$3" --arg n "${4:-}" '($o + (if $n == "" then "" else "\u0000" + $n end)) as $k | select(.t == $t and .k == $k) | .h' "$1"
}

# Was der Agent zuletzt auf /push/commit oder /push/rollback geantwortet hat – das hält das
# mu-plugin e2e-tap der Quelle fest; die CLI zeigt content.after nicht.
tap() { tail -n 1 "$SRC/e2e-tap.jsonl" | jq -r "$1"; }
# after_diff <export>: Schlüssel aus content.after der letzten Commit-Antwort, deren Abdruck auf dem
# Ziel nicht der der Arbeitskopie ist (AC-147: Ziel ↔ Arbeitskopie; ein gelöschter Schlüssel: null ↔ fehlt).
after_diff() {
  tail -n 1 "$SRC/e2e-tap.jsonl" | jq --slurpfile e "$1" '
    ($e | map(select(.t != null) | {key: (.t + "\u0001" + .k), value: .h}) | from_entries) as $E
    | [.data.content.after[] | select(.h != $E[.t + "\u0001" + .k])] | length'
}
# keys_diff <paket-rumpf> <export>: Schlüssel des Pakets, deren Abdruck im Manifest nicht der des Exports ist.
keys_diff() {
  jq -n --slurpfile p "$1" --slurpfile e "$2" --slurpfile m "$CONTENT/manifest.jsonl" '
    ($e | map(select(.t != null) | {key: (.t + "\u0001" + .k), value: .h}) | from_entries) as $E
    | ($m | map(select(.t != null) | {key: (.t + "\u0001" + .k), value: .h}) | from_entries) as $M
    | [$p[] | (.table + "\u0001" + .key) | select($E[.] != $M[.])] | length'
}
# rowsum <id>: alles, was auf Live an einem Beitrag hängt, Byte für Byte – die ganze Zeile, seine Meta
# (je Schlüssel in ihrer Reihenfolge) und seine Zuordnungen. Die Rücknahme muss genau das wiederherstellen.
rowsum() {
  src mysql -N -e "SELECT * FROM ${PREFIX}posts WHERE ID = $1; SELECT meta_key, HEX(meta_value) FROM ${PREFIX}postmeta WHERE post_id = $1 ORDER BY BINARY meta_key, meta_id; SELECT term_taxonomy_id, term_order FROM ${PREFIX}term_relationships WHERE object_id = $1 ORDER BY 1" | shasum -a 256 | cut -d' ' -f1
}
optsum() { # die drei Optionen der reichen Fixture auf Live, Byte für Byte
  src mysql -N -e "SELECT option_name, HEX(option_value), autoload FROM ${PREFIX}options WHERE option_name IN ('blogdescription', 'wpseo_social', 'options_e2e_neu') ORDER BY 1" | shasum -a 256 | cut -d' ' -f1
}
msum() { LC_ALL=C sort "$1" | shasum -a 256 | cut -d' ' -f1; } # Manifest/Baseline ohne Rücksicht auf die Reihenfolge
pending() { src wp eval 'echo json_encode(WpSync\Push::pending());'; }
# probe <wo> <adresse>: die Werte der reichen Fixture (Seite A und drei Optionen) als Zeilen, die Adresse
# des Ziels in ihren drei Schreibweisen maskiert – serialisierte Werte erst gelesen, dann maskiert. Auf
# Arbeitskopie, Staging-Kopie und Live muss dasselbe herauskommen.
probe() {
  wp_in "$1" eval-file - "$PAGE_A" "$2" --skip-plugins --skip-themes <<'PHP'
<?php
global $wpdb;
$id   = (int) $args[0];
$home = rtrim($args[1], '/');
$mask = static function ($v) use ($home) {
    return str_replace([str_replace('/', '\\\\\\/', $home), str_replace('/', '\\/', $home), $home], ['{H2}', '{H1}', '{H}'], (string) $v);
};
$sum = static function ($v) use ($mask) { $m = $mask($v); return strlen($m) . ':' . md5($m); };
$ser = static function ($raw) use ($mask) {
    $v = is_string($raw) ? @unserialize($raw) : false;
    if (!is_array($v)) { return 'nicht lesbar'; }
    array_walk_recursive($v, static function (&$x) use ($mask) { if (is_string($x)) { $x = $mask($x); } });
    return md5(serialize($v));
};
$meta = static function ($key) use ($wpdb, $id) {
    return $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND BINARY meta_key = %s ORDER BY meta_id", $id, $key));
};
$opt = static function ($name) use ($wpdb) {
    return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
};
$post = $wpdb->get_row($wpdb->prepare("SELECT post_title, post_content FROM {$wpdb->posts} WHERE ID = %d", $id));
echo 'title=', bin2hex($post->post_title), "\n";
echo 'content=', bin2hex($mask($post->post_content)), "\n";
echo 'elementor=', $sum($meta('_elementor_data')[0] ?? ''), ' gross=', strlen($meta('_elementor_data')[0] ?? '') > 100000 ? 'ja' : 'nein', "\n";
echo 'json2=', $sum($meta('_e2e_json2')[0] ?? ''), "\n";
echo 'ser=', $ser($meta('_e2e_ser')[0] ?? null), "\n";
echo 'emoji=', bin2hex($meta('_e2e_emoji')[0] ?? ''), "\n";
// Mehrere Werte unter einem Schlüssel sind für Manifest und Paket eine Menge: ihre Reihenfolge
// (meta_id) überträgt ein Push nicht – das Ziel bekommt sie sortiert. Verglichen wird die Menge.
$multi = $meta('_e2e_multi');
sort($multi, SORT_STRING);
echo 'multi=', implode('|', $multi), "\n";
echo 'weg=', count($meta('_e2e_weg')), "\n";
echo 'blogdescription=', bin2hex((string) $opt('blogdescription')), "\n";
echo 'wpseo_social=', $ser($opt('wpseo_social')), "\n";
echo 'options_e2e_neu=', $mask($opt('options_e2e_neu')), "\n";
PHP
}

# seal <name>: setzt den Kopf vor $PKG/<name>.body – Zeilenende \n, JSON kompakt, sha256 über den Rumpf.
# EXTENSIONS='{…}' seal <name> nennt Projekt-Erweiterungen im Kopf; sonst keine.
seal() {
  local name="$1" body="$PKG/$1.body" rows digest ext="${EXTENSIONS:-}"
  [ -n "$ext" ] || ext='{"post_types":[],"taxonomies":[],"meta_exceptions":[]}'
  rows="$(wc -l <"$body" | tr -d ' ')"
  digest="$(sha "$body")"
  jq -c -n --arg home "$(jq -r '.live.home' "$CONTENT/map.json")" --arg map "$(sha "$CONTENT/map.json")" --arg host "$LOCAL_HOST" \
    --argjson rows "$rows" --arg sha "$digest" --argjson lv "$LIST_VERSION" \
    --argjson ext "$ext" \
    '{head: {list_version: $lv, extensions: $ext,
      corridor: {offset: 1000000, posts: [1000001, 9999999], terms: [1000001, 9999999], term_taxonomy: [1000001, 9999999]},
      canon_version: 1, variants: ["plain", "esc1", "esc2"], home: $home, map_id: $map, local_host: $host, rows: $rows, sha256: $sha}}' >"$PKG/$name.jsonl"
  cat "$body" >>"$PKG/$name.jsonl"
}
# pkg <name> [jq-Bedingung über .t und .k]: baut das Paket aus dem Export der Arbeitskopie gegen
# die Baseline – update, insert, trash und gelöschte Paare; expected kommt aus dem Manifest.
# Nur pushbare Zeilen (p), wie das Studio. Für einen Beitrag, der lokal in den Papierkorb ging,
# entsteht nur die Zeile trash: was der Papierkorb an ihm hinterlässt (__trashed am Namen,
# _wp_desired_post_slug, _wp_trash_meta_*), schreibt der Agent selbst – solche Meta-Zeilen im
# Paket wären package_invalid. Hat WordPress dabei das Datum gesetzt (nie veröffentlichter Entwurf),
# trägt trash row mit post_date und post_date_gmt. Liefert die Zahl der Zeilen in ROWS.
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
# raw_pkg <name> <zeile…>: ein Paket aus handgeschriebenen Zeilen – was das Studio nie bauen würde.
raw_pkg() {
  local name="$1"
  shift
  printf '%s\n' "$@" >"$PKG/$name.body"
  seal "$name"
}
push_content() { # push_content <name> <paket> [weitere Argumente…]: nur Inhalte, --json
  local name="$1" file="$2"
  shift 2
  jrun "$name" "$WPSYNC" push "$TARGET" code --no-code --content "$PKG/$file.jsonl" --json "$@"
}
refused() { # refused <was> <name> <reason>: Exit 1 mit error.reason, nichts auf der Site
  eq "$1: Exit 1" "$RC" 1
  eq "$1: error.reason" "$(last "$2" '.error.reason')" "$3"
}
trust_ddev() { # gibt .ddev des Ziels frei – ohne Terminal nur über den Fingerprint des angezeigten Stands
  local fp
  fp="$({ "$WPSYNC" trust "$TARGET" </dev/null 2>&1 || true; } | sed -n 's/^Fingerprint: //p')"
  [ -n "$fp" ] || fail "wpsync trust zeigt keinen Fingerprint"
  "$WPSYNC" trust "$TARGET" --fingerprint "$fp" </dev/null >/dev/null
}
finish() {
  local rc=$?
  trap - EXIT
  set +e
  rm -f "$WPC/mu-plugins/e2e-fatal.php"
  if [ -f "$SRC/.ddev/config.yaml" ]; then
    [ -z "$KEY_ID" ] || window 0 2>/dev/null
    (cd "$SRC" && ddev mysql -e "DROP TRIGGER IF EXISTS e2e_boom" >/dev/null 2>&1)
    [ "${WPSYNC_E2E_KEEP:-}" = 1 ] || (cd "$SRC" && ddev stop >/dev/null 2>&1)
  fi
  if [ "${WPSYNC_E2E_KEEP:-}" != 1 ] && [ -f "$SITE/.ddev/config.yaml" ]; then (cd "$SITE" && ddev stop >/dev/null 2>&1); fi
  echo
  if [ "$rc" != 0 ]; then
    echo "E2E Content-Push ABGEBROCHEN (Exit $rc) – $CHECKS Prüfungen bis dahin, $FAILED FAIL"
    exit "$rc"
  fi
  if [ "$FAILED" != 0 ]; then
    echo "E2E Content-Push: $CHECKS Prüfungen, $FAILED FAIL"
    exit 1
  fi
  echo "E2E Content-Push OK – $CHECKS Prüfungen grün, 0 FAIL"
}
trap finish EXIT

command -v jq >/dev/null || fail "jq fehlt"
mkdir -p "$E2E/bin" "$PUB" "$WPSYNC_SITES_DIR"
rm -rf "$JSON" "$PKG"
mkdir -p "$JSON" "$PKG"
(cd "$ROOT/agent" && ./build.sh)
(cd "$ROOT/cli" && go build -o "$WPSYNC" ./cmd/wpsync)

echo "== Quelle (Apache, PHP 8.2)"
cd "$SRC"
if [ ! -f .ddev/config.yaml ]; then
  ddev config --project-name="$SOURCE_NAME" --project-type=wordpress --docroot=public \
    --php-version=8.2 --database=mariadb:10.11 --webserver-type=apache-fpm --performance-mode=none
fi
ddev start -y
if ! ddev wp core is-installed >/dev/null 2>&1; then
  ddev wp core download --force
  sed -i '' 's/#ddev-generated//' public/wp-config.php
  ddev wp config set table_prefix "$PREFIX" --type=variable
  ddev wp core install --url="$(http_url "$SRC")" --title="wpsync Content-Push E2E" --admin_user=admin \
    --admin_password=admin --admin_email=e2e@example.invalid --skip-email
  ddev wp rewrite structure '/%postname%/' --hard
fi
ddev wp config set DISABLE_WP_CRON true --raw --type=constant
ddev wp config set WPSYNC_ALLOW_HTTP true --raw --type=constant
rm -f "$WPC/mu-plugins/e2e-fatal.php"
ddev mysql -e "DROP TRIGGER IF EXISTS e2e_boom"
# Permalinks auf Apache: WP-CLI schreibt ohne apache_modules in seiner Konfiguration keine .htaccess,
# und ohne sie antwortet /e2e-b/ mit 404 – der Health-Check sähe die geänderte Seite nie.
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

echo "== Fixtures: Theme, Plugin, Seiten mit der URL in allen Schreibweisen"
mkdir -p "$WPC/themes/e2e-theme" "$WPC/plugins/e2e-health" "$WPC/uploads/2026/10" "$WPC/mu-plugins"
# Hält fest, was der Agent auf Commit und Rollback antwortet (siehe tap).
cat >"$WPC/mu-plugins/e2e-tap.php" <<'PHP'
<?php
add_filter('rest_post_dispatch', static function ($response, $server, $request) {
    $route = (string) $request->get_route();
    if (strpos($route, '/push/commit') !== false || strpos($route, '/push/rollback') !== false) {
        @file_put_contents(dirname(ABSPATH) . '/e2e-tap.jsonl', wp_json_encode(['route' => $route, 'data' => $response->get_data()]) . "\n", FILE_APPEND);
    }
    return $response;
}, 10, 3);
PHP
: >"$SRC/e2e-tap.jsonl"
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
$wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 1");
$json  = wp_json_encode([['id' => 'a1', 'elType' => 'widget', 'settings' => ['link' => ['url' => $home . '/kontakt']]]]);
$json2 = wp_json_encode(['data' => $json]);
$ids   = [];
foreach ($wpdb->get_col("SELECT term_id FROM {$wpdb->terms} WHERE term_id > 1000000") as $old) {
    wp_delete_term((int) $old, 'category');
}
// Was ein früherer Lauf im Korridor hinterlassen hat, ganz: wp_delete_post() lässt die Zuordnung einer
// Seite zu einer Kategorie stehen (für Seiten ist die Taxonomie nicht angemeldet) – läge sie an der ID
// eines neuen Beitrags dieses Laufs, wäre der Schlüssel im Manifest schon vergeben.
$wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id > 1000000 OR term_taxonomy_id > 1000000");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id > 1000000");
$wpdb->query("DELETE FROM {$wpdb->termmeta} WHERE term_id > 1000000");
$wpdb->query("DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id > 1000000 OR term_id > 1000000");
$wpdb->query("DELETE FROM {$wpdb->terms} WHERE term_id > 1000000");
$wpdb->query("ALTER TABLE {$wpdb->terms} AUTO_INCREMENT = 1");
$wpdb->query("ALTER TABLE {$wpdb->term_taxonomy} AUTO_INCREMENT = 1");
foreach (['a' => 'publish', 'b' => 'publish', 'entwurf' => 'draft', 'voll' => 'publish', 'papierkorb' => 'publish'] as $slug => $status) {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => $status, 'post_title' => 'E2E ' . strtoupper($slug), 'post_name' => 'e2e-' . $slug,
        'post_content' => '<a href="' . $home . '/kontakt">Kontakt</a>']);
    update_post_meta($id, '_elementor_data', wp_slash($json));
    update_post_meta($id, '_e2e_json2', wp_slash($json2));
    update_post_meta($id, '_e2e_weg', 'wird lokal entfernt');
    update_post_meta($id, '_e2e_ser', wp_slash(['url' => $home . '/kontakt', 'json' => $json, 'json2' => $json2, 'n' => 1]));
    $ids[$slug] = $id;
}
$cat = term_exists('e2e-kategorie', 'category') ?: wp_insert_term('E2E Kategorie', 'category', ['slug' => 'e2e-kategorie']);
$ids['cat']    = (int) $cat['term_id'];
$ids['cat_tt'] = (int) $cat['term_taxonomy_id'];
update_option('blogname', 'wpsync Content-Push E2E');
update_option('blogdescription', 'E2E Untertitel');
update_option('wpseo_social', ['og_default_image' => $home . '/wp-content/uploads/og.png', 'json' => $json, 'json2' => $json2]);
delete_option('options_e2e_neu');
update_option('e2e_cdb_ids', $ids);
PHP
IDS="$(ddev wp eval 'echo json_encode(get_option("e2e_cdb_ids"));')"
PAGE_A="$(jq -r '.a' <<<"$IDS")"
PAGE_B="$(jq -r '.b' <<<"$IDS")"
DRAFT="$(jq -r '.entwurf' <<<"$IDS")"
PAGE_FULL="$(jq -r '.voll' <<<"$IDS")"
PAGE_TRASH="$(jq -r '.papierkorb' <<<"$IDS")"
CAT_TT="$(jq -r '.cat_tt' <<<"$IDS")"
for v in "$PAGE_A" "$PAGE_B" "$DRAFT" "$PAGE_FULL" "$PAGE_TRASH" "$CAT_TT"; do
  case "$v" in '' | *[!0-9]*) fail "Fixtures nicht angelegt ($IDS)" ;; esac
done

echo "== Agent, Pairing, Erst-Pull mit --content"
cp "$ROOT/agent/dist/wpsync-agent.zip" public/wpsync-agent.zip
ddev wp plugin install /var/www/html/public/wpsync-agent.zip --force --activate
rm public/wpsync-agent.zip
# Reste früherer Läufe: Kopie, offene Pushes, Sperren, Pairings.
ddev wp eval 'WpSync\Staging::uninstall(); WpSync\Push::uninstall(); global $wpdb; $wpdb->query("DELETE FROM " . WpSync\Store::table("pushes")); $wpdb->query("DELETE FROM " . WpSync\Store::table("pairings")); WpSync\Store::setState("push_lock", null); WpSync\Store::setState("infosheet", null);'
if find "$PUB" -maxdepth 1 -name 'wpsync-staging-*' | grep -q . || [ -n "$(ddev mysql -N -e "SHOW TABLES LIKE 'stg%'")" ]; then
  find "$PUB" -maxdepth 1 -name 'wpsync-staging-*' -exec rm -rf {} +
  for t in $(ddev mysql -N -e "SHOW TABLES LIKE 'stg%'"); do ddev mysql -e "DROP TABLE \`$t\`"; done
fi
CODE="$(ddev wp wpsync pair-code | tail -1)"
"$WPSYNC" unpair "$TARGET" >/dev/null 2>&1 || true
rm -rf "$SITE/.wpsync/content" "$SITE/.wpsync/pushes" "$SITE/.wpsync/staging-base.json"
"$WPSYNC" pair "$SOURCE_URL" "$CODE" --name "$TARGET" --insecure
KEY_ID="$(awk '/^key_id:/ { print $2 }' "$WPSYNC_CONFIG_DIR/sites/$TARGET.yaml")"
"$WPSYNC" scan "$TARGET" --refresh --preset vollstaendig --uploads-since alle
if [ -f "$SITE/.ddev/config.yaml" ] && [ ! -f "$WPSYNC_CONFIG_DIR/ddev-state/$TARGET.json" ]; then trust_ddev; fi
jrun pull "$WPSYNC" pull "$TARGET" --content --full --yes --json
[ "$RC" = 0 ] || fail "pull --content (Exit $RC, siehe $JSON/pull.err)"
LOCAL_URL="$(last pull '.data.local_url')"
LOCAL_HOST="$(printf %s "$LOCAL_URL" | sed -E 's#^https?://##; s#/.*$##')"
LIST_VERSION="$(head -n 1 "$CONTENT/manifest.jsonl" | jq -r '.head.list_version')"
eq "Kopf des Manifests: list_version 2 (gehärtete Listen)" "$LIST_VERSION" 2
ok "lokale Arbeitskopie hat die Seite" test -n "$(post tgt "$PAGE_A" post_title)"
# Der ID-Korridor des Studios (Studio §6.1): neue Objekte der Arbeitskopie beginnen bei 1.000.001 über dem Maximum von Live.
for table in posts terms term_taxonomy; do
  max="$(head -n 1 "$CONTENT/manifest.jsonl" | jq -r ".head.id_max.$table")"
  tgt mysql -e "ALTER TABLE ${PREFIX}${table} AUTO_INCREMENT = $((max + 1000001))"
done
cp "$CONTENT/manifest.jsonl" "$E2E/manifest.pulled"
cp "$CONTENT/baseline.jsonl" "$E2E/baseline.pulled"
window 0

echo "== Lokale Änderung an Seite A: Titel mit Emoji, Inhalt, Elementor-Meta über 100 KB mit \\/ und \\\\\\/, serialisiertes Meta, mehrere Werte je Schlüssel, ein Meta entfernt, drei Optionen"
TITLE_A='E2E A geändert 🚀 Ünïcödé 日本語'
tgt wp eval-file - "$PAGE_A" --skip-plugins --skip-themes <<'PHP'
<?php
$id      = (int) $args[0];
$home    = rtrim(home_url(), '/');
$widgets = [];
for ($i = 0; $i < 1000; $i++) {
    $widgets[] = ['id' => sprintf('w%04d', $i), 'elType' => 'widget', 'settings' => ['link' => ['url' => $home . '/neu'], 'text' => 'Größe ' . $i . ' 🚀']];
}
$json   = wp_json_encode($widgets);
$json2  = wp_json_encode(['data' => $json]);
$small  = wp_json_encode([['link' => $home . '/neu']]);
$small2 = wp_json_encode(['data' => $small]);
wp_update_post(['ID' => $id, 'post_title' => 'E2E A geändert 🚀 Ünïcödé 日本語', 'post_content' => '<a href="' . $home . '/neu">Neu</a> – „Größe“ 🚀']);
update_post_meta($id, '_elementor_data', wp_slash($json));
update_post_meta($id, '_e2e_json2', wp_slash($json2));
update_post_meta($id, '_e2e_ser', wp_slash(['url' => $home . '/neu', 'json' => $small, 'json2' => $small2, 'n' => 2, 'text' => 'Ä 🚀', 'tief' => ['liste' => [$home . '/a', $home . '/b']]]));
delete_post_meta($id, '_e2e_weg');
add_post_meta($id, '_e2e_multi', 'eins');
add_post_meta($id, '_e2e_multi', 'zwei');
add_post_meta($id, '_e2e_multi', 'eins');
update_post_meta($id, '_e2e_emoji', 'Wert mit 🚀, Ümläuten und 日本語');
update_option('blogdescription', 'Ünterzeile 🚀');
update_option('wpseo_social', ['og_default_image' => $home . '/wp-content/uploads/og-neu.png', 'json' => $small, 'json2' => $small2]);
update_option('options_e2e_neu', $home . '/neu');
PHP
EDIT_ONLY="(.t == \"options\" and (.k == \"blogdescription\" or .k == \"wpseo_social\" or .k == \"options_e2e_neu\")) or (.t != \"options\" and (.k | split(\"\u0000\")[0]) == \"$PAGE_A\")"
pkg edit "$EDIT_ONLY"
eq "Paket edit: Beitrag, fünf geänderte oder neue Meta, ein gelöschtes Paar, drei Optionen" "$ROWS" 10
eq "Paket edit: die neue Option ist ein insert" "$(jq -r 'select(.table == "options" and .key == "options_e2e_neu") | .op' "$PKG/edit.body")" insert
eq "Paket edit: drei Werte unter einem Schlüssel, zwei davon gleich" "$(jq -r 'select(.key | endswith("_e2e_multi")) | .row.values | length' "$PKG/edit.body")" 3
PROBE_TGT="$(probe tgt "$LOCAL_URL")"
ok "Arbeitskopie: _elementor_data ist über 100 KB gross" contains "$PROBE_TGT" "gross=ja"
no "Arbeitskopie: die serialisierten Werte sind lesbar" contains "$PROBE_TGT" "nicht lesbar"
no "Paket edit: keine lokale Adresse im Inhalt" contains "$(jq -r 'select(.table == "posts") | .row.post_content' "$PKG/edit.body" | openssl base64 -d -A)" "$LOCAL_HOST"
ok "Paket edit: Werte tragen Platzhalter" contains "$(jq -r 'select(.table == "posts") | .row.post_content' "$PKG/edit.body" | openssl base64 -d -A)" '⟦wpsync:origin⟧/neu'

echo "== Probelauf ohne Push-Fenster: das ganze Paket wird geprüft, nichts geschrieben"
push_content dry edit --dry-run
eq "Probelauf: Exit 0" "$RC" 0
eq "Probelauf: Status und Einheiten" "$(last dry '.data.status + " " + (.data.units | join(","))')" "dry_run content"
eq "Probelauf: plan.content.rows" "$(event dry plan | jq -c '.content.rows')" '{"options":3,"postmeta":6,"posts":1}'
eq "Probelauf: keine Konflikte" "$(event dry plan | jq -c '.content.conflicts')" '[]'
eq "Probelauf: Grenzen" "$(event dry plan | jq -c '[.content.limits.max_rows, .content.limits.max_bytes]')" '[5000,8388608]'
eq "Probelauf: kein Messwert, nichts wurde angewandt" "$(last dry '.data | has("content")')" false
eq "Probelauf: Fenster zu" "$(event dry plan | jq -r '.window_open')" false
eq "N3: ohne Fenster ist der Probelauf nur ein Teil – plan.content.partial" "$(event dry plan | jq -r '.content.partial')" true
ok "N3: die CLI sagt es in einer Zeile" hasF "$JSON/dry.err" "nur teilweise geprüft"
eq "M1/N1: die Grenzen nennen den ID-Abstand und die Summe der Vorher-Zustände" "$(event dry plan | jq -c '[.content.limits.id_headroom, .content.limits.max_state_bytes]')" '[2000000,67108864]'
# N3: ohne Fenster verrät der Probelauf nicht, ob es ein Objekt gibt – ein Verweis ins Leere sieht aus wie eine gesperrte Zeile.
raw_pkg dangling "$(jq -c -n --arg v "$(b64 'x')" '{op: "insert", table: "postmeta", key: ("987654\u0000_e2e_ins_leere"), expected: "absent", row: {values: [$v]}}')"
push_content dangling-closed dangling --dry-run
refused "N3: Verweis ins Leere ohne Fenster" dangling-closed blocked_row
eq "N3: ohne Fenster auch hier partial" "$(event dangling-closed plan | jq -r '.content.partial')" true
eq "Probelauf: Live unverändert" "$(post src "$PAGE_A" post_title)" "E2E A"
eq "Probelauf: die neue Option gibt es auf Live nicht" "$(opt src options_e2e_neu)" ""
push_content no-window edit --yes
eq "S3: echter Push ohne Fenster: Exit 40" "$RC" 40
eq "S3: ohne Fenster nichts auf Live" "$(post src "$PAGE_A" post_title)" "E2E A"

echo "== §7.8: Push nach Staging – dieselben Abdrücke, die Adressen der Kopie"
open_window
jrun staging-create "$WPSYNC" staging create "$TARGET" --yes --json --rps 20
[ "$RC" = 0 ] || fail "staging create (Exit $RC, siehe $JSON/staging-create.err)"
STG_URL="$(last staging-create '.data.url')"
STG_DIR="${STG_URL##*/}"
push_content push-staging edit --to staging --yes
eq "Staging: Exit 0" "$RC" 0
eq "N3: mit offenem Fenster ist die Prüfung vollständig – partial false" "$(event push-staging plan | jq -r '.content.partial')" false
eq "Staging: Ziel und Status" "$(last push-staging '.data.target + " " + .data.status')" "staging confirmed"
eq "Staging: Titel in der Kopie" "$(post stg "$PAGE_A" post_title)" "$TITLE_A"
ok "Staging: Klartext mit der Adresse der Kopie" contains "$(post stg "$PAGE_A" post_content)" "$STG_URL/neu"
ok "Staging: \\/ mit dem Pfad der Kopie" contains "$(meta stg "$PAGE_A" _elementor_data)" "$(esc1 "$STG_URL/neu")"
ok "Staging: \\\\\\/ mit dem Pfad der Kopie" contains "$(meta stg "$PAGE_A" _e2e_json2)" "$(esc2 "$STG_URL/neu")"
eq "Staging: gelöschtes Paar ist weg" "$(meta stg "$PAGE_A" _e2e_weg)" ""
eq "Staging: Live unverändert" "$(post src "$PAGE_A" post_title)" "E2E A"
eq "AC-147 Staging: jeder Wert der Kopie ist der der Arbeitskopie – Emoji, 100 KB, serialisiert, mehrere Werte, Optionen" "$(probe stg "$STG_URL")" "$PROBE_TGT"
eq "AC-147 Staging: der Agent meldet für jeden Schlüssel den Abdruck der Arbeitskopie" "$(after_diff "$PKG/edit.export")" 0
eq "Staging: content.after nennt alle zehn Schlüssel" "$(tap '.data.content.after | length')" 10
eq "Staging: die neue Option steht in der Kopie mit ihrer Adresse" "$(opt stg options_e2e_neu)" "$STG_URL/neu"
ok "§10: Manifest nach Staging unverändert" cmp -s "$E2E/manifest.pulled" "$CONTENT/manifest.jsonl"
ok "§10: Baseline nach Staging unverändert" cmp -s "$E2E/baseline.pulled" "$CONTENT/baseline.jsonl"
ok "V8: Vorher-Abbild liegt im Arbeitsordner der Kopie" sh -c "find '$PUB/$STG_DIR/wp-content' -path '*wpsync-push-*' -name before.json | grep -q ."
STG_BEFORE="$(find "$PUB/$STG_DIR/wp-content" -path '*wpsync-push-*' -name before.json | head -1)"
no "N2: das Vorher-Abbild ist kein lesbares JSON (versiegelt mit dem Schlüssel der Installation)" jq -e . "$STG_BEFORE"
ok "N2: es beginnt mit der Kennung des versiegelten Formats" sh -c "head -c 15 '$STG_BEFORE' | grep -qx 'wpsync-image:v1'"
no "N2: kein Wert der Site steht darin, auch nicht base64 wie im Abbild" grep -qaF -e "E2E Untertitel" -e "$(b64 'E2E Untertitel')" "$STG_BEFORE"

echo "== AC-150: derselbe Satz nach Live"
REV_BEFORE="$(src wp post list --post_type=revision --post_parent="$PAGE_A" --format=count)"
MOD_BEFORE="$(post src "$PAGE_A" post_modified_gmt)"
AUTHOR_BEFORE="$(post src "$PAGE_A" post_author)" # WP-CLI legt die Fixtures ohne Benutzer an: 0, nicht 1
SUM_A_BEFORE="$(rowsum "$PAGE_A")"
OPT_BEFORE="$(optsum)"
push_content push-live edit --yes
eq "Live: Exit 0" "$RC" 0
cat "$JSON/push-live.err"
PUSH_LIVE="$(last push-live '.data.push_id')"
eq "Live: Status und Einheiten" "$(last push-live '.data.status + " " + (.data.units | join(","))')" "confirmed content"
for e in plan commit health; do ok "Live: Ereignis $e" hasF "$JSON/push-live.jsonl" "\"event\":\"$e\""; done
eq "Live: Titel" "$(post src "$PAGE_A" post_title)" "$TITLE_A"
ok "Live: Klartext mit der Adresse von Live" contains "$(post src "$PAGE_A" post_content)" "$SOURCE_URL/neu"
ok "Live: \\/ mit der Adresse von Live" contains "$(meta src "$PAGE_A" _elementor_data)" "$(esc1 "$SOURCE_URL/neu")"
ok "Live: \\\\\\/ mit der Adresse von Live" contains "$(meta src "$PAGE_A" _e2e_json2)" "$(esc2 "$SOURCE_URL/neu")"
eq "Live: keine Adresse der Arbeitskopie in der Datenbank" "$(src mysql -N -e "SELECT (SELECT COUNT(*) FROM ${PREFIX}postmeta WHERE meta_value LIKE '%$LOCAL_HOST%') + (SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_content LIKE '%$LOCAL_HOST%')")" 0
eq "Live: gelöschtes Paar ist weg" "$(meta src "$PAGE_A" _e2e_weg)" ""
ok "Live: post_modified ist die Zeit des Pushs" test "$(post src "$PAGE_A" post_modified_gmt)" != "$MOD_BEFORE"
eq "Live: post_author bleibt" "$(post src "$PAGE_A" post_author)" "$AUTHOR_BEFORE"
eq "AC-154: Nacharbeiten im Ergebnis, Object-Cache gelungen" "$(last push-live '.data.post_actions | map(select(.step == "object_cache" and .ok)) | length')" 1
ok "AC-154: Revision der geänderten Seite angelegt" test "$(src wp post list --post_type=revision --post_parent="$PAGE_A" --format=count)" -gt "$REV_BEFORE"
eq "Live: geänderte Seite antwortet" "$(code "$SOURCE_URL/e2e-a/")" 200
eq "AC-147 Live: jeder Wert auf Live ist der der Arbeitskopie – Emoji, 100 KB, serialisiert, mehrere Werte, Optionen" "$(probe src "$SOURCE_URL")" "$PROBE_TGT"
eq "AC-147 Live: der Agent meldet für jeden Schlüssel den Abdruck der Arbeitskopie (wie für die Kopie)" "$(after_diff "$PKG/edit.export")" 0
eq "D14: das Ergebnis nennt Zeilen und Sekunden des Anwendens" "$(last push-live '.data.content | [.rows, (.seconds | type), (.seconds >= 0)] | @csv')" '10,"number",true'
eq "D14: es sind die Sekunden, die der Agent gemessen hat" "$(last push-live '.data.content.seconds')" "$(tap '.data.content.seconds')"
ok "D14: ohne --json eine Zeile für Menschen" grep -Eq 'Inhalte: 10 Zeilen in [0-9.]+ s angewandt' "$JSON/push-live.err"
echo "INFO: 10 Zeilen (darunter zwei Meta über 100 KB) angewandt in $(last push-live '.data.content.seconds') s"
eq "§10: Manifest trägt für jeden Schlüssel des Pakets den Abdruck der Arbeitskopie" "$(keys_diff "$PKG/edit.body" "$PKG/edit.export")" 0

echo "== AC-153: der reiche Satz wieder zurück – Byte für Byte der Stand davor – und noch einmal nach Live"
jrun rollback-live "$WPSYNC" rollback "$TARGET" "$PUSH_LIVE" --json
eq "Rücknahme Live: Exit 0" "$RC" 0
eq "Rücknahme Live: Beitrag, Meta und Zuordnungen von Seite A wie vor dem Push" "$(rowsum "$PAGE_A")" "$SUM_A_BEFORE"
eq "Rücknahme Live: die drei Optionen wie vor dem Push (die neue ist wieder weg)" "$(optsum)" "$OPT_BEFORE"
eq "Rücknahme Live: Manifest wie nach dem Pull" "$(msum "$CONTENT/manifest.jsonl")" "$(msum "$E2E/manifest.pulled")"
eq "Rücknahme Live: Baseline wie nach dem Pull" "$(msum "$CONTENT/baseline.jsonl")" "$(msum "$E2E/baseline.pulled")"
pkg edit-wieder "$EDIT_ONLY"
ok "Rücknahme Live: aus der Arbeitskopie entsteht wieder dasselbe Paket" cmp -s "$PKG/edit.body" "$PKG/edit-wieder.body"
push_content push-live2 edit --yes
eq "Live (noch einmal): Exit 0" "$RC" 0
PUSH_LIVE="$(last push-live2 '.data.push_id')"
eq "Live (noch einmal): wieder jeder Wert wie in der Arbeitskopie" "$(probe src "$SOURCE_URL")" "$PROBE_TGT"
eq "§10: Manifest trägt den Abdruck der Arbeitskopie" "$(hash_of "$CONTENT/manifest.jsonl" posts "$PAGE_A")" "$(hash_of "$PKG/edit.export" posts "$PAGE_A")"
no "§10: das gelöschte Paar steht nicht mehr im Manifest" test -n "$(hash_of "$CONTENT/manifest.jsonl" postmeta "$PAGE_A" _e2e_weg)"
pkg again "(.k | split(\"\u0000\")[0]) == \"$PAGE_A\""
eq "§10: nach dem Push ist für die Seite nichts mehr zu pushen" "$ROWS" 0
jrun pull-after "$WPSYNC" pull "$TARGET" --content --yes --json
eq "Folge-Pull: Exit 0" "$RC" 0
eq "AC-147: der Abdruck von Live ist der der Arbeitskopie" "$(hash_of "$CONTENT/manifest.jsonl" posts "$PAGE_A")" "$(hash_of "$PKG/edit.export" posts "$PAGE_A")"
eq "Folge-Pull: für keinen Schlüssel des Pakets weicht Live von der Arbeitskopie vor dem Push ab" "$(keys_diff "$PKG/edit.body" "$PKG/edit.export")" 0
eq "Folge-Pull: kein gepushter Schlüssel gilt als nicht treu übertragen" \
  "$(jq -c --arg a "$PAGE_A" 'select((.k | split("\u0000")[0]) == $a or .k == "blogdescription" or .k == "wpseo_social" or .k == "options_e2e_neu")' "$CONTENT/unfaithful.jsonl" | wc -l | tr -d ' ')" 0
pkg nach-pull
eq "Folge-Pull: content export gegen die Baseline ergibt ein leeres Paket" "$ROWS" 0
for table in posts terms term_taxonomy; do
  max="$(head -n 1 "$CONTENT/manifest.jsonl" | jq -r ".head.id_max.$table")"
  tgt mysql -e "ALTER TABLE ${PREFIX}${table} AUTO_INCREMENT = $((max + 1000001))"
done

echo "== AC-153: Rücknahme über den Agent stellt Live und den lokalen Inhaltsstand wieder her"
tgt wp post update "$PAGE_B" --post_title="E2E B geändert" --skip-plugins --skip-themes >/dev/null
pkg title-b ".t == \"posts\" and .k == \"$PAGE_B\""
eq "Paket title-b: eine Zeile" "$ROWS" 1
cp "$CONTENT/manifest.jsonl" "$E2E/manifest.before-b"
SUM_B_BEFORE="$(rowsum "$PAGE_B")"
push_content push-b title-b --yes
eq "Push B: Exit 0" "$RC" 0
PUSH_B="$(last push-b '.data.push_id')"
eq "Push B: Titel auf Live" "$(post src "$PAGE_B" post_title)" "E2E B geändert"
no "Push B: Manifest hat sich geändert" cmp -s "$E2E/manifest.before-b" "$CONTENT/manifest.jsonl"
jrun rollback-b "$WPSYNC" rollback "$TARGET" "$PUSH_B" --json
eq "Rücknahme B: Exit 0" "$RC" 0
eq "Rücknahme B: Status, Einheiten" "$(last rollback-b '.data.status + " " + (.data.units | join(","))')" "rolled_back content"
eq "Rücknahme B: ohne Warnung" "$(last rollback-b '.data | has("warnings")')" false
ok "AC-154: Nacharbeiten auch nach der Rücknahme" test "$(last rollback-b '.data.post_actions | length')" -gt 0
eq "Rücknahme B: Titel auf Live wie vorher" "$(post src "$PAGE_B" post_title)" "E2E B"
eq "Rücknahme B: die Zeile Byte für Byte wie vorher (auch post_modified)" "$(rowsum "$PAGE_B")" "$SUM_B_BEFORE"
eq "Rücknahme B: Manifest wie vor dem Push" "$(LC_ALL=C sort "$CONTENT/manifest.jsonl" | shasum | cut -d' ' -f1)" "$(LC_ALL=C sort "$E2E/manifest.before-b" | shasum | cut -d' ' -f1)"
no "Rücknahme B: rescue.php wurde nicht gebraucht" hasF "$JSON/rollback-b.err" "rescue.php"

echo "== AC-151: Konflikt – seit dem Pull auf Live geändert, alle Schlüssel genannt"
src wp post update "$PAGE_B" --post_title="auf Live geändert" >/dev/null
push_content conflict title-b --yes
refused "Konflikt" conflict conflict
eq "Konflikt: keys" "$(last conflict '.error.keys | map(.table + ":" + .key) | join(",")')" "posts:$PAGE_B"
eq "Konflikt: steht auch im plan" "$(event conflict plan | jq -c '.content.conflicts | length')" 1
eq "Konflikt: Live behält seinen Stand" "$(post src "$PAGE_B" post_title)" "auf Live geändert"
src wp post update "$PAGE_B" --post_title="E2E B" >/dev/null # wieder der Stand des Pulls

echo "== AC-151: Konflikt über mehrere Zeilen – jede wird genannt"
tgt wp eval "update_post_meta($PAGE_B, '_e2e_weg', 'lokal anders');" --skip-plugins --skip-themes
pkg conflict2 "(.k | split(\"\u0000\")[0]) == \"$PAGE_B\""
eq "Paket conflict2: der Beitrag und ein Meta" "$ROWS" 2
src wp eval "wp_update_post(['ID' => $PAGE_B, 'post_title' => 'auf Live geändert']); update_post_meta($PAGE_B, '_e2e_weg', 'auf Live anders');" >/dev/null
push_content conflict2 conflict2 --yes
refused "Konflikt, zwei Zeilen" conflict2 conflict
eq "Konflikt, zwei Zeilen: beide Schlüssel" "$(last conflict2 '.error.keys | map(.table + ":" + (.key | gsub("\u0000"; "/"))) | sort | join(",")')" "postmeta:$PAGE_B/_e2e_weg,posts:$PAGE_B"
eq "Konflikt, zwei Zeilen: beide im plan" "$(event conflict2 plan | jq -c '.content.conflicts | length')" 2
src wp eval "wp_update_post(['ID' => $PAGE_B, 'post_title' => 'E2E B']); update_post_meta($PAGE_B, '_e2e_weg', 'wird lokal entfernt');" >/dev/null

echo "== AC-151: Konflikt unter Sperre – eine zweite Sitzung ändert die Zeile, während der Push läuft"
# Die zweite Sitzung hält die Zeile des Beitrags gesperrt und schreibt erst nach dem Begin fest: der
# Probelauf und das Begin sehen noch den alten Stand, das Anwenden wartet an FOR UPDATE und findet
# dann den neuen – nichts wird geschrieben, auch die zweite Zeile des Pakets nicht.
(src mysql -e "START TRANSACTION; UPDATE ${PREFIX}posts SET post_title = 'gleichzeitig' WHERE ID = $PAGE_B; SELECT SLEEP(8); COMMIT" >/dev/null 2>&1) &
LOCKER=$!
sleep 2
push_content conflict-lock conflict2 --yes
wait "$LOCKER" || true
refused "Konflikt unter Sperre" conflict-lock conflict
eq "Konflikt unter Sperre: das Begin sah ihn noch nicht" "$(event conflict-lock plan | jq -c '.content.conflicts | length')" 0
eq "Konflikt unter Sperre: der Schlüssel" "$(last conflict-lock '.error.keys | map(.table + ":" + .key) | join(",")')" "posts:$PAGE_B"
eq "Konflikt unter Sperre: Live trägt den Stand der zweiten Sitzung" "$(post src "$PAGE_B" post_title)" "gleichzeitig"
eq "Konflikt unter Sperre: die zweite Zeile des Pakets wurde nicht geschrieben" "$(meta src "$PAGE_B" _e2e_weg)" "wird lokal entfernt"
eq "Konflikt unter Sperre: kein offener Push" "$(pending)" "null"
src wp post update "$PAGE_B" --post_title="E2E B" >/dev/null
tgt wp eval "update_post_meta($PAGE_B, '_e2e_weg', 'wird lokal entfernt');" --skip-plugins --skip-themes

echo "== AC-153: changed_since_push – nach einer Änderung auf Live wird nichts zurückgenommen"
push_content push-b2 title-b --yes
eq "Push B (zweiter): Exit 0" "$RC" 0
PUSH_B2="$(last push-b2 '.data.push_id')"
src wp post update "$PAGE_B" --post_title="nach dem Push geändert" >/dev/null
jrun rollback-changed "$WPSYNC" rollback "$TARGET" "$PUSH_B2" --json
refused "changed_since_push" rollback-changed changed_since_push
eq "changed_since_push: keys" "$(last rollback-changed '.error.keys | map(.table + ":" + .key) | join(",")')" "posts:$PAGE_B"
eq "changed_since_push: Live behält die Änderung" "$(post src "$PAGE_B" post_title)" "nach dem Push geändert"
no "changed_since_push: nie an der Prüfung vorbei über rescue.php" hasF "$JSON/rollback-changed.err" "rescue.php"
src wp post update "$PAGE_B" --post_title="E2E B geändert" >/dev/null # wieder der gepushte Stand
jrun rollback-b2 "$WPSYNC" rollback "$TARGET" "$PUSH_B2" --json
eq "Rücknahme nach Wiederherstellen des gepushten Stands: Exit 0" "$RC" 0
eq "Rücknahme: Titel wie vor dem Push" "$(post src "$PAGE_B" post_title)" "E2E B"
tgt wp post update "$PAGE_B" --post_title="E2E B" --skip-plugins --skip-themes >/dev/null

echo "== AC-150: neue Seite im Korridor mit Meta und Zuordnung, ein Entwurf in den Papierkorb"
NEW_ID="$(tgt wp post create --post_type=page --post_status=publish --post_title="E2E Neu" --post_name=e2e-neu --porcelain --skip-plugins --skip-themes)"
ok "die neue Seite liegt im Korridor" test "$NEW_ID" -gt 1000000
NEW_TERM_JSON="$(tgt wp eval "\$t = wp_insert_term('E2E Neu Term', 'category', ['slug' => 'e2e-neu-term']); add_term_meta(\$t['term_id'], '_e2e_term_meta', 'Ä 🚀'); echo json_encode(\$t);" --skip-plugins --skip-themes)"
NEW_TERM="$(jq -r '.term_id' <<<"$NEW_TERM_JSON")"
NEW_TT="$(jq -r '.term_taxonomy_id' <<<"$NEW_TERM_JSON")"
ok "der neue Term und seine Taxonomie-Zeile liegen im Korridor" test "$NEW_TERM" -gt 1000000 -a "$NEW_TT" -gt 1000000
# Ein Beitrag dazu: WordPress zählt in einer Kategorie nur Beiträge, keine Seiten – an ihm zeigt sich der Zähler.
NEW_POST="$(tgt wp post create --post_type=post --post_status=publish --post_title="E2E Neu Beitrag" --post_name=e2e-neu-beitrag --porcelain --skip-plugins --skip-themes)"
tgt wp eval "update_post_meta($NEW_ID, '_wp_page_template', 'default'); wp_set_object_terms($NEW_ID, [$(jq -r '.cat' <<<"$IDS"), $NEW_TERM], 'category'); wp_set_object_terms($NEW_POST, [$(jq -r '.cat' <<<"$IDS"), $NEW_TERM], 'category'); wp_trash_post($DRAFT);" --skip-plugins --skip-themes
pkg neu "(.k | split(\"\u0000\")[0]) as \$o | ((.t == \"posts\" or .t == \"postmeta\" or .t == \"term_relationships\") and (\$o == \"$NEW_ID\" or \$o == \"$NEW_POST\" or \$o == \"$DRAFT\")) or ((.t == \"terms\" or .t == \"termmeta\") and \$o == \"$NEW_TERM\") or (.t == \"term_taxonomy\" and \$o == \"$NEW_TT\")"
eq "Paket neu: insert des Beitrags, seiner Meta, des Terms mit Meta und Taxonomie, der Zuordnung und trash" "$(jq -r '.op + ":" + .table' "$PKG/neu.body" | LC_ALL=C sort -u | paste -sd' ' -)" "insert:postmeta insert:posts insert:term_relationships insert:term_taxonomy insert:termmeta insert:terms trash:posts"
SUM_DRAFT_BEFORE="$(rowsum "$DRAFT")"
eq "Paket neu: trash des nie veröffentlichten Entwurfs trägt genau post_date und post_date_gmt" "$(jq -r --arg d "$DRAFT" 'select(.op == "trash" and .key == $d) | .row | keys | join(",")' "$PKG/neu.body")" "post_date,post_date_gmt"
no "Paket neu: das neue Datum ist nicht mehr die Null der Baseline" test "$(jq -r --arg d "$DRAFT" 'select(.op == "trash" and .key == $d) | .row.post_date_gmt' "$PKG/neu.body" | openssl base64 -d -A)" = "0000-00-00 00:00:00"
eq "Paket neu: für den Entwurf nur die Zeile trash, keine Meta des Papierkorbs" "$(jq -r --arg d "$DRAFT" 'select(.key | split("\u0000")[0] == $d) | .op + ":" + .table' "$PKG/neu.body" | paste -sd' ' -)" "trash:posts"
push_content push-neu neu --yes
eq "Neu: Exit 0" "$RC" 0
cat "$JSON/push-neu.err"
PUSH_NEU="$(last push-neu '.data.push_id')"
eq "Neu: dieselbe ID auf Live" "$(post src "$NEW_ID" post_name)" "e2e-neu"
eq "Neu: Autor ist der Öffner des Fensters" "$(post src "$NEW_ID" post_author)" 1
eq "Neu: guid in der Permalink-Form von Live" "$(post src "$NEW_ID" guid)" "$SOURCE_URL/?p=$NEW_ID"
eq "Neu: Meta" "$(meta src "$NEW_ID" _wp_page_template)" "default"
eq "Neu: Zuordnung zur Kategorie" "$(src mysql -N -e "SELECT COUNT(*) FROM ${PREFIX}term_relationships WHERE object_id = $NEW_ID AND term_taxonomy_id = $CAT_TT")" 1
eq "Neu: der Term mit derselben ID, seine Taxonomie-Zeile und die Zuordnung" \
  "$(src mysql -N -e "SELECT CONCAT(t.slug, ' ', x.taxonomy, ' ', (SELECT COUNT(*) FROM ${PREFIX}term_relationships WHERE object_id = $NEW_ID AND term_taxonomy_id = $NEW_TT)) FROM ${PREFIX}terms t JOIN ${PREFIX}term_taxonomy x ON x.term_id = t.term_id WHERE t.term_id = $NEW_TERM AND x.term_taxonomy_id = $NEW_TT")" "e2e-neu-term category 1"
eq "Neu: Term-Meta mit Umlaut und Emoji bytegleich" "$(src mysql -N -e "SELECT HEX(meta_value) FROM ${PREFIX}termmeta WHERE term_id = $NEW_TERM AND meta_key = '_e2e_term_meta'")" "$(tgt mysql -N -e "SELECT HEX(meta_value) FROM ${PREFIX}termmeta WHERE term_id = $NEW_TERM AND meta_key = '_e2e_term_meta'")"
eq "AC-154: Zähler der beiden Kategorien nachgezogen (ein Beitrag; die Seite zählt WordPress nicht)" "$(src mysql -N -e "SELECT GROUP_CONCAT(count ORDER BY term_taxonomy_id) FROM ${PREFIX}term_taxonomy WHERE term_taxonomy_id IN ($CAT_TT, $NEW_TT)")" "1,1"
eq "AC-154: die Zähler sind die der Arbeitskopie" "$(src mysql -N -e "SELECT GROUP_CONCAT(count ORDER BY term_taxonomy_id) FROM ${PREFIX}term_taxonomy WHERE term_taxonomy_id IN ($CAT_TT, $NEW_TT)")" "$(tgt mysql -N -e "SELECT GROUP_CONCAT(count ORDER BY term_taxonomy_id) FROM ${PREFIX}term_taxonomy WHERE term_taxonomy_id IN ($CAT_TT, $NEW_TT)")"
eq "Neu: der Beitrag antwortet unter seiner Adresse" "$(code "$SOURCE_URL/e2e-neu-beitrag/")" 200
eq "Neu: die Seite antwortet unter ihrer Adresse" "$(code "$SOURCE_URL/e2e-neu/")" 200
eq "Papierkorb: Status" "$(post src "$DRAFT" post_status)" "trash"
eq "Papierkorb: der Agent schreibt die Papierkorb-Meta (W8)" "$(meta src "$DRAFT" _wp_trash_meta_status)" "draft"
eq "Papierkorb: __trashed am Namen wie in WordPress" "$(post src "$DRAFT" post_name)" "$(post tgt "$DRAFT" post_name)"
eq "Papierkorb: der alte Name in _wp_desired_post_slug" "$(meta src "$DRAFT" _wp_desired_post_slug)" "e2e-entwurf"
# M3: was nach dem Push an einem eingefügten Beitrag entstand, löscht die Rücknahme nicht mit – sie lehnt ab.
COMMENT="$(src wp comment create --comment_post_ID="$NEW_ID" --comment_content="nach dem Push" --comment_author=e2e --porcelain)"
src wp eval "add_post_meta($NEW_POST, '_e2e_nach_dem_push', 'bleibt'); update_post_meta($NEW_ID, '_edit_lock', time() . ':1'); update_post_meta($NEW_ID, '_edit_last', '1');" >/dev/null
jrun rollback-grown "$WPSYNC" rollback "$TARGET" "$PUSH_NEU" --json
refused "M3: eingefügte Objekte sind seit dem Push gewachsen" rollback-grown changed_since_push
eq "M3: genannt werden der Kommentar und das neue Meta – nicht _edit_lock und _edit_last" \
  "$(last rollback-grown '.error.keys | map(.table + ":" + (.key | gsub("\u0000"; "/"))) | sort | join(",")')" "comments:$NEW_ID,postmeta:$NEW_POST/_e2e_nach_dem_push"
eq "M3: nichts wurde zurückgenommen – die neue Seite und der Kommentar stehen" "$(src mysql -N -e "SELECT (SELECT COUNT(*) FROM ${PREFIX}posts WHERE ID IN ($NEW_ID, $NEW_POST)) + (SELECT COUNT(*) FROM ${PREFIX}comments WHERE comment_post_ID = $NEW_ID)")" 3
eq "M3: auch der Entwurf liegt noch im Papierkorb" "$(post src "$DRAFT" post_status)" "trash"
src wp comment delete "$COMMENT" --force >/dev/null
src wp eval "delete_post_meta($NEW_POST, '_e2e_nach_dem_push');" >/dev/null
# _edit_lock und _edit_last bleiben: Meta der festen Sperrliste hindert die Rücknahme nicht und geht mit der Seite.
jrun rollback-neu "$WPSYNC" rollback "$TARGET" "$PUSH_NEU" --json
eq "AC-153 Rücknahme: Exit 0" "$RC" 0
eq "AC-153: die neue Seite und der neue Beitrag sind samt Meta und Zuordnung wieder weg" \
  "$(src mysql -N -e "SELECT (SELECT COUNT(*) FROM ${PREFIX}posts WHERE ID IN ($NEW_ID, $NEW_POST)) + (SELECT COUNT(*) FROM ${PREFIX}postmeta WHERE post_id IN ($NEW_ID, $NEW_POST)) + (SELECT COUNT(*) FROM ${PREFIX}term_relationships WHERE object_id IN ($NEW_ID, $NEW_POST))")" 0
eq "AC-153: der Entwurf ist aus dem Papierkorb zurück" "$(post src "$DRAFT" post_status)" "draft"
eq "AC-153: die Papierkorb-Meta sind wieder weg" "$(meta src "$DRAFT" _wp_trash_meta_status)$(meta src "$DRAFT" _wp_desired_post_slug)" ""
eq "AC-153: der Name ist wieder der alte" "$(post src "$DRAFT" post_name)" "e2e-entwurf"
eq "AC-153: der Entwurf Byte für Byte wie vor dem Push – auch sein altes Datum" "$(rowsum "$DRAFT")" "$SUM_DRAFT_BEFORE"
eq "AC-153: der neue Term ist samt Meta und Taxonomie-Zeile wieder weg" \
  "$(src mysql -N -e "SELECT (SELECT COUNT(*) FROM ${PREFIX}terms WHERE term_id = $NEW_TERM) + (SELECT COUNT(*) FROM ${PREFIX}termmeta WHERE term_id = $NEW_TERM) + (SELECT COUNT(*) FROM ${PREFIX}term_taxonomy WHERE term_taxonomy_id = $NEW_TT)")" 0
eq "AC-154: der Zähler der alten Kategorie ist wieder 0" "$(src mysql -N -e "SELECT count FROM ${PREFIX}term_taxonomy WHERE term_taxonomy_id = $CAT_TT")" 0
# Noch einmal nach Live – dieselben Abdrücke gelten wieder – und dort mit WordPress selbst wiederherstellen.
push_content push-neu2 neu --yes
eq "Neu (zweiter Push desselben Pakets): Exit 0" "$RC" 0
pkg nach-papierkorb "(.k | split(\"\u0000\")[0]) == \"$DRAFT\""
eq "Papierkorb: nach dem Push weicht die Arbeitskopie für den Entwurf nicht mehr von Live ab – auch nicht im Datum" "$ROWS" 0
eq "Papierkorb: der Entwurf trägt auf Live das Datum, das WordPress ihm lokal beim Verschieben gab" "$(post src "$DRAFT" "CONCAT(post_date, ' ', post_date_gmt)")" "$(post tgt "$DRAFT" "CONCAT(post_date, ' ', post_date_gmt)")"
src wp eval "wp_untrash_post($DRAFT);" >/dev/null
no "Papierkorb: „Wiederherstellen“ in WordPress funktioniert" test "$(post src "$DRAFT" post_status)" = trash
eq "Papierkorb: nach dem Wiederherstellen trägt der Entwurf wieder seinen Namen" "$(post src "$DRAFT" post_name)" "e2e-entwurf"

echo "== Papierkorb einer veröffentlichten Seite: Manifest und Baseline ziehen mit, die Rücknahme stellt sie wieder her"
tgt wp eval "wp_trash_post($PAGE_TRASH);" --skip-plugins --skip-themes
pkg papierkorb "(.k | split(\"\u0000\")[0]) == \"$PAGE_TRASH\""
eq "Paket papierkorb: nur die Zeile trash" "$(jq -r '.op + ":" + .table' "$PKG/papierkorb.body" | paste -sd' ' -)" "trash:posts"
eq "Paket papierkorb: eine veröffentlichte Seite behält ihr Datum – trash ohne row" "$(jq -c 'has("row")' "$PKG/papierkorb.body")" false
SUM_TRASH_BEFORE="$(rowsum "$PAGE_TRASH")"
push_content push-papierkorb-seite papierkorb --yes
eq "Papierkorb (Seite): Exit 0" "$RC" 0
cat "$JSON/push-papierkorb-seite.err"
eq "Papierkorb (Seite): Status und Name wie in der Arbeitskopie" "$(post src "$PAGE_TRASH" "CONCAT(post_status, ' ', post_name)")" "$(post tgt "$PAGE_TRASH" "CONCAT(post_status, ' ', post_name)")"
eq "Papierkorb (Seite): die Seite antwortet nicht mehr" "$(code "$SOURCE_URL/e2e-papierkorb/")" 404
pkg papierkorb-danach "(.k | split(\"\u0000\")[0]) == \"$PAGE_TRASH\""
eq "Papierkorb (Seite): nach dem Push weicht die Arbeitskopie nicht mehr von Live ab" "$ROWS" 0
jrun rollback-papierkorb "$WPSYNC" rollback "$TARGET" "$(last push-papierkorb-seite '.data.push_id')" --json
eq "Papierkorb (Seite) zurück: Exit 0" "$RC" 0
eq "Papierkorb (Seite) zurück: Byte für Byte wie vor dem Push" "$(rowsum "$PAGE_TRASH")" "$SUM_TRASH_BEFORE"
eq "Papierkorb (Seite) zurück: die Seite antwortet wieder" "$(code "$SOURCE_URL/e2e-papierkorb/")" 200

echo "== AC-152: id_taken – die ID eines neuen Objekts ist auf Live schon belegt"
TAKEN="$(tgt wp post create --post_type=page --post_status=draft --post_title="E2E Belegt" --post_name=e2e-belegt --porcelain --skip-plugins --skip-themes)"
src mysql -e "INSERT INTO ${PREFIX}posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_type) VALUES ($TAKEN, 1, NOW(), UTC_TIMESTAMP(), '', 'fremd', '', 'draft', 'e2e-fremd', '', '', NOW(), UTC_TIMESTAMP(), '', 'page')"
pkg taken ".t == \"posts\" and .k == \"$TAKEN\""
push_content id-taken taken --dry-run
refused "id_taken" id-taken id_taken
eq "id_taken: keys" "$(last id-taken '.error.keys | map(.table + ":" + .key) | join(",")')" "posts:$TAKEN"
eq "id_taken: die fremde Zeile bleibt" "$(post src "$TAKEN" post_title)" "fremd"
src mysql -e "DELETE FROM ${PREFIX}posts WHERE ID = $TAKEN"

echo "== H2: den Benutzer, der das Fenster geöffnet hat, gibt es nicht mehr – kein neuer Beitrag ohne Autor"
src wp eval "WpSync\\Admin::openWindow('$KEY_ID', 28800, 987654);" >/dev/null
push_content author-gone taken --yes
refused "H2: Öffner des Fensters existiert nicht" author-gone author_unknown
eq "H2: der Beitrag wurde nicht angelegt" "$(src mysql -N -e "SELECT COUNT(*) FROM ${PREFIX}posts WHERE ID = $TAKEN")" 0
eq "H2: kein offener Push" "$(pending)" "null"
open_window

echo "== M1: eine ID im Korridor des Pakets, aber weit über der höchsten ID von Live"
AI_BEFORE="$(src mysql -N -e "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PREFIX}posts'")"
jq -c --arg k "$TAKEN" 'select(.t == "posts" and .k == $k) | {op: "insert", table: "posts", key: "9999999", expected: "absent", row: .row}' "$PKG/taken.export" >"$PKG/far.body"
seal far
push_content id-far far --yes
refused "M1: ID weit über der höchsten des Ziels" id-far id_outside_corridor
eq "M1: keys" "$(last id-far '.error.keys | map(.table + ":" + .key) | join(",")')" "posts:9999999"
eq "M1: der Zähler von Live hat sich nicht bewegt" "$(src mysql -N -e "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${PREFIX}posts'")" "$AI_BEFORE"
tgt wp post delete "$TAKEN" --force --skip-plugins --skip-themes >/dev/null

echo "== Sicherheit: Pseudonym, Sperrliste, Rest der lokalen Adresse, serialisiertes Objekt"
tgt wp eval "update_post_meta($PAGE_B, '_e2e_form', 'Antwort an user-0123456789abcdef@example.invalid');" --skip-plugins --skip-themes
pkg pseudo ".t == \"postmeta\" and (.k | endswith(\"_e2e_form\"))"
push_content pseudonym pseudo --dry-run
refused "AC-149 Pseudonym" pseudonym pseudonym_in_package
eq "AC-149: Schlüssel und Muster" "$(last pseudonym '.error.keys | map(.table + ":" + (.key | gsub("\u0000"; "/")) + ":" + .pattern) | join(",")')" "postmeta:$PAGE_B/_e2e_form:email"
no "AC-149: der Wert steht in keiner Ausgabe" grep -qF 'user-0123456789abcdef' "$JSON/pseudonym.jsonl" "$JSON/pseudonym.err"
tgt wp eval "delete_post_meta($PAGE_B, '_e2e_form');" --skip-plugins --skip-themes
H_SITEURL="$(hash_of "$CONTENT/manifest.jsonl" options siteurl)"
raw_pkg blocked "$(jq -c -n --arg h "$H_SITEURL" --arg v "$(b64 'https://boese.example')" '{op: "update", table: "options", key: "siteurl", expected: $h, row: {option_value: $v}}')"
push_content blocked blocked --dry-run
refused "AC-155 gesperrte Zeile" blocked blocked_row
eq "blocked_row: keys" "$(last blocked '.error.keys | map(.table + ":" + .key) | join(",")')" "options:siteurl"
raw_pkg local-origin "$(jq -c -n --arg k "$PAGE_B" --arg v "$(b64 "u=https%3A%2F%2F$(printf %s "$LOCAL_HOST" | sed 's/\./%2E/g')%2Fx")" '{op: "insert", table: "postmeta", key: ($k + "\u0000_e2e_link"), expected: "absent", row: {values: [$v]}}')"
push_content local-origin local-origin --dry-run
refused "Rest der lokalen Adresse, URL-kodiert" local-origin local_origin_in_package
raw_pkg unsafe "$(jq -c -n --arg k "$PAGE_B" --arg v "$(b64 'O:8:"stdClass":1:{s:1:"a";i:1;}')" '{op: "insert", table: "postmeta", key: ($k + "\u0000_e2e_objekt"), expected: "absent", row: {values: [$v]}}')"
push_content unsafe unsafe --dry-run
refused "S1: serialisiertes Objekt" unsafe unsafe_value
raw_pkg unsafe-tail "$(jq -c -n --arg k "$PAGE_B" --arg v "$(b64 'O:8:"stdClass":0:{}x')" '{op: "insert", table: "postmeta", key: ($k + "\u0000_e2e_objekt"), expected: "absent", row: {values: [$v]}}')"
push_content unsafe-tail unsafe-tail --dry-run
refused "H1: Objekt mit Anhang" unsafe-tail unsafe_value
# N3, Gegenprobe: mit offenem Fenster heisst der Verweis ins Leere wieder dangling_reference.
seal dangling # neu versiegelt: seit dem Folge-Pull gilt eine andere map_id
push_content dangling-open dangling --dry-run
refused "N3: Verweis ins Leere mit offenem Fenster" dangling-open dangling_reference
eq "N3: mit Fenster partial false" "$(event dangling-open plan | jq -r '.content.partial')" false
# N4: der Schlüssel in anderer Schreibweise – WordPress läse ihn trotzdem als Datei des Attachments.
raw_pkg file-case "$(jq -c -n --arg k "$PAGE_B" --arg v "$(b64 '../../../wp-config.php')" '{op: "insert", table: "postmeta", key: ($k + "\u0000_WP_Attached_File"), expected: "absent", row: {values: [$v]}}')"
push_content file-case file-case --dry-run
refused "N4: _WP_Attached_File mit einem Pfad aus uploads hinaus" file-case blocked_row
# M4: was keine Projekt-Erweiterung freischaltet – und was sichtbar bleibt.
for ext in '{"post_types":["wc_e2e"],"taxonomies":[],"meta_exceptions":[]}' '{"post_types":["e2e-snippet"],"taxonomies":[],"meta_exceptions":[]}' '{"post_types":[],"taxonomies":["user-group"],"meta_exceptions":[]}' '{"post_types":[],"taxonomies":["e2e_roles"],"meta_exceptions":[]}'; do
  cp "$PKG/dangling.body" "$PKG/ext-bad.body"
  EXTENSIONS="$ext" seal ext-bad
  push_content ext-bad ext-bad --dry-run
  refused "M4: Erweiterung $ext" ext-bad package_invalid
done
cp "$PKG/title-b.body" "$PKG/ext-ok.body"
EXTENSIONS='{"post_types":["referenz"],"taxonomies":["branche"],"meta_exceptions":["design_token"]}' seal ext-ok
push_content ext-ok ext-ok --dry-run
eq "M4: ein Paket mit üblichen Erweiterungen geht durch den Probelauf" "$RC" 0
eq "M4: der Probelauf nennt die Erweiterungen des Pakets" "$(event ext-ok plan | jq -c '.content.extensions')" '{"post_types":["referenz"],"taxonomies":["branche"],"meta_exceptions":["design_token"]}'
raw_pkg unknown-placeholder "$(jq -c -n --arg k "$PAGE_B" --arg v "$(b64 '⟦wpsync:origin:esc9⟧/x')" '{op: "insert", table: "postmeta", key: ($k + "\u0000_e2e_ph"), expected: "absent", row: {values: [$v]}}')"
push_content unknown-placeholder unknown-placeholder --dry-run
refused "Platzhalter in unbekannter Form" unknown-placeholder package_invalid
raw_pkg trash-row "$(jq -c -n --arg k "$PAGE_B" --arg h "$(hash_of "$CONTENT/manifest.jsonl" posts "$PAGE_B")" --arg d "$(b64 '2026-10-09 12:00:00')" --arg t "$(b64 'eingeschmuggelt')" '{op: "trash", table: "posts", key: $k, expected: $h, row: {post_date: $d, post_date_gmt: $d, post_title: $t}}')"
push_content trash-row trash-row --dry-run
refused "trash mit einer Spalte ausser den beiden Daten" trash-row package_invalid
# D15: denselben Schlüssel gibt es auf Live in anderer Schreibweise – für die Datenbank gleich, in Bytes verschieden.
src wp eval "add_post_meta($PAGE_B, '_E2E_Twin', 'x');" >/dev/null
raw_pkg twin "$(jq -c -n --arg k "$PAGE_B" --arg v "$(b64 'y')" '{op: "insert", table: "postmeta", key: ($k + "\u0000_e2e_twin"), expected: "absent", row: {values: [$v]}}')"
push_content twin twin --dry-run
refused "S4: Meta-Schlüssel, der sich nur in Gross/klein unterscheidet" twin blocked_row
eq "S4: der Zwilling auf Live bleibt" "$(src mysql -N -e "SELECT CONCAT(meta_key, '=', meta_value) FROM ${PREFIX}postmeta WHERE post_id = $PAGE_B AND meta_key = '_e2e_twin'")" "_E2E_Twin=x"
src wp eval "delete_post_meta($PAGE_B, '_E2E_Twin');" >/dev/null
eq "Sicherheit: keine dieser Zeilen kam auf Live an" "$(src mysql -N -e "SELECT COUNT(*) FROM ${PREFIX}postmeta WHERE meta_key IN ('_e2e_form', '_e2e_link', '_e2e_objekt', '_e2e_ph', '_e2e_twin', '_e2e_ins_leere', '_WP_Attached_File')")" 0
eq "Sicherheit: siteurl unverändert" "$(opt src siteurl)" "$SOURCE_URL"

echo "== §7.4/§7.6: Health-Check schlägt an – Rücknahme über den Agent, samt Inhalten"
tgt wp post update "$PAGE_B" --post_content="Diese Seite zeigt nach dem Push: Fatal error" --skip-plugins --skip-themes >/dev/null
pkg health ".t == \"posts\" and .k == \"$PAGE_B\""
SUM_B_BEFORE="$(rowsum "$PAGE_B")"
push_content health-rollback health --yes
eq "Health: Exit 43" "$RC" 43
cat "$JSON/health-rollback.err"
eq "Health: Status" "$(last health-rollback '.data.status')" rolled_back
eq "Health: die geänderte Seite ist die verschlechterte" "$(last health-rollback '.data.health | map(.url) | join(",")')" "$SOURCE_URL/e2e-b/"
eq "Health: kein Messwert mehr – die Inhalte stehen nicht auf der Site" "$(last health-rollback '.data | has("content")')" false
eq "Health: ohne Warnung – die Inhalte sind zurück" "$(last health-rollback '.data | has("warnings")')" false
no "Health: Rücknahme über den Agent, nicht über rescue.php" hasF "$JSON/health-rollback.err" "rescue.php"
no "Health: der Inhalt ist nicht mehr auf Live" contains "$(post src "$PAGE_B" post_content)" "Fatal error"
eq "Health: Live antwortet" "$(code "$SOURCE_URL/e2e-b/")" 200
eq "Health: die Seite Byte für Byte wie vor dem Push" "$(rowsum "$PAGE_B")" "$SUM_B_BEFORE"
eq "Health: kein offener Push" "$(pending)" "null"
tgt wp post update "$PAGE_B" --post_content='<a href="'"$LOCAL_URL"'/kontakt">Kontakt</a>' --skip-plugins --skip-themes >/dev/null

echo "== AC-157: WordPress antwortet nicht – rescue.php nimmt Code zurück, die Inhalte holt wpsync rollback nach"
HEALTH="$LWPC/plugins/e2e-health/e2e-health.php"
cp -p "$HEALTH" "$E2E/e2e-health.good"
printf '\nthis is not php(\n' >>"$HEALTH"
tgt wp post update "$PAGE_FULL" --post_title="E2E VOLL mit kaputtem Code" --skip-plugins --skip-themes >/dev/null
pkg broken ".t == \"posts\" and .k == \"$PAGE_FULL\""
SUM_FULL_BEFORE="$(rowsum "$PAGE_FULL")"
jrun broken "$WPSYNC" push "$TARGET" code plugins/e2e-health --content "$PKG/broken.jsonl" --yes --json
eq "Stumm: Exit 43" "$RC" 43
cat "$JSON/broken.err"
PUSH_BROKEN="$(last broken '.data.push_id')"
eq "Stumm: Status und Warnung" "$(last broken '.data.status + " " + (.data.warnings | join(","))')" "rolled_back content_not_rolled_back"
ok "Stumm: Weg über rescue.php" hasF "$JSON/broken.err" "rescue.php"
ok "Stumm: Hinweis auf wpsync rollback (mit --json in error.message)" contains "$(last broken '.error.message')" "wpsync rollback $TARGET $PUSH_BROKEN"
no "Stumm: kaputter Code auf Live" grep -q "this is not php" "$WPC/plugins/e2e-health/e2e-health.php"
eq "Stumm: Live antwortet wieder" "$(code "$SOURCE_URL/")" 200
eq "Stumm: der Messwert bleibt – die Inhalte stehen noch" "$(last broken '.data.content.rows')" 1
eq "Stumm: die Inhalte stehen noch" "$(post src "$PAGE_FULL" post_title)" "E2E VOLL mit kaputtem Code"
cp -p "$E2E/e2e-health.good" "$HEALTH"
jrun pending "$WPSYNC" push "$TARGET" code --no-code --content "$PKG/broken.jsonl" --dry-run --json
eq "Stumm: der Push bleibt offen und blockiert weitere (Exit 42)" "$RC" 42
jrun rollback-broken "$WPSYNC" rollback "$TARGET" "$PUSH_BROKEN" --json
eq "Nachholen: Exit 0" "$RC" 0
eq "Nachholen: ohne Warnung" "$(last rollback-broken '.data | has("warnings")')" false
eq "Nachholen: die Inhalte sind zurück" "$(post src "$PAGE_FULL" post_title)" "E2E VOLL"
eq "Nachholen: die Seite Byte für Byte wie vor dem Push" "$(rowsum "$PAGE_FULL")" "$SUM_FULL_BEFORE"
eq "Nachholen: kein offener Push mehr" "$(pending)" "null"
tgt wp post update "$PAGE_FULL" --post_title="E2E VOLL" --skip-plugins --skip-themes >/dev/null

echo "== Ein Satz aus Code, Uploads und Inhalten – und ganz zurück"
THEME="$LWPC/themes/e2e-theme/index.php"
sed -i '' -E 's/e2e-marker v[0-9]+/e2e-marker v2/' "$THEME"
mkdir -p "$LWPC/uploads/2026/10"
printf %s "$PNG_B64" | openssl base64 -d -A >"$LWPC/uploads/2026/10/e2e-satz.png"
printf '2026/10/e2e-satz.png\n' >"$E2E/uploads.txt"
tgt wp post update "$PAGE_FULL" --post_title="E2E VOLL im Satz" --skip-plugins --skip-themes >/dev/null
pkg satz ".t == \"posts\" and .k == \"$PAGE_FULL\""
cp "$CONTENT/manifest.jsonl" "$E2E/manifest.before-satz"
cp "$SITE/.wpsync/baseline.json" "$E2E/baseline.before-satz"
SUM_FULL_BEFORE="$(rowsum "$PAGE_FULL")"
jrun satz "$WPSYNC" push "$TARGET" code themes/e2e-theme --uploads "$E2E/uploads.txt" --content "$PKG/satz.jsonl" --yes --json
eq "Satz: Exit 0" "$RC" 0
cat "$JSON/satz.err"
PUSH_SATZ="$(last satz '.data.push_id')"
eq "Satz: Einheiten in der Reihenfolge Code, Uploads, Inhalte" "$(last satz '.data.units | join(",")')" "themes/e2e-theme,uploads,content"
ok "Satz: Code auf Live" grep -q 'e2e-marker v2' "$WPC/themes/e2e-theme/index.php"
ok "Satz: Upload auf Live" cmp -s "$LWPC/uploads/2026/10/e2e-satz.png" "$WPC/uploads/2026/10/e2e-satz.png"
eq "Satz: Inhalte auf Live" "$(post src "$PAGE_FULL" post_title)" "E2E VOLL im Satz"
eq "Satz: das Protokoll nennt alle drei" "$("$WPSYNC" pushes "$TARGET" --json | jq -r ".data.pushes[] | select(.push_id == \"$PUSH_SATZ\") | [.units[].path] | join(\",\")")" "themes/e2e-theme,uploads,content"
jrun rollback-satz "$WPSYNC" rollback "$TARGET" "$PUSH_SATZ" --json
eq "Satz zurück: Exit 0" "$RC" 0
ok "Satz zurück: Code" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"
no "Satz zurück: Upload" test -e "$WPC/uploads/2026/10/e2e-satz.png"
eq "Satz zurück: Inhalte" "$(post src "$PAGE_FULL" post_title)" "E2E VOLL"
eq "Satz zurück: die Seite Byte für Byte wie vor dem Push" "$(rowsum "$PAGE_FULL")" "$SUM_FULL_BEFORE"
eq "Satz zurück: Manifest" "$(LC_ALL=C sort "$CONTENT/manifest.jsonl" | shasum | cut -d' ' -f1)" "$(LC_ALL=C sort "$E2E/manifest.before-satz" | shasum | cut -d' ' -f1)"
ok "Satz zurück: Baseline der Dateien" cmp -s "$E2E/baseline.before-satz" "$SITE/.wpsync/baseline.json"
eq "Live antwortet" "$(code "$SOURCE_URL/")" 200

echo "== AC-150: die Datenbank lehnt mitten in der Transaktion ab – keine Zeile bleibt, Code und Uploads gehen zurück"
# Derselbe Satz, dazu zwei Meta. Ein Trigger der Quelle lässt genau das zweite scheitern: da sind die
# Zeile des Beitrags und das erste Meta in der Transaktion schon geschrieben.
src mysql <<SQL
DELIMITER //
CREATE TRIGGER e2e_boom BEFORE INSERT ON ${PREFIX}postmeta FOR EACH ROW
BEGIN
  IF NEW.meta_key = '_e2e_boom' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'e2e boom'; END IF;
END//
SQL
tgt wp eval "update_post_meta($PAGE_FULL, '_e2e_aaa', 'ginge durch'); update_post_meta($PAGE_FULL, '_e2e_boom', 'kommt nie an');" --skip-plugins --skip-themes
pkg boom "(.k | split(\"\u0000\")[0]) == \"$PAGE_FULL\""
eq "Paket boom: der Beitrag und zwei Meta, das scheiternde zuletzt" "$(jq -r '.table + ":" + (.key | split("\u0000")[1] // "")' "$PKG/boom.body" | paste -sd' ' -)" "posts: postmeta:_e2e_aaa postmeta:_e2e_boom"
jrun boom "$WPSYNC" push "$TARGET" code themes/e2e-theme --uploads "$E2E/uploads.txt" --content "$PKG/boom.jsonl" --yes --json
refused "Fehler mitten in der Transaktion" boom content_failed
eq "Fehler mittendrin: an der Seite hat sich kein Byte geändert (Beitrag und erstes Meta waren schon geschrieben)" "$(rowsum "$PAGE_FULL")" "$SUM_FULL_BEFORE"
eq "Fehler mittendrin: keines der beiden Meta auf Live" "$(src mysql -N -e "SELECT COUNT(*) FROM ${PREFIX}postmeta WHERE meta_key IN ('_e2e_aaa', '_e2e_boom')")" 0
ok "Fehler mittendrin: der Code ist zurückgetauscht" grep -q 'e2e-marker v1' "$WPC/themes/e2e-theme/index.php"
no "Fehler mittendrin: der Upload ist zurückgenommen" test -e "$WPC/uploads/2026/10/e2e-satz.png"
eq "Fehler mittendrin: kein offener Push" "$(pending)" "null"
eq "Fehler mittendrin: Manifest unverändert" "$(msum "$CONTENT/manifest.jsonl")" "$(msum "$E2E/manifest.before-satz")"
ok "Fehler mittendrin: Baseline der Dateien unverändert" cmp -s "$E2E/baseline.before-satz" "$SITE/.wpsync/baseline.json"
eq "Fehler mittendrin: Live antwortet" "$(code "$SOURCE_URL/e2e-voll/")" 200
# M2: die gescheiterte Abfrage trug den Wert des Pakets – im Fehlerprotokoll des Servers steht er nicht.
src logs -s web >"$E2E/web.log" 2>&1 || true
no "M2: der Wert der gescheiterten Abfrage steht nicht im Fehlerprotokoll" grep -qF "kommt nie an" "$E2E/web.log"
no "M2: auch die Abfrage selbst nicht" grep -qF "_e2e_boom" "$E2E/web.log"
ok "M2: protokolliert ist nur die Fehlernummer der Datenbank" grep -qF "wpsync: a query of the content channel failed (MySQL error 1644)" "$E2E/web.log"
src mysql -e "DROP TRIGGER IF EXISTS e2e_boom"
tgt wp eval "delete_post_meta($PAGE_FULL, '_e2e_aaa'); delete_post_meta($PAGE_FULL, '_e2e_boom');" --skip-plugins --skip-themes

echo "== N1: eine fremde Sitzung hält die Zeile länger als 10 Sekunden gesperrt – content_failed statt eines hängenden Requests"
tgt wp post update "$PAGE_B" --post_title="E2E B wartet" --skip-plugins --skip-themes >/dev/null
pkg lock-wait ".t == \"posts\" and .k == \"$PAGE_B\""
SUM_B_BEFORE="$(rowsum "$PAGE_B")"
(src mysql -e "START TRANSACTION; SELECT ID FROM ${PREFIX}posts WHERE ID = $PAGE_B FOR UPDATE; SELECT SLEEP(25); ROLLBACK" >/dev/null 2>&1) &
LOCKER=$!
sleep 2
T0="$(date +%s)"
push_content lock-wait lock-wait --yes
WAITED=$(($(date +%s) - T0))
wait "$LOCKER" || true
refused "N1: Wartezeit auf eine Sperre abgelaufen" lock-wait content_failed
ok "N1: der Push hat nicht auf das Ende der fremden Sitzung gewartet (${WAITED} s)" test "$WAITED" -lt 22
eq "N1: an der Seite hat sich kein Byte geändert" "$(rowsum "$PAGE_B")" "$SUM_B_BEFORE"
eq "N1: kein offener Push" "$(pending)" "null"
tgt wp post update "$PAGE_B" --post_title="E2E B" --skip-plugins --skip-themes >/dev/null

echo "== D7: die Datenbank schneidet einen Wert ab – write_mismatch, die Transaktion geht ganz zurück"
# post_title ist TEXT und fasst 65.535 Bytes (kürzere Spalten wie post_name prüft schon die Form des
# Pakets); WordPress schaltet den strengen Modus der Datenbank ab, sie kürzt also still. Der Agent
# liest zurück, der Abdruck ist nicht der der Paketzeile. Die Zeile davor (Titel der Seite VOLL) ist
# da schon geschrieben.
LONG_TITLE="$(printf '%070000d' 0)"
jq -c --arg k "$PAGE_FULL" --arg h "$(hash_of "$CONTENT/manifest.jsonl" posts "$PAGE_FULL")" 'select(.t == "posts" and .k == $k) | {op: "update", table: "posts", key: .k, expected: $h, row: .row}' "$PKG/boom.export" >"$PKG/mismatch.body"
jq -c --arg k "$PAGE_B" --arg h "$(hash_of "$CONTENT/manifest.jsonl" posts "$PAGE_B")" --arg n "$(b64 "$LONG_TITLE")" 'select(.t == "posts" and .k == $k) | {op: "update", table: "posts", key: .k, expected: $h, row: (.row + {post_title: $n})}' "$PKG/boom.export" >>"$PKG/mismatch.body"
seal mismatch
eq "Paket mismatch: zwei Zeilen" "$(wc -l <"$PKG/mismatch.body" | tr -d ' ')" 2
SUM_B_BEFORE="$(rowsum "$PAGE_B")"
push_content mismatch mismatch --yes
refused "Abgeschnittener Wert" mismatch write_mismatch
eq "write_mismatch: der Schlüssel" "$(last mismatch '.error.keys | map(.table + ":" + .key) | join(",")')" "posts:$PAGE_B"
eq "write_mismatch: die Zeile mit dem zu langen Titel ist unverändert" "$(rowsum "$PAGE_B")" "$SUM_B_BEFORE"
eq "write_mismatch: auch die Zeile davor ist unverändert" "$(rowsum "$PAGE_FULL")" "$SUM_FULL_BEFORE"
eq "write_mismatch: kein offener Push" "$(pending)" "null"

echo "== Messung: 2.000 Zeilen in einer Transaktion und wieder zurück"
tgt wp eval "for (\$i = 1; \$i <= 2000; \$i++) { add_post_meta($PAGE_B, '_e2e_bulk_' . \$i, str_repeat('Ä', 100) . \$i); }" --skip-plugins --skip-themes
pkg bulk ".t == \"postmeta\" and (.k | contains(\"_e2e_bulk_\"))"
eq "Paket bulk: 2.000 Zeilen" "$ROWS" 2000
SUM_B_BEFORE="$(rowsum "$PAGE_B")"
T0="$(date +%s)"
push_content push-bulk bulk --yes
eq "Bulk: Exit 0" "$RC" 0
cat "$JSON/push-bulk.err"
eq "Bulk: das Ergebnis nennt 2.000 Zeilen" "$(last push-bulk '.data.content.rows')" 2000
echo "INFO: 2000 Zeilen (je ein Meta, rund 200 Bytes) angewandt in $(last push-bulk '.data.content.seconds') s; der ganze Push dauerte $(($(date +%s) - T0)) s"
eq "Bulk: 2.000 Meta auf Live" "$(src mysql -N -e "SELECT COUNT(*) FROM ${PREFIX}postmeta WHERE post_id = $PAGE_B AND meta_key LIKE '\\_e2e\\_bulk\\_%'")" 2000
eq "Bulk: nach dem Push ist nichts mehr zu pushen" "$(pkg bulk-danach ".t == \"postmeta\" and (.k | contains(\"_e2e_bulk_\"))"; echo "$ROWS")" 0
T0="$(date +%s)"
jrun rollback-bulk "$WPSYNC" rollback "$TARGET" "$(last push-bulk '.data.push_id')" --json
eq "Bulk zurück: Exit 0" "$RC" 0
echo "INFO: Rücknahme der 2000 Zeilen: $(($(date +%s) - T0)) s (ganzer Befehl)"
eq "Bulk zurück: die Seite Byte für Byte wie vor dem Push" "$(rowsum "$PAGE_B")" "$SUM_B_BEFORE"
tgt wp eval "global \$wpdb; \$wpdb->query(\"DELETE FROM {\$wpdb->postmeta} WHERE meta_key LIKE '\\\\_e2e\\\\_bulk\\\\_%'\"); wp_cache_flush();" --skip-plugins --skip-themes

echo "== Nichts verlässt den geschützten Arbeitsordner"
WORK="$(find "$WPC" -maxdepth 1 -name 'wpsync-push-*' | head -1)"
ok "Arbeitsordner hat eine .htaccess" test -s "$WORK/.htaccess"
PUSH_NEU2="$(last push-neu2 '.data.push_id')"
ok "Vorher-Abbild des bestätigten Pushs liegt im Arbeitsordner" test -s "$WORK/$PUSH_NEU2/content/before.json"
eq "N2: Vorher-Abbild und Paket sind nur für den Besitzer lesbar" "$(src exec stat -c %a "/var/www/html/public/wp-content/${WORK##*/}/$PUSH_NEU2/content/before.json" "/var/www/html/public/wp-content/${WORK##*/}/$PUSH_NEU2/content/after.json" "/var/www/html/public/wp-content/${WORK##*/}/$PUSH_NEU2/content/package.jsonl" | paste -sd' ' -)" "600 600 600"
no "N2: das Vorher-Abbild auf Live ist kein lesbares JSON" jq -e . "$WORK/$PUSH_NEU2/content/before.json"
eq "Vorher-Abbild ist von aussen nicht abrufbar" "$(code "$SOURCE_URL/wp-content/${WORK##*/}/$PUSH_NEU2/content/before.json")" 403
eq "abgelegtes Paket ist nach dem bestätigten Push nach Live weg" "$(find "$WORK/packages" -name "$(sha "$PKG/neu.jsonl").jsonl" 2>/dev/null | wc -l | tr -d ' ')" 0
no "kein Wert einer Zeile in den Fehlerausgaben" grep -rqF -- "boese.example" "$JSON"

echo "== Aufräumen auf der Quelle"
jrun staging-delete "$WPSYNC" staging delete "$TARGET" --yes --json
window 0
