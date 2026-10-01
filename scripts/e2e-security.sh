#!/usr/bin/env bash
# Sicherheits-Regressionen aus dem Audit des Agents 0.2.0, mit echten Requests gegen die
# lokale DDEV-Quelle. Voraussetzung: scripts/e2e-local.sh lief mindestens einmal.
# Bricht nicht beim ersten Fehler ab, sondern zählt – so sieht man alle offenen Punkte.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E2E="${WPSYNC_E2E_DIR:-$HOME/wpsync-e2e}"
KEY=00000000000000e2
SECRET="$(printf 'ab%.0s' $(seq 32))"
FAKE_SIG="$(printf '0%.0s' $(seq 64))"
WPC=public/wp-content
FAILED=0

check() { # check <id> <beschreibung> <ist> <soll>
  if [ "$3" = "$4" ]; then
    echo "ok   $1 $2"
  else
    echo "FAIL $1 $2 (ist: $3, soll: $4)"
    FAILED=$((FAILED + 1))
  fi
}
SQL() { ddev mysql -N -e "$1"; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
pair() { # pair <code> <device> → HTTP-Status
  code -X POST "$URL/?rest_route=/wpsync/v1/pair" -H 'Content-Type: application/json' \
    --data-binary "{\"code\":\"$1\",\"device\":\"$2\"}"
}
pairings() { SQL "SELECT COUNT(*) FROM e2e_wpsync_pairings WHERE device LIKE '$1'"; }
signed() { # signed <route> <json-body> [query-suffix] [curl-args…]
  local route="$1" body="$2" query="${3:-}" ts nonce hash sig
  shift 2
  [ $# -gt 0 ] && shift
  ts="$(date +%s)"
  nonce="$(openssl rand -hex 16)"
  hash="$(printf %s "$body" | openssl dgst -sha256 -r | cut -d' ' -f1)"
  sig="$(printf 'POST\n%s\n%s\n%s\n%s' "$route" "$ts" "$nonce" "$hash" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)"
  curl -s -X POST "$URL/?rest_route=$route$query" -H 'Content-Type: application/json' \
    -H "X-Wpsync-Key: $KEY" -H "X-Wpsync-Timestamp: $ts" -H "X-Wpsync-Nonce: $nonce" \
    -H "X-Wpsync-Signature: $sig" --data-binary "$body" "$@"
}
cleanup() {
  rm -rf "$WPC/updraft" "$WPC/.git" "$WPC/plugins/e2e-objects/.git"
  rm -f "$WPC/cache/sec-page.html" "$WPC/sec-debug.LOG" "$WPC/.htpasswd" \
    "$WPC/uploads/sec-dump.sql" "$WPC/sec-backup.zip" "$WPC/themes/sec-ok.txt"
  SQL "DROP VIEW IF EXISTS e2e_v;
       DROP TABLE IF EXISTS e2e_zweit_options, e2e_zweit_posts, e2e_zweit_postmeta, e2e_zweit_wpsync_pairings, e2e_x_wpsync_state;
       DELETE FROM e2e_wpsync_pairings WHERE device LIKE 'sec-%' OR device LIKE 'aaaa%';
       DELETE FROM e2e_wpsync_state WHERE name IN ('pairing_code', 'pair_last');" >/dev/null 2>&1
}

[ -f "$E2E/source/.ddev/config.yaml" ] || { echo "Quelle fehlt – zuerst scripts/e2e-local.sh ausführen"; exit 1; }
(cd "$ROOT/agent" && ./build.sh) || exit 1
cd "$E2E/source" || exit 1
ddev start -y >/dev/null || exit 1
cp "$ROOT/agent/dist/wpsync-agent.zip" public/wpsync-agent.zip
ddev wp plugin install /var/www/html/public/wpsync-agent.zip --force --activate >/dev/null || exit 1
rm public/wpsync-agent.zip
URL="$(ddev describe -j | python3 -c 'import json,sys; print(json.load(sys.stdin)["raw"]["httpurl"])')"
trap cleanup EXIT
cleanup

echo "== SEC-03: HTTPS-Zwang"
ddev wp config delete WPSYNC_ALLOW_HTTP --type=constant >/dev/null 2>&1
body="$(curl -s -X POST "$URL/?rest_route=/wpsync/v1/pair" -H 'Content-Type: application/json' --data-binary '{"code":"XXXXXXXX","device":"sec-http"}')"
check SEC-03 "/pair über http ohne Freigabe abgelehnt" "$(printf %s "$body" | grep -c wpsync_https)" 1
ddev wp config set WPSYNC_ALLOW_HTTP true --raw --type=constant >/dev/null || exit 1

echo "== SEC-01: Password Protected"
check SEC-01 "Query-String schaltet den Schutz nicht ab (Startseite)" "$(code "$URL/?x=/wp-json/wpsync/v1")" 302
check SEC-01 "REST-Nutzerliste bleibt geschützt" "$(code "$URL/wp-json/wp/v2/users?x=/wp-json/wpsync/v1")" 401
body="$(curl -s -X POST "$URL/wp-json/wpsync/v1/ping" --data 'rest_route=/wp/v2/users&_method=GET')"
check SEC-01 "rest_route im POST-Body überstimmt den Pfad nicht" "$(printf %s "$body" | grep -c '"slug"')" 0
check SEC-01 "Namespace-Index bleibt erreichbar (Discover)" "$(code "$URL/?rest_route=/wpsync/v1")" 200

echo "== SEC-07: /pair liest nur den Body"
CODE="$(ddev wp wpsync pair-code | tail -1)"
check SEC-07 "/pair ignoriert code im Query-String" "$(code -X POST "$URL/?rest_route=/wpsync/v1/pair&code=$CODE&device=sec-query")" 403
sleep 2

echo "== CR-01: Gerätename mit Mehrbyte-Zeichen"
CODE="$(ddev wp wpsync pair-code | tail -1)"
check CR-01 "Pairing mit ä an Position 100 gelingt" "$(pair "$CODE" "$(printf 'a%.0s' $(seq 99))ä")" 200
check CR-01 "Pairing ist gespeichert" "$(pairings 'aaaa%')" 1
sleep 2

echo "== SEC-04: Code nur einmal einlösbar"
for round in 1 2 3; do
  CODE="$(ddev wp wpsync pair-code | tail -1)"
  for _ in $(seq 20); do pair "$CODE" "sec-race-$round" >/dev/null & done
  wait
  check SEC-04 "Runde $round: 20 parallele Requests, ein Pairing" "$(pairings "sec-race-$round")" 1
  sleep 2
done

echo "== Signierte Requests"
ddev wp eval "WpSync\\Store::addPairing('$KEY', '$SECRET', 'sec-curl');" || exit 1
mkdir -p "$WPC/updraft" "$WPC/cache" "$WPC/.git" "$WPC/plugins/e2e-objects/.git" "$WPC/uploads"
for f in updraft/backup_db.gz cache/sec-page.html .git/config plugins/e2e-objects/.git/config \
  sec-debug.LOG .htpasswd uploads/sec-dump.sql sec-backup.zip themes/sec-ok.txt; do
  printf 'sec' > "$WPC/$f"
done

EXCLUDED='"wp-content/updraft/backup_db.gz","wp-content/cache/sec-page.html","wp-content/.git/config","wp-content/plugins/e2e-objects/.git/config","wp-content/sec-debug.LOG","wp-content/.htpasswd","wp-content/uploads/sec-dump.sql","wp-content/sec-backup.zip"'
out="$(signed /wpsync/v1/files "{\"paths\":[$EXCLUDED,\"wp-content/themes/sec-ok.txt\"]}")"
check SEC-02 "8 ausgeschlossene Pfade kommen als M" "$(printf '%s\n' "$out" | grep -c '^M ')" 8
check SEC-02 "erlaubte Datei kommt weiterhin" "$(printf '%s\n' "$out" | grep -c '^F wp-content/themes/sec-ok.txt')" 1

out="$(signed /wpsync/v1/files '{"paths":["wp-content/themes/sec-ok.txt","wp-content/a\u0000b","wp-content/themes/sec-ok.txt"]}')"
check CR-07 "Nullbyte: beide Dateien drumherum kommen an" "$(printf '%s\n' "$out" | grep -c '^F ')" 2
check CR-07 "Nullbyte: Stream endet mit E" "$(printf '%s\n' "$out" | tail -1)" E

rows="$(signed /wpsync/v1/db '{"table":"e2e_posts"}' '&limit=1' -o /dev/null -D - | tr -d '\r' | awk -F': ' 'tolower($1) == "x-wpsync-rows" { print $2 }')"
check SEC-07 "limit im Query-String wird ignoriert" "$([ "${rows:-1}" -gt 1 ] && echo ja || echo nein)" ja

check CR-06 "/db lehnt Tabellen im Modus skip ab" \
  "$(signed /wpsync/v1/db '{"table":"e2e_users","scope":{"tables":{"e2e_users":"skip"}}}' '' -o /dev/null -w '%{http_code}')" 400

SQL "CREATE OR REPLACE VIEW e2e_v AS SELECT ID FROM e2e_posts;
     CREATE TABLE IF NOT EXISTS e2e_zweit_options (id INT);
     CREATE TABLE IF NOT EXISTS e2e_zweit_posts (id INT);
     CREATE TABLE IF NOT EXISTS e2e_zweit_postmeta (id INT);
     CREATE TABLE IF NOT EXISTS e2e_zweit_wpsync_pairings (secret VARCHAR(64));
     CREATE TABLE IF NOT EXISTS e2e_x_wpsync_state (v INT);" || exit 1
delta="$(signed /wpsync/v1/delta '{"cursor":"","scope":null}')"
check CR-02 "View steht nicht in /delta" "$(printf %s "$delta" | grep -c '"name":"e2e_v"')" 0
check SEC-05 "fremde Installation steht nicht in /delta" "$(printf %s "$delta" | grep -c 'e2e_zweit_')" 0
check SEC-05 "fremde wpsync-Tabelle steht nicht in /delta" "$(printf %s "$delta" | grep -c 'e2e_x_wpsync_state')" 0
check SEC-05 "eigene Tabellen stehen weiter in /delta" "$(printf %s "$delta" | grep -c '"name":"e2e_posts"')" 1

echo "== SEC-09 / CR-10: kein DDL durch anonyme Requests"
signed /wpsync/v1/ping '{}' >/dev/null # Aufwärmen: Schema ist danach angelegt
ddl() { SQL "SHOW GLOBAL STATUS LIKE 'Com_create_table'" | awk '{ print $2 }'; }
before="$(ddl)"
for _ in 1 2 3 4 5; do
  code -X POST "$URL/?rest_route=/wpsync/v1/ping" -H 'X-Wpsync-Key: 0000000000000000' -H "X-Wpsync-Signature: $FAKE_SIG" >/dev/null
  code -X POST "$URL/?rest_route=/wpsync/v1/pair" -H 'Content-Type: application/json' --data-binary '{"code":"XXXXXXXX"}' >/dev/null
done
check SEC-09 "10 anonyme Requests, 0 CREATE TABLE" "$(($(ddl) - before))" 0

echo "== SEC-13: Direktaufruf"
check SEC-13 "src/WpProbe.php gibt nichts aus" "$(curl -s "$URL/wp-content/plugins/wpsync-agent/src/WpProbe.php" | grep -c 'wpsync-agent')" 0

if [ "$FAILED" = 0 ]; then
  echo "SECURITY E2E OK"
else
  echo "SECURITY E2E: $FAILED fehlgeschlagen"
  exit 1
fi
