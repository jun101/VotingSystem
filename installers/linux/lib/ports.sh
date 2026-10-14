# Port probing. A port is "in use" when anything listens on it, on any address.

# port_in_use PORT
port_in_use() {
  ss -H -ltn "sport = :$1" 2>/dev/null | grep -q .
}

# next_free_port START [RESERVED...]: the first port from START that nothing listens on and
# that is not in RESERVED (ports chosen earlier in the same run). Prints it; fails above 65535.
next_free_port() {
  local port="$1" r taken
  shift
  while [ "$port" -le 65535 ]; do
    taken=0
    for r in "$@"; do [ "$r" = "$port" ] && taken=1; done
    if [ "$taken" = 0 ] && ! port_in_use "$port"; then
      printf '%s\n' "$port"
      return 0
    fi
    port=$((port + 1))
  done
  return 1
}

# random_free_port [RESERVED...]: a free port between 20000 and 59999, for the internal services.
random_free_port() {
  local try port r taken
  for try in $(seq 1 200); do
    port=$((20000 + RANDOM % 40000))
    taken=0
    for r in "$@"; do [ "$r" = "$port" ] && taken=1; done
    if [ "$taken" = 0 ] && ! port_in_use "$port"; then
      printf '%s\n' "$port"
      return 0
    fi
  done
  return 1
}
