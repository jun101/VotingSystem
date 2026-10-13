#!/usr/bin/env bash
# VoteSystem, school edition, Linux installer (Debian 12 and 13, Ubuntu 24.04 and 26.04).
#
#   sudo ./install.sh [--yes] [--dry-run] [--web-port N] [--lang fr|en]
#
# Steps: checks, ports, the plan, the firewall question. The unpacking of the bundle, the
# database, the accounts and the services come with the bundle build (slice 19c, steps C and D).
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$HERE/lib/common.sh"
. "$HERE/lib/preflight.sh"
. "$HERE/lib/ports.sh"
. "$HERE/lib/firewall.sh"

ASSUME_YES=0
DRY_RUN=0
WEB_PORT=80
while [ $# -gt 0 ]; do
  case "$1" in
    --yes|-y) ASSUME_YES=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --web-port) shift; WEB_PORT="${1:-80}" ;;
    --lang) shift; VOTE_LANG="${1:-fr}" ;;
    -h|--help) sed -n '2,8p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) die "option inconnue : $1" "unknown option: $1" ;;
  esac
  shift
done
export ASSUME_YES

say "VoteSystem : installation (édition école)" "VoteSystem: installation (school edition)"
say "" ""
if [ "$DRY_RUN" = 1 ]; then
  # A dry run changes nothing and needs no administrator rights.
  preflight_root() { say "  droits : essai à blanc, aucun droit requis" "  rights: dry run, none needed"; return 0; }
fi
preflight_run || die "les vérifications ont échoué, rien n'a été modifié" "checks failed, nothing was changed"

say "" ""
say "Ports :" "Ports:"
chosen_web="$(next_free_port "$WEB_PORT")" || die "aucun port libre à partir de $WEB_PORT" "no free port from $WEB_PORT"
if [ "$chosen_web" != "$WEB_PORT" ]; then
  say "  le port $WEB_PORT est occupé, le port $chosen_web sera utilisé" "  port $WEB_PORT is busy, port $chosen_web will be used"
else
  say "  site web : port $chosen_web" "  web site: port $chosen_web"
fi
reserved=("$chosen_web")
for name in api web_internal database redis; do
  p="$(random_free_port "${reserved[@]}")" || die "plus de port libre" "no free port left"
  reserved+=("$p")
  say "  $name : 127.0.0.1:$p (interne)" "  $name: 127.0.0.1:$p (internal)"
done

say "" ""
fw="$(firewall_detect)"
say "Pare-feu : $fw" "Firewall: $fw"
if [ "$fw" != none ]; then
  mapfile -t nets < <(firewall_local_networks)
  if [ "${#nets[@]}" -eq 0 ]; then
    warn "  aucun réseau local privé trouvé : aucune règle ne sera ajoutée" "  no private local network found: no rule will be added"
  else
    for n in "${nets[@]}"; do say "  règle : $(firewall_rule_text "$fw" "$chosen_web" "$n")" "  rule: $(firewall_rule_text "$fw" "$chosen_web" "$n")"; done
    if [ "$DRY_RUN" = 1 ]; then
      say "  (essai à blanc : rien n'est ouvert)" "  (dry run: nothing is opened)"
    elif ask_yes_no "Ouvrir le port $chosen_web pour le réseau local ?" "Open port $chosen_web for the local network?" y; then
      firewall_open "$fw" "$chosen_web" "${nets[@]}" && say "  port ouvert" "  port opened" \
        || warn "  l'ouverture a échoué : ouvrez le port à la main" "  opening failed: open the port by hand"
    else
      say "  port laissé fermé : ouvrez-le à la main avec la règle ci-dessus" "  port left closed: open it by hand with the rule above"
    fi
  fi
fi

say "" ""
if [ "$DRY_RUN" = 1 ]; then
  say "Essai à blanc terminé : rien n'a été modifié." "Dry run finished: nothing was changed."
  exit 0
fi
die "le paquet logiciel est introuvable : cette version du script n'installe pas encore les services" \
    "the software bundle is missing: this version of the script does not install the services yet"
