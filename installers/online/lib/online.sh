# Helpers of the online installer (slice 19c, step E). Sourced after common.sh, ports.sh and firewall.sh.
# Paths and switches come from variables so a test can point them at a folder of its own and fake the tools.

ONLINE_DIR="${VOTE_ONLINE_DIR:-/opt/votesystem}"
LINK_DIR="${VOTE_LINK_DIR:-/usr/local/bin}"
BACKUP_DIR="${VOTE_BACKUP_DIR:-/var/backups/votesystem}"
REPO="${VOTE_REPO:-jun101/VotingSystem}"
REGISTRY_DEFAULT="${VOTE_REGISTRY:-ghcr.io/jun101}"
OS_RELEASE_FILE="${OS_RELEASE_FILE:-/etc/os-release}"
MIN_RAM_MB="${MIN_RAM_MB:-1800}"
MIN_DISK_GB="${MIN_DISK_GB:-10}"

hex() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

# ---- questions ------------------------------------------------------------------------------------------------------------

# ask_value VARIABLE "question fr" "question en" DEFAULT: asks for a text; `--yes` or an empty answer keeps the default.
ask_value() {
  local var="$1" default="${4:-}" answer
  if [ "${ASSUME_YES:-0}" = 1 ]; then printf -v "$var" '%s' "$default"; return 0; fi
  if [ "$VOTE_LANG" = en ]; then printf '%s [%s] ' "$3" "$default"; else printf '%s [%s] ' "$2" "$default"; fi
  read -r answer || answer=''
  printf -v "$var" '%s' "${answer:-$default}"
}

# ask_secret VARIABLE "question fr" "question en": a text that is not shown. Empty when `--yes`.
ask_secret() {
  local var="$1" answer=''
  if [ "${ASSUME_YES:-0}" = 1 ]; then printf -v "$var" '%s' ''; return 0; fi
  if [ "$VOTE_LANG" = en ]; then printf '%s ' "$3"; else printf '%s ' "$2"; fi
  read -r -s answer || answer=''
  printf '\n'
  printf -v "$var" '%s' "$answer"
}

valid_domain() { printf '%s' "$1" | grep -Eq '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$'; }
valid_email() { printf '%s' "$1" | grep -Eq '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'; }
valid_port() { printf '%s' "$1" | grep -Eq '^[0-9]{1,5}$' && [ "$1" -ge 1 ] && [ "$1" -le 65535 ]; }

# ---- checks -------------------------------------------------------------------------------------------------------------

online_preflight_os() {
  local id version_id
  id="$(. "$OS_RELEASE_FILE" 2>/dev/null; printf '%s' "${ID:-}")"
  version_id="$(. "$OS_RELEASE_FILE" 2>/dev/null; printf '%s' "${VERSION_ID:-}")"
  case "$id" in
    debian|ubuntu) say "  système : $id $version_id" "  system: $id $version_id"; return 0 ;;
    *)
      # Another distribution can still run it when Docker is there: a warning, not a stop.
      warn "  système : ${id:-inconnu} ${version_id:-?} n'est pas testé (Debian et Ubuntu le sont) ; Docker doit être déjà installé" \
           "  system: ${id:-unknown} ${version_id:-?} is not tested (Debian and Ubuntu are); Docker must already be installed"
      DOCKER_INSTALL_ALLOWED=0
      return 0 ;;
  esac
}

online_preflight_arch() {
  local arch="${ARCH_OVERRIDE:-$(uname -m)}"
  if [ "$arch" = x86_64 ]; then say "  processeur : x86_64" "  cpu: x86_64"; return 0; fi
  warn "  processeur : $arch n'est pas pris en charge (images x86_64 seulement)" "  cpu: $arch is not supported (x86_64 images only)"
  return 1
}

online_preflight_ram() {
  local mb
  mb="$(awk '/MemTotal/ {print int($2/1024)}' "${MEMINFO_FILE:-/proc/meminfo}")"
  if [ "$mb" -ge "$MIN_RAM_MB" ]; then say "  mémoire : ${mb} Mo" "  memory: ${mb} MB"; return 0; fi
  warn "  mémoire : ${mb} Mo, il en faut au moins 2 Go" "  memory: ${mb} MB, at least 2 GB is needed"
  return 1
}

online_preflight_disk() {
  local dir="${1:-/opt}" gb
  [ -d "$dir" ] || dir=/
  gb="$(df -Pk "$dir" | awk 'NR==2 {print int($4/1024/1024)}')"
  if [ "$gb" -ge "$MIN_DISK_GB" ]; then say "  disque : ${gb} Go libres" "  disk: ${gb} GB free"; return 0; fi
  warn "  disque : ${gb} Go libres, il en faut au moins ${MIN_DISK_GB}" "  disk: ${gb} GB free, at least ${MIN_DISK_GB} are needed"
  return 1
}

online_preflight_root() {
  if [ "${VOTE_TEST_NO_ROOT:-0}" = 1 ] || [ "${EUID:-$(id -u)}" -eq 0 ]; then say "  droits : administrateur" "  rights: administrator"; return 0; fi
  warn "  droits : lancez le script avec sudo" "  rights: run the script with sudo"
  return 1
}

online_preflight_tools() {
  local missing=0 tool
  for tool in curl awk sed grep ss; do
    command -v "$tool" >/dev/null 2>&1 || { warn "  outil manquant : $tool" "  missing tool: $tool"; missing=1; }
  done
  [ "$missing" = 0 ] && say "  outils : curl, awk, sed, grep, ss" "  tools: curl, awk, sed, grep, ss"
  return "$missing"
}

