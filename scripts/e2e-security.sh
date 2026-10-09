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
       DELETE FROM e2e_wpsync_state WHERE name IN ('pairing_code', 'pair_last');
       DELETE FROM e2e_options WHERE option_name = 'e2e_sec102_bytes';" >/dev/null 2>&1
  ddev wp config delete WPSYNC_KEY --type=constant >/dev/null 2>&1
  rm -rf "$WPC/plugins/sec-push" "$WPC/plugins/sec-other"
  SQL "DELETE FROM e2e_wpsync_pushes WHERE device LIKE 'sec-%';
       DELETE FROM e2e_wpsync_state WHERE name = 'push_lock';" >/dev/null 2>&1
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

echo "== 2a: Push"
OTHER=00000000000000e3
CONTENT='<?php // sec-push'
SHA="$(printf %s "$CONTENT" | openssl dgst -sha256 -r | cut -d' ' -f1)"
B64="$(printf %s "$CONTENT" | base64 | tr -d '\n')"
EVIL="$(printf %s '<?php // sec-evil' | base64 | tr -d '\n')" # gleiche Länge, anderer Inhalt
unit() { printf '{"path":"%s","base":{},"files":{"%s":{"size":%d,"sha256":"%s","mtime":1700000000}}}' "$1" "$2" "${#CONTENT}" "$SHA"; }
begin() { printf '{"target":"live","dry":%s,"force":true,"units":[%s]}' "$1" "$2"; }
# JSON immer über printf-Helfer bauen: bash 3.2 (macOS) wendet auf "{…,…}" in einer
# Kommandosubstitution innerhalb doppelter Anführungszeichen Klammer-Expansion an.
upload() { printf '{"push_id":"%s","unit":0,"files":[{"path":"%s","offset":0,"data":"%s"}]}' "$PUSH" "$1" "$2"; }
byid() { printf '{"push_id":"%s"}' "$PUSH"; }
pcode() { signed "$1" "$2" '' -o /dev/null -w '%{http_code}'; }
field() { python3 -c 'import json,sys; d=json.load(sys.stdin); print(d'"$1"')'; }
window() { ddev wp eval "WpSync\\Store::setPushUntil('$1', $2);" >/dev/null; }
pushes() { SQL "SELECT COUNT(*) FROM e2e_wpsync_pushes WHERE device = 'sec-curl' AND $1"; }
ddev wp eval "WpSync\\Store::addPairing('$OTHER', '$SECRET', 'sec-other');" || exit 1
window "$KEY" 0
window "$OTHER" "time() + 900"

check AC-50 "Probelauf braucht kein Fenster" "$(pcode /wpsync/v1/push/begin "$(begin true "$(unit plugins/sec-push main.php)")")" 200
check AC-51 "das Fenster eines anderen Pairings gilt nicht" "$(pcode /wpsync/v1/push/begin "$(begin false "$(unit plugins/sec-push main.php)")")" 403
check AC-50 "ohne Fenster entsteht kein Push" "$(pushes "1=1")" 0

check AC-59 "der Agent selbst ist keine Einheit" "$(pcode /wpsync/v1/push/begin "$(begin true "$(unit plugins/wpsync-agent main.php)")")" 400
check AC-59 "Einheit mit .. abgelehnt" "$(pcode /wpsync/v1/push/begin "$(begin true "$(unit plugins/../x main.php)")")" 400
# „uploads“ allein ist ab Agent 0.6.0 eine Einheit (AC-142 unten), ein Unterordner nie.
check AC-59 "uploads/2026 ist keine Einheit" "$(pcode /wpsync/v1/push/begin "$(begin true "$(unit uploads/2026 main.php)")")" 400
check AC-59 "Datei mit .. abgelehnt" "$(pcode /wpsync/v1/push/begin "$(begin true "$(unit plugins/sec-push ../x.php)")")" 400
check AC-59 "Mail-Riegel in mu-plugins abgelehnt" "$(pcode /wpsync/v1/push/begin "$(begin true "$(unit mu-plugins 00-local-mailguard.php)")")" 400
check AC-59 "wpsync-Datei in mu-plugins abgelehnt" "$(pcode /wpsync/v1/push/begin "$(begin true "$(unit mu-plugins wpsync-loader.php)")")" 400

echo "== P1/AC-142: Uploads – Dateityp- und Pfadsperre"
uunit() { printf '{"path":"uploads","base":{},"files":{"%s":{"size":%d,"sha256":"%s","mtime":1700000000}}}' "$1" "${#CONTENT}" "$SHA"; }
ucode() { signed /wpsync/v1/push/begin "$(begin "$1" "$(uunit "$2")")" | field '["code"]'; } # ucode <dry> <pfad>
for name in 2026/10/x.php 2026/10/x.PHP 2026/10/x.phtml 2026/10/x.phar 2026/10/x.php7 2026/10/x.pht \
  2026/10/bild.php.jpg .htaccess 2026/10/.htaccess .user.ini 2026/10/.versteckt.jpg 2026/10/x.svg 2026/10/x.exe 2026/10/x.html; do
  check AC-142 "$name abgelehnt" "$(ucode true "$name")" wpsync_upload_type_blocked
done
check AC-142 "erlaubter Typ im Probelauf" "$(pcode /wpsync/v1/push/begin "$(begin true "$(uunit 2026/10/bild.jpg)")")" 200
check AC-142 "Pfad mit .. abgelehnt" "$(ucode true ../x.jpg)" wpsync_upload_path
check AC-142 "Pfad in einen Push-Arbeitsordner abgelehnt" "$(ucode true wpsync-push-0123456789abcdef/a.jpg)" wpsync_upload_path
window "$KEY" "time() + 900"
res="$(signed /wpsync/v1/push/begin "$(begin false "$(uunit 2026/10/e2e-sec-bild.jpg)")")"
PUSH="$(printf %s "$res" | field '["push_id"]')"
check AC-142 "PHP-Inhalt als .jpg beim Upload abgelehnt" "$(signed /wpsync/v1/push/upload "$(upload 2026/10/e2e-sec-bild.jpg "$B64")" | field '["code"]')" wpsync_upload_type_blocked
check AC-142 "Commit ohne Datei bricht ab" "$(pcode /wpsync/v1/push/commit "$(byid)")" 409
check AC-142 "nichts unter uploads" "$([ -e "$WPC/uploads/2026/10/e2e-sec-bild.jpg" ] && echo da || echo weg)" weg
window "$KEY" 0

