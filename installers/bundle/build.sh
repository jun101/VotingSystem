#!/usr/bin/env bash
# Builds the Linux bundle of the school edition (slice 19c, step C):
#
#   build.sh [--version X.Y.Z] [--out DIR] [--skip-web] [--skip-redis]
#
# One tar.gz with everything the installer needs and nothing it must fetch: the pinned upstream binaries of
# sources.lock (FrankenPHP with PHP 8.5 (server and command line), Node, MariaDB, Redis compiled for glibc 2.36), the API without
# its development packages, the web application's standalone build, and the installer files. A SHA256SUMS of every file
# and one of the archive come with it. Needs Docker (Composer and `next build` run in the project's own images).
set -eu

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="${VOTE_BUNDLE_CACHE:-$HERE/cache}"
OUT="$HERE/dist"
STAGE_BASE="$HERE/stage"
VERSION="$(cd "$ROOT" && git describe --tags --always 2>/dev/null || echo 0.0.0-dev)"
SKIP_WEB=0
SKIP_REDIS=0
API_IMAGE="${VOTE_API_IMAGE:-votesystem-api:dev}"
REDIS_BUILD_IMAGE="${VOTE_REDIS_BUILD_IMAGE:-debian:bookworm}"

while [ $# -gt 0 ]; do
  case "$1" in
    --version) shift; VERSION="${1:?--version needs a value}" ;;
    --out) shift; OUT="${1:?--out needs a folder}" ;;
    --skip-web) SKIP_WEB=1 ;;
    --skip-redis) SKIP_REDIS=1 ;;
    *) echo "build.sh: unknown option $1" >&2; exit 1 ;;
  esac
  shift
done

say() { printf '==> %s\n' "$*"; }
lock() { # lock NAME FIELD: a field of sources.lock (1 name, 2 version, 3 file, 6 sha256)
  awk -F'|' -v n="$1" -v f="$2" '$1 ~ "^ *"n" *$" { gsub(/^ +| +$/, "", $f); print $f }' "$HERE/sources.lock" | head -1
}

NAME="votesystem-$VERSION-linux-x86_64"
STAGE="$STAGE_BASE/$NAME"

say "Checking the upstream files"
"$HERE/fetch.sh" --cache "$CACHE"

rm -rf "$STAGE"
mkdir -p "$STAGE"/{bin,api,web,node,mariadb,redis,installers,config} "$OUT"

# ---- PHP: the server (FrankenPHP, which is Caddy too) and the command line ------------------------------------------
say "PHP"
cp "$CACHE/$(lock frankenphp 3)" "$STAGE/bin/frankenphp"
# The command line is the same binary as the server: one PHP version, patched in one place.
cat > "$STAGE/bin/php" <<'WRAP'
#!/bin/sh
exec "$(dirname "$(readlink -f "$0")")/frankenphp" php-cli "$@"
WRAP
chmod 755 "$STAGE/bin/frankenphp" "$STAGE/bin/php"

# ---- Node, only the interpreter -------------------------------------------------------------------------------------
say "Node"
tar -xJf "$CACHE/$(lock node 3)" -C "$STAGE/node" --strip-components=1 --wildcards '*/bin/node' '*/LICENSE'

# ---- MariaDB, the bintar without what a server never runs -------------------------------------------------------------
say "MariaDB"
tar -xzf "$CACHE/$(lock mariadb 3)" -C "$STAGE/mariadb" --strip-components=1
rm -rf "$STAGE/mariadb"/{mysql-test,mariadb-test,sql-bench,man,docs,include,debug,COPYING.thirdparty} \
       "$STAGE/mariadb"/lib/*.a "$STAGE/mariadb"/lib/*/*.a 2>/dev/null || true
# Storage engines, test plugins and tools a school server never uses (InnoDB, Aria, MyISAM and the auth plugins stay).
for p in ha_rocksdb ha_s3 ha_spider ha_mroonga ha_connect ha_duckdb ha_oqgraph ha_sphinx ha_federated ha_federatedx \
         ha_videx ha_example ha_test_sql_discovery handlersocket hashicorp_key_management debug_key_management \
         example_key_management auth_test_plugin qa_auth_client qa_auth_interface qa_auth_server mypluglib func_test \
         adt_null dialog_examples libdaemon_example; do
  rm -f "$STAGE/mariadb/lib/plugin/$p.so"
