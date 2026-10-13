#!/usr/bin/env bash
# Downloads the upstream files of sources.lock into cache/ and checks each one:
#   - against the publisher's own hash list when the lock names one (a mismatch stops everything), and
#   - against the sha256 pinned in the lock (a pinned file that changed stops everything).
# `--pin` writes the hash of a file whose pin is still empty, after the publisher check passed.
#
#   fetch.sh [--pin] [--cache DIR]
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCK="$HERE/sources.lock"
CACHE="${VOTE_BUNDLE_CACHE:-$HERE/cache}"
PIN=0
while [ $# -gt 0 ]; do
  case "$1" in
    --pin) PIN=1 ;;
    --cache) shift; CACHE="${1:?--cache needs a folder}" ;;
    *) echo "fetch.sh: unknown option $1" >&2; exit 1 ;;
  esac
  shift
done
mkdir -p "$CACHE"

fail() { echo "fetch.sh: $*" >&2; exit 1; }

# publisher_hash SPEC FILE: the sha256 the publisher states for FILE, or empty.
publisher_hash() {
  local spec="$1" file="$2"
  case "$spec" in
    -|"") return 0 ;;
    github-digest:*)
      local repo tag
      repo="$(echo "${spec#github-digest:}" | cut -d: -f1)"; tag="$(echo "${spec#github-digest:}" | cut -d: -f2)"
      curl -fsSL "https://api.github.com/repos/$repo/releases/tags/$tag" \
        | python3 -c "import json,sys;d=json.load(sys.stdin);print(next((a.get('digest','').replace('sha256:','') for a in d['assets'] if a['name']==sys.argv[1]),''))" "$file" ;;
    github-redis-hashes:*)
      curl -fsSL "https://raw.githubusercontent.com/redis/redis-hashes/master/README" | awk -v f="${spec#github-redis-hashes:}" '$1=="hash" && $2==f {print $4}' ;;
    http*)
      curl -fsSL "$spec" | awk -v f="$file" '{n=$2; sub(/^\*/,"",n); if (n==f || n ~ ("/" f "$")) {print $1; exit}}' ;;
  esac
}

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
changed=0

while IFS='|' read -r name version file url hashlist pinned; do
  name="$(echo "$name" | xargs)"
  case "$name" in ''|\#*) continue ;; esac
  version="$(echo "$version" | xargs)"; file="$(echo "$file" | xargs)"; url="$(echo "$url" | xargs)"
  hashlist="$(echo "$hashlist" | xargs)"; pinned="$(echo "$pinned" | xargs)"

  target="$CACHE/$file"
  if [ ! -f "$target" ]; then
    echo "fetch  $name $version  $file"
    curl -fsSL --retry 3 --retry-delay 3 -C - -o "$target.part" "$url" || fail "download of $file failed"
    mv "$target.part" "$target"
  else
    echo "have   $name $version  $file"
  fi

  actual="$(sha256sum "$target" | awk '{print $1}')"
  stated="$(publisher_hash "$hashlist" "$file")"

  if [ -n "$stated" ] && [ "$stated" != "$actual" ]; then
    rm -f "$target"
    fail "$file does not match the publisher's hash ($stated): removed"
  fi
  if [ -z "$stated" ] && [ "$hashlist" != "-" ] && [ -n "$hashlist" ]; then
    echo "warn   $name: the publisher's hash list had no entry for $file" >&2
  fi
  if [ -n "$pinned" ] && [ "$pinned" != "$actual" ]; then
    fail "$file changed since it was pinned ($pinned): stopped"
  fi
  if [ -z "$pinned" ]; then
    if [ "$PIN" = 1 ]; then
      pinned="$actual"; changed=1
      echo "pinned $name $version  $actual${stated:+  (publisher: same)}"
    else
      fail "$file is not pinned yet: run fetch.sh --pin once, after reading the lines above"
    fi
  else
    echo "ok     $name $version  $actual${stated:+  (publisher: same)}"
  fi
  printf '%s | %s | %s | %s | %s | %s\n' "$name" "$version" "$file" "$url" "$hashlist" "$pinned" >> "$tmp"
done < <(grep -v '^#' "$LOCK")

if [ "$changed" = 1 ]; then
  { grep '^#' "$LOCK"; cat "$tmp"; } > "$LOCK.new" && mv "$LOCK.new" "$LOCK"
  echo "sources.lock updated"
fi