window "$KEY" "time() + 900"
res="$(signed /wpsync/v1/push/begin "$(begin false "$(unit plugins/sec-push main.php)")")"
PUSH="$(printf %s "$res" | field '["push_id"]')"
RESCUE="$(printf %s "$res" | field '["rescue"]["url"]')"
check AC-54 "Push angelegt" "$(pushes "status = 'uploading'")" 1
check AC-61 "zweiter Push während eines laufenden" "$(pcode /wpsync/v1/push/begin "$(begin false "$(unit plugins/sec-other main.php)")")" 423
check AC-60 "falscher Inhalt abgelehnt" "$(pcode /wpsync/v1/push/upload "$(upload main.php "$EVIL")")" 400
check AC-59 "nicht angeforderte Datei abgelehnt" "$(pcode /wpsync/v1/push/upload "$(upload other.php "$B64")")" 400

window "$KEY" 0
check AC-52 "Fenster läuft im Upload ab" "$(pcode /wpsync/v1/push/upload "$(upload main.php "$B64")")" 403
check AC-52 "Push ist verfallen" "$(pushes "status = 'expired' AND pruned = 1")" 1
check AC-52 "nichts im Zielverzeichnis" "$([ -e "$WPC/plugins/sec-push" ] && echo da || echo weg)" weg
check AC-52 "Arbeitsordner des Pushs entfernt" "$(find "$WPC" -maxdepth 2 -path '*wpsync-push-*' -name "$PUSH" | wc -l | tr -d ' ')" 0

# push <force>: begin, upload falls nötig, commit – legt $PUSH und $SALT ab
push() {
  res="$(signed /wpsync/v1/push/begin "$(begin false "$(unit plugins/sec-push main.php)")")"
  PUSH="$(printf %s "$res" | field '["push_id"]')"
  SALT="$(printf %s "$res" | field '["rescue"]["salt"]')"
  if [ "$(printf %s "$res" | field '["units"][0]["need"].__len__()')" != "0" ]; then
    signed /wpsync/v1/push/upload "$(upload main.php "$B64")" >/dev/null
  fi
  signed /wpsync/v1/push/commit "$(byid)" >/dev/null
}
rcode() { code -X POST "$RESCUE" --data-urlencode action=rollback --data-urlencode "push_id=$PUSH" --data-urlencode "key=$1"; }
rkey() { printf 'rescue:%s:%s' "$PUSH" "$SALT" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1; }

window "$KEY" "time() + 900"
push
check AC-54 "Einheit liegt auf der Site" "$(cat "$WPC/plugins/sec-push/main.php" 2>/dev/null)" "$CONTENT"
check U7 "unbestätigter Push blockiert den nächsten" "$(pcode /wpsync/v1/push/begin "$(begin false "$(unit plugins/sec-other main.php)")")" 409
check AC-66 "rescue.php antwortet ohne WordPress" "$(curl -s -X POST "$RESCUE" --data action=ping)" '{"ok":true}'
check AC-65 "rescue.php nur per POST" "$(code "$RESCUE")" 405
for _ in 1 2 3 4; do rcode "$FAKE_SIG" >/dev/null; done
check AC-65 "falscher Schlüssel abgelehnt" "$(rcode "$FAKE_SIG")" 403
check AC-65 "nach 5 Fehlversuchen gesperrt, auch für den richtigen Schlüssel" "$(rcode "$(rkey)")" 429
check AC-65 "Einheit steht noch" "$([ -e "$WPC/plugins/sec-push" ] && echo da || echo weg)" da
check AC-55 "Rollback über den Agent" "$(pcode /wpsync/v1/push/rollback "$(byid)")" 200
check AC-55 "neue Einheit ist wieder weg" "$([ -e "$WPC/plugins/sec-push" ] && echo da || echo weg)" weg

push
check AC-64 "Rollback über rescue.php mit richtigem Schlüssel" "$(rcode "$(rkey)")" 200
check AC-64 "Einheit ist weg" "$([ -e "$WPC/plugins/sec-push" ] && echo da || echo weg)" weg
signed /wpsync/v1/push/list '{}' >/dev/null # übernimmt den Rollback ins Protokoll
check AC-64 "Protokoll kennt den Rollback" "$(pushes "push_id = '$PUSH' AND status = 'rolled_back'")" 1

for _ in 1 2 3 4; do
  push
  signed /wpsync/v1/push/confirm "$(byid)" >/dev/null
done
check AC-69 "nur die letzten 3 bestätigten Pushes behalten ihren Snapshot" "$(pushes "status = 'confirmed' AND pruned = 0")" 3
check AC-69 "der älteste ist aufgeräumt" "$(pushes "status = 'confirmed' AND pruned = 1")" 1

delta="$(signed /wpsync/v1/delta '{"cursor":"","scope":null}')"
check AC-71 "Arbeitsordner steht nicht in /delta" "$(printf %s "$delta" | grep -c 'wpsync-push-')" 0
check AC-71 "Protokoll-Tabelle steht nicht in /delta" "$(printf %s "$delta" | grep -c 'wpsync_pushes')" 0
check AC-71 "gepushte Einheit steht in /delta" "$(printf %s "$delta" | grep -c 'sec-push')" 1
WORK="$(find "$WPC" -maxdepth 1 -name 'wpsync-push-*' | head -1)"
# Die DDEV-Quelle läuft mit nginx und wertet keine .htaccess aus – geprüft wird, dass die Sperre liegt.
check AC-71 "Arbeitsordner hat eine .htaccess-Sperre" "$(grep -c 'Require all denied' "$WORK/.htaccess")" 1

echo "== U18: Rollback eines bestätigten Pushs nur bei offenem Fenster"
window "$KEY" 0
check U18 "bestätigter Push ohne Fenster: /push/rollback abgelehnt" "$(pcode /wpsync/v1/push/rollback "$(byid)")" 403
check U18 "Fehlercode für das geschlossene Fenster" "$(signed /wpsync/v1/push/rollback "$(byid)" | field '["code"]')" wpsync_push_window
check U18 "rescue.php rollt einen bestätigten Push nicht zurück" "$(rcode "$(rkey)")" 409
check U18 "bestätigter Push bleibt bestätigt" "$(pushes "push_id = '$PUSH' AND status = 'confirmed' AND pruned = 0")" 1
window "$KEY" "time() + 900"
check U18 "bestätigter Push mit Fenster: Rollback ok" "$(pcode /wpsync/v1/push/rollback "$(byid)")" 200
check U18 "Protokoll kennt den Rollback" "$(pushes "push_id = '$PUSH' AND status = 'rolled_back'")" 1
push
window "$KEY" 0
check U18 "unbestätigter Push ohne Fenster: Rollback ok" "$(pcode /wpsync/v1/push/rollback "$(byid)")" 200
check U18 "Protokoll kennt den Notfall-Rollback" "$(pushes "push_id = '$PUSH' AND status = 'rolled_back'")" 1
window "$KEY" "time() + 900"

