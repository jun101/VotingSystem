#!/usr/bin/env bash
# VoteSystem, school edition, Linux installer (Debian 12 and 13, Ubuntu 24.04 and 26.04).
#
#   sudo ./installers/install.sh [install] [options]     first installation
#   sudo ./installers/install.sh update                  put this bundle in place of the installed version
#   sudo ./installers/install.sh uninstall [--purge-data]
#
# Options of install:
#   --yes                    answer every question with its default (a script's run)
#   --dry-run                checks, ports and the plan; changes nothing
#   --web-port N             the port of the site (default 80; the next free one is taken when it is busy)
#   --school NAME            the name of the school (default "Mon école")
#   --owner-email ADDRESS    the school's owner sign-in address (default directeur@ecole.local)
#   --admin-email ADDRESS    the platform admin's sign-in address (default admin@ecole.local)
#   --credentials-file FILE  where the accounts are written (default: the home of the person who ran sudo)
#   --lang fr|en             the language of this script's messages
#
# The script lives in the bundle (<bundle>/installers); it copies the bundle to /opt/votesystem and sets up the services.
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE="$(cd "$HERE/.." && pwd)"
# shellcheck source=lib/common.sh
. "$HERE/lib/common.sh"
. "$HERE/lib/preflight.sh"
. "$HERE/lib/ports.sh"
. "$HERE/lib/firewall.sh"
. "$HERE/lib/setup.sh"

ACTION=install
ASSUME_YES=0
DRY_RUN=0
PURGE=0
WEB_PORT=80
SCHOOL="Mon école"
OWNER_EMAIL="directeur@ecole.local"
ADMIN_EMAIL="admin@ecole.local"
CREDENTIALS_FILE=""

case "${1:-}" in install|update|uninstall) ACTION="$1"; shift ;; esac
while [ $# -gt 0 ]; do
  case "$1" in
    --yes|-y) ASSUME_YES=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --purge-data) PURGE=1 ;;
    --web-port) shift; WEB_PORT="${1:-80}" ;;
    --school) shift; SCHOOL="${1:-$SCHOOL}" ;;
    --owner-email) shift; OWNER_EMAIL="${1:-$OWNER_EMAIL}" ;;
    --admin-email) shift; ADMIN_EMAIL="${1:-$ADMIN_EMAIL}" ;;
    --credentials-file) shift; CREDENTIALS_FILE="${1:-}" ;;
    --lang) shift; VOTE_LANG="${1:-fr}" ;;
    -h|--help) sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "option inconnue : $1" "unknown option: $1" ;;
  esac
  shift
done
export ASSUME_YES

if [ "$DRY_RUN" = 1 ]; then
  # A dry run changes nothing and needs no administrator rights.
  preflight_root() { say "  droits : essai à blanc, aucun droit requis" "  rights: dry run, none needed"; return 0; }
fi

# ---- uninstall --------------------------------------------------------------------------------------------------------

do_uninstall() {
  [ "$(id -u)" -eq 0 ] || die "lancez le script avec sudo" "run the script with sudo"
  say "Désinstallation de VoteSystem" "Uninstalling VoteSystem"

  if [ -x "$ROOT_LINK/bin/votectl" ] && [ "$PURGE" = 1 ]; then
    say "  sauvegarde finale avant de supprimer les données..." "  final backup before the data is removed..."
    VOTECTL_CONF="$ETC/votectl.conf" "$ROOT_LINK/bin/votectl" backup --out "/root" >/dev/null 2>&1 \
      && say "  sauvegarde : /root/votesystem-backup-*.tar" "  backup: /root/votesystem-backup-*.tar"
  fi

  local s
  for s in "${SERVICES[@]}"; do
    systemctl disable --now "votesystem-$s.service" >/dev/null 2>&1
    rm -f "$UNITS/votesystem-$s.service"
  done
  systemctl disable --now votesystem-backup.timer >/dev/null 2>&1
  rm -f "$UNITS/votesystem-backup.timer" "$UNITS/votesystem-backup.service"
  systemctl daemon-reload

  firewall_close
  rm -f /usr/local/bin/votectl
  rm -rf "${PREFIX:?}/releases" "${PREFIX:?}/current"

  if [ "$PURGE" = 1 ]; then
    rm -rf "${DATA:?}" "${ETC:?}" "${BACKUPS:?}" "${PREFIX:?}/installer"
    rmdir "$PREFIX" 2>/dev/null
    userdel "$SVC_USER" >/dev/null 2>&1
    say "Services, données et configuration supprimés." "Services, data and configuration removed."
  else
    say "Services supprimés. Les données ($DATA), la configuration ($ETC) et les sauvegardes ($BACKUPS) sont conservées." \
        "Services removed. The data ($DATA), the configuration ($ETC) and the backups ($BACKUPS) are kept."
    say "Pour tout effacer : $PREFIX/installer/install.sh uninstall --purge-data" "To erase everything: $PREFIX/installer/install.sh uninstall --purge-data"
  fi
}

