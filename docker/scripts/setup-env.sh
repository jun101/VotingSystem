#!/bin/sh
# Creates .env from .env.example, then generates the secrets that are missing.
# A value already set in .env is never replaced. Nothing secret is printed.
# Runs in a throwaway container: only Docker is needed on the machine.
set -eu

ENV_FILE=.env
EXAMPLE=.env.example

[ -f "$ENV_FILE" ] || cp "$EXAMPLE" "$ENV_FILE"
chmod 600 "$ENV_FILE"

hex() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

generate() {
  case "$1" in
    APP_KEY) echo "base64:$(head -c 32 /dev/urandom | base64)" ;;
    CREDENTIAL_HASH_KEY) hex 32 ;;
    DB_ROOT_PASSWORD | DB_PASSWORD | DB_MIGRATOR_PASSWORD | REDIS_PASSWORD) hex 24 ;;
    HOST_UID) id -u ;;
    HOST_GID) id -g ;;
    *) return 1 ;;
  esac
}

# Variables of the example, in order.
names=$(sed -n 's/^\([A-Z][A-Z0-9_]*\)=.*/\1/p' "$EXAMPLE")

for name in $names; do
  current=$(sed -n "s/^$name=//p" "$ENV_FILE" | head -n 1)
  if grep -q "^$name=" "$ENV_FILE"; then
    # Present: only an empty value that we know how to generate is filled in.
    if [ -z "$current" ] && value=$(generate "$name"); then
      tmp=$(mktemp)
      awk -v n="$name" -v v="$value" 'BEGIN{done=0} index($0, n"=")==1 && !done {print n"="v; done=1; next} {print}' "$ENV_FILE" > "$tmp"
      cat "$tmp" > "$ENV_FILE"
      rm -f "$tmp"
      echo "set $name"
    fi
  else
    # Absent: copy the example's line, with a generated value when it has none.
    line=$(grep "^$name=" "$EXAMPLE" | head -n 1)
    if [ "$line" = "$name=" ] && value=$(generate "$name"); then
      line="$name=$value"
    fi
    printf '%s\n' "$line" >> "$ENV_FILE"
    echo "added $name"
  fi
done
