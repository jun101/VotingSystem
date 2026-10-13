#!/usr/bin/env bash
# Builds the Windows bundle of the school edition (slice 19c, step F):
#
#   build-windows.sh [--version X.Y.Z] [--out DIR] [--from-stage DIR]
#
# One zip with everything install.ps1 needs and nothing it must fetch: the pinned Windows builds of sources-windows.lock
# (PHP, MariaDB, Redis for Windows, Node, Caddy, the Visual C++ runtime), the API and the web application taken from the
# Linux bundle's stage (they are the same files: PHP code and a Node standalone build), and the installer files.
# Needs python3 and curl; no Windows machine.
set -eu

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="${VOTE_BUNDLE_CACHE:-$HERE/cache-windows}"
OUT="$HERE/dist"
VERSION="$(cd "$ROOT" && git describe --tags --always 2>/dev/null || echo 0.0.0-dev)"
FROM_STAGE=""

while [ $# -gt 0 ]; do
  case "$1" in
    --version) shift; VERSION="${1:?--version needs a value}" ;;
    --out) shift; OUT="${1:?--out needs a folder}" ;;
    --from-stage) shift; FROM_STAGE="${1:?--from-stage needs a folder}" ;;
    *) echo "build-windows.sh: unknown option $1" >&2; exit 1 ;;
  esac
  shift
done

say() { printf '==> %s\n' "$*"; }
lock() { awk -F'|' -v n="$1" -v f="$2" '$1 ~ "^ *"n" *$" { gsub(/^ +| +$/, "", $f); print $f }' "$HERE/sources-windows.lock" | head -1; }

[ -n "$FROM_STAGE" ] || FROM_STAGE="$(ls -d "$HERE"/stage/votesystem-*-linux-x86_64 2>/dev/null | tail -1)"
[ -d "$FROM_STAGE/api" ] && [ -d "$FROM_STAGE/web" ] || { echo "build-windows.sh: no Linux stage with api/ and web/ (run build.sh first, or --from-stage)" >&2; exit 1; }

mkdir -p "$CACHE" "$OUT"
say "Fetching and checking the upstream files"
for name in php mariadb redis node caddy vc_redist; do
  file="$(lock $name 3)"; url="$(lock $name 4)"; want="$(lock $name 5)"
  if [ ! -f "$CACHE/$file" ]; then
    say "  download $file"
    curl -fL --retry 3 -o "$CACHE/$file.part" "$url"
    mv "$CACHE/$file.part" "$CACHE/$file"
  fi
  have="$(sha256sum "$CACHE/$file" | cut -d' ' -f1)"
  if [ -n "$want" ] && [ "$have" != "$want" ]; then
    echo "build-windows.sh: $file does not match its pinned checksum ($have)" >&2; exit 1
  fi
  [ -n "$want" ] || echo "  note: $file has no pinned checksum ($have)"
done

NAME="votesystem-$VERSION-windows-x64"
STAGE="$HERE/stage/$NAME"
rm -rf "$STAGE"; mkdir -p "$STAGE"

say "Unpacking"
python3 - "$CACHE" "$STAGE" "$(lock php 3)" "$(lock mariadb 3)" "$(lock redis 3)" "$(lock node 3)" "$(lock caddy 3)" <<'PY'
import sys, zipfile, os, shutil
cache, stage, php, mariadb, redis, node, caddy = sys.argv[1:8]
def extract(zname, dest, strip=False):
    z = zipfile.ZipFile(os.path.join(cache, zname))
    names = z.namelist()
    prefix = ''
    if strip:
        first = names[0].split('/')[0] + '/'
        if all(n.startswith(first) for n in names): prefix = first
    for info in z.infolist():
        rel = info.filename[len(prefix):]
        if not rel or rel.endswith('/'): continue
        # What a school server never runs.
        low = rel.lower()
        if low.endswith('.pdb') or low.startswith('mysql-test/') or low.startswith('sql-bench/') or low.startswith('debug/'): continue
        target = os.path.join(stage, dest, rel)
        os.makedirs(os.path.dirname(target), exist_ok=True)
        with z.open(info) as src, open(target, 'wb') as out: shutil.copyfileobj(src, out)
extract(php, 'php'); extract(mariadb, 'mariadb', True); extract(redis, 'redis'); extract(node, 'node', True); extract(caddy, 'caddy')
PY

cp "$CACHE/$(lock vc_redist 3)" "$STAGE/vc_redist.x64.exe"
say "The application"
cp -a "$FROM_STAGE/api" "$STAGE/api"
cp -a "$FROM_STAGE/web" "$STAGE/web"
rm -rf "$STAGE/api/storage/logs"/* 2>/dev/null || true
mkdir -p "$STAGE/installers"
cp -a "$ROOT/installers/windows/install.ps1" "$ROOT/installers/windows/votectl.ps1" "$ROOT/installers/windows/lib" "$ROOT/installers/windows/templates" "$STAGE/installers/"
echo "$VERSION" > "$STAGE/VERSION"

say "Checksums and archive"
( cd "$STAGE" && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS )
rm -f "$OUT/$NAME.zip" "$OUT/$NAME.zip.sha256"
python3 - "$STAGE" "$OUT/$NAME.zip" "$NAME" <<'PY'
import sys, os, zipfile
stage, out, name = sys.argv[1:4]
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, compresslevel=6) as z:
    for root, dirs, files in os.walk(stage):
        for f in files:
            p = os.path.join(root, f)
            if os.path.islink(p): continue
            z.write(p, os.path.join(name, os.path.relpath(p, stage)))
PY
( cd "$OUT" && sha256sum "$NAME.zip" > "$NAME.zip.sha256" )
say "Done: $OUT/$NAME.zip ($(du -h "$OUT/$NAME.zip" | cut -f1))"