# ---- update -----------------------------------------------------------------------------------------------------------

do_update() {
  [ "$(id -u)" -eq 0 ] || die "lancez le script avec sudo" "run the script with sudo"
  [ -L "$ROOT_LINK" ] || die "aucune installation trouvée : lancez d'abord l'installation" "no installation found: run the installation first"

  local new old target
  new="$(cat "$BUNDLE/VERSION" 2>/dev/null)"
  old="$(cat "$ROOT_LINK/VERSION" 2>/dev/null)"
  [ -n "$new" ] || die "ce dossier n'est pas un paquet VoteSystem" "this folder is not a VoteSystem bundle"
  target="$PREFIX/releases/$new"
  say "Mise à jour : $old -> $new" "Update: $old -> $new"
  [ "$new" != "$old" ] || die "cette version est déjà installée" "this version is already installed"
  [ ! -d "$target" ] || die "le dossier $target existe déjà" "the folder $target already exists"

  say "  sauvegarde avant la mise à jour..." "  backup before the update..."
  VOTECTL_CONF="$ETC/votectl.conf" runuser -u "$SVC_USER" -- "$ROOT_LINK/bin/votectl" backup >/dev/null 2>&1 \
    || die "la sauvegarde a échoué : rien n'a été modifié" "the backup failed: nothing was changed"

  install_release "$BUNDLE" "$new" || die "la copie a échoué" "the copy failed"
  chown -R root:root "$target"
  systemctl stop votesystem-proxy votesystem-web votesystem-api votesystem-queue votesystem-scheduler >/dev/null 2>&1
  switch_release "$new"
  chown -R "$SVC_USER:$SVC_USER" "$DATA/storage" "$DATA/bootstrap-cache"

  # The migration output goes to a file (a failed migration prints a long trace): the screen gets one line.
  local log="$DATA/last-update.log"
  if migrate >"$log" 2>&1 && systemctl restart votesystem-api votesystem-queue votesystem-scheduler votesystem-web votesystem-proxy \
     && wait_for_http "http://127.0.0.1:$(grep '^WEB_PORT=' "$ETC/env" | cut -d= -f2)/api/v1/health" 90; then
    say "Mise à jour terminée : version $new." "Update finished: version $new."
    return 0
  fi

  warn "La mise à jour a échoué : retour à la version $old (détails : $log)." "The update failed: going back to version $old (details: $log)."
  chown "$SVC_USER:$SVC_USER" "$log" 2>/dev/null
  systemctl stop votesystem-proxy votesystem-web votesystem-api votesystem-queue votesystem-scheduler >/dev/null 2>&1
  switch_release "$old"
  rm -rf "${target:?}"
  systemctl start votesystem-api votesystem-queue votesystem-scheduler votesystem-web votesystem-proxy >/dev/null 2>&1
  if wait_for_http "http://127.0.0.1:$(grep '^WEB_PORT=' "$ETC/env" | cut -d= -f2)/api/v1/health" 90; then
    say "La version $old fonctionne de nouveau." "Version $old is running again."
  else
    warn "La version $old ne répond pas : restaurez la sauvegarde (votectl restore) ou contactez le support." \
         "Version $old does not answer: restore the backup (votectl restore) or contact support."
  fi
  warn "Les migrations déjà passées ne sont pas défaites : en cas de doute, restaurez la sauvegarde (votectl restore)." \
       "Migrations that already ran are not undone: if in doubt, restore the backup (votectl restore)."
  return 1
}

