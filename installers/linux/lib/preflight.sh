# Checks before anything is written. Each check prints a line and returns 1 on failure;
# preflight_run runs all of them and fails if one did.

OS_RELEASE_FILE="${OS_RELEASE_FILE:-/etc/os-release}"
MIN_RAM_MB="${MIN_RAM_MB:-3500}"   # "4 GB" machines report a little less than 4096
MIN_DISK_GB="${MIN_DISK_GB:-10}"

preflight_os() {
  local id version_id
  id="$(. "$OS_RELEASE_FILE" 2>/dev/null; printf '%s' "${ID:-}")"
  version_id="$(. "$OS_RELEASE_FILE" 2>/dev/null; printf '%s' "${VERSION_ID:-}")"
  case "$id:$version_id" in
    debian:12|debian:13|ubuntu:24.04|ubuntu:26.04)
      say "  système : $id $version_id (pris en charge)" "  system: $id $version_id (supported)" ;;
    *)
      warn "  système : ${id:-inconnu} ${version_id:-?} n'est pas pris en charge (Debian 12 ou 13, Ubuntu 24.04 ou 26.04)" \
           "  system: ${id:-unknown} ${version_id:-?} is not supported (Debian 12 or 13, Ubuntu 24.04 or 26.04)"
      return 1 ;;
  esac
}

preflight_arch() {
  local arch="${ARCH_OVERRIDE:-$(uname -m)}"
  if [ "$arch" = x86_64 ]; then say "  processeur : x86_64" "  cpu: x86_64"; return 0; fi
  warn "  processeur : $arch n'est pas pris en charge (x86_64 seulement)" "  cpu: $arch is not supported (x86_64 only)"
  return 1
}

preflight_ram() {
  local mb
  mb="$(awk '/MemTotal/ {print int($2/1024)}' "${MEMINFO_FILE:-/proc/meminfo}")"
  if [ "$mb" -ge "$MIN_RAM_MB" ]; then say "  mémoire : ${mb} Mo" "  memory: ${mb} MB"; return 0; fi
  warn "  mémoire : ${mb} Mo, il en faut au moins 4 Go" "  memory: ${mb} MB, at least 4 GB is needed"
  return 1
}

preflight_disk() {
  local dir="${1:-/opt}" gb
  [ -d "$dir" ] || dir=/
  gb="$(df -Pk "$dir" | awk 'NR==2 {print int($4/1024/1024)}')"
  if [ "$gb" -ge "$MIN_DISK_GB" ]; then say "  disque : ${gb} Go libres" "  disk: ${gb} GB free"; return 0; fi
  warn "  disque : ${gb} Go libres, il en faut au moins ${MIN_DISK_GB}" "  disk: ${gb} GB free, at least ${MIN_DISK_GB} are needed"
  return 1
}

preflight_systemd() {
  if [ -d /run/systemd/system ]; then say "  systemd : présent" "  systemd: present"; return 0; fi
  warn "  systemd : absent (obligatoire)" "  systemd: missing (required)"
  return 1
}

preflight_root() {
  if [ "${EUID:-$(id -u)}" -eq 0 ]; then say "  droits : administrateur" "  rights: administrator"; return 0; fi
  warn "  droits : lancez le script avec sudo" "  rights: run the script with sudo"
  return 1
}

preflight_run() {
  local failed=0 check
  say "Vérifications :" "Checks:"
  for check in preflight_os preflight_arch preflight_ram preflight_disk preflight_systemd preflight_root; do
    "$check" "$@" || failed=1
  done
  return "$failed"
}