ddev wp eval "WpSync\\Store::deletePairing('$KEY');" >/dev/null
check AC-53 "widerrufenes Pairing kann nicht zurückrollen" "$(pcode /wpsync/v1/push/rollback "$(byid)")" 401
check AC-53 "widerrufenes Pairing kann nicht pushen" "$(pcode /wpsync/v1/push/begin "$(begin true "$(unit plugins/sec-push main.php)")")" 401
ddev wp eval "WpSync\\Store::addPairing('$KEY', '$SECRET', 'sec-curl');" >/dev/null

echo "== SEC-006: Pairing-Secrets verschlüsselt in der Datenbank"
stored() { SQL "SELECT secret FROM e2e_wpsync_pairings WHERE key_id = '$1'"; }
as_key() { # as_key <key-id> <route> → HTTP-Status mit fremder Key-ID, gleiches Secret
  local saved="$KEY" out
  KEY="$1"
  out="$(pcode "$2" '{}')"
  KEY="$saved"
  printf %s "$out"
}
check SEC-006 "Spalte secret ist VARCHAR(255)" \
  "$(SQL "SHOW COLUMNS FROM e2e_wpsync_pairings LIKE 'secret'" | awk '{ print $2 }')" 'varchar(255)'
check SEC-006 "per /pair gekoppelt: Secret steht als v1:-Wert" "$(SQL "SELECT LEFT(secret, 3) FROM e2e_wpsync_pairings WHERE device LIKE 'aaaa%'")" v1:
check SEC-006 "addPairing: Secret steht als v1:-Wert" "$(stored "$KEY" | cut -c1-3)" v1:
check SEC-006 "kein 64-Hex-Klartext in der Tabelle" \
  "$(SQL "SELECT COUNT(*) FROM e2e_wpsync_pairings WHERE secret REGEXP '^[a-f0-9]{64}\$'")" 0
check SEC-006 "das Secret steht nirgends in der Tabelle" "$(SQL "SELECT COUNT(*) FROM e2e_wpsync_pairings WHERE secret LIKE '%$SECRET%'")" 0
check SEC-006 "signierter Request mit versiegeltem Secret" "$(pcode /wpsync/v1/ping '{}')" 200

MIG=00000000000000e4
SQL "INSERT INTO e2e_wpsync_pairings (key_id, secret, device, created) VALUES ('$MIG', '$SECRET', 'sec-mig', UNIX_TIMESTAMP())"
check SEC-006 "Altbestand: Klartext-Zeile eingesetzt" "$(stored "$MIG")" "$SECRET"
ddev wp eval "delete_option('wpsync_schema'); WpSync\\Store::install();" >/dev/null
check SEC-006 "Upgrade versiegelt den Klartext" "$(stored "$MIG" | cut -c1-3)" v1:
check SEC-006 "nach dem Upgrade: Auth funktioniert" "$(as_key "$MIG" /wpsync/v1/ping)" 200

SQL "UPDATE e2e_wpsync_pairings SET secret = '$SECRET' WHERE key_id = '$MIG'"
check SEC-006 "Klartext ohne Upgrade wird weiter akzeptiert" "$(as_key "$MIG" /wpsync/v1/ping)" 200
check SEC-006 "… und beim Lesen versiegelt" "$(stored "$MIG" | cut -c1-3)" v1:

SQL "UPDATE e2e_wpsync_pairings SET secret = CONCAT('v1:', TO_BASE64(REPEAT('x', 120))) WHERE key_id = '$MIG'"
check SEC-006 "nicht entschlüsselbar: 401" "$(as_key "$MIG" /wpsync/v1/ping)" 401
saved="$KEY"; KEY="$MIG"
check SEC-006 "nicht entschlüsselbar: wpsync_unpaired" "$(signed /wpsync/v1/ping '{}' | field '["code"]')" wpsync_unpaired
KEY="$saved"
admin="$(ddev wp eval 'wp_set_current_user(1); WpSync\Admin::render();' 2>/dev/null)"
check SEC-006 "Admin markiert das Pairing" "$(printf %s "$admin" | grep -c '>nicht entschlüsselbar – neu koppeln</td>')" 1
check SEC-006 "Admin zeigt verschlüsselte Pairings" "$([ "$(printf %s "$admin" | grep -c '>verschlüsselt</td>')" -ge 1 ] && echo ja || echo nein)" ja
check SEC-006 "Admin warnt nicht vor Klartext" "$(printf %s "$admin" | grep -c 'liegen im Klartext')" 0

before="$(stored "$KEY")"
ddev wp config set WPSYNC_KEY "$(openssl rand -hex 24)" --type=constant >/dev/null || exit 1
check SEC-006 "WPSYNC_KEY gesetzt: Salt-versiegeltes Secret gilt weiter" "$(pcode /wpsync/v1/ping '{}')" 200
check SEC-006 "… und wird mit WPSYNC_KEY neu versiegelt" "$([ "$(stored "$KEY")" != "$before" ] && echo ja || echo nein)" ja
ddev wp config delete WPSYNC_KEY --type=constant >/dev/null || exit 1
check SEC-006 "Schlüssel weg (wie Salt-Rotation): Pairing ist ungültig" "$(pcode /wpsync/v1/ping '{}')" 401
ddev wp eval "WpSync\\Store::deletePairing('$KEY'); WpSync\\Store::deletePairing('$MIG');" >/dev/null
ddev wp eval "WpSync\\Store::addPairing('$KEY', '$SECRET', 'sec-curl');" >/dev/null
check SEC-006 "neu gekoppelt: Auth funktioniert" "$(pcode /wpsync/v1/ping '{}')" 200

echo "== SEC-13: Direktaufruf"
check SEC-13 "src/WpProbe.php gibt nichts aus" "$(curl -s "$URL/wp-content/plugins/wpsync-agent/src/WpProbe.php" | grep -c 'wpsync-agent')" 0

