#!/usr/bin/env bash
# Tests of the online installer (slice 19c, step E). Plain bash, no framework: run `installers/online/tests/run.sh`.
# They need no Docker and no root: the installer's helpers, its dry run, its refusals and the archive builder.
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ONLINE="$(cd "$HERE/.." && pwd)"
ROOT="$(cd "$ONLINE/../.." && pwd)"
export VOTE_LANG=en MIN_RAM_MB=0 VOTE_TEST_NO_ROOT=1
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASS=0
FAIL=0
t() { # t "name" command...: the command must succeed
  local name="$1"
  shift
  if "$@" >/dev/null 2>&1; then PASS=$((PASS + 1)); printf '  ok   %s\n' "$name"; else FAIL=$((FAIL + 1)); printf '  FAIL %s\n' "$name"; fi
}
nt() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then FAIL=$((FAIL + 1)); printf '  FAIL %s\n' "$name"; else PASS=$((PASS + 1)); printf '  ok   %s\n' "$name"; fi; }
contains() { printf '%s' "$1" | grep -qF -- "$2"; }

. "$ROOT/installers/linux/lib/common.sh"
. "$ROOT/installers/linux/lib/ports.sh"
. "$ROOT/installers/online/lib/online.sh"

echo "validation"
t "a domain is accepted" valid_domain vote.fleuridor.com
nt "a name with no dot is refused" valid_domain localhost
nt "a name with a space is refused" valid_domain "vote fleuridor.com"
nt "a name with an underscore is refused" valid_domain "vote_x.example.com"
t "an email is accepted" valid_email admin@vote.fleuridor.com
nt "an email with no domain is refused" valid_email admin@
t "a port is accepted" valid_port 587
nt "port 0 is refused" valid_port 0
nt "port 70000 is refused" valid_port 70000

echo "the .env"
export ENV_REGISTRY=ghcr.io/jun101 ENV_VERSION=1.2.3 ENV_SITE_URL=https://vote.example.com ENV_SITE_ADDRESS=vote.example.com \
  ENV_HTTP_BIND=0.0.0.0 ENV_HTTP_PORT=80 ENV_HTTPS_BIND=0.0.0.0 ENV_HTTPS_PORT=443 ENV_APP_KEY=base64:abc ENV_AUDIT_PUBLIC=aa \
  ENV_HASH_KEY=bb ENV_DB_ROOT_PASSWORD=r ENV_DB_PASSWORD=d ENV_DB_MIGRATOR_PASSWORD=m ENV_REDIS_PASSWORD=p ENV_MAIL_MAILER=smtp \
  ENV_MAIL_HOST=smtp.example.com ENV_MAIL_PORT=587 ENV_MAIL_SCHEME= ENV_MAIL_USERNAME=u ENV_MAIL_PASSWORD=pw ENV_MAIL_FROM=no-reply@vote.example.com
write_env "$TMP/env"
t "the file is private (0600)" test "$(stat -c %a "$TMP/env")" = 600
t "the version is written" test "$(env_value "$TMP/env" VOTE_VERSION)" = 1.2.3
t "the site address is written" test "$(env_value "$TMP/env" SITE_ADDRESS)" = vote.example.com
t "the proxy network defaults to votesystem_edge" test "$(env_value "$TMP/env" PROXY_NETWORK)" = votesystem_edge
set_env_value "$TMP/env" VOTE_VERSION 2.0.0
t "a value is replaced in place" test "$(env_value "$TMP/env" VOTE_VERSION)" = 2.0.0
t "the other values are kept" test "$(env_value "$TMP/env" DB_PASSWORD)" = d
set_env_value "$TMP/env" NEW_ONE yes
t "a missing value is added" test "$(env_value "$TMP/env" NEW_ONE)" = yes

echo "the domain"
getent() { printf '203.0.113.9 STREAM %s\n' "$2"; }
local_addresses() { printf '127.0.0.1\n203.0.113.9\n'; }
t "a name that resolves to this machine is accepted" domain_points_here vote.example.com
local_addresses() { printf '127.0.0.1\n10.0.0.5\n'; }
nt "a name that resolves elsewhere is reported" domain_points_here vote.example.com
getent() { return 2; }
nt "a name that does not resolve is reported" domain_points_here vote.example.com
unset -f getent local_addresses

echo "the installer"
mkdir -p "$TMP/bin"
# A fake `docker` that is ready, and a fake `ss` that says 80 and 443 are used when asked to (FAKE_BUSY=1).
cat > "$TMP/bin/docker" <<'F'
#!/bin/sh
[ "$1" = --version ] && { echo "Docker version 99.0.0, build fake"; exit 0; }
exit 0
F
cat > "$TMP/bin/ss" <<'F'
#!/bin/sh
if [ "${FAKE_BUSY:-0}" = 1 ]; then case "$*" in *":80"|*":443") echo "LISTEN 0 4096 0.0.0.0:80 0.0.0.0:*"; exit 0 ;; esac; fi
exit 0
F
chmod +x "$TMP/bin/docker" "$TMP/bin/ss"
RUN() { PATH="$TMP/bin:$PATH" VOTE_ONLINE_DIR="$TMP/install" "$ONLINE/install-online.sh" --lang en "$@"; }

out="$(RUN --dry-run --yes --domain vote.example.com --admin-email admin@example.com --version 1.2.3 2>&1)"; rc=$?
t "a dry run succeeds" test "$rc" -eq 0
t "it shows the site address" contains "$out" "site: https://vote.example.com"
t "it shows the version and the registry" contains "$out" "image version: 1.2.3"
nt "it writes nothing" test -e "$TMP/install"

