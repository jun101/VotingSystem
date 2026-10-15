#!/usr/bin/env bash
# VoteSystem, online edition, installer (Debian and Ubuntu with Docker).
#
#   sudo ./install-online.sh [install] [options]     first installation
#   sudo ./install-online.sh update [--version X.Y.Z] [--archive FILE]
#   sudo ./install-online.sh uninstall [--purge-data]
#
# Options of install:
#   --yes                  answer every question with its default (a script's run; needs --domain and --admin-email)
#   --dry-run              checks, ports and the plan; changes nothing
#   --domain NAME          the site's domain (example: vote.fleuridor.com); the only name asked
#   --behind-proxy         another proxy (Nginx Proxy Manager, ...) already owns ports 80 and 443 and does the HTTPS
#   --site-url URL         with --behind-proxy: the public address (default https://<domain>)
#   --proxy-network NAME   with --behind-proxy: the Docker network of the other proxy (example npm_network); VoteSystem's
#                          proxy joins it, and the other proxy forwards to votesystem-proxy-1 port 80
#   --admin-email ADDRESS  the platform admin's sign-in address
#   --skip-mail            leave the mail settings for later
#   --version X.Y.Z        the version of the images (default: the version of this archive)
#   --registry NAME        where the images are (default ghcr.io/jun101)
#   --credentials-file F   where the admin's password is written (default: the home of the person who ran sudo)
#   --lang fr|en           the language of this script's messages
#
# One domain only: the site calls /api on its own host, so no API name is asked.
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$HERE/lib/common.sh" ]; then LIB="$HERE/lib"; else LIB="$HERE/../linux/lib"; fi
# shellcheck source=../linux/lib/common.sh
. "$LIB/common.sh"
. "$LIB/ports.sh"
. "$LIB/firewall.sh"
. "$HERE/lib/online.sh"

# Where the files to install are: in the release archive next to the script, else in the repository.
if [ -d "$HERE/files" ]; then
  FILE_COMPOSE="$HERE/files/compose.prod.yaml"; FILE_CADDY="$HERE/files/Caddyfile"; FILE_INIT="$HERE/files/init-db.sh"; FILE_VOTECTL="$HERE/files/votectl"
else
  ROOT="$(cd "$HERE/../.." && pwd)"
  FILE_COMPOSE="$ROOT/compose.prod.yaml"; FILE_CADDY="$ROOT/docker/proxy/Caddyfile.production"; FILE_INIT="$ROOT/docker/db/init-prod.sh"; FILE_VOTECTL="$ROOT/installers/common/votectl"
fi
if [ -f "$HERE/VERSION" ]; then ARCHIVE_VERSION="$(cat "$HERE/VERSION")"; else ARCHIVE_VERSION="${VOTE_VERSION:-0.0.0-dev}"; fi

ACTION=install
ASSUME_YES=0
DRY_RUN=0
PURGE=0
DOMAIN=""
BEHIND_PROXY=0
SITE_URL_ARG=""
VERSION_GIVEN=0
PROXY_NETWORK_ARG=""
ADMIN_EMAIL=""
SKIP_MAIL=0
VERSION="$ARCHIVE_VERSION"
REGISTRY="$REGISTRY_DEFAULT"
CREDENTIALS_FILE=""
ARCHIVE=""
DOCKER_INSTALL_ALLOWED=1
FIREWALL_RECORD="$ONLINE_DIR/firewall.rules"

case "${1:-}" in install|update|uninstall) ACTION="$1"; shift ;; esac
while [ $# -gt 0 ]; do
  case "$1" in
    --yes|-y) ASSUME_YES=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --purge-data) PURGE=1 ;;
    --domain) shift; DOMAIN="$(printf '%s' "${1:-}" | tr 'A-Z' 'a-z')" ;;
    --behind-proxy) BEHIND_PROXY=1 ;;
    --site-url) shift; SITE_URL_ARG="${1:-}" ;;
    --proxy-network) shift; PROXY_NETWORK_ARG="${1:-}" ;;
    --admin-email) shift; ADMIN_EMAIL="${1:-}" ;;
    --skip-mail) SKIP_MAIL=1 ;;
    --version) shift; VERSION="${1:-$VERSION}"; VERSION_GIVEN=1 ;;
    --registry) shift; REGISTRY="${1:-$REGISTRY}" ;;
    --credentials-file) shift; CREDENTIALS_FILE="${1:-}" ;;
    --archive) shift; ARCHIVE="${1:-}" ;;
    --lang) shift; VOTE_LANG="${1:-fr}" ;;
    -h|--help) sed -n '2,24p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "option inconnue : $1" "unknown option: $1" ;;
  esac
  shift