echo "== SEC-102: Dump wird nur als SQL importiert"
# Präpariert die lokale Tabellendatei des Ziels aus e2e-local.sh (ersetzt eine bösartige Site;
# der .done-Marker bleibt gültig, pull --full importiert die Datei ohne sie neu zu laden).
export WPSYNC_CONFIG_DIR="$E2E/config"
export WPSYNC_SITES_DIR="$E2E/sites"
WPSYNC="$ROOT/cli/bin/wpsync"
TARGET=wpsync-e2e-target
T="$WPSYNC_SITES_DIR/$TARGET"
TBL="$T/.wpsync/db/tables/e2e_options"
SEC102_TMP=""
tsql() { (cd "$T" && ddev mysql -N -e "$1"); }
in_db() { (cd "$T" && ddev exec -s db sh -c "test -e '$1' && echo da || echo fehlt"); }
baseline_hash() { shasum -a 256 "$T/.wpsync/baseline.json" 2>/dev/null | cut -d' ' -f1; }
snap_count() { git --git-dir="$WPSYNC_SITES_DIR/.wpsync-git/$TARGET.git" rev-list --count HEAD 2>/dev/null; } # nie git im Site-Ordner (SEC-131)
yn() { if "$@"; then echo ja; else echo nein; fi; }
sec102_restore() { # präparierte Datei + .done löschen, Ziel per pull --full neu aufbauen
  [ -n "$SEC102_TMP" ] || return 0
  rm -rf "$SEC102_TMP"
  SEC102_TMP=""
  rm -f "$TBL.sql" "$TBL.done" "$T/.ddev/sec102-outfile"
  tsql "DROP TABLE IF EXISTS sec102_load, sec102_sourced" >/dev/null 2>&1
  (cd "$T" && ddev exec -s db sh -c 'rm -f /tmp/wpsync-sec102-*') >/dev/null 2>&1
  "$WPSYNC" pull "$TARGET" --full --yes >"$E2E/sec102-restore.log" 2>&1 \
    || echo "WARN SEC-102: Ziel nicht wiederhergestellt – scripts/e2e-local.sh erneut ausführen (Log: $E2E/sec102-restore.log)"
}
sec102_case() { # sec102_case <ac> <beschreibung> <angehängte zeilen>
  local base git out rc
  cp "$SEC102_TMP/orig.sql" "$TBL.sql"
  printf '%s\n' "$3" >>"$TBL.sql"
  tsql "DROP TABLE IF EXISTS sec102_load, sec102_sourced" >/dev/null 2>&1
  base="$(baseline_hash)"
  git="$(snap_count)"
  out="$("$WPSYNC" pull "$TARGET" --full --yes 2>&1)"
  rc=$?
  check "$1" "$2: Pull scheitert" "$([ "$rc" -ne 0 ] && echo ja || echo nein)" ja
  check AC-10 "$2: Meldung nennt den Datenbank-Import" "$(yn grep -q 'Datenbank-Import abgebrochen' <<<"$out")" ja
  check AC-10 "$2: kein ✓ Fertig" "$(yn grep -q '✓ Fertig' <<<"$out")" nein
  check AC-10 "$2: baseline.json unverändert" "$(baseline_hash)" "$base"
  check AC-10 "$2: kein Schnappschuss-Commit" "$(snap_count)" "$git"
}

if [ ! -f "$TBL.sql" ] || [ ! -f "$TBL.done" ]; then
  check SEC-102 "Ziel aus scripts/e2e-local.sh vorhanden" nein ja
else
  (cd "$ROOT/cli" && go build -o bin/wpsync ./cmd/wpsync) || exit 1
  (cd "$T" && ddev start -y >/dev/null) || exit 1
  # Vorlauf: .done-Marker passen danach zur aktuellen Prüfsumme (Agent-Neuinstallation ändert e2e_options)
  "$WPSYNC" pull "$TARGET" --yes >"$E2E/sec102-pre.log" 2>&1
  check SEC-102 "Vorlauf-Pull gelingt" "$(yn grep -q '✓ Fertig' "$E2E/sec102-pre.log")" ja
  SEC102_TMP="$(mktemp -d)"
  cp "$TBL.sql" "$SEC102_TMP/orig.sql"
  trap 'cleanup; sec102_restore' EXIT
  (cd "$T" && ddev exec -s db sh -c 'rm -f /tmp/wpsync-sec102-*') >/dev/null
  rm -f "$T/.ddev/sec102-outfile"
  for m in a b c data; do
    check SEC-102 "Marker /tmp/wpsync-sec102-$m fehlt vorab" "$(in_db "/tmp/wpsync-sec102-$m")" fehlt
  done

  sec102_case AC-3 '\! am Zeilenanfang' '\! touch /tmp/wpsync-sec102-a'
  check AC-3 "\\! führt nichts aus" "$(in_db /tmp/wpsync-sec102-a)" fehlt

  sec102_case AC-4 '\! mitten in einer Anweisung' $'SELECT 1 \\! touch /tmp/wpsync-sec102-b\n;'
  check AC-4 "\\! mitten in der Anweisung führt nichts aus" "$(in_db /tmp/wpsync-sec102-b)" fehlt

  sec102_case AC-4 'system' 'system touch /tmp/wpsync-sec102-c'
  check AC-4 "system führt nichts aus" "$(in_db /tmp/wpsync-sec102-c)" fehlt

  (cd "$T" && ddev exec -s db sh -c "printf 'CREATE TABLE sec102_sourced(i int);\n' > /tmp/wpsync-sec102-src.sql") || exit 1
  sourced() { tsql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'db' AND table_name = 'sec102_sourced'"; }
  sec102_case AC-5 'source' 'source /tmp/wpsync-sec102-src.sql'
  check AC-5 "source liest keine Datei" "$(sourced)" 0
  sec102_case AC-5 '\.' '\. /tmp/wpsync-sec102-src.sql'
  check AC-5 "\\. liest keine Datei" "$(sourced)" 0

  sec102_case AC-6 'LOAD DATA LOCAL INFILE' $'CREATE TABLE IF NOT EXISTS sec102_load (l TEXT);\nLOAD DATA LOCAL INFILE \'/etc/hostname\' INTO TABLE sec102_load;'
  check AC-6 "LOAD DATA LOCAL liest nichts ein" "$(tsql 'SELECT COUNT(*) FROM sec102_load')" 0

  sec102_case AC-7 'INTO OUTFILE' "SELECT 'x' INTO OUTFILE '/mnt/ddev_config/sec102-outfile';"
  check AC-7 "INTO OUTFILE legt keine Datei in .ddev/ an" "$(yn test -e "$T/.ddev/sec102-outfile")" nein
  sec102_case AC-7 'LOAD DATA INFILE' $'CREATE TABLE IF NOT EXISTS sec102_load (l TEXT);\nLOAD DATA INFILE \'/etc/hostname\' INTO TABLE sec102_load;'
  check AC-7 "LOAD DATA INFILE liest nichts ein" "$(tsql 'SELECT COUNT(*) FROM sec102_load')" 0

  echo "== SEC-102 AC-9: Byte-Treue eines ehrlichen Dumps"
  # a NUL CRLF \ ' " 😀 LF, dazu Textzeilen, die als Client-Direktive gelesen würden
  HEXVAL="$(printf '%s' "6100" "0D0A" "5C" "27" "22" "F09F9880" "0A" \
    "$(printf '%s' '\! touch /tmp/wpsync-sec102-data' | xxd -p | tr -d '\n')" "0A" \
    "$(printf '%s' 'source /etc/passwd' | xxd -p | tr -d '\n')" "0A" | tr a-f A-F)"
  SQL "INSERT INTO e2e_options (option_name, option_value, autoload) VALUES ('e2e_sec102_bytes', UNHEX('$HEXVAL'), 'no')
       ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)" || exit 1
  check AC-9 "Fixture in der Quelle" "$(SQL "SELECT HEX(option_value) FROM e2e_options WHERE option_name = 'e2e_sec102_bytes'")" "$HEXVAL"
  sec102_restore
  check AC-9 "regulärer Pull gelingt" "$(yn grep -q '✓ Fertig' "$E2E/sec102-restore.log")" ja
  check AC-9 "Wert kommt byte-gleich an" "$(tsql "SELECT HEX(option_value) FROM e2e_options WHERE option_name = 'e2e_sec102_bytes'")" "$HEXVAL"
  check AC-9 "Textzeile im Wert führt nichts aus" "$(in_db /tmp/wpsync-sec102-data)" fehlt
  tsql "DELETE FROM e2e_options WHERE option_name = 'e2e_sec102_bytes'" >/dev/null 2>&1
