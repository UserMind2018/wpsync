#!/usr/bin/env bash
# E2E Inhalte (Spec Content-Push P2a, AC-146/AC-147 Live ↔ Arbeitskopie, AC-148): pull --content
# gegen eine eigene DDEV-Quelle – Manifest, map.json, Baseline, unfaithful, env.json – und
# content export der Arbeitskopie, dazu wann der Pull die Inhaltstabellen neu lädt (Plan B1, B10,
# B11) und dass der Export offline läuft (B12).
# Eigene Projekte wpsync-e2e-content (Quelle) und wpsync-e2e-content-target (lokal) unter
# ~/wpsync-e2e/content; die geteilten E2E-Projekte bleiben unberührt. Das Kopplungs-Secret kommt
# über pair --secret-out und --secret-stdin, die Keychain bleibt unberührt.
#
# Voraussetzung: Docker, DDEV, jq, Go. Dauer rund 3 Minuten (erster Lauf länger).
# Eine fehlgeschlagene Prüfung zählt und der Lauf geht weiter; nur was den Rest sinnlos macht,
# bricht ab. Die JSON-Zeilen der Befehle liegen danach unter ~/wpsync-e2e/content/json.
# Am Ende werden beide Projekte gestoppt (nicht gelöscht); WPSYNC_E2E_KEEP=1 lässt sie laufen.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E2E="${WPSYNC_E2E_DIR:-$HOME/wpsync-e2e}/content"
export WPSYNC_CONFIG_DIR="$E2E/config"
export WPSYNC_SITES_DIR="$E2E/sites"
SOURCE_NAME=wpsync-e2e-content
TARGET=wpsync-e2e-content-target
SRC="$E2E/source"
SITE="$WPSYNC_SITES_DIR/$TARGET"
CONTENT="$SITE/.wpsync/content"
SITE_YAML="$WPSYNC_CONFIG_DIR/sites/$TARGET.yaml"
WPSYNC="$E2E/bin/wpsync" # eigener Build – nie eine installierte wpsync
JSON="$E2E/json"
PROBE="$E2E/probe.php"
PREFIX=e2e_
TABLES="posts postmeta terms term_taxonomy term_relationships termmeta options"

CHECKS=0
FAILED=0
RC=0
SECRET=""
SRC_DOWN=no
YAML_SAVED=no