done
rm -f "$STAGE/mariadb/bin"/{mariadb-test,mariadb-client-test,mariadb-client-test-embedded,mariadb-test-embedded,mariadb-ldb,mariadb-slap}
# Debug symbols are most of the size of the server binary.
docker run --rm -v "$STAGE/mariadb:/m" -e HOST_UID="$(id -u)" -e HOST_GID="$(id -g)" "$REDIS_BUILD_IMAGE" bash -c '
  set -eu
  apt-get update -qq && apt-get install -y -qq --no-install-recommends binutils >/dev/null
  find /m -type f \( -perm -u+x -o -name "*.so*" \) -exec strip --strip-unneeded {} + 2>/dev/null || true
  chown -R "$HOST_UID:$HOST_GID" /m'

# ---- Redis, compiled on the oldest supported system (Debian 12, glibc 2.36) -------------------------------------------
if [ "$SKIP_REDIS" = 0 ]; then
  say "Redis (compiled in $REDIS_BUILD_IMAGE)"
  docker run --rm \
    -v "$CACHE:/src:ro" -v "$STAGE/redis:/out" \
    -e REDIS_TARBALL="$(lock redis 3)" \
    "$REDIS_BUILD_IMAGE" bash -c '
      set -eu
      apt-get update -qq && apt-get install -y -qq --no-install-recommends build-essential pkg-config ca-certificates >/dev/null
      mkdir /build && tar -xzf "/src/$REDIS_TARBALL" -C /build --strip-components=1
      # The core server only (src/): Redis 8 would also build its search, JSON and time-series modules, which the
      # application does not use and which need Rust and CMake.
      make -C /build/src -j2 BUILD_TLS=no MALLOC=libc >/dev/null
      install -m 755 /build/src/redis-server /build/src/redis-cli /out/
      chown -R '"$(id -u):$(id -g)"' /out'
fi

# ---- The API without its development packages -----------------------------------------------------------------------
say "API"
(cd "$ROOT/api" && tar --exclude=./vendor --exclude=./tests --exclude=./.env --exclude='./storage/logs/*' \
    --exclude='./storage/framework/*/*' --exclude=./node_modules --exclude=./.phpunit.cache --exclude=./phpstan.neon \
    --exclude=./pint.json --exclude='./bootstrap/cache/*.php' -cf - .) | tar -C "$STAGE/api" -xf -
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
  -v "$STAGE/api:/app" -w /app "$API_IMAGE" \
  composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader --no-progress
mkdir -p "$STAGE/api/storage/app/private" "$STAGE/api/storage/framework"/{cache,sessions,views} "$STAGE/api/storage/logs" \
         "$STAGE/api/bootstrap/cache"

# ---- The web application, the project's own standalone build ----------------------------------------------------------
if [ "$SKIP_WEB" = 0 ]; then
  say "Web"
  docker build -q --target prod -t votesystem-web:bundle -f "$ROOT/docker/web/Dockerfile" "$ROOT" >/dev/null
  cid="$(docker create votesystem-web:bundle)"
  docker cp "$cid:/app/." "$STAGE/web"
  docker rm "$cid" >/dev/null
fi

# ---- The installer, the commands, the templates ------------------------------------------------------------------------
say "Installer files"
cp -a "$ROOT/installers/linux/." "$STAGE/installers/"
rm -rf "$STAGE/installers/tests"
cp "$ROOT/installers/common/votectl" "$STAGE/bin/votectl"
chmod 755 "$STAGE/bin/votectl" "$STAGE/installers/install.sh"
cp "$ROOT/docker/proxy/Caddyfile" "$STAGE/config/Caddyfile.reference"

# ---- Version, manifest, checksums ----------------------------------------------------------------------------------
say "Manifest and checksums"
echo "$VERSION" > "$STAGE/VERSION"
{
  echo "votesystem $VERSION"
  echo "built $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  for n in frankenphp node mariadb redis; do
    echo "$n $(lock "$n" 2) sha256 $(lock "$n" 6)"
  done
} > "$STAGE/MANIFEST"
(cd "$STAGE" && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS)

ARCHIVE="$OUT/$NAME.tar.gz"
tar -C "$STAGE_BASE" --sort=name --owner=0 --group=0 --numeric-owner -czf "$ARCHIVE" "$NAME"
(cd "$OUT" && sha256sum "$(basename "$ARCHIVE")" > "$(basename "$ARCHIVE").sha256")
say "Bundle: $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