fi

echo "== SEC-101: .ddev ist aus den Containern nicht beschreibbar, Abweichungen stoppen wpsync"
# Die Container können .ddev nach der Härtung nicht mehr schreiben – Hook-, Kommando- und
# Markerdateien legt der Test deshalb vom Host aus an (wie bei einer vorher manipulierten Site).
# Der Test ruft nie `wpsync trust` auf; der geprüfte Stand des Ziels bleibt also unverändert.
WEB="ddev-$TARGET-web"
DB="ddev-$TARGET-db"
HELPER=sec101-ac5-helper
SEC101_TMP=""
in_web() { docker exec -u "$(id -u):$(id -g)" "$WEB" sh -c "$1" >/dev/null 2>&1; }
in_web_root() { docker exec "$WEB" sh -c "$1" >/dev/null 2>&1; }
in_db_root() { docker exec "$DB" sh -c "$1" >/dev/null 2>&1; }
container_exists() { docker inspect "$1" >/dev/null 2>&1; }
sec101_files() { # alles, was der Test in .ddev anlegt
  docker rm -f "$HELPER" >/dev/null 2>&1
  rm -f "$T/.ddev/config.audit.yaml" "$T/.ddev/commands/host/x" "$T/.ddev/commands/host/wp" \
    "$T/.ddev/sec101-x" "$T/.ddev/sec101-root" "$T/.ddev/config.sec101loop.yaml"
  rm -rf "$T/.ddev/db_snapshots/sec101-"*
}
sec101_restore() { # Dateien entfernen, falls ein Schreibversuch doch gelang: Stand wiederherstellen
  [ -n "$SEC101_TMP" ] || return 0
  [ -d "$T/.ddev2" ] && [ ! -e "$T/.ddev" ] && mv "$T/.ddev2" "$T/.ddev"
  [ -f "$T/.ddev/config.yaml" ] || cp "$SEC101_TMP/config.yaml" "$T/.ddev/config.yaml"
  cp "$SEC101_TMP/docker-compose.mailguard.yaml" "$T/.ddev/docker-compose.mailguard.yaml"
  sec101_files
  rm -f "$T/.ddev/db_snapshots/sec101-w"
  tsql "DELETE FROM e2e_options WHERE option_name = 'e2e_sec101_snap'" >/dev/null 2>&1
  rm -rf "$SEC101_TMP"
  SEC101_TMP=""
  # Abgebrochene Pulls und `wpsync stop` lassen das Ziel gestoppt zurück – ein regulärer Pull startet es.
  "$WPSYNC" pull "$TARGET" --yes >"$E2E/sec101-restore.log" 2>&1 \
    || echo "WARN SEC-101: Ziel nicht wiederhergestellt – scripts/e2e-local.sh erneut ausführen (Log: $E2E/sec101-restore.log)"
}
sec101_pull() { # sec101_pull <ac> <beschreibung> <datei in .ddev>
  local out rc
  out="$("$WPSYNC" pull "$TARGET" --yes </dev/null 2>&1)"
  rc=$?
  check "$1" "$2: Pull scheitert" "$([ "$rc" -ne 0 ] && echo ja || echo nein)" ja
  check "$1" "$2: Meldung nennt $3" "$(yn grep -qF "$3" <<<"$out")" ja
  check "$1" "$2: Meldung sagt, dass nichts ausgeführt wurde" "$(yn grep -q 'kein ddev-Befehl ausgeführt' <<<"$out")" ja
  check "$1" "$2: kein ✓ Fertig" "$(yn grep -q '✓ Fertig' <<<"$out")" nein
}
hooks_to() { # Hook-Datei, deren Hooks bei jedem ddev-Aufruf eine Markerdatei auf dem Host anlegen
  printf 'hooks:\n'
  for h in pre-start post-start pre-exec post-exec pre-describe pre-stop pre-snapshot; do
    printf '  %s:\n    - exec-host: touch "%s"\n' "$h" "$1"
  done
}

if [ ! -f "$T/.ddev/config.yaml" ] || [ ! -f "$WPSYNC_CONFIG_DIR/ddev-state/$TARGET.json" ]; then
  check SEC-101 "Ziel aus scripts/e2e-local.sh mit geprüftem .ddev-Stand vorhanden" nein ja