case "$ACTION" in
  uninstall) do_uninstall; exit $? ;;
  update) do_update; exit $? ;;
esac

# ---- install: the questions and the plan -------------------------------------------------------------------------------

say "VoteSystem : installation (édition école)" "VoteSystem: installation (school edition)"
say "" ""
preflight_run || die "les vérifications ont échoué, rien n'a été modifié" "checks failed, nothing was changed"

if [ "$DRY_RUN" != 1 ] && [ -L "$ROOT_LINK" ]; then
  die "VoteSystem est déjà installé. Pour le mettre à jour : $0 update" "VoteSystem is already installed. To update it: $0 update"
fi
if [ "$DRY_RUN" != 1 ] && { [ -f "$ETC/env" ] || [ -d "$DATA/mariadb/mysql" ]; }; then
  die "des données d'une installation précédente existent ($DATA, $ETC). Pour repartir de zéro : $0 uninstall --purge-data" \
      "data from a previous installation exists ($DATA, $ETC). To start clean: $0 uninstall --purge-data"
fi
[ "$DRY_RUN" = 1 ] || [ -f "$BUNDLE/VERSION" ] || die "ce dossier n'est pas un paquet VoteSystem (VERSION manquant)" "this folder is not a VoteSystem bundle (VERSION missing)"

say "" ""
say "Ports :" "Ports:"
chosen_web="$(next_free_port "$WEB_PORT")" || die "aucun port libre à partir de $WEB_PORT" "no free port from $WEB_PORT"
if [ "$chosen_web" != "$WEB_PORT" ]; then
  say "  le port $WEB_PORT est occupé, le port $chosen_web sera utilisé" "  port $WEB_PORT is busy, port $chosen_web will be used"
else
  say "  site web : port $chosen_web" "  web site: port $chosen_web"
fi
reserved=("$chosen_web")
declare -A INTERNAL
for name in api api_admin web_internal database redis; do
  p="$(random_free_port "${reserved[@]}")" || die "plus de port libre" "no free port left"
  reserved+=("$p")
  INTERNAL[$name]="$p"
  say "  $name : 127.0.0.1:$p (interne)" "  $name: 127.0.0.1:$p (internal)"
done

say "" ""
fw="$(firewall_detect)"
say "Pare-feu : $fw" "Firewall: $fw"
OPEN_FIREWALL=0
nets=()
if [ "$fw" != none ]; then
  mapfile -t nets < <(firewall_local_networks)
  if [ "${#nets[@]}" -eq 0 ]; then
    warn "  aucun réseau local privé trouvé : aucune règle ne sera ajoutée" "  no private local network found: no rule will be added"
  else
    for n in "${nets[@]}"; do say "  règle : $(firewall_rule_text "$fw" "$chosen_web" "$n")" "  rule: $(firewall_rule_text "$fw" "$chosen_web" "$n")"; done
    if [ "$DRY_RUN" = 1 ]; then
      say "  (essai à blanc : rien n'est ouvert)" "  (dry run: nothing is opened)"
    elif ask_yes_no "Ouvrir le port $chosen_web pour le réseau local ?" "Open port $chosen_web for the local network?" y; then
      OPEN_FIREWALL=1
    else
      say "  port laissé fermé : ouvrez-le à la main avec la règle ci-dessus" "  port left closed: open it by hand with the rule above"
    fi
  fi
fi

HOST_ADDRESS="$(server_address)"
SITE_URL="http://$HOST_ADDRESS"
[ "$chosen_web" = 80 ] || SITE_URL="$SITE_URL:$chosen_web"
if [ -z "$CREDENTIALS_FILE" ]; then
  home="$(getent passwd "${SUDO_USER:-root}" | cut -d: -f6)"
  CREDENTIALS_FILE="${home:-/root}/votesystem-identifiants.txt"
fi
TAKEAWAY_DIR="$(dirname "$CREDENTIALS_FILE")"