out="$(RUN --yes --dry-run --admin-email admin@example.com --version 1.2.3 2>&1)"; rc=$?
t "--yes without --domain is refused" test "$rc" -ne 0
t "it says why" contains "$out" "--domain is required"

out="$(RUN --yes --dry-run --domain "not a domain" --admin-email admin@example.com --version 1.2.3 2>&1)"; rc=$?
t "an invalid domain is refused" test "$rc" -ne 0

out="$(RUN --yes --dry-run --domain vote.example.com --admin-email nope --version 1.2.3 2>&1)"; rc=$?
t "an invalid admin address is refused" test "$rc" -ne 0

out="$(FAKE_BUSY=1 RUN --yes --dry-run --domain vote.example.com --admin-email admin@example.com --version 1.2.3 2>&1)"; rc=$?
t "busy ports 80 and 443 stop a --yes run" test "$rc" -ne 0
t "and name the way out" contains "$out" "--behind-proxy"

out="$(FAKE_BUSY=1 RUN --yes --dry-run --behind-proxy --domain vote.example.com --admin-email admin@example.com --version 1.2.3 2>&1)"; rc=$?
t "--behind-proxy takes a free local port and succeeds" test "$rc" -eq 0
t "it keeps the public address for the site" contains "$out" "site: https://vote.example.com"
t "it tells how to set the other proxy" contains "$out" "-> http://127.0.0.1:"

out="$(RUN --yes --dry-run --behind-proxy --site-url https://elections.example.org --domain vote.example.com --admin-email admin@example.com --version 1.2.3 2>&1)"
t "--site-url sets the public address" contains "$out" "site: https://elections.example.org"

echo "update"
mkdir -p "$TMP/install"
out="$(RUN update --archive "$TMP/missing.tar.gz" 2>&1)"; rc=$?
t "update with no installation is refused" test "$rc" -ne 0

echo "the archive"
"$ONLINE/build.sh" --version 9.9.9 --out "$TMP/dist" >/dev/null
t "the archive exists" test -f "$TMP/dist/votesystem-online-9.9.9.tar.gz"
t "its checksum verifies" bash -c "cd '$TMP/dist' && sha256sum -c --quiet votesystem-online-9.9.9.tar.gz.sha256"
list="$(tar -tzf "$TMP/dist/votesystem-online-9.9.9.tar.gz")"
for f in install-online.sh VERSION lib/online.sh lib/common.sh lib/ports.sh lib/firewall.sh files/compose.prod.yaml files/Caddyfile files/init-db.sh files/votectl; do
  t "it holds $f" contains "$list" "votesystem-online-9.9.9/$f"
done
nt "it holds no .env" contains "$list" ".env"
nt "a bad version is refused" "$ONLINE/build.sh" --version "../x" --out "$TMP/dist2"
tar -C "$TMP" -xzf "$TMP/dist/votesystem-online-9.9.9.tar.gz"
t "the VERSION file is the version" test "$(cat "$TMP/votesystem-online-9.9.9/VERSION")" = 9.9.9
out="$(PATH="$TMP/bin:$PATH" "$TMP/votesystem-online-9.9.9/install-online.sh" --lang en --dry-run --yes --domain vote.example.com --admin-email a@example.com 2>&1)"; rc=$?
t "the unpacked installer runs a dry run from its own folder" test "$rc" -eq 0
t "and takes the archive's version" contains "$out" "image version: 9.9.9"

echo
echo "the update picks its archive"
# An installation (a .env) in a folder of its own; the update stops at the backup (no votectl there), after the choice is printed.
UPD="$TMP/upd"
mkdir -p "$UPD"
printf 'VOTE_VERSION=9.0.0\n' > "$UPD/.env"
# From the unpacked archive, no option: this archive is the new version, nothing is downloaded.
out="$(VOTE_ONLINE_DIR="$UPD" VOTE_TEST_NO_ROOT=1 VOTE_LANG=en "$TMP/votesystem-online-9.9.9/install-online.sh" update 2>&1)"
t "from the unpacked archive it uses that archive" contains "$out" "Using the unpacked archive"
nt "and downloads nothing" contains "$out" "Downloading version"
# From the unpacked archive with --version equal to its own: still that archive.
out="$(VOTE_ONLINE_DIR="$UPD" VOTE_TEST_NO_ROOT=1 VOTE_LANG=en "$TMP/votesystem-online-9.9.9/install-online.sh" update --version 9.9.9 2>&1)"
t "--version equal to the archive's own uses the archive" contains "$out" "Using the unpacked archive"
# From the unpacked archive with another version: that version is downloaded.
out="$(VOTE_ONLINE_DIR="$UPD" VOTE_TEST_NO_ROOT=1 VOTE_LANG=en timeout 20 "$TMP/votesystem-online-9.9.9/install-online.sh" update --version 9.8.7 2>&1)"
t "another --version is honoured (it is downloaded)" contains "$out" "Downloading version 9.8.7"
# From the copy installed in the installation's own folder, a version is asked for: it is downloaded, not the local files.
mkdir -p "$UPD/installer" && cp -a "$TMP/votesystem-online-9.9.9/." "$UPD/installer/"
out="$(VOTE_ONLINE_DIR="$UPD" VOTE_TEST_NO_ROOT=1 VOTE_LANG=en timeout 20 "$UPD/installer/install-online.sh" update --version 9.8.7 2>&1)"
t "from the installed copy, --version is downloaded" contains "$out" "Downloading version 9.8.7"
nt "and the installed copy is not taken for a new archive" contains "$out" "Using the unpacked archive"

echo
printf '%s passed, %s failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
