# Firewall detection and the one rule the installer may add: the web port, for the local network.
# Nothing here runs without the question being answered (see install.sh). Every rule that is added
# is recorded in $FIREWALL_RECORD so that the uninstall can remove exactly those and nothing else.

FIREWALL_RECORD="${FIREWALL_RECORD:-/etc/votesystem/firewall.rules}"

# firewall_detect: prints ufw, firewalld, nftables, iptables or none (the first that is active).
firewall_detect() {
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then echo ufw; return; fi
  if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld 2>/dev/null; then echo firewalld; return; fi
  if command -v nft >/dev/null 2>&1 && nft list ruleset 2>/dev/null | grep -qE 'hook input.*policy drop'; then echo nftables; return; fi
  if command -v iptables >/dev/null 2>&1 && iptables -S INPUT 2>/dev/null | grep -q '^-P INPUT DROP'; then echo iptables; return; fi
  echo none
}

# firewall_local_networks: the private IPv4 networks this machine is on (CIDR), one per line.
firewall_local_networks() {
  ip -o -4 route show scope link 2>/dev/null | awk '{print $1}' | grep '/' | grep -E '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' | sort -u
}

# firewall_rule_text FIREWALL PORT CIDR: the exact command, for the question and for the log.
firewall_rule_text() {
  local fw="$1" port="$2" cidr="$3"
  case "$fw" in
    ufw) echo "ufw allow from $cidr to any port $port proto tcp comment votesystem" ;;
    firewalld) echo "firewall-cmd --permanent --add-rich-rule='rule family=ipv4 source address=$cidr port port=$port protocol=tcp accept' && firewall-cmd --reload" ;;
    nftables) echo "nft insert rule inet filter input ip saddr $cidr tcp dport $port accept comment votesystem" ;;
    iptables) echo "iptables -I INPUT -s $cidr -p tcp --dport $port -j ACCEPT -m comment --comment votesystem" ;;
    *) echo "" ;;
  esac
}

# firewall_open FIREWALL PORT CIDR...: adds the rules and records them. Returns 1 on a failure.
firewall_open() {
  local fw="$1" port="$2" cidr text rc=0
  shift 2
  mkdir -p "$(dirname "$FIREWALL_RECORD")"
  for cidr in "$@"; do
    text="$(firewall_rule_text "$fw" "$port" "$cidr")"
    [ -n "$text" ] || return 1
    if bash -c "$text" >/dev/null 2>&1; then
      printf '%s %s %s\n' "$fw" "$port" "$cidr" >> "$FIREWALL_RECORD"
    else
      rc=1
    fi
  done
  return "$rc"
}

# firewall_close: removes the rules recorded by firewall_open, then forgets them.
firewall_close() {
  local fw port cidr
  [ -f "$FIREWALL_RECORD" ] || return 0
  while read -r fw port cidr; do
    case "$fw" in
      ufw) ufw --force delete allow from "$cidr" to any port "$port" proto tcp >/dev/null 2>&1 ;;
      firewalld)
        firewall-cmd --permanent --remove-rich-rule="rule family=ipv4 source address=$cidr port port=$port protocol=tcp accept" >/dev/null 2>&1
        firewall-cmd --reload >/dev/null 2>&1 ;;
      nftables)
        local handle
        handle="$(nft -a list chain inet filter input 2>/dev/null | awk -v c="$cidr" -v p="$port" '$0 ~ "saddr "c && $0 ~ "dport "p && /votesystem/ {print $NF}' | head -1)"
        [ -n "$handle" ] && nft delete rule inet filter input handle "$handle" >/dev/null 2>&1 ;;
      iptables) iptables -D INPUT -s "$cidr" -p tcp --dport "$port" -j ACCEPT -m comment --comment votesystem >/dev/null 2>&1 ;;
    esac
  done < "$FIREWALL_RECORD"
  : > "$FIREWALL_RECORD"
}