done
export ASSUME_YES

step() { say "==> $1" "==> $2"; }
fail() { warn "ÉCHEC : $1" "FAILED: $2"; warn "Rien de plus n'a été fait. Voir : docker compose -f $ONLINE_DIR/compose.prod.yaml logs" "Nothing more was done. See: docker compose -f $ONLINE_DIR/compose.prod.yaml logs"; exit 1; }
need_root() { [ "${VOTE_TEST_NO_ROOT:-0}" = 1 ] || [ "$(id -u)" -eq 0 ] || die "lancez le script avec sudo" "run the script with sudo"; }

# install_files FROM_DIR_OR_EMPTY: the Compose file, the proxy file, the database script and votectl, in place.
install_files() {
  mkdir -p "$ONLINE_DIR"
  cp "$FILE_COMPOSE" "$ONLINE_DIR/compose.prod.yaml"
  cp "$FILE_CADDY" "$ONLINE_DIR/Caddyfile"
  cp "$FILE_INIT" "$ONLINE_DIR/init-db.sh"
  chmod 755 "$ONLINE_DIR/init-db.sh"
  printf '%s\n' "$VERSION" > "$ONLINE_DIR/VERSION"
  mkdir -p "$ONLINE_DIR/installer/lib"
  cp "$HERE/install-online.sh" "$ONLINE_DIR/installer/install-online.sh"
  cp "$LIB/common.sh" "$LIB/ports.sh" "$LIB/firewall.sh" "$HERE/lib/online.sh" "$ONLINE_DIR/installer/lib/"
  mkdir -p "$ONLINE_DIR/installer/files"
  cp "$FILE_COMPOSE" "$ONLINE_DIR/installer/files/compose.prod.yaml"
  cp "$FILE_CADDY" "$ONLINE_DIR/installer/files/Caddyfile"
  cp "$FILE_INIT" "$ONLINE_DIR/installer/files/init-db.sh"
  cp "$FILE_VOTECTL" "$ONLINE_DIR/installer/files/votectl"
  cp "$FILE_VOTECTL" "$ONLINE_DIR/votectl"
  chmod 755 "$ONLINE_DIR/votectl" "$ONLINE_DIR/installer/install-online.sh"
  cat > "$ONLINE_DIR/votectl.conf" <<CONF
# Read by votectl. Written by the installer.
DRIVER=docker
COMPOSE_FILE=$ONLINE_DIR/compose.prod.yaml
VERSION_FILE=$ONLINE_DIR/VERSION
BACKUP_DIR=$BACKUP_DIR
DB_NAME=votesystem
CONF
  mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
  if [ "${VOTE_TEST_NO_ROOT:-0}" != 1 ]; then
    mkdir -p /etc/votesystem
    cp "$ONLINE_DIR/votectl.conf" /etc/votesystem/votectl.conf
    ln -sfn "$ONLINE_DIR/votectl" "$LINK_DIR/votectl"
  fi
}

# ---- uninstall --------------------------------------------------------------------------------------------------------

do_uninstall() {
  need_root
  [ -f "$ONLINE_DIR/compose.prod.yaml" ] || die "aucune installation trouvée dans $ONLINE_DIR" "no installation found in $ONLINE_DIR"
  say "Désinstallation de VoteSystem" "Uninstalling VoteSystem"
  if [ "$PURGE" = 1 ]; then
    say "  sauvegarde finale avant de supprimer les données..." "  final backup before the data is removed..."
    VOTECTL_CONF="$ONLINE_DIR/votectl.conf" "$ONLINE_DIR/votectl" backup --out /root >/dev/null 2>&1 \
      && say "  sauvegarde : /root/votesystem-backup-*.tar" "  backup: /root/votesystem-backup-*.tar"
    compose down -v --remove-orphans >/dev/null 2>&1
  else
    compose down --remove-orphans >/dev/null 2>&1
  fi
  firewall_close
  rm -f "$LINK_DIR/votectl" /etc/votesystem/votectl.conf
  if [ "$PURGE" = 1 ]; then
    rm -rf "${ONLINE_DIR:?}"
    say "Services, données et configuration supprimés." "Services, data and configuration removed."
  else
    say "Services arrêtés. Les données (volumes Docker) et la configuration ($ONLINE_DIR) sont conservées." \
        "Services stopped. The data (Docker volumes) and the configuration ($ONLINE_DIR) are kept."
    say "Pour tout effacer : $ONLINE_DIR/installer/install-online.sh uninstall --purge-data" "To erase everything: $ONLINE_DIR/installer/install-online.sh uninstall --purge-data"
  fi
}