online_preflight_run() {
  local failed=0 check
  say "Vérifications :" "Checks:"
  for check in online_preflight_os online_preflight_arch online_preflight_ram online_preflight_disk online_preflight_root online_preflight_tools; do
    "$check" "$@" || failed=1
  done
  return "$failed"
}

# ---- Docker -------------------------------------------------------------------------------------------------------------

docker_ready() { docker version >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; }

# docker_install: Docker Engine and the Compose plugin from Docker's own apt repository (Debian and Ubuntu).
docker_install() {
  local id codename arch
  id="$(. "$OS_RELEASE_FILE"; printf '%s' "$ID")"
  codename="$(. "$OS_RELEASE_FILE"; printf '%s' "${VERSION_CODENAME:-}")"
  arch="$(dpkg --print-architecture)"
  [ -n "$codename" ] || return 1
  apt-get update -qq && apt-get install -y -qq ca-certificates curl || return 1
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL "https://download.docker.com/linux/$id/gpg" -o /etc/apt/keyrings/docker.asc || return 1
  chmod a+r /etc/apt/keyrings/docker.asc
  printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/%s %s stable\n' "$arch" "$id" "$codename" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -qq && apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin || return 1
  systemctl enable --now docker >/dev/null 2>&1
  docker_ready
}

# ---- the domain ---------------------------------------------------------------------------------------------------------

# local_addresses: the IPv4 addresses of this machine, one per line.
local_addresses() { ip -o -4 addr show 2>/dev/null | awk '{print $4}' | cut -d/ -f1; }

# domain_points_here DOMAIN: 0 when the name resolves to an address of this machine, 1 when it resolves elsewhere or not at all.
domain_points_here() {
  local resolved ip
  resolved="$(getent ahostsv4 "$1" 2>/dev/null | awk '{print $1}' | sort -u)"
  [ -n "$resolved" ] || return 1
  for ip in $resolved; do
    local_addresses | grep -qx "$ip" && return 0
  done
  return 1
}

# ---- the files ----------------------------------------------------------------------------------------------------------

# write_env FILE: the .env of the installation, from the ENV_* variables of the caller. Mode 0600.
write_env() {
  local file="$1"
  (
    umask 077
    {
      echo "# VoteSystem, online edition. Written by the installer. Secrets: keep this file private (mode 0600)."
      echo "VOTE_REGISTRY=$ENV_REGISTRY"
      echo "VOTE_VERSION=$ENV_VERSION"
      echo "APP_NAME=\"VoteSystem\""
      echo "SITE_URL=$ENV_SITE_URL"
      echo "SITE_ADDRESS=$ENV_SITE_ADDRESS"
      echo "HTTP_BIND=$ENV_HTTP_BIND"
      echo "HTTP_PORT=$ENV_HTTP_PORT"
      echo "HTTPS_BIND=$ENV_HTTPS_BIND"
      echo "HTTPS_PORT=$ENV_HTTPS_PORT"
      echo "APP_KEY=$ENV_APP_KEY"
      echo "VOTE_AUDIT_PUBLIC_KEY=$ENV_AUDIT_PUBLIC"
      echo "CREDENTIAL_HASH_KEY=$ENV_HASH_KEY"
      echo "DB_DATABASE=votesystem"
      echo "DB_ROOT_PASSWORD=$ENV_DB_ROOT_PASSWORD"
      echo "DB_PASSWORD=$ENV_DB_PASSWORD"
      echo "DB_MIGRATOR_PASSWORD=$ENV_DB_MIGRATOR_PASSWORD"
      echo "REDIS_PASSWORD=$ENV_REDIS_PASSWORD"
      echo "SESSION_COOKIE=votesystem_session"
      echo "MAIL_MAILER=$ENV_MAIL_MAILER"
      echo "MAIL_HOST=$ENV_MAIL_HOST"
      echo "MAIL_PORT=$ENV_MAIL_PORT"
      echo "MAIL_SCHEME=$ENV_MAIL_SCHEME"
      echo "MAIL_USERNAME=$ENV_MAIL_USERNAME"
      echo "MAIL_PASSWORD=$ENV_MAIL_PASSWORD"
      echo "MAIL_FROM_ADDRESS=$ENV_MAIL_FROM"
    } > "$file"
  )
}

# set_env_value FILE NAME VALUE: changes one line of the .env (adds it when missing).
set_env_value() {
  local file="$1" name="$2" value="$3" tmp
  tmp="$(mktemp "$file.XXXXXX")"
  awk -v n="$name" -v v="$value" 'BEGIN{d=0} index($0, n"=")==1 && !d {print n"="v; d=1; next} {print} END{if(!d) print n"="v}' "$file" > "$tmp"
  cat "$tmp" > "$file"
  rm -f "$tmp"
}

env_value() { sed -n "s/^$2=//p" "$1" | head -1; }

# compose ARGS...: Docker Compose on the installation's file, from its folder (the .env there is read).
compose() { docker compose -f "$ONLINE_DIR/compose.prod.yaml" --project-directory "$ONLINE_DIR" "$@"; }

# wait_healthy URL [EXTRA CURL ARGS]: 0 as soon as the URL answers 2xx, 1 after about the given time.
wait_for_url() {
  local tries="$1" i
  shift
  for i in $(seq 1 "$tries"); do
    curl -fsS --max-time 4 "$@" >/dev/null 2>&1 && return 0
    sleep 2
  done
  return 1
}
