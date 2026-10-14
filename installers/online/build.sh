#!/usr/bin/env bash
# Builds the release archive of the online installer (slice 19c, step E):
#
#   build.sh --version X.Y.Z [--out DIR]
#
# One tar.gz with the installer, its libraries, the production Compose file, the proxy file, the database script and
# votectl, and a .sha256 next to it. The images (API, web) are not in it: they are pulled by version from the registry.
set -eu
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
VERSION=""
OUT="$HERE/dist"

while [ $# -gt 0 ]; do
  case "$1" in
    --version) shift; VERSION="${1:?--version needs a value}" ;;
    --out) shift; OUT="${1:?--out needs a folder}" ;;
    *) echo "build.sh: unknown option $1" >&2; exit 1 ;;
  esac
  shift
done
[ -n "$VERSION" ] || { echo "build.sh: --version is required" >&2; exit 1; }
printf '%s' "$VERSION" | grep -Eq '^[0-9A-Za-z][0-9A-Za-z._-]*$' || { echo "build.sh: invalid version" >&2; exit 1; }

NAME="votesystem-online-$VERSION"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
D="$STAGE/$NAME"
mkdir -p "$D/lib" "$D/files" "$OUT"

cp "$HERE/install-online.sh" "$D/"
cp "$HERE/lib/online.sh" "$ROOT/installers/linux/lib/common.sh" "$ROOT/installers/linux/lib/ports.sh" "$ROOT/installers/linux/lib/firewall.sh" "$D/lib/"
cp "$ROOT/compose.prod.yaml" "$D/files/compose.prod.yaml"
cp "$ROOT/docker/proxy/Caddyfile.production" "$D/files/Caddyfile"
cp "$ROOT/docker/db/init-prod.sh" "$D/files/init-db.sh"
cp "$ROOT/installers/common/votectl" "$D/files/votectl"
printf '%s\n' "$VERSION" > "$D/VERSION"
chmod 755 "$D/install-online.sh" "$D/files/votectl" "$D/files/init-db.sh"

ARCHIVE="$OUT/$NAME.tar.gz"
tar -C "$STAGE" --owner=0 --group=0 --sort=name --mtime='2026-01-01 00:00:00' -czf "$ARCHIVE" "$NAME"
(cd "$OUT" && sha256sum "$NAME.tar.gz" > "$NAME.tar.gz.sha256")
echo "$ARCHIVE"
echo "$ARCHIVE.sha256"