else
  (cd "$ROOT/cli" && go build -o bin/wpsync ./cmd/wpsync) || exit 1
  "$WPSYNC" pull "$TARGET" --yes >"$E2E/sec101-pre.log" 2>&1
  check SEC-101 "Vorlauf-Pull gelingt (Container gehärtet)" "$(yn grep -q '✓ Fertig' "$E2E/sec101-pre.log")" ja
  check AC-4 "Härtungsdatei liegt in .ddev" "$(yn test -f "$T/.ddev/docker-compose.wpsync-hardening.yaml")" ja
  # Sicherung erst nach dem Vorlauf: der darf wpsyncs eigene Dateien neu schreiben (AC-4).
  SEC101_TMP="$(mktemp -d)"
  MARK="$SEC101_TMP/marker"
  cp "$T/.ddev/config.yaml" "$T/.ddev/docker-compose.mailguard.yaml" "$SEC101_TMP/"
  trap 'cleanup; sec102_restore; sec101_restore' EXIT

  echo "-- AC-1: web-Container"
  check AC-1 "touch /var/www/html/.ddev/x scheitert" "$(yn in_web 'touch /var/www/html/.ddev/sec101-x')" nein
  check AC-1 "touch /mnt/ddev_config/x scheitert" "$(yn in_web 'touch /mnt/ddev_config/sec101-x')" nein
  check AC-1 "touch als root scheitert" "$(yn in_web_root 'touch /var/www/html/.ddev/sec101-root')" nein
  check AC-1 "rm .ddev/config.yaml scheitert" "$(yn in_web 'rm -f /var/www/html/.ddev/config.yaml')" nein
  check AC-1 "mv .ddev .ddev2 scheitert" "$(yn in_web 'mv /var/www/html/.ddev /var/www/html/.ddev2')" nein
  check AC-1 "Hook-Datei schreiben scheitert" "$(yn in_web 'printf "hooks: {}\n" > /var/www/html/.ddev/config.audit.yaml')" nein
  check AC-1 "remount rw scheitert" "$(yn in_web_root 'mount -o remount,rw /var/www/html/.ddev')" nein
  check AC-1 "keine der Dateien ist auf dem Host angekommen" \
    "$(ls "$T/.ddev/sec101-x" "$T/.ddev/sec101-root" "$T/.ddev/config.audit.yaml" "$T/.ddev2" 2>/dev/null | wc -l | tr -d ' ')" 0
  check AC-1 "config.yaml unverändert" "$(yn cmp -s "$T/.ddev/config.yaml" "$SEC101_TMP/config.yaml")" ja
  check AC-1 "Rest von /var/www/html bleibt schreibbar" "$(yn in_web 'touch /var/www/html/.wpsync/sec101-w && rm /var/www/html/.wpsync/sec101-w')" ja
  check AC-3 "Mailguard-Datei bleibt ro" "$(yn in_web_root 'touch /var/www/html/public/wp-content/mu-plugins/00-local-mailguard.php')" nein
  T_URL="$(cd "$T" && ddev describe -j | python3 -c 'import json,sys; print(json.load(sys.stdin)["raw"]["httpurl"])')"
  check AC-1 "Site antwortet mit 200" "$(code "$T_URL/")" 200

  echo "-- AC-2: db-Container"
  check AC-2 "touch /mnt/ddev_config/x scheitert" "$(yn in_db_root 'touch /mnt/ddev_config/sec101-x')" nein
  check AC-2 "config.x.yaml anlegen scheitert" "$(yn in_db_root 'printf "hooks: {}\n" > /mnt/ddev_config/config.audit.yaml')" nein
  check AC-2 "mv /mnt/ddev_config/db_snapshots scheitert" "$(yn in_db_root 'mv /mnt/ddev_config/db_snapshots /mnt/ddev_config/sec101-moved')" nein
  check AC-2 "/mnt/snapshots bleibt schreibbar" "$(yn in_db_root 'touch /mnt/snapshots/sec101-w && rm /mnt/snapshots/sec101-w')" ja
  check AC-2 "/mnt/ddev_config/db_snapshots bleibt schreibbar (D3)" "$(yn in_db_root 'touch /mnt/ddev_config/db_snapshots/sec101-w && rm /mnt/ddev_config/db_snapshots/sec101-w')" ja
  check AC-2 "nichts davon auf dem Host" "$(ls "$T/.ddev/sec101-x" "$T/.ddev/config.audit.yaml" "$T/.ddev/sec101-moved" 2>/dev/null | wc -l | tr -d ' ')" 0
  # ddev snapshot meldet einen Schreibfehler mit Exit 0 – Erfolg zählt nur über die Datei auf dem Host.
  tsql "INSERT INTO e2e_options (option_name, option_value, autoload) VALUES ('e2e_sec101_snap', 'vorher', 'no')
        ON DUPLICATE KEY UPDATE option_value = 'vorher'" >/dev/null || exit 1
  (cd "$T" && ddev snapshot --name sec101-snap >/dev/null 2>&1)
  check AC-2 "ddev snapshot legt die Datei auf dem Host ab" "$(ls "$T/.ddev/db_snapshots/" 2>/dev/null | grep -c '^sec101-snap')" 1
  tsql "UPDATE e2e_options SET option_value = 'nachher' WHERE option_name = 'e2e_sec101_snap'" >/dev/null
  (cd "$T" && ddev snapshot restore sec101-snap >/dev/null 2>&1)
  check AC-2 "ddev snapshot restore stellt den Wert wieder her" \
    "$(tsql "SELECT option_value FROM e2e_options WHERE option_name = 'e2e_sec101_snap'")" vorher
  check AC-2 "nach dem Restore bleibt /mnt/ddev_config ro" "$(yn in_db_root 'touch /mnt/ddev_config/sec101-x')" nein
  tsql "DELETE FROM e2e_options WHERE option_name = 'e2e_sec101_snap'" >/dev/null 2>&1
  rm -rf "$T/.ddev/db_snapshots/sec101-"*

  echo "-- AC-5: Container mit beschreibbarem .ddev wird ohne ddev gestoppt"
  # Ein Hilfscontainer im Compose-Projekt ddev-<target> (wie ein Add-on-Service, ohne
  # com.ddev.approot → checkOwner lässt ihn durch) mountet .ddev rw und schreibt in einer Schleife
  # eine Hook-Datei. Erwartet: wpsync stoppt und entfernt alle Projekt-Container per docker, bevor
  # ddev läuft; die Datei liegt dann schon in .ddev, die Prüfung bricht mit ihr als Abweichung ab.
  hooks_to "$MARK" >"$SEC101_TMP/loop.yaml"
  docker run -d --init --name "$HELPER" -u "$(id -u):$(id -g)" \
    --label "com.docker.compose.project=ddev-$TARGET" --label com.docker.compose.service=sec101-helper \
    -v "$T/.ddev:/ddev" -v "$SEC101_TMP/loop.yaml:/src/loop.yaml:ro" \
    --entrypoint sh "$(docker inspect -f '{{.Config.Image}}' "$WEB")" \
    -c 'while :; do cp /src/loop.yaml /ddev/config.sec101loop.yaml; sleep 0.2; done' >/dev/null || exit 1
  for _ in $(seq 50); do [ -f "$T/.ddev/config.sec101loop.yaml" ] && break; sleep 0.2; done
  check AC-5 "Hilfscontainer schreibt in .ddev (Voraussetzung)" "$(yn test -f "$T/.ddev/config.sec101loop.yaml")" ja
  out="$("$WPSYNC" pull "$TARGET" --yes </dev/null 2>&1)"
  rc=$?
  check AC-5 "Pull bricht ab" "$([ "$rc" -ne 0 ] && echo ja || echo nein)" ja
  check AC-5 "Meldung: Container ohne ddev gestoppt" "$(yn grep -q 'ohne ddev gestoppt' <<<"$out")" ja
  check AC-5 "Meldung nennt config.sec101loop.yaml" "$(yn grep -qF config.sec101loop.yaml <<<"$out")" ja
  check AC-5 "Hilfscontainer entfernt (docker rm)" "$(yn container_exists "$HELPER")" nein
  check AC-5 "web-Container entfernt" "$(yn container_exists "$WEB")" nein
  check AC-5 "kein Marker vom Host-Hook" "$(yn test -e "$MARK")" nein
  check AC-5 "Hook-Datei nicht im geprüften Stand" \
    "$(yn grep -q sec101loop "$WPSYNC_CONFIG_DIR/ddev-state/$TARGET.json")" nein
  rm -f "$T/.ddev/config.sec101loop.yaml"
  sleep 1
  check AC-5 "Schleife schreibt nicht mehr" "$(yn test -e "$T/.ddev/config.sec101loop.yaml")" nein

  echo "-- AC-10: PoC wird nicht mehr ausgeführt"
  hooks_to "$MARK" >"$T/.ddev/config.audit.yaml"
  sec101_pull AC-10 "config.audit.yaml mit exec-host" config.audit.yaml
  check AC-10 "config.audit.yaml: kein Marker auf dem Host" "$(yn test -e "$MARK")" nein
  rm -f "$T/.ddev/config.audit.yaml"

  mkdir -p "$T/.ddev/commands/host"
  # x wie im Finding; wp überdeckt `ddev wp` und liefe auf dem Host, sobald wpsync ddev wp aufruft.
  for cmd in x wp; do
    printf '#!/bin/sh\n## Description: sec101\ntouch "%s"\n' "$MARK" >"$T/.ddev/commands/host/$cmd"
    chmod +x "$T/.ddev/commands/host/$cmd"
    sec101_pull AC-10 "commands/host/$cmd" "commands/host/$cmd"
    check AC-10 "commands/host/$cmd: kein Marker auf dem Host" "$(yn test -e "$MARK")" nein
    rm -f "$T/.ddev/commands/host/$cmd"
  done

  # Geänderte wpsync-eigene Datei: macht .ddev im web-Container wieder beschreibbar.
  printf '      - ".:/var/www/html/.ddev"\n' >>"$T/.ddev/docker-compose.mailguard.yaml"
  sec101_pull AC-10 "geänderte docker-compose.mailguard.yaml" docker-compose.mailguard.yaml
  check AC-10 "Mailguard-Compose: Datei nicht still repariert" \
    "$(yn cmp -s "$T/.ddev/docker-compose.mailguard.yaml" "$SEC101_TMP/docker-compose.mailguard.yaml")" nein
  cp "$SEC101_TMP/docker-compose.mailguard.yaml" "$T/.ddev/docker-compose.mailguard.yaml"

  # wpsync stop prüft nur laufende Sites – nach AC-5 und den abgebrochenen Pulls ist das Ziel aus.
  "$WPSYNC" pull "$TARGET" --yes >"$E2E/sec101-stop-pre.log" 2>&1
  check AC-10 "wpsync stop: Ziel läuft vor dem Test" "$(yn grep -q '✓ Fertig' "$E2E/sec101-stop-pre.log")" ja
  hooks_to "$MARK" >"$T/.ddev/config.audit.yaml"
  out="$("$WPSYNC" stop "$TARGET" </dev/null 2>&1)"
  rc=$?
  check AC-10 "wpsync stop: endet ≠ 0" "$([ "$rc" -ne 0 ] && echo ja || echo nein)" ja
  check AC-10 "wpsync stop: Meldung nennt config.audit.yaml" "$(yn grep -qF config.audit.yaml <<<"$out")" ja
  check AC-10 "wpsync stop: kein Marker auf dem Host (pre-stop lief nicht)" "$(yn test -e "$MARK")" nein
  rm -f "$T/.ddev/config.audit.yaml"

  sec101_restore
  check AC-9 "Pull nach dem Aufräumen gelingt ohne Fehlalarm (auch nach snapshot restore)" \
    "$(yn grep -q '✓ Fertig' "$E2E/sec101-restore.log")" ja