pass() { CHECKS=$((CHECKS + 1)); }
bad() { CHECKS=$((CHECKS + 1)); FAILED=$((FAILED + 1)); echo "FAIL: $*"; }
fail() { echo "FAIL: $*"; exit 1; }
eq() { if [ "$2" = "$3" ]; then pass; else bad "$1 (ist: $2, soll: $3)"; fi; } # eq <was> <ist> <soll>
ok() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then pass; else bad "$what"; fi; }
no() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$what"; else pass; fi; }
hasF() { grep -qF -- "$2" "$1"; } # hasF <datei> <text>
src() { (cd "$SRC" && ddev "$@"); }
tgt() { (cd "$SITE" && ddev "$@"); }
SQL() { (cd "$SRC" && ddev mysql -N -e "$1"); }
http_url() { (cd "$1" && ddev describe -j | jq -r '.raw.httpurl'); }
code() { curl -s -o /dev/null -m 10 -w '%{http_code}' "$@" || true; }
sha() { shasum "$1" | cut -d' ' -f1; }
last() { tail -n 1 "$JSON/$1.jsonl" | jq -r "$2"; } # last <name> <jq>: über der Ergebniszeile
s1() { printf '%s\n' "$SECRET" | "$WPSYNC" "$@"; } # Secret als Zeile 1 von stdin
jrun() { # jrun <name> <befehl… --json>: stdout nach $JSON/<name>.jsonl, stderr nach .err, Exit nach RC
  local name="$1"
  shift
  set +e
  "$@" >"$JSON/$name.jsonl" 2>"$JSON/$name.err"
  RC=$?
  set -e
  # Jede Zeile gültiges JSON, die letzte das Ergebnis mit demselben Exit-Code.
  if jq -e -R 'fromjson | type == "object"' "$JSON/$name.jsonl" >/dev/null 2>&1 \
    && [ "$(last "$name" '[.event, .exit_code, .ok] | @tsv')" = "result"$'\t'"$RC"$'\t'"$([ "$RC" = 0 ] && echo true || echo false)" ]; then
    pass
  else
    bad "$name: stdout ist nicht zeilenweise JSON mit passendem Ergebnis (Exit $RC)"
    cat "$JSON/$name.jsonl" "$JSON/$name.err"
  fi
}
xrun() { # xrun <name> <befehl…>: wie jrun, ohne Prüfung der Form (content export ohne --json)
  local name="$1"
  shift
  set +e
  "$@" >"$JSON/$name.jsonl" 2>"$JSON/$name.err" </dev/null
  RC=$?
  set -e
}
# Schlüssel eines Paars ist <Objekt>\0<Name>. Bash hält kein NUL in einer Variablen, deshalb
# bekommen die Helfer Objekt und Name getrennt und jq setzt den Schlüssel zusammen.
KEYSEL='($o + (if $n == "" then "" else "\u0000" + $n end)) as $k | select(.t == $t and .k == $k)'
rec() { # rec <datei> <tabelle> <objekt> <name|""> [jq]: der Datensatz zu einem Schlüssel
  jq -r --arg t "$2" --arg o "$3" --arg n "$4" "$KEYSEL | ${5:-.}" "$1"
}
hash_of() { rec "$1" "$2" "$3" "${4:-}" '.h'; }             # Abdruck, "null" ohne, leer wenn der Schlüssel fehlt
why_of() { rec "$1" "$2" "$3" "${4:-}" '.why // "-"'; }      # Grund, "-" ohne, leer wenn der Schlüssel fehlt
unfaithful() { [ -n "$(rec "$CONTENT/unfaithful.jsonl" "$1" "$2" "${3:-}" '.t')" ]; } # unfaithful <tabelle> <objekt> [name]
faithful() { # faithful <was> <tabelle> <objekt> [name]: Abdruck im Manifest = Abdruck der Baseline, nicht in unfaithful
  local m b
  m="$(hash_of "$CONTENT/manifest.jsonl" "$2" "$3" "${4:-}")"
  b="$(hash_of "$CONTENT/baseline.jsonl" "$2" "$3" "${4:-}")"
  if [ "${#m}" = 64 ] && [ "$m" = "$b" ] && ! unfaithful "$2" "$3" "${4:-}"; then
    pass
  else
    bad "$1: nicht treu (Manifest ${m:-fehlt}, Baseline ${b:-fehlt}, unfaithful: $(why_of "$CONTENT/unfaithful.jsonl" "$2" "$3" "${4:-}"))"
  fi
}
tkh() { jq -c 'select(.t != null) | [.t, .k, .h]' "$1" | LC_ALL=C sort; } # Schlüssel und Abdrücke einer Datei
same_tkh() { # same_tkh <was> <datei> <datei>
  if [ "$(tkh "$2" | shasum)" = "$(tkh "$3" | shasum)" ] && [ -s "$2" ]; then
    pass
  else
    bad "$1"
    diff <(tkh "$2") <(tkh "$3") | head -20 || true
  fi
}
data_lines() { # data_lines <datei>: jede Zeile ein Datensatz {t, k, h[, row], p[, why]}
  jq -e -s 'length > 0 and all(.[]; type == "object" and (.t | type == "string") and (.k | type == "string")
    and has("h") and (.p | type == "boolean") and (.p or (.why | type == "string"))
    and (if .h == null then (has("row") | not) else (.row | type == "object") end))' "$1" >/dev/null
}
# delta <datei>: was sich gegenüber der Baseline geändert hat, Schlüssel als "<t> <objekt>[/<name>]"
delta() {
  jq -n -c --slurpfile b "$CONTENT/baseline.jsonl" --slurpfile e "$1" '
    def idx: map(select(.t != null) | {key: (.t + " " + (.k | gsub("\u0000"; "/"))), value: .h}) | from_entries;
    ($b | idx) as $B | ($e | idx) as $E
    | {changed: [$E | to_entries[] | . as $x | select($B | has($x.key)) | select($B[$x.key] != $x.value) | $x.key] | sort,
       added: [$E | keys[] | . as $k | select($B | has($k) | not)] | sort,
       removed: [$B | keys[] | . as $k | select($E | has($k) | not)] | sort}'
}
# added_kinds <datei> <delta>: "<tabelle> <why>" der Zeilen, die gegenüber der Baseline neu sind
added_kinds() {
  jq -n -r --slurpfile e "$1" --slurpfile d "$2" '$d[0].added as $A
    | [$e[] | select(.t != null) | select((.t + " " + (.k | gsub("\u0000"; "/"))) as $key | $A | index($key) != null) | "\(.t) \(.why // "-")"]
    | unique | join(";")'
}
probe() { # probe <src|tgt> <beitrag> <term> <eigene url> <fremde url>: Schreibweisen in den Rohwerten
  local where="$1"
  shift
  if [ "$where" = src ]; then
    (cd "$SRC" && ddev wp eval-file - "$@" --skip-plugins --skip-themes <"$PROBE")
  else
    (cd "$SITE" && ddev wp eval-file - "$@" --skip-plugins --skip-themes <"$PROBE")
  fi
}
rename_term() { SQL "UPDATE ${PREFIX}terms SET name = 'E2E Menu $1' WHERE term_id = $TERM_ID"; } # ändert genau eine Inhaltstabelle
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
  unset SECRET
  [ "$YAML_SAVED" = no ] || mv "$SITE_YAML.e2e" "$SITE_YAML"
  if [ "${WPSYNC_E2E_KEEP:-}" = 1 ]; then
    [ "$SRC_DOWN" = no ] || (cd "$SRC" && ddev start -y >/dev/null 2>&1)
  else
    [ ! -f "$SRC/.ddev/config.yaml" ] || (cd "$SRC" && ddev stop >/dev/null 2>&1)
    [ ! -f "$SITE/.ddev/config.yaml" ] || (cd "$SITE" && ddev stop >/dev/null 2>&1)
  fi
  echo
  if [ "$rc" != 0 ]; then
    echo "E2E Content ABGEBROCHEN (Exit $rc) – $CHECKS Prüfungen bis dahin, $FAILED FAIL"
    exit "$rc"
  fi
  if [ "$FAILED" != 0 ]; then
    echo "E2E Content: $CHECKS Prüfungen, $FAILED FAIL"
    exit 1
  fi
  echo "E2E Content OK – $CHECKS Prüfungen grün, 0 FAIL"
}
trap finish EXIT

command -v jq >/dev/null || fail "jq fehlt"
mkdir -p "$E2E/bin" "$SRC/public" "$WPSYNC_SITES_DIR"
rm -rf "$JSON"
mkdir -p "$JSON"
(cd "$ROOT/agent" && ./build.sh)
(cd "$ROOT/cli" && go build -o "$WPSYNC" ./cmd/wpsync)

echo "== Quelle"
cd "$SRC"
if [ ! -f .ddev/config.yaml ]; then
  ddev config --project-name="$SOURCE_NAME" --project-type=wordpress --docroot=public \
    --php-version=8.2 --database=mariadb:10.11 --performance-mode=none
fi
ddev start -y
if ! ddev wp core is-installed >/dev/null 2>&1; then
  ddev wp core download --force
  sed -i '' 's/#ddev-generated//' public/wp-config.php
  ddev wp config set table_prefix "$PREFIX" --type=variable
  ddev wp core install --url="$(http_url "$SRC")" --title="wpsync Content E2E" --admin_user=admin \
    --admin_password=admin --admin_email=e2e@example.invalid --skip-email