say "" ""
say "Plan :" "Plan:"
say "  école : $SCHOOL" "  school: $SCHOOL"
say "  responsable : $OWNER_EMAIL" "  owner: $OWNER_EMAIL"
say "  administrateur de la plateforme : $ADMIN_EMAIL" "  platform admin: $ADMIN_EMAIL"
say "  adresse du site : $SITE_URL" "  site address: $SITE_URL"
say "  fichier des identifiants : $CREDENTIALS_FILE" "  credentials file: $CREDENTIALS_FILE"

if [ "$DRY_RUN" = 1 ]; then
  say "" ""
  say "Essai à blanc terminé : rien n'a été modifié." "Dry run finished: nothing was changed."
  exit 0
fi

say "" ""
ask_yes_no "Installer maintenant ?" "Install now?" y || { say "Installation annulée." "Installation cancelled."; exit 0; }

# ---- install: the steps -------------------------------------------------------------------------------------------------

fail() { warn "ÉCHEC : $1" "FAILED: $2"; warn "Rien de plus n'a été fait. Voir : journalctl -u 'votesystem-*'" "Nothing more was done. See: journalctl -u 'votesystem-*'"; exit 1; }
step() { say "==> $1" "==> $2"; }

VERSION="$(cat "$BUNDLE/VERSION")"
ENV_WEB_PORT="$chosen_web"
ENV_API_PORT="${INTERNAL[api]}"
ENV_API_ADMIN_PORT="${INTERNAL[api_admin]}"
ENV_WEB_INTERNAL_PORT="${INTERNAL[web_internal]}"
ENV_DB_PORT="${INTERNAL[database]}"
ENV_REDIS_PORT="${INTERNAL[redis]}"
ENV_SITE_URL="$SITE_URL"
ENV_APP_NAME="VoteSystem"
ENV_APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
ENV_HASH_KEY="$(hex 32)"
ENV_DB_PASSWORD="$(hex 24)"
ENV_DB_MIGRATOR_PASSWORD="$(hex 24)"
ENV_DB_ROOT_PASSWORD="$(hex 24)"
ENV_REDIS_PASSWORD="$(hex 24)"

step "Utilisateur et dossiers" "User and folders"
setup_user && setup_dirs || fail "utilisateur ou dossiers" "user or folders"

step "Copie de la version $VERSION" "Copying version $VERSION"
install_release "$BUNDLE" "$VERSION" || fail "copie des fichiers" "copying the files"
chown -R root:root "$PREFIX/releases/$VERSION"
chown -R "$SVC_USER:$SVC_USER" "$DATA/storage" "$DATA/bootstrap-cache"
switch_release "$VERSION"
ln -sfn "$ROOT_LINK/bin/votectl" /usr/local/bin/votectl
mkdir -p "$PREFIX/installer" && cp -a "$HERE/." "$PREFIX/installer/"

step "Clés de l'audit des votes" "Vote audit keys"
KEYPAIR="$(audit_keypair)" || fail "clés" "keys"
ENV_AUDIT_PUBLIC="${KEYPAIR%% *}"
AUDIT_PRIVATE="${KEYPAIR##* }"
unset KEYPAIR
[ "${#ENV_AUDIT_PUBLIC}" -eq 64 ] && [ "${#AUDIT_PRIVATE}" -eq 64 ] || fail "clés de l'audit" "audit keys"

step "Configuration" "Configuration"
write_env && write_configs "$HERE/templates" || fail "configuration" "configuration"
install_units "$HERE/templates" || fail "services" "services"

step "Base de données" "Database"
init_database || fail "initialisation de la base de données" "database initialisation"
systemctl start votesystem-mariadb.service votesystem-redis.service || fail "démarrage de la base et du cache" "starting the database and the cache"
for i in $(seq 1 60); do "$ROOT_LINK/mariadb/bin/mariadb-admin" --no-defaults --socket=/run/votesystem/mariadb.sock ping >/dev/null 2>&1 && break; sleep 1; done
migrate || fail "migrations" "migrations"

