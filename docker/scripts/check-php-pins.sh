#!/bin/sh
# Fails when a PHP or Node runtime pinned in the project is older than the patched floor, or differs from the others.
# Raise MIN_PHP or MIN_NODE when a new security release is out. Checks the pins; with --image it also asks the image itself.
#
#   check-php-pins.sh [--image]
set -u
MIN_PHP="8.5.12"
MIN_NODE="24.22.1"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
fail=0

# older A B: true when version A is lower than version B.
older() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" = "$1" ]; }

check() { # check LABEL VERSION
  if [ -z "$2" ]; then echo "FAIL  $1: no PHP version found"; fail=1
  elif older "$2" "$MIN_PHP"; then echo "FAIL  $1: PHP $2 is older than $MIN_PHP"; fail=1
  else echo "ok    $1: PHP $2"; fi
}

image="$(sed -n 's/^FROM \(dunglas\/frankenphp:[^ ]*\) .*/\1/p' "$ROOT/docker/api/Dockerfile" | head -n1)"
check "docker/api/Dockerfile ($image)" "$(echo "$image" | sed -n 's/.*-php\([0-9][0-9.]*\)-.*/\1/p')"
check "api/composer.json (php constraint floor)" "$(sed -n 's/.*"php": "\^\([0-9.]*\)".*/\1/p' "$ROOT/api/composer.json" | head -n1)"
check "api/composer.lock (platform)" "$(grep -A1 '"platform": {' "$ROOT/api/composer.lock" | sed -n 's/.*"php": "\^\([0-9.]*\)".*/\1/p')"
check "installers/bundle/sources-windows.lock" "$(sed -n 's/^php | \([0-9.]*\) |.*/\1/p' "$ROOT/installers/bundle/sources-windows.lock")"
# The Linux bundle's PHP is inside the FrankenPHP binary: its version follows the image's FrankenPHP release.
fp_lock="$(sed -n 's/^frankenphp | \([0-9.]*\) |.*/\1/p' "$ROOT/installers/bundle/sources.lock")"
fp_img="$(echo "$image" | sed -n 's/.*frankenphp:\([0-9.]*\)-.*/\1/p')"
if [ "$fp_lock" = "$fp_img" ] && [ -n "$fp_lock" ]; then echo "ok    FrankenPHP release: bundle and image both $fp_lock"
else echo "FAIL  FrankenPHP release differs: bundle '$fp_lock', image '$fp_img'"; fail=1; fi

checknode() { # checknode LABEL VERSION
  if [ -z "$2" ]; then echo "FAIL  $1: no Node version found"; fail=1
  elif older "$2" "$MIN_NODE"; then echo "FAIL  $1: Node $2 is older than $MIN_NODE"; fail=1
  else echo "ok    $1: Node $2"; fi
}
for f in docker/web/Dockerfile .github/workflows/ci.yml .github/workflows/security-audit.yml; do
  for v in $(grep -o 'node:[0-9][0-9.]*' "$ROOT/$f" | cut -d: -f2 | sort -u); do checknode "$f" "$v"; done
done
checknode "installers/bundle/sources.lock" "$(sed -n 's/^node | \([0-9.]*\) |.*/\1/p' "$ROOT/installers/bundle/sources.lock")"
checknode "installers/bundle/sources-windows.lock" "$(sed -n 's/^node | \([0-9.]*\) |.*/\1/p' "$ROOT/installers/bundle/sources-windows.lock")"

if [ "${1:-}" = "--image" ]; then
  real="$(docker run --rm --entrypoint php "$image" -r 'echo PHP_VERSION;')" || real=""
  check "running image ($image)" "$real"
  nreal="$(docker run --rm --entrypoint node "$(sed -n 's/^FROM \(node:[^ ]*\) .*/\1/p' "$ROOT/docker/web/Dockerfile" | head -n1)" -p process.versions.node)" || nreal=""
  checknode "running web image" "$nreal"
fi

[ "$fail" = 0 ] && echo "PHP is at $MIN_PHP or newer and Node at $MIN_NODE or newer everywhere." || echo "Runtime pins need an update."
exit "$fail"
