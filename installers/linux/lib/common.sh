# Shared helpers of the Linux installer. Sourced, never executed.
# Messages come in French (default) and English: say "texte français" "english text".

VOTE_LANG="${VOTE_LANG:-}"
if [ -z "$VOTE_LANG" ]; then
  case "${LC_ALL:-${LANG:-fr}}" in en*) VOTE_LANG=en ;; *) VOTE_LANG=fr ;; esac
fi

say() { if [ "$VOTE_LANG" = en ]; then printf '%s\n' "$2"; else printf '%s\n' "$1"; fi; }
warn() { say "$1" "$2" >&2; }
die() { warn "ERREUR : $1" "ERROR: $2"; exit 1; }

# ask_yes_no "question fr" "question en" [default y|n]: returns 0 for yes. `--yes` (ASSUME_YES=1) answers yes.
ask_yes_no() {
  local default="${3:-y}" answer prompt
  [ "${ASSUME_YES:-0}" = 1 ] && return 0
  if [ "$default" = y ]; then prompt='[O/n]'; [ "$VOTE_LANG" = en ] && prompt='[Y/n]'; else prompt='[o/N]'; [ "$VOTE_LANG" = en ] && prompt='[y/N]'; fi
  if [ "$VOTE_LANG" = en ]; then printf '%s %s ' "$2" "$prompt"; else printf '%s %s ' "$1" "$prompt"; fi
  read -r answer || answer=''
  case "${answer:-$default}" in y|Y|o|O|yes|oui) return 0 ;; *) return 1 ;; esac
}