fi
ddev wp config set DISABLE_WP_CRON true --raw --type=constant
ddev wp config set WPSYNC_ALLOW_HTTP true --raw --type=constant
cp "$ROOT/agent/dist/wpsync-agent.zip" public/wpsync-agent.zip
ddev wp plugin install /var/www/html/public/wpsync-agent.zip --force --activate
rm public/wpsync-agent.zip
# Reste früherer Läufe: Infosheet, Pairings.
ddev wp eval 'WpSync\Store::setState("infosheet", null); WpSync\Store::setState("infosheet_job", null); global $wpdb; $wpdb->query("DELETE FROM " . WpSync\Store::table("pairings"));'
SOURCE_URL="$(http_url "$SRC")"

echo "== Fixtures: die Live-URL im Klartext, als \\/, als \\\\\\/ und serialisiert"
ddev wp eval-file - <<'PHP'
<?php
global $wpdb;
$home = rtrim(home_url(), '/');
foreach ($wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'e2e-%' AND post_type <> 'revision'") as $old) {
    wp_delete_post((int) $old, true);
}
// Ein Beitrag mit der URL in allen Schreibweisen: Klartext im Inhalt, \/ im JSON-Meta (wie
// Elementor), \\\/ im JSON im JSON – und alle drei noch einmal in einem serialisierten Wert,
// dessen s:-Längen sich beim Ersetzen ändern.
$id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E URLs', 'post_name' => 'e2e-urls',
    'post_content' => '<a href="' . $home . '/kontakt">Kontakt</a> <img src="' . $home . '/wp-content/uploads/a.jpg">']);
$json  = wp_json_encode([['id' => 'a1', 'elType' => 'widget', 'settings' => ['link' => ['url' => $home . '/kontakt']]]]);
$json2 = wp_json_encode(['data' => $json]);
update_post_meta($id, '_elementor_data', wp_slash($json));
update_post_meta($id, '_e2e_json2', wp_slash($json2));
update_post_meta($id, '_e2e_ser', wp_slash(['url' => $home . '/x', 'json' => $json, 'json2' => $json2, 'deep' => [$home, 5, 1.5, null, true]]));
add_post_meta($id, '_e2e_multi', 'b');
add_post_meta($id, '_e2e_multi', 'a');
update_post_meta($id, '_e2e_plain', 'ohne url');
update_post_meta($id, '_edit_lock', time() . ':1');
update_post_meta($id, 'e2e_api_key', 'kein-echter-schluessel');
update_option('e2e_ser_option', ['logo' => $home . '/logo.png', 'json' => $json, 'json2' => $json2]);
// Der Platzhalter der Normalisierung im Rohwert: nicht normalisierbar.
$bad = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E Platzhalter', 'post_name' => 'e2e-platzhalter',
    'post_content' => "Text mit \u{27E6}wpsync:origin\u{27E7} im Rohwert"]);
update_post_meta($bad, '_e2e_ph', "Meta mit \u{27E6}wpsync:origin\u{27E7}/pfad");
update_post_meta($bad, '_e2e_plain', 'ohne platzhalter');
// Ein Beitragstyp, der nicht auf der Liste des Agents steht.
$cpt = wp_insert_post(['post_type' => 'e2e_cpt', 'post_status' => 'publish', 'post_title' => 'E2E CPT', 'post_name' => 'e2e-cpt',
    'post_content' => 'Eigener Beitragstyp, ' . $home . '/cpt']);
update_post_meta($cpt, '_e2e_plain', 'meta am eigenen typ');
$term = term_exists('e2e-menu', 'nav_menu');
if (!$term) {
    $term = wp_insert_term('E2E Menu', 'nav_menu', ['slug' => 'e2e-menu']);
}
$term_id = (int) $term['term_id'];
wp_update_term($term_id, 'nav_menu', ['name' => 'E2E Menu']);
update_term_meta($term_id, 'e2e_link', $home . '/menu');
wp_set_object_terms($id, [$term_id], 'nav_menu');
set_transient('e2e_probe', 'nie im Manifest', DAY_IN_SECONDS);
update_option('wpsync_e2e_probe', 'nie im Manifest', false);
update_option('e2e_content_ids', ['urls' => $id, 'bad' => $bad, 'cpt' => $cpt, 'term' => $term_id]);
PHP
IDS="$(ddev wp eval 'echo json_encode(get_option("e2e_content_ids"));')"
URLS_ID="$(jq -r '.urls' <<<"$IDS")"
BAD_ID="$(jq -r '.bad' <<<"$IDS")"
CPT_ID="$(jq -r '.cpt' <<<"$IDS")"
TERM_ID="$(jq -r '.term' <<<"$IDS")"
for v in "$URLS_ID" "$BAD_ID" "$CPT_ID" "$TERM_ID"; do
  case "$v" in '' | *[!0-9]*) fail "Fixtures nicht angelegt ($IDS)" ;; esac