fi

echo "== SEC-131: .git im Site-Ordner führt beim Schnappschuss nichts auf dem Host aus"
# Code im web-Container legt ein Repo mit pre-commit-Hook und core.fsmonitor an; beide Nutzlasten
# zeigen auf Host-Pfade, weil git sie auf dem Host ausführen würde. Der Test ruft nie git im Site-Ordner auf.
SEC131_MARK="$E2E/sec131.marker"
SEC131_ARMED=""
SEC131_EMBED="$T/public/wp-content/plugins/sec131embed"
sec131_restore() { # vom Container angelegte Repos und Marker entfernen
  [ -n "$SEC131_ARMED" ] || return 0
  SEC131_ARMED=""
  rm -rf "$T/.git" "$SEC131_EMBED" "$SEC131_MARK"
}
sec131_plant_embed() { # eingebettetes Repo mit Commit; git im Container ist der Angreifer-Schritt
  docker exec -u "$(id -u):$(id -g)" -e M="$SEC131_MARK" -e F="$SEC131_EMBED/.git/fsm" "$WEB" sh -c '
    d=/var/www/html/public/wp-content/plugins/sec131embed
    mkdir -p "$d" && cd "$d" && printf "sec131\n" > readme.txt &&
    git init -q -b main && git add readme.txt &&
    git -c user.name=x -c user.email=x@sec131.example commit -q -m embed &&
    git config core.fsmonitor "$F" &&
    printf "#!/bin/sh\necho embed-fsm >> \"%s\"\n" "$M" > .git/fsm &&
    printf "#!/bin/sh\necho embed-hook >> \"%s\"\n" "$M" > .git/hooks/post-index-change &&
    chmod +x .git/fsm .git/hooks/post-index-change' >/dev/null 2>&1
}
sec131_plant() { # minimales Repo, damit auch ein git mit Repo-Suche es als Repo erkennt
  docker exec -u "$(id -u):$(id -g)" -e M="$SEC131_MARK" -e F="$T/.git/fsm" "$WEB" sh -c '
    g=/var/www/html/.git
    mkdir -p "$g/hooks" "$g/objects" "$g/refs/heads" &&
    printf "ref: refs/heads/main\n" > "$g/HEAD" &&
    printf "[core]\n\trepositoryformatversion = 0\n\tbare = false\n\tfsmonitor = %s\n" "$F" > "$g/config" &&
    printf "#!/bin/sh\necho hook >> \"%s\"\n" "$M" > "$g/hooks/pre-commit" &&
    printf "#!/bin/sh\necho fsm >> \"%s\"\n" "$M" > "$g/fsm" &&
    chmod +x "$g/hooks/pre-commit" "$g/fsm"' >/dev/null 2>&1
}

