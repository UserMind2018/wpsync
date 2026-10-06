#!/usr/bin/env bash
# Baut das Linux-Binary für den OS-Container (Spec Server-Modus §8) reproduzierbar:
# gleiche Quelle + gleiche Go-Version = gleiche SHA-256. Ausgabe nach dist/, Prüfsumme daneben.
# Aufruf: scripts/build-linux.sh <version> [arm64|amd64]
set -euo pipefail

VERSION="${1:?Aufruf: scripts/build-linux.sh <version> [arm64|amd64]}"
ARCH="${2:-arm64}"
case "$ARCH" in arm64|amd64) ;; *) echo "Architektur $ARCH nicht unterstützt (arm64, amd64)" >&2; exit 2;; esac
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Version im Format X.Y.Z erwartet: $VERSION" >&2; exit 2; }

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NAME="wpsync_${VERSION}_linux_${ARCH}"
mkdir -p "$ROOT/dist"

(cd "$ROOT/cli" && CGO_ENABLED=0 GOOS=linux GOARCH="$ARCH" go build -trimpath -buildvcs=false \
  -ldflags "-s -w -buildid= -X github.com/usermind/wpsync/internal/agentapi.Version=$VERSION" \
  -o "$ROOT/dist/$NAME" ./cmd/wpsync)

if command -v sha256sum >/dev/null; then
  SUM="$(sha256sum "$ROOT/dist/$NAME" | cut -d' ' -f1)"
else
  SUM="$(shasum -a 256 "$ROOT/dist/$NAME" | cut -d' ' -f1)"
fi
echo "$SUM  $NAME" > "$ROOT/dist/$NAME.sha256"

cat <<NOTE
wpsync $VERSION linux/$ARCH
Datei:   dist/$NAME
SHA-256: $SUM
Go:      $(cd "$ROOT/cli" && go version)
Commit:  $(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unbekannt)
NOTE