done
# Zählt in den Rohwerten der Datenbank, wie oft die eigene URL in jeder Schreibweise steht und ob
# eine fremde vorkommt; $args: Beitrag, Term, eigene URL, fremde URL (oder "-").
cat >"$PROBE" <<'PHP'
<?php
global $wpdb;
[$wpsync_post, $wpsync_term, $wpsync_own, $wpsync_other] = [(int) $args[0], (int) $args[1], (string) $args[2], (string) $args[3]];
$wpsync_esc = static function (string $s, int $n): string {
    for ($i = 0; $i < $n; $i++) {
        $s = substr((string) json_encode($s), 1, -1);
    }
    return $s;
};
$wpsync_meta = static function (int $id, string $key) use ($wpdb): string {
    return (string) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, $key));
};
$wpsync_raw = [
    'post_content'    => (string) $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $wpsync_post)),
    '_elementor_data' => $wpsync_meta($wpsync_post, '_elementor_data'),
    '_e2e_json2'      => $wpsync_meta($wpsync_post, '_e2e_json2'),
    '_e2e_ser'        => $wpsync_meta($wpsync_post, '_e2e_ser'),
    'e2e_ser_option'  => (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'e2e_ser_option')),
    'termmeta'        => (string) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s", $wpsync_term, 'e2e_link')),
];
$wpsync_out = [];
foreach ($wpsync_raw as $wpsync_name => $wpsync_value) {
    $wpsync_row = ['plain' => substr_count($wpsync_value, $wpsync_own), 'esc1' => substr_count($wpsync_value, $wpsync_esc($wpsync_own, 1)),
        'esc2' => substr_count($wpsync_value, $wpsync_esc($wpsync_own, 2)), 'other' => 0];
    if ($wpsync_other !== '-') {
        $wpsync_host        = (string) parse_url($wpsync_other, PHP_URL_HOST);
        $wpsync_row['other'] = substr_count($wpsync_value, $wpsync_host . '/') + substr_count($wpsync_value, $wpsync_host . '\\') + substr_count($wpsync_value, $wpsync_host . '"');
    }
    if (is_serialized($wpsync_value)) {
        $wpsync_data       = @unserialize($wpsync_value, ['allowed_classes' => false]);
        $wpsync_row['ser'] = is_array($wpsync_data) ? 'ok' : 'broken';
        if (isset($wpsync_data['deep'])) {
            $wpsync_row['deep'] = [$wpsync_data['deep'][0] === $wpsync_own, $wpsync_data['deep'][1], $wpsync_data['deep'][2], $wpsync_data['deep'][3], $wpsync_data['deep'][4]];
        }
    }
    $wpsync_out[$wpsync_name] = $wpsync_row;
}
echo json_encode($wpsync_out);
PHP
probe src "$URLS_ID" "$TERM_ID" "$SOURCE_URL" - >"$JSON/probe-source.json"
# Die Fixtures tragen wirklich alle drei Schreibweisen – sonst prüfte der Rest nichts.
eq "Fixture: Klartext im Inhalt" "$(jq -c '.post_content | [.plain, .esc1, .esc2]' "$JSON/probe-source.json")" "[2,0,0]"
eq "Fixture: \\/ im JSON-Meta" "$(jq -c '._elementor_data | [.plain, .esc1, .esc2]' "$JSON/probe-source.json")" "[0,1,0]"
eq "Fixture: \\\\\\/ im JSON im JSON" "$(jq -c '._e2e_json2 | [.plain, .esc1, .esc2]' "$JSON/probe-source.json")" "[0,0,1]"
eq "Fixture: serialisiert mit allen dreien" "$(jq -c '._e2e_ser | [.plain, .esc1, .esc2, .ser]' "$JSON/probe-source.json")" '[2,1,1,"ok"]'
eq "Fixture: serialisierte Option" "$(jq -c '.e2e_ser_option | [.plain, .esc1, .esc2, .ser]' "$JSON/probe-source.json")" '[1,1,1,"ok"]'

echo "== pair (Secret über --secret-out, nie in der Keychain), scan"
CODE="$(ddev wp wpsync pair-code | tail -1)"
rm -f "$SITE_YAML"
"$WPSYNC" pair "$SOURCE_URL" "$CODE" --name "$TARGET" --insecure --json --secret-out >"$E2E/pair.tmp" 2>"$JSON/pair.err" \
  || { cat "$JSON/pair.err"; fail "pair"; }
SECRET="$(tail -n 1 "$E2E/pair.tmp" | jq -r '.data.secret')"
tail -n 1 "$E2E/pair.tmp" | jq -c 'del(.data.secret)' >"$JSON/pair.jsonl"
rm -f "$E2E/pair.tmp"
[ -n "$SECRET" ] && [ "$SECRET" != null ] || fail "kein Secret aus pair --secret-out"
jrun scan s1 scan "$TARGET" --refresh --preset vollstaendig --uploads-since alle --json --secret-stdin
[ "$RC" = 0 ] || fail "scan (Exit $RC, siehe $JSON/scan.err)"

echo "== Pull ohne --content legt keinen Inhaltsstand an"
rm -rf "$CONTENT" # Stand früherer Läufe
if [ -f "$SITE/.ddev/config.yaml" ] && [ ! -f "$WPSYNC_CONFIG_DIR/ddev-state/$TARGET.json" ]; then trust_ddev; fi
jrun pull-plain s1 pull "$TARGET" --yes --json --secret-stdin
[ "$RC" = 0 ] || fail "Pull ohne --content (Exit $RC, siehe $JSON/pull-plain.err)"
no "kein .wpsync/content nach einem Pull ohne --content" test -e "$CONTENT"
eq "ohne --content: kein content im Ergebnis" "$(last pull-plain '.data | has("content")')" false
LOCAL_URL="$(last pull-plain '.data.local_url')"
eq "lokale URL = home der lokalen Site" "$(tgt wp option get home --skip-plugins --skip-themes)" "$LOCAL_URL"
xrun export-none "$WPSYNC" content export "$TARGET"
eq "content export ohne Inhaltsstand: Exit 2" "$RC" 2
eq "content export ohne Inhaltsstand: stdout leer" "$(wc -c <"$JSON/export-none.jsonl" | tr -d ' ')" 0
ok "content export ohne Inhaltsstand: Hinweis auf pull --content" hasF "$JSON/export-none.err" "wpsync pull $TARGET --content"
jrun export-none-json "$WPSYNC" content export "$TARGET" --json
eq "content export --json ohne Inhaltsstand: Exit 2" "$RC" 2
eq "content export --json ohne Inhaltsstand: nur die Ergebniszeile, usage" \
  "$(wc -l <"$JSON/export-none-json.jsonl" | tr -d ' ') $(last export-none-json '.error.code')" "1 usage"

