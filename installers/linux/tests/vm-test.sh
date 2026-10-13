#!/usr/bin/env bash
# Runs the whole installer test in a fresh QEMU/KVM virtual machine of a cloud image, unattended:
#
#   vm-test.sh IMAGE BUNDLE.tar.gz [--ram MB] [--cpus N] [--timeout SECONDS] [--keep]
#
# The test files travel on a read-only ext4 disk image; cloud-init runs vm-run.sh and the result comes back through the
# serial console, so nothing listens on this machine and no SSH is needed. Prints the console and exits 0 on PASS.
# Images: Debian genericcloud and Ubuntu server cloud images (qcow2).
set -eu
PATH="$PATH:/sbin:/usr/sbin"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
IMAGE="${1:?image}"; BUNDLE="${2:?bundle}"; shift 2
RAM=5120; CPUS=2; TIMEOUT=2400; KEEP=0
while [ $# -gt 0 ]; do
  case "$1" in
    --ram) shift; RAM="$1" ;; --cpus) shift; CPUS="$1" ;; --timeout) shift; TIMEOUT="$1" ;; --keep) KEEP=1 ;;
    *) echo "vm-test.sh: unknown option $1" >&2; exit 2 ;;
  esac
  shift
done
WORK="$(mktemp -d "${TMPDIR:-/tmp}/vs-vmtest.XXXXXX")"
trap '[ "$KEEP" = 1 ] || rm -rf "$WORK"' EXIT
mkdir -p "$WORK/seed" "$WORK/data"
cp "$BUNDLE" "$WORK/data/bundle.tar.gz"
cp "$HERE/vm-run.sh" "$HERE/vm-flow.py" "$WORK/data/"
cat > "$WORK/seed/meta-data" <<M
instance-id: vs-test-$(date +%s)
local-hostname: votetest
M
cat > "$WORK/seed/user-data" <<'U'
#cloud-config
ssh_pwauth: false
runcmd:
  - [ bash, -c, "mkdir -p /mnt/vs && mount -r -L VSDATA /mnt/vs && (bash /mnt/vs/vm-run.sh /mnt/vs 2>&1 | tee /dev/console); echo VOTESYSTEM-TEST-DONE > /dev/console; sleep 2; poweroff" ]
U
# The files on a small ext4 image made without root (mke2fs -d); the seed is tiny, so a virtual FAT disk is fine for it.
size=$(( $(du -sm "$WORK/data" | cut -f1) + 64 ))
truncate -s "${size}M" "$WORK/data.img"
mke2fs -q -t ext4 -L VSDATA -d "$WORK/data" "$WORK/data.img"
qemu-img create -q -f qcow2 -b "$(readlink -f "$IMAGE")" -F qcow2 "$WORK/disk.qcow2" 20G
qemu-system-x86_64 -enable-kvm -cpu host -smp "$CPUS" -m "$RAM" -display none -no-reboot \
  -serial "file:$WORK/console.log" -nic user,model=virtio-net-pci \
  -drive "file=$WORK/disk.qcow2,if=none,id=sys,format=qcow2" -device virtio-blk-pci,drive=sys,bootindex=1 \
  -blockdev "driver=vvfat,dir=$WORK/seed,label=CIDATA,fat-type=16,rw=off,read-only=on,node-name=seed" -device virtio-blk-pci,drive=seed \
  -drive "file=$WORK/data.img,if=none,id=data,format=raw,readonly=on" -device virtio-blk-pci,drive=data &
QPID=$!
end=$(( $(date +%s) + TIMEOUT ))
while kill -0 "$QPID" 2>/dev/null; do
  grep -q VOTESYSTEM-TEST-DONE "$WORK/console.log" 2>/dev/null && break
  [ "$(date +%s)" -lt "$end" ] || { echo "vm-test.sh: timeout after ${TIMEOUT}s" >&2; kill "$QPID" 2>/dev/null; break; }
  sleep 5
done
sleep 3; kill "$QPID" 2>/dev/null || true; wait "$QPID" 2>/dev/null || true
sed -n '/== machine/,/VOTESYSTEM-TEST-DONE/p' "$WORK/console.log" | tr -d '\r'
[ "$KEEP" = 1 ] && echo "console log kept in $WORK/console.log"
grep -q "RESULT: PASS" "$WORK/console.log"
