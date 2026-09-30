#!/usr/bin/env bash
# Baut dist/wpsync-agent.zip – nur Laufzeitdateien, kein vendor/, keine Tests.
set -euo pipefail
cd "$(dirname "$0")"

for f in wpsync-agent.php src/*.php; do
  php -l "$f" >/dev/null
done

rm -rf dist
mkdir -p dist/wpsync-agent/src
cp wpsync-agent.php dist/wpsync-agent/
cp src/*.php dist/wpsync-agent/src/
(cd dist && zip -qr wpsync-agent.zip wpsync-agent && rm -rf wpsync-agent)
echo "dist/wpsync-agent.zip"