echo "== AC-146: pull --content (keine Tabelle geändert, der Stand fehlt: alle sieben neu)"
jrun pull-content s1 pull "$TARGET" --content --yes --json --secret-stdin
[ "$RC" = 0 ] || fail "pull --content (Exit $RC, siehe $JSON/pull-content.err)"
HEAD="$(head -n 1 "$CONTENT/manifest.jsonl")"
eq "B10: der erste Pull mit --content meldet reloaded" "$(last pull-content '.data.content.reloaded')" true
eq "B1: alle sieben Inhaltstabellen neu geladen" "$(last pull-content '.data.tables_loaded')" 7
eq "content: Felder" "$(last pull-content '.data.content | keys_unsorted | sort | join(",")')" "canon_version,id_max,reloaded,rows,unfaithful"
eq "content.canon_version" "$(last pull-content '.data.content.canon_version')" 1
ok "content.rows > 0" test "$(last pull-content '.data.content.rows')" -gt 0
eq "content.rows = Zeilen im Manifest" "$(last pull-content '.data.content.rows')" "$(($(wc -l <"$CONTENT/manifest.jsonl") - 1))"
eq "content.unfaithful = Zeilen in unfaithful.jsonl" "$(last pull-content '.data.content.unfaithful')" "$(wc -l <"$CONTENT/unfaithful.jsonl" | tr -d ' ')"
eq "content.id_max = id_max im Kopf" "$(tail -n 1 "$JSON/pull-content.jsonl" | jq -cS '.data.content.id_max')" "$(jq -cS '.head.id_max' <<<"$HEAD")"
for f in manifest.jsonl map.json baseline.jsonl unfaithful.jsonl env.json summary.json; do ok "$f liegt da" test -s "$CONTENT/$f"; done
eq "kein Rest einer halben Datei" "$(find "$CONTENT" -type f | wc -l | tr -d ' ')" 6
eq "Kopf: canon_version, list_version" "$(jq -c '.head | [.canon_version, .list_version]' <<<"$HEAD")" "[1,2]"
eq "Kopf: origins" "$(jq -c '.head.origins' <<<"$HEAD")" "{\"home\":\"$SOURCE_URL\",\"siteurl\":\"$SOURCE_URL\"}"
eq "Kopf: pushable" "$(jq -c '.head | [.pushable, has("why")]' <<<"$HEAD")" "[true,false]"
eq "Kopf: variants" "$(jq -c '.head.variants' <<<"$HEAD")" '["plain","esc1","esc2"]'
eq "Kopf: prefix" "$(jq -r '.head.prefix' <<<"$HEAD")" "$PREFIX"
eq "Kopf: Engines aller sieben Tabellen" "$(jq -c '.head.engines | [(keys | length), ([.[] | select(. == "InnoDB")] | length)]' <<<"$HEAD")" "[7,7]"
for t in posts terms term_taxonomy; do ok "Kopf: id_max.$t > 0" test "$(jq -r ".head.id_max.$t" <<<"$HEAD")" -gt 0; done
ok "Kopf: id_max.posts deckt die Fixtures" test "$(jq -r '.head.id_max.posts' <<<"$HEAD")" -ge "$CPT_ID"
ok "Kopf: Listen" jq -e '.head.lists | (.post_types | index("page")) != null and (.blocked_meta | index("_edit_lock")) != null and (.blocked_options | index("siteurl")) != null' <<<"$HEAD"
ok "Kopf: Muster der Pseudonymisierung" jq -e '.head.pseudonym | length > 0' <<<"$HEAD"
eq "map.json" "$(jq -c '[.canon_version, .variants, .live.home, .live.siteurl, .local, (.pulled_at | test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T"))]' "$CONTENT/map.json")" \
  "[1,[\"plain\",\"esc1\",\"esc2\"],\"$SOURCE_URL\",\"$SOURCE_URL\",\"$LOCAL_URL\",true]"
eq "env.json: nur PHP-Version und Präfix der Quelle" "$(jq -c '[(keys | join(",")), (.php_version | test("^8\\.2")), .table_prefix]' "$CONTENT/env.json")" \
  "[\"php_version,table_prefix\",true,\"$PREFIX\"]"
eq "summary.json = content des Ergebnisses, reloaded immer false" "$(jq -c '.' "$CONTENT/summary.json")" "$(last pull-content '.data.content | .reloaded = false | tojson')"
no "Manifest ohne Werte" grep -q '"row"' "$CONTENT/manifest.jsonl"
ok "die Quelle hat Transients und wpsync-Optionen" test "$(SQL "SELECT SUM(option_name LIKE '\\_transient\\_%') > 0 AND SUM(option_name LIKE 'wpsync\\_%') > 0 FROM ${PREFIX}options")" = 1
eq "B2: Manifest ohne Transients und wpsync-Optionen" "$(grep '^{"t":"options"' "$CONTENT/manifest.jsonl" | grep -c -e '"k":"_transient_' -e '"k":"_site_transient_' -e '"k":"wpsync_')" 0
# shellcheck disable=SC2086 # die Liste ist absichtlich in Wörter zerlegt
eq "Manifest: alle sieben Tabellen" "$(jq -r 'select(.t != null) | .t' "$CONTENT/manifest.jsonl" | sort -u | tr '\n' ' ')" "$(printf '%s\n' $TABLES | sort | tr '\n' ' ')"
ok "Baseline: Datensätze wie content export" data_lines "$CONTENT/baseline.jsonl"

echo "== AC-146/AC-147: keine Schreibweise zeigt lokal noch auf die Quelle, dieselben Abdrücke"
probe tgt "$URLS_ID" "$TERM_ID" "$LOCAL_URL" "$SOURCE_URL" >"$JSON/probe-local.json"
for v in post_content _elementor_data _e2e_json2 _e2e_ser e2e_ser_option termmeta; do
  eq "lokal $v: jede Schreibweise ersetzt, Zahl wie auf der Quelle, serialisiert lesbar" \
    "$(jq -c --arg v "$v" '.[$v]' "$JSON/probe-local.json")" "$(jq -c --arg v "$v" '.[$v]' "$JSON/probe-source.json")"
  eq "lokal $v: die Quelle kommt nicht mehr vor" "$(jq -r --arg v "$v" '.[$v].other' "$JSON/probe-local.json")" 0
done
eq "lokal: serialisierter Wert über WordPress lesbar" \
  "$(tgt wp eval 'echo get_post_meta('"$URLS_ID"', "_e2e_ser", true)["deep"][0];' --skip-plugins --skip-themes)" "$LOCAL_URL"