step "Comptes" "Accounts"
# The commands run as the service user: they append to a file that user owns, and the file is then moved into place.
CRED_TMP="$(mktemp "$DATA/credentials.XXXXXX")"
chown "$SVC_USER:$SVC_USER" "$CRED_TMP"
chmod 600 "$CRED_TMP"
{
  echo "VoteSystem : identifiants / credentials"
  echo "Adresse du site / Site address : $SITE_URL"
  echo "Ces mots de passe sont à usage unique : changez-les à la première connexion."
  echo "These passwords are for one use: change them at the first sign-in."
  echo
} >> "$CRED_TMP"
artisan owner:create "$OWNER_EMAIL" --institution="$SCHOOL" --credentials-file="$CRED_TMP" >/dev/null 2>&1 || fail "compte du responsable" "owner account"
PLATFORM_ADMIN_PASSWORD="$(hex 16)" artisan platform:create-admin "$ADMIN_EMAIL" --password-from-env=PLATFORM_ADMIN_PASSWORD >/dev/null 2>&1 \
  || fail "compte de l'administrateur" "admin account"
echo "Administrateur de la plateforme / Platform admin :" >> "$CRED_TMP"
artisan user:reset-password "$ADMIN_EMAIL" --credentials-file="$CRED_TMP" >/dev/null 2>&1 || fail "mot de passe de l'administrateur" "admin password"
install -m 600 -o "${SUDO_USER:-root}" "$CRED_TMP" "$CREDENTIALS_FILE" || fail "écriture du fichier des identifiants" "writing the credentials file"
shred -u "$CRED_TMP" 2>/dev/null || rm -f "$CRED_TMP"

step "Démarrage des services" "Starting the services"
enable_services
start_services api queue scheduler web proxy || fail "démarrage des services" "starting the services"
systemctl start votesystem-backup.timer >/dev/null 2>&1
if ! wait_for_http "http://127.0.0.1:$ENV_WEB_PORT/api/v1/health" 90; then
  fail "le site ne répond pas sur le port $ENV_WEB_PORT" "the site does not answer on port $ENV_WEB_PORT"
fi

if [ "$OPEN_FIREWALL" = 1 ]; then
  step "Pare-feu" "Firewall"
  firewall_open "$fw" "$chosen_web" "${nets[@]}" && say "  port $chosen_web ouvert pour le réseau local" "  port $chosen_web opened for the local network" \
    || warn "  l'ouverture a échoué : ouvrez le port à la main" "  opening failed: open the port by hand"
fi

# ---- the audit key take-away ------------------------------------------------------------------------------------------------
TAKEAWAY="$TAKEAWAY_DIR/votesystem-cle-audit-A-EMPORTER.txt"
umask 077
{
  echo "CLÉ PRIVÉE DE L'AUDIT DES VOTES / VOTE AUDIT PRIVATE KEY"
  echo
  echo "$AUDIT_PRIVATE"
  echo
  echo "FR : cette clé ne doit PAS rester sur ce serveur. Copiez ce fichier sur une clé USB, rangez-la en lieu sûr,"
  echo "     puis supprimez ce fichier. Sans cette clé, l'audit des votes de cette installation ne pourra jamais être lu."
  echo "EN : this key must NOT stay on this server. Copy this file to a USB stick, keep it safe, then delete this file."
  echo "     Without this key the vote audit of this installation can never be read."
} > "$TAKEAWAY"
chown "${SUDO_USER:-root}" "$TAKEAWAY"
chmod 600 "$TAKEAWAY"
unset AUDIT_PRIVATE

say "" ""
say "================ Installation terminée ================" "================ Installation finished ================"
say "  Site          : $SITE_URL" "  Site          : $SITE_URL"
say "  Identifiants  : $CREDENTIALS_FILE" "  Credentials   : $CREDENTIALS_FILE"
say "  Clé d'audit   : $TAKEAWAY" "  Audit key     : $TAKEAWAY"
say "" ""
say "  IMPORTANT : copiez la clé d'audit sur une clé USB, puis supprimez ce fichier du serveur." \
    "  IMPORTANT: copy the audit key to a USB stick, then delete that file from the server."
if [ "$ASSUME_YES" != 1 ]; then
  ask_yes_no "  La clé est-elle copiée ? Supprimer le fichier maintenant ?" "  Is the key copied? Delete the file now?" n \
    && { shred -u "$TAKEAWAY" 2>/dev/null || rm -f "$TAKEAWAY"; say "  Fichier supprimé." "  File deleted."; }
fi
say "  Commande d'administration : votectl help" "  Administration command: votectl help"