if [ ! -f "$T/.ddev/config.yaml" ] || [ ! -f "$WPSYNC_CONFIG_DIR/ddev-state/$TARGET.json" ]; then
  check SEC-131 "Ziel aus scripts/e2e-local.sh mit geprüftem .ddev-Stand vorhanden" nein ja
elif [ -e "$T/.git" ] && [ ! -e "$WPSYNC_SITES_DIR/.wpsync-git/$TARGET.git" ]; then
  # Bestands-.git, das noch nicht verschoben wurde – nicht anfassen, das erledigt der nächste e2e-local-Lauf.
  check SEC-131 "Ziel ohne unverschobenes Bestands-.git (scripts/e2e-local.sh ausführen)" nein ja
else
  (cd "$ROOT/cli" && go build -o bin/wpsync ./cmd/wpsync) || exit 1
  "$WPSYNC" pull "$TARGET" --yes </dev/null >"$E2E/sec131-pre.log" 2>&1
  check SEC-131 "Vorlauf-Pull gelingt" "$(yn grep -q '✓ Fertig' "$E2E/sec131-pre.log")" ja
  check AC-1 "Schnappschuss-Repo liegt neben dem Site-Ordner" "$(yn test -d "$WPSYNC_SITES_DIR/.wpsync-git/$TARGET.git")" ja
  check AC-1 "kein .git im Site-Ordner" "$(yn test -e "$T/.git")" nein
  SEC131_ARMED=1
  trap 'cleanup; sec102_restore; sec101_restore; sec131_restore' EXIT
  rm -f "$SEC131_MARK"

  echo "-- AC-5: Hook und fsmonitor aus dem web-Container"
  sec131_plant
  check AC-5 "Container schreibt .git/hooks/pre-commit (ausführbar)" "$(yn test -x "$T/.git/hooks/pre-commit")" ja
  check AC-5 "Container schreibt core.fsmonitor in .git/config" "$(yn grep -q fsmonitor "$T/.git/config")" ja
  before="$(snap_count)"
  out="$("$WPSYNC" pull "$TARGET" --yes </dev/null 2>&1)"
  rc=$?
  check AC-5 "Pull endet mit 0" "$rc" 0
  check AC-5 "✓ Fertig" "$(yn grep -q '✓ Fertig' <<<"$out")" ja
  check AC-5 "kein Marker auf dem Host" "$(yn test -e "$SEC131_MARK")" nein
  check AC-5 "Schnappschuss-Commit im externen Repo" "$(snap_count)" "$((before + 1))"
  check AC-7 "Warnung nennt .git im Site-Ordner" "$(yn grep -qF "$T/.git" <<<"$out")" ja
  check AC-7 "Warnung: nicht von wpsync angelegt" "$(yn grep -q 'nicht von wpsync angelegt' <<<"$out")" ja
  sec131_restore

  echo "-- eingebettetes Repo unter plugins/ (greift erst ab dem zweiten Pull)"
  SEC131_ARMED=1
  sec131_plant_embed
  check SEC-131 "Container legt plugins/sec131embed mit Commit an" "$(yn test -f "$SEC131_EMBED/.git/HEAD" -a -d "$SEC131_EMBED/.git/objects")" ja
  check SEC-131 "post-index-change und fsmonitor ausführbar" \
    "$(yn test -x "$SEC131_EMBED/.git/hooks/post-index-change" -a -x "$SEC131_EMBED/.git/fsm")" ja
  for i in 1 2; do
    printf 'v%s\n' "$i" >>"$SEC131_EMBED/readme.txt"
    out="$("$WPSYNC" pull "$TARGET" --yes </dev/null 2>&1)"
    rc=$?
    check SEC-131 "eingebettet, Pull $i endet mit 0" "$rc" 0
    check SEC-131 "eingebettet, Pull $i: Ordner liegt noch da (Fall greift)" "$(yn test -d "$SEC131_EMBED/.git")" ja
    check SEC-131 "eingebettet, Pull $i: Warnung „hat ein eigenes .git“" \
      "$(yn grep -qF 'plugins/sec131embed hat ein eigenes .git' <<<"$out")" ja
    check SEC-131 "eingebettet, Pull $i: kein Marker auf dem Host" "$(yn test -e "$SEC131_MARK")" nein
    check SEC-131 "eingebettet, Pull $i: nicht im Schnappschuss" \
      "$(git --git-dir="$WPSYNC_SITES_DIR/.wpsync-git/$TARGET.git" ls-files | grep -c 'plugins/sec131embed')" 0
  done
  sec131_restore
fi

if [ "$FAILED" = 0 ]; then
  echo "SECURITY E2E OK"
else
  echo "SECURITY E2E: $FAILED fehlgeschlagen"
  exit 1
fi