eq "lokal: JSON im JSON lesbar" \
  "$(tgt wp eval 'echo get_post_meta('"$URLS_ID"', "_e2e_json2", true);' --skip-plugins --skip-themes | jq -r '.data | fromjson | .[0].settings.link.url')" "$LOCAL_URL/kontakt"
faithful "posts e2e-urls (Klartext)" posts "$URLS_ID"
for key in _elementor_data _e2e_json2 _e2e_ser _e2e_multi _e2e_plain _edit_lock e2e_api_key; do
  faithful "postmeta $key" postmeta "$URLS_ID" "$key"
done
faithful "options e2e_ser_option (serialisiert)" options e2e_ser_option
faithful "options home (normalisiert)" options home
faithful "options siteurl (normalisiert)" options siteurl
faithful "options blogname" options blogname
faithful "terms" terms "$TERM_ID"
faithful "termmeta mit URL" termmeta "$TERM_ID" e2e_link
faithful "term_relationships" term_relationships "$URLS_ID" nav_menu
faithful "posts eigener Beitragstyp" posts "$CPT_ID"
faithful "postmeta am Platzhalter-Beitrag ohne Platzhalter" postmeta "$BAD_ID" _e2e_plain

echo "== Platzhalter im Rohwert: nicht normalisierbar"
eq "Manifest: Beitrag ohne Abdruck, why" "$(rec "$CONTENT/manifest.jsonl" posts "$BAD_ID" "" '[.h, .why] | tojson')" '[null,"unnormalizable"]'
eq "Manifest: Meta-Paar ohne Abdruck, why" "$(rec "$CONTENT/manifest.jsonl" postmeta "$BAD_ID" _e2e_ph '[.h, .why] | tojson')" '[null,"unnormalizable"]'
eq "unfaithful: Beitrag" "$(why_of "$CONTENT/unfaithful.jsonl" posts "$BAD_ID")" unnormalizable
eq "unfaithful: Meta-Paar" "$(why_of "$CONTENT/unfaithful.jsonl" postmeta "$BAD_ID" _e2e_ph)" unnormalizable
eq "Baseline: Beitrag ohne Abdruck und ohne Werte" "$(rec "$CONTENT/baseline.jsonl" posts "$BAD_ID" "" '[.h, has("row"), .p, .why] | tojson')" '[null,false,false,"unnormalizable"]'

echo "== unfaithful: was der Pull nicht treu überträgt"
echo "nach Grund:"
jq -r '.why' "$CONTENT/unfaithful.jsonl" | sort | uniq -c
echo "Schlüssel:"
jq -r '[.why, .t, (.k | gsub("\u0000"; " / "))] | @tsv' "$CONTENT/unfaithful.jsonl" | sort | head -n 100
# Genau diese drei: die Admin-Adresse, die der Pull pseudonymisiert (gesperrte Option, AC-148) –
# für sie nennt das Manifest keinen Abdruck des echten Werts –, und die beiden Fixtures mit dem
# Platzhalter im Rohwert. Alles andere kommt treu an.
eq "Manifest: pseudonymisierte Option ohne Abdruck, why" "$(rec "$CONTENT/manifest.jsonl" options admin_email "" '[.h, .why] | tojson')" '[null,"pseudonymized"]'
eq "unfaithful: nur das Erwartete" "$(jq -r '[.why, .t, (.k | gsub("\u0000"; "/"))] | join(" ")' "$CONTENT/unfaithful.jsonl" | LC_ALL=C sort | tr '\n' ';')" \
  "pseudonymized options admin_email;unnormalizable postmeta $BAD_ID/_e2e_ph;unnormalizable posts $BAD_ID;"
eq "AC-148: die pseudonymisierte Option ist nicht pushbar" "$(rec "$CONTENT/baseline.jsonl" options admin_email "" '[.p, .why] | tojson')" '[false,"option"]'

