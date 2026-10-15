#!/usr/bin/env bash
# Runs inside the test VM as root (see vm-online.sh): installs the online edition with a self-signed certificate
# (Caddy `local_certs`), then checks it from inside. The VM stays up afterwards.
set -u
DATA="${1:-/mnt/vs}"
DOMAIN="$(cat "$DATA/domain")"
fail=0
say() { printf '\n== %s\n' "$*"; }
check() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then echo "PASS  $name"; else echo "FAIL  $name"; fail=$((fail + 1)); fi; }
say "machine"; . /etc/os-release; echo "$PRETTY_NAME, $(nproc) CPUs, $(free -m | awk '/Mem:/{print $2}') MB"
echo "127.0.0.1 $DOMAIN" >> /etc/hosts
say "archive (Caddyfile patched: local_certs = self-signed)"
mkdir -p /root/on && cd /root/on && tar -xzf "$DATA/online.tar.gz" && cd votesystem-online-* || exit 1
sed -i '0,/admin off/s//admin off\n\tlocal_certs/' files/Caddyfile
head -9 files/Caddyfile
say "install"
START=$(date +%s)
./install-online.sh install --yes --lang en --domain "$DOMAIN" --admin-email admin@vote.test --skip-mail \
  --credentials-file /root/votesystem-identifiants.txt 2>&1 | tail -30
echo "install took $(( $(date +%s) - START )) s"
say "checks"
H=(--resolve "$DOMAIN:443:127.0.0.1" -k)
check "health over https" test "$(curl -s "${H[@]}" -o /dev/null -w '%{http_code}' https://$DOMAIN/api/v1/health)" = 200
check "http redirects to https" test "$(curl -s -o /dev/null -w '%{http_code}' --resolve $DOMAIN:80:127.0.0.1 http://$DOMAIN/)" = 308
check "HSTS header" bash -c "curl -sIk --resolve $DOMAIN:443:127.0.0.1 https://$DOMAIN/ | grep -qi '^strict-transport-security: max-age=63072000'"
docker ps --format '{{.Names}} {{.Status}}'
echo "RESULT: $([ $fail -eq 0 ] && echo PASS || echo FAIL) ($fail failures)"