# ---- update -----------------------------------------------------------------------------------------------------------

latest_version() {
  curl -fsS --max-time 15 "https://api.github.com/repos/$REPO/releases/latest" 2>/dev/null \
    | sed -n 's/.*"tag_name": *"v\{0,1\}\([^"]*\)".*/\1/p' | head -1
}

do_update() {
  need_root
  [ -f "$ONLINE_DIR/.env" ] || die "aucune installation trouvée dans $ONLINE_DIR" "no installation found in $ONLINE_DIR"
  local old new work
  old="$(env_value "$ONLINE_DIR/.env" VOTE_VERSION)"
  work="$(mktemp -d)"
  trap 'rm -rf "$work"' RETURN

  local src=""
  if [ -n "$ARCHIVE" ]; then
    [ -f "$ARCHIVE" ] || die "archive introuvable : $ARCHIVE" "archive not found: $ARCHIVE"
  elif [ -f "$HERE/VERSION" ] && [ -d "$HERE/files" ] && [ "${HERE#"$ONLINE_DIR"/}" = "$HERE" ] \
       && { [ -z "${VERSION_ARG:-}" ] || [ "$VERSION_ARG" = "$ARCHIVE_VERSION" ]; }; then
    # Run from an unpacked archive (not from the copy installed in $ONLINE_DIR): this archive is the new version.
    src="$HERE"
    say "Archive dépaquetée utilisée : $HERE" "Using the unpacked archive: $HERE"
  else
    new="${VERSION_ARG:-}"
    [ -n "$new" ] || new="$(latest_version)"
    [ -n "$new" ] || die "aucune version stable n'a été trouvée : indiquez --version X.Y.Z, ou lancez la mise à jour depuis l'archive dépaquetée" \
                         "no stable release was found: give --version X.Y.Z, or run the update from the unpacked archive"
    ARCHIVE="$work/votesystem-online-$new.tar.gz"
    say "Téléchargement de la version $new..." "Downloading version $new..."
    curl -fsSL "https://github.com/$REPO/releases/download/v$new/votesystem-online-$new.tar.gz" -o "$ARCHIVE" \
      && curl -fsSL "https://github.com/$REPO/releases/download/v$new/votesystem-online-$new.tar.gz.sha256" -o "$ARCHIVE.sha256" \
      || die "le téléchargement a échoué" "the download failed"
  fi
  if [ -z "$src" ]; then
    if [ -f "$ARCHIVE.sha256" ]; then
      (cd "$(dirname "$ARCHIVE")" && sha256sum -c --quiet "$(basename "$ARCHIVE").sha256") \
        || die "la somme de contrôle ne correspond pas : fichier abîmé ou modifié" "the checksum does not match: the file is damaged or was changed"
    else
      warn "  aucune somme de contrôle à côté de l'archive : elle n'est pas vérifiée" "  no checksum next to the archive: it is not verified"
    fi
    tar -C "$work" -xzf "$ARCHIVE" || die "archive illisible" "unreadable archive"
    src="$(find "$work" -maxdepth 2 -name install-online.sh -print -quit | xargs -r dirname)"
  fi
  [ -n "$src" ] && [ -f "$src/VERSION" ] || die "ce n'est pas une archive VoteSystem en ligne" "this is not a VoteSystem online archive"
  new="$(cat "$src/VERSION")"
  say "Mise à jour : $old -> $new" "Update: $old -> $new"
  [ "$new" != "$old" ] || die "cette version est déjà installée" "this version is already installed"

  say "  sauvegarde avant la mise à jour..." "  backup before the update..."
  VOTECTL_CONF="$ONLINE_DIR/votectl.conf" "$ONLINE_DIR/votectl" backup >/dev/null 2>&1 || die "la sauvegarde a échoué : rien n'a été modifié" "the backup failed: nothing was changed"

  # The previous files are kept, so a failed update goes back to them.
  local prev="$ONLINE_DIR/.previous"
  rm -rf "$prev" && mkdir -p "$prev"
  cp -a "$ONLINE_DIR/compose.prod.yaml" "$ONLINE_DIR/Caddyfile" "$ONLINE_DIR/init-db.sh" "$ONLINE_DIR/VERSION" "$ONLINE_DIR/.env" "$ONLINE_DIR/votectl" "$ONLINE_DIR/votectl.conf" "$prev/"

  FILE_COMPOSE="$src/files/compose.prod.yaml"; FILE_CADDY="$src/files/Caddyfile"; FILE_INIT="$src/files/init-db.sh"; FILE_VOTECTL="$src/files/votectl"
  HERE="$src"; LIB="$src/lib"; VERSION="$new"
  set_env_value "$ONLINE_DIR/.env" VOTE_VERSION "$new"
  install_files
  ensure_proxy_network || die "création du réseau du proxy" "creating the proxy network"

  local log="$ONLINE_DIR/last-update.log"
  : > "$log"
  if { [ "${VOTE_NO_PULL:-0}" = 1 ] || compose pull >"$log" 2>&1; } \
     && compose run --rm migrate >>"$log" 2>&1 \
     && compose up -d --wait --remove-orphans >>"$log" 2>&1; then
    say "Mise à jour terminée : version $new." "Update finished: version $new."
    return 0
  fi

  warn "La mise à jour a échoué : retour à la version $old (détails : $log)." "The update failed: going back to version $old (details: $log)."
  cp -a "$prev/." "$ONLINE_DIR/"
  if compose up -d --wait >>"$log" 2>&1; then
    say "La version $old fonctionne de nouveau." "Version $old is running again."
  else
    warn "La version $old ne répond pas : restaurez la sauvegarde (votectl restore)." "Version $old does not answer: restore the backup (votectl restore)."
  fi
  warn "Les migrations déjà passées ne sont pas défaites : en cas de doute, restaurez la sauvegarde (votectl restore)." \
       "Migrations that already ran are not undone: if in doubt, restore the backup (votectl restore)."
  return 1
}

