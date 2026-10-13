#!/usr/bin/env bats
# Runs inside the test VM (Debian 13): bats installer.bats
# The firewall tests use ufw and only touch the VM.

setup() {
  ROOT="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"
  export VOTE_LANG=en
  . "$ROOT/lib/common.sh"; . "$ROOT/lib/preflight.sh"; . "$ROOT/lib/ports.sh"; . "$ROOT/lib/firewall.sh"
  export FIREWALL_RECORD="$BATS_TEST_TMPDIR/fw.rules"
  OCCUPIED=()
}

teardown() { for pid in "${OCCUPIED[@]}"; do kill "$pid" 2>/dev/null || true; done; }

occupy() { python3 -c "import socket,time;s=socket.socket();s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1);s.bind(('0.0.0.0',$1));s.listen();time.sleep(30)" & OCCUPIED+=("$!"); sleep 0.5; }

@test "a free port is returned as it is" {
  run next_free_port 38080
  [ "$status" -eq 0 ]; [ "$output" = 38080 ]
}

@test "a busy port gives the next free one" {
  occupy 38081
  run next_free_port 38081
  [ "$output" = 38082 ]
}

@test "two busy ports in a row are both skipped" {
  occupy 38091; occupy 38092
  run next_free_port 38091
  [ "$output" = 38093 ]
}

@test "a port already chosen in the same run is skipped" {
  run next_free_port 38100 38100 38101
  [ "$output" = 38102 ]
}

@test "random internal ports are free and distinct" {
  a="$(random_free_port)"; b="$(random_free_port "$a")"
  [ "$a" != "$b" ]; ! port_in_use "$a"; ! port_in_use "$b"
}

@test "a supported system passes the check" {
  printf 'ID=ubuntu\nVERSION_ID="24.04"\n' > "$BATS_TEST_TMPDIR/os"
  OS_RELEASE_FILE="$BATS_TEST_TMPDIR/os" run preflight_os
  [ "$status" -eq 0 ]
}

@test "an unsupported system is refused" {
  printf 'ID=ubuntu\nVERSION_ID="20.04"\n' > "$BATS_TEST_TMPDIR/os"
  OS_RELEASE_FILE="$BATS_TEST_TMPDIR/os" run preflight_os
  [ "$status" -eq 1 ]
}

@test "arm64 is refused and x86_64 accepted" {
  ARCH_OVERRIDE=aarch64 run preflight_arch; [ "$status" -eq 1 ]
  ARCH_OVERRIDE=x86_64 run preflight_arch; [ "$status" -eq 0 ]
}

@test "too little memory is refused" {
  printf 'MemTotal:        2097152 kB\n' > "$BATS_TEST_TMPDIR/mem"
  MEMINFO_FILE="$BATS_TEST_TMPDIR/mem" run preflight_ram
  [ "$status" -eq 1 ]
}

@test "a dry run changes nothing and opens no port" {
  before="$(ufw status 2>/dev/null | md5sum)"
  run "$ROOT/install.sh" --dry-run --lang en --web-port 38200
  [ "$status" -eq 0 ]
  [[ "$output" == *"Dry run finished"* ]]
  [ "$before" = "$(ufw status 2>/dev/null | md5sum)" ]
}

@test "a dry run reports a busy web port and uses the next one" {
  occupy 38300
  run "$ROOT/install.sh" --dry-run --lang en --web-port 38300
  [[ "$output" == *"port 38300 is busy, port 38301 will be used"* ]]
}

@test "ufw: detected, the rule is added for a local network, then removed" {
  [ "$(id -u)" -eq 0 ] || skip "needs root"
  command -v ufw >/dev/null || skip "ufw not installed"
  ufw allow 22/tcp >/dev/null; ufw --force enable >/dev/null
  [ "$(firewall_detect)" = ufw ]
  firewall_open ufw 38400 10.0.2.0/24
  ufw status | grep -q '38400.*10.0.2.0/24'
  grep -q 'ufw 38400 10.0.2.0/24' "$FIREWALL_RECORD"
  firewall_close
  ! ufw status | grep -q 38400
  [ ! -s "$FIREWALL_RECORD" ]
}
