#!/bin/sh
# Creates the key pair of the vote audit records (SPEC FR-SEC-08) when .env has no
# public key yet. The public key goes in .env. The private key is written OUTSIDE the
# repository and is never given to the containers: the server can write audit records
# and cannot read them.
#
# This is for development and CI. The production pair is made on the operator's own
# machine and only its public key is sent to the server (SPEC NFR-SEC-09).
set -eu

ENV_FILE=.env
NAME=VOTE_AUDIT_PUBLIC_KEY

current=$(sed -n "s/^$NAME=//p" "$ENV_FILE" | head -n 1)
[ -n "$current" ] && exit 0

out="${VOTE_AUDIT_PRIVATE_KEY_FILE:-$HOME/.config/new-voting-system/vote-audit-dev.private}"
if [ -e "$out" ]; then
  echo "A private key already exists at $out but .env has no $NAME." >&2
  echo "Move that file away, or put its public key in .env, then run again." >&2
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

mkdir -p "$(dirname "$out")"
chmod 700 "$(dirname "$out")"
( umask 077 && printf '%s\n' "$private" > "$out" )

tmp=$(mktemp)
if grep -q "^$NAME=" "$ENV_FILE"; then
  awk -v n="$NAME" -v v="$public" 'index($0, n"=")==1 {print n"="v; next} {print}' "$ENV_FILE" > "$tmp"
else
  cat "$ENV_FILE" > "$tmp"
  printf '%s=%s\n' "$NAME" "$public" >> "$tmp"
fi
cat "$tmp" > "$ENV_FILE"
rm -f "$tmp"

echo "set $NAME"
echo "The private audit key is in $out. Keep it out of the repository and off any server."