# The version asked for with --version, always (also when it equals the version of this archive).
VERSION_ARG=""
[ "$VERSION_GIVEN" = 1 ] && VERSION_ARG="$VERSION"

case "$ACTION" in
  uninstall) do_uninstall; exit $? ;;
  update) do_update; exit $? ;;
esac

# ---- install: checks ----------------------------------------------------------------------------------------------------

say "VoteSystem : installation (édition en ligne)" "VoteSystem: installation (online edition)"
say "" ""
online_preflight_run || die "les vérifications ont échoué, rien n'a été modifié" "checks failed, nothing was changed"
if [ "$DRY_RUN" != 1 ] && [ -f "$ONLINE_DIR/.env" ]; then
  die "VoteSystem est déjà installé dans $ONLINE_DIR. Pour le mettre à jour : $0 update" "VoteSystem is already installed in $ONLINE_DIR. To update it: $0 update"
fi
[ "$VERSION" != 0.0.0-dev ] || [ "$DRY_RUN" = 1 ] || die "version inconnue : indiquez --version X.Y.Z" "unknown version: give --version X.Y.Z"

# ---- Docker ---------------------------------------------------------------------------------------------------------------

say "" ""
if command -v docker >/dev/null 2>&1 && docker_ready; then
  say "Docker : présent ($(docker --version | sed 's/,.*//'))" "Docker: present ($(docker --version | sed 's/,.*//'))"
else
  warn "Docker (ou son plugin Compose) est absent." "Docker (or its Compose plugin) is missing."
  if [ "$DOCKER_INSTALL_ALLOWED" != 1 ]; then
    die "installez Docker Engine et le plugin Compose, puis relancez" "install Docker Engine and the Compose plugin, then run again"
  fi
  if [ "$DRY_RUN" = 1 ]; then
    say "  (essai à blanc : Docker ne serait installé qu'après votre accord)" "  (dry run: Docker would be installed only after you agree)"
  elif ask_yes_no "Installer Docker Engine depuis le dépôt officiel de Docker ?" "Install Docker Engine from Docker's official repository?" y; then
    step "Installation de Docker" "Installing Docker"
    docker_install || fail "l'installation de Docker a échoué" "the Docker installation failed"
  else
    die "Docker est nécessaire : installation annulée" "Docker is required: installation cancelled"
  fi
fi

# ---- questions: the domain, ports, mail, admin --------------------------------------------------------------------------

