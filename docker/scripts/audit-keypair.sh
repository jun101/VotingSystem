#!/bin/sh
# Creates the key pair of the vote audit records (SPEC FR-SEC-08) when .env has no
# public key yet. The public key goes in .env. The private key is written OUTSIDE the
# repository and is never given to the containers: the server can write audit records
# and cannot read them.
#
# This is for development and CI. The production pair is made on the operator's own
# machine and only its public key is sent to the server (SPEC NFR-SEC-09).
set -eu

ENV_FILE="${ENV_FILE:-.env}"
NAME=VOTE_AUDIT_PUBLIC_KEY

# The temporary copy of the env file is removed whatever happens.
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT

current=$(sed -n "s/^$NAME=//p" "$ENV_FILE" | head -n 1)
[ -n "$current" ] && exit 0

out="${VOTE_AUDIT_PRIVATE_KEY_FILE:-$HOME/.config/new-voting-system/vote-audit-dev.private}"

# Absolute form of a path that may not exist yet (symbolic links resolved as far as it does).
absolute() {
  dir=$(dirname "$1")
  rest=$(basename "$1")
  while [ ! -d "$dir" ]; do
    rest="$(basename "$dir")/$rest"
    dir=$(dirname "$dir")
  done
  printf '%s/%s\n' "$(cd "$dir" && pwd -P)" "$rest"
}

# The private key must never sit in the repository, where a commit or an image could take it.
repo=$(pwd -P)
case "$(absolute "$out")" in
  "$repo"/*)
    echo "Refusing to write the private audit key inside the repository ($repo)." >&2
    echo "Set VOTE_AUDIT_PRIVATE_KEY_FILE to a path outside it." >&2
    exit 1
    ;;
esac

if [ -e "$out" ]; then
  echo "A private key already exists at $out, but $ENV_FILE has no $NAME." >&2
  echo "This happens on a second clone of the project on the same machine: the first clone" >&2
  echo "made that key. Either point VOTE_AUDIT_PRIVATE_KEY_FILE at a new path for this clone," >&2
  echo "or put the public key of the existing pair in $ENV_FILE (see README.md)." >&2
  exit 1
fi

image=$(sed -n 's/^FROM \([^ ]*\).*/\1/p' docker/api/Dockerfile | head -n 1)
pair=$(docker run --rm "$image" php -r '$k = sodium_crypto_box_keypair(); echo bin2hex(sodium_crypto_box_publickey($k)), " ", bin2hex(sodium_crypto_box_secretkey($k));')
public=${pair%% *}
private=${pair##* }

if [ ${#public} -ne 64 ] || [ ${#private} -ne 64 ]; then
  echo "Could not generate the audit key pair." >&2
  exit 1
fi

# Only a folder made here is given a mode: a folder that already existed is not ours.
keydir=$(dirname "$out")
if [ ! -d "$keydir" ]; then
  mkdir -p "$keydir"
  chmod 700 "$keydir"
fi
( umask 077 && printf '%s\n' "$private" > "$out" )

if grep -q "^$NAME=" "$ENV_FILE"; then
  awk -v n="$NAME" -v v="$public" 'index($0, n"=")==1 {print n"="v; next} {print}' "$ENV_FILE" > "$tmp"
else
  cat "$ENV_FILE" > "$tmp"
  printf '%s=%s\n' "$NAME" "$public" >> "$tmp"
fi
cat "$tmp" > "$ENV_FILE"

echo "set $NAME"
echo "The private audit key is in $out. Keep it out of the repository and off any server."
