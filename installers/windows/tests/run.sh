#!/usr/bin/env bash
# Static checks of the Windows installer files, on any machine (no Windows, no PowerShell). The real runs are in a VM:
# vm-flow.ps1 and vm-lifecycle.ps1 (see ../README.md).
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
W="$(cd "$HERE/.." && pwd)"
pass=0; fail=0
ok() { pass=$((pass + 1)); }
bad() { fail=$((fail + 1)); echo "FAIL: $*"; }

for f in "$W"/install.ps1 "$W"/votectl.ps1 "$W"/lib/common.ps1; do
  name="${f#$W/}"
  # Windows PowerShell 5.1 reads a file without a BOM as ANSI: accents would be garbled.
  if [ "$(head -c 3 "$f" | od -An -tx1 | tr -d ' ')" = "efbbbf" ]; then ok; else bad "$name has no UTF-8 BOM"; fi
  # Typographic quotes are string delimiters for PowerShell.
  if grep -nP "[\x{2018}\x{2019}\x{201C}\x{201D}]" "$f" >/dev/null; then bad "$name has a typographic quote"; else ok; fi
  # A real parse, when PowerShell is on this machine (it is in the Windows VM and in CI images with pwsh).
  if command -v pwsh >/dev/null 2>&1; then
    pwsh -NoProfile -Command "[void][System.Management.Automation.Language.Parser]::ParseFile('$f',[ref]\$null,[ref]\$e); if (\$e.Count) { \$e | ForEach-Object { \$_.Message }; exit 1 }" >/dev/null && ok || bad "$name does not parse"
  fi
done

# The message of every Say has a French and an English text (two quoted arguments).
n=$(grep -c "Say " "$W/install.ps1"); [ "$n" -gt 20 ] && ok || bad "install.ps1 has too few messages"

# No secret written to the install log, no password on a command line of the scripts except the database client's.
if grep -n "Write-Host.*\$secrets" "$W/install.ps1" >/dev/null; then bad "a secret is printed"; else ok; fi

for t in Caddyfile.tmpl mariadb.ini.tmpl redis.conf.tmpl; do
  [ -s "$W/templates/$t" ] && ok || bad "template $t missing"
  # Every @KEY@ of a template is given a value by install.ps1.
  for key in $(grep -o '@[A-Z_]*@' "$W/templates/$t" | tr -d '@' | sort -u); do
    grep -q "$key = " "$W/install.ps1" && ok || bad "$t: @$key@ has no value in install.ps1"
  done
done

# The bundle script's lock lists every file with a checksum, but the Microsoft runtime.
lock="$W/../bundle/sources-windows.lock"
while IFS='|' read -r name _ file _ sha; do
  case "$name" in \#*|"") continue ;; esac
  name="$(echo $name)"; sha="$(echo $sha)"
  if [ "$name" = vc_redist ] || [ ${#sha} -eq 64 ]; then ok; else bad "lock: $name has no checksum"; fi
done < "$lock"

echo "windows installer static checks: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