say "" ""
if [ -z "$DOMAIN" ]; then
  [ "$ASSUME_YES" = 1 ] && die "--domain est nécessaire avec --yes" "--domain is required with --yes"
  ask_value DOMAIN "Nom de domaine du site (exemple : vote.fleuridor.com) :" "Domain name of the site (example: vote.fleuridor.com):" "vote.fleuridor.com"
  DOMAIN="$(printf '%s' "$DOMAIN" | tr 'A-Z' 'a-z')"
fi
valid_domain "$DOMAIN" || die "ce nom de domaine n'est pas valide : $DOMAIN" "this domain name is not valid: $DOMAIN"

say "" ""
say "Ports :" "Ports:"
busy=()
for p in 80 443; do port_in_use "$p" && busy+=("$p"); done
if [ "$BEHIND_PROXY" = 0 ] && [ "${#busy[@]}" -gt 0 ]; then
  warn "  le port ${busy[*]} est déjà utilisé (un autre proxy ?)" "  port ${busy[*]} is already in use (another proxy?)"
  if [ "$ASSUME_YES" = 1 ]; then
    die "ports 80 et 443 indisponibles : relancez avec --behind-proxy si un autre proxy fait le HTTPS" \
        "ports 80 and 443 unavailable: run again with --behind-proxy if another proxy does the HTTPS"
  elif ask_yes_no "Un autre proxy (Nginx Proxy Manager, ...) fera le HTTPS : VoteSystem écoutera sur un port libre en local ?" \
                  "Another proxy (Nginx Proxy Manager, ...) does the HTTPS: should VoteSystem listen on a free local port?" y; then
    BEHIND_PROXY=1
  else
    die "libérez les ports 80 et 443 puis relancez" "free ports 80 and 443, then run again"
  fi
fi

if [ "$BEHIND_PROXY" = 1 ]; then
  ENV_HTTP_BIND=127.0.0.1; ENV_HTTPS_BIND=127.0.0.1
  ENV_HTTP_PORT="$(next_free_port 8080)" || die "aucun port libre" "no free port"
  ENV_HTTPS_PORT="$(random_free_port "$ENV_HTTP_PORT")" || die "aucun port libre" "no free port"
  ENV_SITE_ADDRESS=":80"
  ENV_SITE_URL="${SITE_URL_ARG:-https://$DOMAIN}"
  say "  derrière un autre proxy : VoteSystem écoute sur 127.0.0.1:$ENV_HTTP_PORT (HTTP)" "  behind another proxy: VoteSystem listens on 127.0.0.1:$ENV_HTTP_PORT (HTTP)"
  say "  réglez l'autre proxy : $DOMAIN -> http://127.0.0.1:$ENV_HTTP_PORT" "  set the other proxy: $DOMAIN -> http://127.0.0.1:$ENV_HTTP_PORT"
else
  ENV_HTTP_BIND=0.0.0.0; ENV_HTTPS_BIND=0.0.0.0; ENV_HTTP_PORT=80; ENV_HTTPS_PORT=443
  ENV_SITE_ADDRESS="$DOMAIN"
  ENV_SITE_URL="https://$DOMAIN"
  say "  HTTPS : ports 80 et 443 libres, Caddy obtiendra le certificat de $DOMAIN" "  HTTPS: ports 80 and 443 are free, Caddy will get the certificate of $DOMAIN"
  if domain_points_here "$DOMAIN"; then
    say "  DNS : $DOMAIN pointe vers cette machine" "  DNS: $DOMAIN points to this machine"
  else
    warn "  DNS : $DOMAIN ne pointe pas (encore) vers une adresse de cette machine ; sans cela, pas de certificat" \
         "  DNS: $DOMAIN does not (yet) point to an address of this machine; without that there is no certificate"
    if [ "$DRY_RUN" != 1 ] && [ "$ASSUME_YES" != 1 ] && ! ask_yes_no "Continuer quand même ?" "Continue anyway?" n; then
      say "Installation annulée : réglez le DNS puis relancez." "Installation cancelled: set the DNS, then run again."
      exit 0
    fi
  fi
fi

