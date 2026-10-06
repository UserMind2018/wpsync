#!/usr/bin/env bash
# Baut dist/wpsync-agent.zip – nur Laufzeitdateien, kein vendor/, keine Tests.
set -euo pipefail
cd "$(dirname "$0")"

for f in wpsync-agent.php rescue.php src/*.php staging/*.php; do
  php -l "$f" >/dev/null
done

# Jede Quelldatei bricht ohne WordPress sofort ab – ein Direktaufruf verrät sonst Serverpfade (SEC-13).
# Ausnahme: was rescue.php lädt, läuft auch mit WPSYNC_RESCUE; ein Direktaufruf bricht trotzdem ab.
missing="$(grep -LE "^defined\('ABSPATH'\) \|\| (defined\('WPSYNC_RESCUE'\) \|\| )?exit;" src/*.php || true)"
if [ -n "$missing" ]; then
  echo "ABSPATH-Guard fehlt in:"
  echo "$missing"
  exit 1
fi

rm -rf dist
mkdir -p dist/wpsync-agent/src dist/wpsync-agent/staging
cp wpsync-agent.php rescue.php dist/wpsync-agent/
cp src/*.php dist/wpsync-agent/src/
cp staging/*.php dist/wpsync-agent/staging/ # Riegel der Staging-Kopie (Spec 2b 5.5)
cp ../LICENSE dist/wpsync-agent/LICENSE # MIT verlangt den Lizenztext in jeder Kopie (CR-14)
(cd dist && zip -qr wpsync-agent.zip wpsync-agent && rm -rf wpsync-agent)
echo "dist/wpsync-agent.zip"
