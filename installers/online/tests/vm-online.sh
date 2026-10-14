#!/usr/bin/env bash
# Starts a fresh QEMU/KVM Debian VM, installs the online edition (Docker, Caddy with a self-signed certificate for a
# test domain) from the release archive, and leaves it RUNNING so that scans can be pointed at it from this machine:
#
#   vm-online.sh IMAGE ARCHIVE.tar.gz WORKDIR [--domain NAME] [--https-port N] [--ram MB] [--cpus N]
#
# No SSH: the files travel on a read-only disk, the result comes back on the serial console (WORKDIR/console.log),
# and the VM's ports 80 and 443 are forwarded to 127.0.0.1 only. Stop it with: kill $(cat WORKDIR/qemu.pid)
set -eu
PATH="$PATH:/sbin:/usr/sbin"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
IMAGE="${1:?image}"; ARCHIVE="${2:?archive}"; WORK="${3:?workdir}"; shift 3
DOMAIN=vote.test; HTTPS_PORT=8443; HTTP_PORT=8081; RAM=4096; CPUS=2
while [ $# -gt 0 ]; do
  case "$1" in
    --domain) shift; DOMAIN="$1" ;; --https-port) shift; HTTPS_PORT="$1" ;; --http-port) shift; HTTP_PORT="$1" ;;
    --ram) shift; RAM="$1" ;; --cpus) shift; CPUS="$1" ;;
    *) echo "vm-online.sh: unknown option $1" >&2; exit 2 ;;
  esac
  shift
done
mkdir -p "$WORK/seed" "$WORK/data"
cp "$ARCHIVE" "$WORK/data/online.tar.gz"
cp "$HERE/vm-online-run.sh" "$WORK/data/"
echo "$DOMAIN" > "$WORK/data/domain"
cat > "$WORK/seed/meta-data" <<M
instance-id: vs-online-$(date +%s)
local-hostname: votetest
M
cat > "$WORK/seed/user-data" <<'U'
#cloud-config
ssh_pwauth: false
runcmd:
  - [ bash, -c, "mkdir -p /mnt/vs && mount -r -L VSDATA /mnt/vs && (bash /mnt/vs/vm-online-run.sh /mnt/vs 2>&1 | tee /dev/console); echo VOTESYSTEM-ONLINE-READY > /dev/console" ]
U
size=$(( $(du -sm "$WORK/data" | cut -f1) + 64 ))
truncate -s "${size}M" "$WORK/data.img"
mke2fs -q -t ext4 -L VSDATA -d "$WORK/data" "$WORK/data.img"
qemu-img create -q -f qcow2 -b "$(readlink -f "$IMAGE")" -F qcow2 "$WORK/disk.qcow2" 30G
nohup qemu-system-x86_64 -enable-kvm -cpu host -smp "$CPUS" -m "$RAM" -display none -no-reboot \
  -serial "file:$WORK/console.log" \
  -nic "user,model=virtio-net-pci,hostfwd=tcp:127.0.0.1:$HTTPS_PORT-:443,hostfwd=tcp:127.0.0.1:$HTTP_PORT-:80" \
  -drive "file=$WORK/disk.qcow2,if=none,id=sys,format=qcow2" -device virtio-blk-pci,drive=sys,bootindex=1 \
  -blockdev "driver=vvfat,dir=$WORK/seed,label=CIDATA,fat-type=16,rw=off,read-only=on,node-name=seed" -device virtio-blk-pci,drive=seed \
  -drive "file=$WORK/data.img,if=none,id=data,format=raw,readonly=on" -device virtio-blk-pci,drive=data \
  >"$WORK/qemu.out" 2>&1 &
echo $! > "$WORK/qemu.pid"
echo "VM started (pid $(cat "$WORK/qemu.pid")); console: $WORK/console.log; https://127.0.0.1:$HTTPS_PORT (Host: $DOMAIN)"