say "" ""
fw="$(firewall_detect)"
say "Pare-feu : $fw" "Firewall: $fw"
OPEN_FIREWALL=0
fw_ports=()
if [ "$fw" != none ] && [ "$BEHIND_PROXY" = 0 ]; then
  fw_ports=(80 443)
  for p in "${fw_ports[@]}"; do say "  règle : $(firewall_rule_text "$fw" "$p" 0.0.0.0/0)" "  rule: $(firewall_rule_text "$fw" "$p" 0.0.0.0/0)"; done
  say "  (Docker publie ses ports avant le pare-feu : la règle sert aussi de trace de ce qui est attendu)" \
      "  (Docker publishes its ports ahead of the firewall: the rule also records what is expected)"
  if [ "$DRY_RUN" = 1 ]; then
    say "  (essai à blanc : rien n'est ouvert)" "  (dry run: nothing is opened)"
  elif ask_yes_no "Ouvrir les ports 80 et 443 ?" "Open ports 80 and 443?" y; then
    OPEN_FIREWALL=1
  fi
fi

say "" ""
ENV_MAIL_MAILER=log; ENV_MAIL_HOST=localhost; ENV_MAIL_PORT=587; ENV_MAIL_SCHEME=""; ENV_MAIL_USERNAME=""; ENV_MAIL_PASSWORD=""
ENV_MAIL_FROM="no-reply@$DOMAIN"
if [ "$SKIP_MAIL" = 1 ] || { [ "$ASSUME_YES" = 1 ] && [ -z "${VOTE_SMTP_HOST:-}" ]; }; then
  say "Courriel : à régler plus tard (fichier $ONLINE_DIR/.env) ; sans lui les organisateurs ne reçoivent pas les courriels de vérification" \
      "Mail: to set later (file $ONLINE_DIR/.env); without it organisers do not receive verification emails"
else
  say "Courriel (SMTP) :" "Mail (SMTP):"
  ask_value ENV_MAIL_HOST "  serveur SMTP :" "  SMTP server:" "${VOTE_SMTP_HOST:-smtp.example.com}"
  ask_value ENV_MAIL_PORT "  port :" "  port:" "${VOTE_SMTP_PORT:-587}"
  valid_port "$ENV_MAIL_PORT" || die "port SMTP invalide" "invalid SMTP port"
  enc=starttls; [ "$ENV_MAIL_PORT" = 465 ] && enc=tls
  ask_value enc "  chiffrement (starttls, tls, none) :" "  encryption (starttls, tls, none):" "$enc"
  [ "$enc" = tls ] && ENV_MAIL_SCHEME=smtps
  ask_value ENV_MAIL_USERNAME "  identifiant (vide si aucun) :" "  user name (empty if none):" "${VOTE_SMTP_USER:-}"
  if [ -n "$ENV_MAIL_USERNAME" ]; then
    ENV_MAIL_PASSWORD="${VOTE_SMTP_PASSWORD:-}"
    [ -n "$ENV_MAIL_PASSWORD" ] || ask_secret ENV_MAIL_PASSWORD "  mot de passe :" "  password:"
  fi
  ask_value ENV_MAIL_FROM "  adresse de l'expéditeur :" "  sender address:" "$ENV_MAIL_FROM"
  ENV_MAIL_MAILER=smtp
fi

if [ -z "$ADMIN_EMAIL" ]; then
  [ "$ASSUME_YES" = 1 ] && die "--admin-email est nécessaire avec --yes" "--admin-email is required with --yes"
  ask_value ADMIN_EMAIL "Adresse courriel de l'administrateur de la plateforme :" "Platform admin's email address:" "admin@$DOMAIN"
fi
valid_email "$ADMIN_EMAIL" || die "adresse courriel invalide : $ADMIN_EMAIL" "invalid email address: $ADMIN_EMAIL"

if [ -z "$CREDENTIALS_FILE" ]; then
  home="$(getent passwd "${SUDO_USER:-root}" | cut -d: -f6)"
  CREDENTIALS_FILE="${home:-/root}/votesystem-identifiants.txt"
fi
TAKEAWAY_DIR="$(dirname "$CREDENTIALS_FILE")"

say "" ""
say "Plan :" "Plan:"
say "  site : $ENV_SITE_URL" "  site: $ENV_SITE_URL"
say "  version des images : $VERSION ($REGISTRY)" "  image version: $VERSION ($REGISTRY)"
say "  administrateur : $ADMIN_EMAIL" "  admin: $ADMIN_EMAIL"
say "  courriel : $ENV_MAIL_MAILER ($ENV_MAIL_HOST:$ENV_MAIL_PORT)" "  mail: $ENV_MAIL_MAILER ($ENV_MAIL_HOST:$ENV_MAIL_PORT)"
say "  dossier : $ONLINE_DIR" "  folder: $ONLINE_DIR"
say "  identifiants : $CREDENTIALS_FILE" "  credentials: $CREDENTIALS_FILE"