echo "== content export: stdout gehört den Daten, direkt nach dem Pull = Baseline"
xrun export1 "$WPSYNC" content export "$TARGET"
eq "content export: Exit 0" "$RC" 0
ok "Export: jede Zeile ein Datensatz mit t, k, h, p – keine Schlusszeile" data_lines "$JSON/export1.jsonl"
same_tkh "Export = Baseline (Schlüssel und Abdrücke)" "$JSON/export1.jsonl" "$CONTENT/baseline.jsonl"
ok "Export: Zeilenzahl auf stderr" grep -Eq '^[0-9]+ Zeilen exportiert$' "$JSON/export1.err"
eq "Export: Werte normalisiert (Klartext)" "$(rec "$JSON/export1.jsonl" posts "$URLS_ID" "" '.row.post_content | @base64d' | grep -o '⟦wpsync:origin⟧/' | wc -l | tr -d ' ')" 2
eq "Export: Werte normalisiert (esc1)" "$(rec "$JSON/export1.jsonl" postmeta "$URLS_ID" _elementor_data '.row.values[0] | @base64d' | grep -c '⟦wpsync:origin:esc1⟧')" 1
eq "Export: Werte normalisiert (esc2)" "$(rec "$JSON/export1.jsonl" postmeta "$URLS_ID" _e2e_json2 '.row.values[0] | @base64d' | grep -c '⟦wpsync:origin:esc2⟧')" 1
no "Export: keine lokale URL in den Werten des Beitrags" grep -qF "${LOCAL_URL#http://}" <(rec "$JSON/export1.jsonl" posts "$URLS_ID" "" '.row[] | select(. != null) | @base64d')
eq "Export: Multimenge sortiert" "$(rec "$JSON/export1.jsonl" postmeta "$URLS_ID" _e2e_multi '.row.values | tojson')" '["YQ==","Yg=="]'
eq "p: Seite pushbar" "$(rec "$JSON/export1.jsonl" posts "$URLS_ID" "" '[.p, .why] | tojson')" '[true,null]'
eq "p: Meta der Seite pushbar" "$(rec "$JSON/export1.jsonl" postmeta "$URLS_ID" _elementor_data '[.p, .why] | tojson')" '[true,null]'
eq "p: _edit_lock → meta_key" "$(rec "$JSON/export1.jsonl" postmeta "$URLS_ID" _edit_lock '[.p, .why] | tojson')" '[false,"meta_key"]'
eq "p: e2e_api_key → meta_word" "$(rec "$JSON/export1.jsonl" postmeta "$URLS_ID" e2e_api_key '[.p, .why] | tojson')" '[false,"meta_word"]'
eq "p: siteurl → option" "$(rec "$JSON/export1.jsonl" options siteurl "" '[.p, .why] | tojson')" '[false,"option"]'
eq "p: Option ausserhalb der Liste → option" "$(rec "$JSON/export1.jsonl" options e2e_ser_option "" '[.p, .why] | tojson')" '[false,"option"]'
eq "p: blogname pushbar" "$(rec "$JSON/export1.jsonl" options blogname "" '[.p, .why] | tojson')" '[true,null]'
eq "p: eigener Beitragstyp → post_type" "$(rec "$JSON/export1.jsonl" posts "$CPT_ID" "" '[.p, .why] | tojson')" '[false,"post_type"]'
eq "p: Meta am eigenen Beitragstyp → post_type" "$(rec "$JSON/export1.jsonl" postmeta "$CPT_ID" _e2e_plain '[.p, .why] | tojson')" '[false,"post_type"]'
eq "p: Term und Zuordnung im Menü pushbar" "$(rec "$JSON/export1.jsonl" terms "$TERM_ID" "" '.p') $(rec "$JSON/export1.jsonl" term_relationships "$URLS_ID" nav_menu '.p')" "true true"
eq "p: nicht normalisierbar → unnormalizable, ohne Abdruck" "$(rec "$JSON/export1.jsonl" posts "$BAD_ID" "" '[.h, .p, .why] | tojson')" '[null,false,"unnormalizable"]'
eq "B2: Export ohne Transients und wpsync-Optionen" "$(grep '^{"t":"options"' "$JSON/export1.jsonl" | grep -c -e '"k":"_transient_' -e '"k":"_site_transient_' -e '"k":"wpsync_')" 0
ok "lokal gibt es Transients" test "$(tgt mysql -N -e "SELECT COUNT(*) FROM ${PREFIX}options WHERE option_name LIKE '\\_transient\\_%'")" -gt 0
jrun export-json "$WPSYNC" content export "$TARGET" --json
eq "content export --json: Exit 0" "$RC" 0
sed '$d' "$JSON/export-json.jsonl" >"$JSON/export-json.data"
ok "Export --json: davor nur Datensätze" data_lines "$JSON/export-json.data"
same_tkh "Export --json: dieselben Datensätze" "$JSON/export-json.data" "$JSON/export1.jsonl"
eq "Export --json: Ergebniszeile" "$(last export-json '[.command, .data.rows, .data.canon_version] | tojson')" "[\"content export\",$(wc -l <"$JSON/export-json.data" | tr -d ' '),1]"
ok "Export ändert .wpsync/content nicht" test -z "$(find "$CONTENT" -newer "$CONTENT/summary.json" -type f)"

echo "== B12: content export läuft ohne die Quelle"
(cd "$SRC" && ddev stop >/dev/null 2>&1)
SRC_DOWN=yes
no "die Quelle ist nicht erreichbar" test "$(code "$SOURCE_URL/?rest_route=/wpsync/v1/")" = 200
xrun export-offline "$WPSYNC" content export "$TARGET"
eq "B12: Export bei gestoppter Quelle: Exit 0" "$RC" 0
same_tkh "B12: Export bei gestoppter Quelle = Export davor" "$JSON/export-offline.jsonl" "$JSON/export1.jsonl"
(cd "$SRC" && ddev start -y >/dev/null 2>&1) || fail "Quelle startet nicht wieder"
SRC_DOWN=no

echo "== Lokale Änderung: genau diese Abdrücke weichen ab"
tgt wp post update "$URLS_ID" --post_title="E2E URLs geändert" --skip-plugins --skip-themes >/dev/null
tgt wp post meta update "$URLS_ID" _e2e_plain "lokal geändert" --skip-plugins --skip-themes >/dev/null
xrun export-changed "$WPSYNC" content export "$TARGET"
eq "content export nach lokaler Änderung: Exit 0" "$RC" 0
delta "$JSON/export-changed.jsonl" >"$JSON/delta-changed.json"
eq "geändert: genau der Beitrag und das Meta-Paar" "$(jq -c '.changed' "$JSON/delta-changed.json")" "[\"postmeta $URLS_ID/_e2e_plain\",\"posts $URLS_ID\"]"
eq "nichts verschwunden" "$(jq -c '.removed' "$JSON/delta-changed.json")" "[]"
# wp post update legt Revisionen an – neue Zeilen, nicht pushbar; sonst kommt nichts dazu.
echo "neu gegenüber der Baseline: $(jq -c '.added' "$JSON/delta-changed.json")"
eq "neu: nur Revisionen (post_type)" "$(added_kinds "$JSON/export-changed.jsonl" "$JSON/delta-changed.json")" "posts post_type"

echo "== B1/B10: Folge-Pull ohne Änderung auf der Quelle lädt nichts neu"
BASE_SHA="$(sha "$CONTENT/baseline.jsonl")"
MANI_SHA="$(sha "$CONTENT/manifest.jsonl")"
jrun pull-same s1 pull "$TARGET" --content --yes --json --secret-stdin
eq "Folge-Pull: Exit 0" "$RC" 0
eq "B10: reloaded false" "$(last pull-same '.data.content.reloaded')" false
eq "keine Tabelle neu geladen" "$(last pull-same '.data.tables_loaded')" 0
eq "content sonst wie zuvor" "$(last pull-same '.data.content | del(.reloaded) | tojson')" "$(last pull-content '.data.content | del(.reloaded) | tojson')"
eq "Manifest und Baseline unverändert" "$(sha "$CONTENT/manifest.jsonl") $(sha "$CONTENT/baseline.jsonl")" "$MANI_SHA $BASE_SHA"
eq "die lokale Änderung bleibt" "$(tgt wp post get "$URLS_ID" --field=post_title --skip-plugins --skip-themes)" "E2E URLs geändert"

