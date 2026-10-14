#!/usr/bin/env bash
# Runs inside a test VM, as root, started by cloud-init (see vm-test.sh): installs the school edition from the bundle on the
# VM's data disk, checks it from the outside, runs the vote flow, then the lifecycle (update, failed update with rollback,
# same version refused, uninstall). Everything is printed to the console; the last line is "RESULT: PASS" or "RESULT: FAIL".
set -u
DATA="${1:-/mnt/vs}"
W=/root/vt
CRED=/root/votesystem-identifiants.txt
KEY=/root/votesystem-cle-audit-A-EMPORTER.txt
fail=0
say() { printf '\n== %s\n' "$*"; }
check() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then echo "PASS  $name"; else echo "FAIL  $name"; fail=$((fail + 1)); fi; }
health() { curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/api/v1/health; }

say "machine"
. /etc/os-release; echo "$PRETTY_NAME, kernel $(uname -r), $(nproc) CPUs, $(free -m | awk '/Mem:/{print $2}') MB"

say "bundle"
rm -rf "$W"; mkdir -p "$W"; cd "$W"
tar -xzf "$DATA"/bundle.tar.gz && mv votesystem-* vs
echo "version: $(cat vs/VERSION)"
( cd vs && sha256sum -c SHA256SUMS --quiet 2>&1 | head -3; echo "checksums: $?" )

say "dry run changes nothing"
./vs/installers/install.sh --dry-run --yes --lang en 2>&1 | tail -12
check "no /opt/votesystem after a dry run" test ! -e /opt/votesystem

say "install"
START=$(date +%s)
./vs/installers/install.sh --yes --lang en --school "Collège Saint-Louis" --owner-email directeur@ecole.local \
  --admin-email admin@ecole.local --credentials-file "$CRED" 2>&1 | tail -25
echo "install took $(( $(date +%s) - START )) s"

say "services and files"
for s in mariadb redis api queue scheduler web proxy; do check "service $s active" systemctl is-active --quiet votesystem-$s; done
check "backup timer active" systemctl is-active --quiet votesystem-backup.timer
check "services enabled at boot" systemctl is-enabled --quiet votesystem-api votesystem-proxy votesystem-mariadb
check "credentials file is 0600" test "$(stat -c %a "$CRED")" = 600
check "audit key file is 0600" test "$(stat -c %a "$KEY")" = 600
check "env file is not world readable" test "$(( $(stat -c %a /etc/votesystem/env) % 10 ))" = 0
check "private audit key is not in the server config" bash -c "! grep -rqF \"\$(sed -n 3p $KEY)\" /etc/votesystem /opt/votesystem/releases 2>/dev/null"
check "login page 200" test "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/login)" = 200
check "register page absent in school mode (404)" test "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/register)" = 404
check "health 200" test "$(health)" = 200
echo "listening:"; ss -ltn | awk 'NR==1 || /:80 |127.0.0.1/' | head -12
echo "firewall:"; (ufw status 2>/dev/null || nft list ruleset 2>/dev/null | grep -i "dport 80" || iptables -S 2>/dev/null | grep -i "dport 80" || echo "none active") | head -5

say "votectl"
votectl version
votectl status | head -12
check "votectl user list shows the owner" bash -c "votectl user list | grep -q directeur@ecole.local"
check "votectl backup makes an archive and its checksum" bash -c "votectl backup >/dev/null && f=\$(ls -1t /var/backups/votesystem/votesystem-backup-*.tar | head -1) && test -s \$f && test -s \$f.sha256"

say "vote flow"
python3 "$DATA"/vm-flow.py "$CRED" || fail=$((fail + 1))

say "after a restart of the services"
systemctl restart votesystem-api votesystem-web votesystem-proxy; sleep 8
check "health 200 after restart" test "$(health)" = 200

say "update, failed update, same version, uninstall"
rm -rf "$W/vs2" "$W/vs3"
cp -a "$W/vs" "$W/vs2"; echo 0.1.1-test > "$W/vs2/VERSION"
"$W"/vs2/installers/install.sh update --lang en 2>&1 | tail -4
check "updated to 0.1.1-test" test "$(votectl version)" = 0.1.1-test
check "health 200 after update" test "$(health)" = 200
cp -a "$W/vs2" "$W/vs3"; echo 0.1.2-test > "$W/vs3/VERSION"
printf '<?php\nthrow new Exception("boom");\n' > "$W/vs3/api/database/migrations/2099_01_01_000000_boom.php"
"$W"/vs3/installers/install.sh update --lang en 2>&1 | tail -6
check "failed update went back to 0.1.1-test" test "$(votectl version)" = 0.1.1-test
check "health 200 after rollback" test "$(health)" = 200
"$W"/vs2/installers/install.sh update --lang en 2>&1 | tail -2
check "same version refused (still 0.1.1-test)" test "$(votectl version)" = 0.1.1-test
/opt/votesystem/installer/install.sh uninstall --lang en 2>&1 | tail -3
check "no votesystem unit left" test -z "$(systemctl list-unit-files 'votesystem-*' --no-legend 2>/dev/null)"
check "data kept after uninstall" test -d /var/lib/votesystem
check "votectl link removed" test ! -e /usr/local/bin/votectl

echo
if [ "$fail" -eq 0 ]; then echo "RESULT: PASS"; else echo "RESULT: FAIL ($fail)"; fi