if [ "$DRY_RUN" = 1 ]; then
  say "" ""
  say "Essai à blanc terminé : rien n'a été modifié." "Dry run finished: nothing was changed."
  exit 0
fi

say "" ""
ask_yes_no "Installer maintenant ?" "Install now?" y || { say "Installation annulée." "Installation cancelled."; exit 0; }

# ---- install: the steps -----------------------------------------------------------------------------------------------------

ENV_REGISTRY="$REGISTRY"
ENV_VERSION="$VERSION"
ENV_APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
ENV_HASH_KEY="$(hex 32)"
ENV_DB_ROOT_PASSWORD="$(hex 24)"
ENV_DB_PASSWORD="$(hex 24)"
ENV_DB_MIGRATOR_PASSWORD="$(hex 24)"
ENV_REDIS_PASSWORD="$(hex 24)"

step "Fichiers" "Files"
install_files || fail "copie des fichiers" "copying the files"

step "Clés de l'audit des votes" "Vote audit keys"
if [ "${VOTE_NO_PULL:-0}" != 1 ]; then
  step "Téléchargement des images (version $VERSION)" "Pulling the images (version $VERSION)"
  docker pull "$REGISTRY/votesystem-api:$VERSION" >/dev/null || fail "image de l'API introuvable ($REGISTRY/votesystem-api:$VERSION)" "API image not found ($REGISTRY/votesystem-api:$VERSION)"
  docker pull "$REGISTRY/votesystem-web:$VERSION" >/dev/null || fail "image du site introuvable" "web image not found"
fi
KEYPAIR="$(docker run --rm "$REGISTRY/votesystem-api:$VERSION" php -r '$k = sodium_crypto_box_keypair(); echo bin2hex(sodium_crypto_box_publickey($k)), " ", bin2hex(sodium_crypto_box_secretkey($k));')" \
  || fail "clés de l'audit" "audit keys"
ENV_AUDIT_PUBLIC="${KEYPAIR%% *}"
AUDIT_PRIVATE="${KEYPAIR##* }"
unset KEYPAIR
[ "${#ENV_AUDIT_PUBLIC}" -eq 64 ] && [ "${#AUDIT_PRIVATE}" -eq 64 ] || fail "clés de l'audit" "audit keys"

step "Configuration" "Configuration"
ENV_PROXY_NETWORK="${PROXY_NETWORK_ARG:-votesystem_edge}"
write_env "$ONLINE_DIR/.env" || fail "écriture de la configuration" "writing the configuration"
ensure_proxy_network || fail "création du réseau du proxy" "creating the proxy network"

step "Base de données et cache" "Database and cache"
compose up -d db redis --wait >/dev/null 2>&1 || fail "démarrage de la base de données" "starting the database"
compose run --rm migrate >"$ONLINE_DIR/last-install.log" 2>&1 || fail "migrations (voir $ONLINE_DIR/last-install.log)" "migrations (see $ONLINE_DIR/last-install.log)"

step "Démarrage des services" "Starting the services"
compose up -d --wait >>"$ONLINE_DIR/last-install.log" 2>&1 || fail "démarrage des services (voir $ONLINE_DIR/last-install.log)" "starting the services (see $ONLINE_DIR/last-install.log)"

step "Compte administrateur" "Admin account"
ADMIN_PASSWORD="$(hex 12)-$(hex 6)"
compose exec -T -e PLATFORM_ADMIN_PASSWORD="$ADMIN_PASSWORD" api \
  php artisan platform:create-admin "$ADMIN_EMAIL" --password-from-env=PLATFORM_ADMIN_PASSWORD >/dev/null 2>&1 \
  || fail "compte de l'administrateur" "admin account"
(
  umask 077
  {
    echo "VoteSystem : identifiants / credentials"
    echo "Adresse du site / Site address : $ENV_SITE_URL"
    echo "Administrateur de la plateforme / Platform admin : $ADMIN_EMAIL"
    echo "Mot de passe / Password : $ADMIN_PASSWORD"
    echo "Changez-le à la première connexion ; l'authentification en deux étapes est demandée."
    echo "Change it at the first sign-in; two-step authentication is asked."
  } > "$CREDENTIALS_FILE"
)
chown "${SUDO_USER:-root}" "$CREDENTIALS_FILE" 2>/dev/null
unset ADMIN_PASSWORD