echo "== B1/B10: eine Inhaltstabelle auf der Quelle geändert – alle sieben neu"
rename_term 2
OLD_TERM="$(hash_of "$CONTENT/manifest.jsonl" terms "$TERM_ID")"
jrun pull-changed s1 pull "$TARGET" --content --yes --json --secret-stdin
eq "Pull nach Änderung: Exit 0" "$RC" 0
eq "B10: reloaded true" "$(last pull-changed '.data.content.reloaded')" true
eq "B1: alle sieben neu geladen, obwohl sich nur terms geändert hat" "$(last pull-changed '.data.tables_loaded')" 7
ok "Manifest neu: Abdruck des Terms geändert" test "$(hash_of "$CONTENT/manifest.jsonl" terms "$TERM_ID")" != "$OLD_TERM"
faithful "Term nach dem Pull" terms "$TERM_ID"
eq "lokal: der neue Name" "$(tgt wp term get nav_menu "$TERM_ID" --field=name --skip-plugins --skip-themes)" "E2E Menu 2"
eq "die lokale Änderung ist weg (posts neu geladen, Baseline = Stand nach dem Pull)" \
  "$(tgt wp post get "$URLS_ID" --field=post_title --skip-plugins --skip-themes)" "E2E URLs"
faithful "Beitrag wieder treu" posts "$URLS_ID"
faithful "Meta-Paar wieder treu" postmeta "$URLS_ID" _e2e_plain
xrun export-reloaded "$WPSYNC" content export "$TARGET"
same_tkh "Export = neue Baseline" "$JSON/export-reloaded.jsonl" "$CONTENT/baseline.jsonl"

echo "== Pull ohne --content, nichts geändert: der Inhaltsstand bleibt"
MANI_SHA="$(sha "$CONTENT/manifest.jsonl")"
jrun pull-plain-same s1 pull "$TARGET" --yes --json --secret-stdin
eq "Pull ohne --content: Exit 0" "$RC" 0
eq "kein content im Ergebnis" "$(last pull-plain-same '.data | has("content")')" false
eq "Manifest unverändert, summary.json liegt da" "$(sha "$CONTENT/manifest.jsonl") $(test -f "$CONTENT/summary.json" && echo ja)" "$MANI_SHA ja"
no "keine Meldung über einen verworfenen Stand" hasF "$JSON/pull-plain-same.err" "Inhaltsstand verworfen"

echo "== B11: Pull ohne --content lädt eine Inhaltstabelle neu – der Inhaltsstand ist verworfen"
rename_term 3
jrun pull-plain-changed s1 pull "$TARGET" --yes --json --secret-stdin
eq "Pull ohne --content nach Änderung: Exit 0" "$RC" 0
ok "B11: Meldung" hasF "$JSON/pull-plain-changed.err" "Inhaltsstand verworfen"
ok "B11: Meldung nennt den Weg" hasF "$JSON/pull-plain-changed.err" "wpsync pull $TARGET --content"
no "B11: summary.json ist weg" test -e "$CONTENT/summary.json"
xrun export-stale "$WPSYNC" content export "$TARGET"
eq "B11: content export: Exit 2" "$RC" 2
eq "B11: content export: stdout leer" "$(wc -c <"$JSON/export-stale.jsonl" | tr -d ' ')" 0
ok "B11: content export: Hinweis auf pull --content" hasF "$JSON/export-stale.err" "wpsync pull $TARGET --content"
jrun pull-rebuild s1 pull "$TARGET" --content --yes --json --secret-stdin
eq "B11: der nächste pull --content: Exit 0" "$RC" 0
eq "B11: der nächste pull --content meldet reloaded" "$(last pull-rebuild '.data.content.reloaded')" true
eq "B11: und lädt alle sieben neu" "$(last pull-rebuild '.data.tables_loaded')" 7
faithful "Term nach dem Neubau" terms "$TERM_ID"
xrun export-rebuilt "$WPSYNC" content export "$TARGET"
eq "content export nach dem Neubau: Exit 0" "$RC" 0
same_tkh "Export = Baseline nach dem Neubau" "$JSON/export-rebuilt.jsonl" "$CONTENT/baseline.jsonl"

echo "== Profil ohne eine Inhaltstabelle mit Daten: Exit 2, bevor etwas geladen wird"
# scan hat ohne Terminal keinen Schalter für einzelne Tabellen – die Überschreibung steht deshalb
# direkt im Profil der Site (dieselbe Stelle, an die die Auswahl von scan sie schreibt).
rename_term 4 # sonst gäbe es nichts zu laden und die Prüfung „nichts geladen“ wäre leer
MANI_SHA="$(sha "$CONTENT/manifest.jsonl")"
cp -p "$SITE_YAML" "$SITE_YAML.e2e"
YAML_SAVED=yes
for mode in structure skip; do
  awk -v mode="$mode" -v table="${PREFIX}termmeta" '{ print } /^    preset: / { print "    tables:"; print "        overrides:"; print "            " table ": " mode }' \
    "$SITE_YAML.e2e" >"$SITE_YAML"
  jrun "pull-scope-$mode" s1 pull "$TARGET" --content --yes --json --secret-stdin
  eq "termmeta $mode: Exit 2" "$RC" 2
  eq "termmeta $mode: usage" "$(last "pull-scope-$mode" '.error.code')" usage
  ok "termmeta $mode: die Meldung nennt die Tabelle" grep -qF "${PREFIX}termmeta" <<<"$(last "pull-scope-$mode" '.error.message')"
  eq "termmeta $mode: nichts geladen, der Inhaltsstand bleibt" \
    "$(sha "$CONTENT/manifest.jsonl") $(test -f "$CONTENT/summary.json" && echo ja) $(tgt wp term get nav_menu "$TERM_ID" --field=name --skip-plugins --skip-themes)" "$MANI_SHA ja E2E Menu 3"
done
mv "$SITE_YAML.e2e" "$SITE_YAML"
YAML_SAVED=no