if [ "$OPEN_FIREWALL" = 1 ]; then
  step "Pare-feu" "Firewall"
  ok=1
  for p in "${fw_ports[@]}"; do firewall_open "$fw" "$p" 0.0.0.0/0 || ok=0; done
  [ "$ok" = 1 ] && say "  ports 80 et 443 ouverts" "  ports 80 and 443 opened" || warn "  l'ouverture a échoué : ouvrez les ports à la main" "  opening failed: open the ports by hand"
fi

MAIL_RESULT=""
if [ "$ENV_MAIL_MAILER" = smtp ]; then
  step "Courriel : message d'essai" "Mail: test message"
  if out="$(compose exec -T api php artisan mail:test "$ADMIN_EMAIL" 2>&1)"; then
    MAIL_RESULT="ok"; say "  message d'essai envoyé à $ADMIN_EMAIL" "  test message sent to $ADMIN_EMAIL"
  else
    MAIL_RESULT="failed"
    warn "  l'envoi a échoué (l'installation continue) :" "  sending failed (the installation goes on):"
    printf '%s\n' "$out" | sed 's/^/    /' >&2
    warn "  corrigez $ONLINE_DIR/.env puis : votectl mail test $ADMIN_EMAIL" "  fix $ONLINE_DIR/.env then run: votectl mail test $ADMIN_EMAIL"
  fi
fi

step "Vérification du site" "Checking the site"
if [ "$BEHIND_PROXY" = 1 ]; then
  wait_for_url 30 "http://127.0.0.1:$ENV_HTTP_PORT/api/v1/health" && SITE_OK=1 || SITE_OK=0
else
  wait_for_url 60 -k --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/api/v1/health" && SITE_OK=1 || SITE_OK=0
fi
if [ "$SITE_OK" = 1 ]; then
  say "  le site répond" "  the site answers"
else
  warn "  le site ne répond pas encore : si le certificat n'est pas obtenu, vérifiez le DNS et les ports 80 et 443 (docker compose -f $ONLINE_DIR/compose.prod.yaml logs proxy)" \
       "  the site does not answer yet: if the certificate is not obtained, check the DNS and ports 80 and 443 (docker compose -f $ONLINE_DIR/compose.prod.yaml logs proxy)"
fi

# ---- the audit key take-away ------------------------------------------------------------------------------------------------
TAKEAWAY="$TAKEAWAY_DIR/votesystem-cle-audit-A-EMPORTER.txt"
(
  umask 077
  {
    echo "CLÉ PRIVÉE DE L'AUDIT DES VOTES / VOTE AUDIT PRIVATE KEY"
    echo
    echo "$AUDIT_PRIVATE"
    echo
    echo "FR : cette clé ne doit PAS rester sur ce serveur. Copiez ce fichier ailleurs (clé USB, gestionnaire de mots de passe),"
    echo "     puis supprimez ce fichier. Sans cette clé, l'audit des votes de cette installation ne pourra jamais être lu."
    echo "EN : this key must NOT stay on this server. Copy this file elsewhere (USB stick, password manager), then delete it."
    echo "     Without this key the vote audit of this installation can never be read."
  } > "$TAKEAWAY"
)
chown "${SUDO_USER:-root}" "$TAKEAWAY" 2>/dev/null
unset AUDIT_PRIVATE

say "" ""
say "================ Installation terminée ================" "================ Installation finished ================"
say "  Site          : $ENV_SITE_URL" "  Site          : $ENV_SITE_URL"
say "  Identifiants  : $CREDENTIALS_FILE" "  Credentials   : $CREDENTIALS_FILE"
say "  Clé d'audit   : $TAKEAWAY" "  Audit key     : $TAKEAWAY"
say "" ""
say "  IMPORTANT : copiez la clé d'audit ailleurs, puis supprimez ce fichier du serveur." "  IMPORTANT: copy the audit key elsewhere, then delete that file from the server."
if [ "$ASSUME_YES" != 1 ]; then
  ask_yes_no "  La clé est-elle copiée ? Supprimer le fichier maintenant ?" "  Is the key copied? Delete the file now?" n \
    && { shred -u "$TAKEAWAY" 2>/dev/null || rm -f "$TAKEAWAY"; say "  Fichier supprimé." "  File deleted."; }
fi
say "  Commande d'administration : votectl help" "  Administration command: votectl help"
