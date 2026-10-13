# The steps that change the system. Sourced by install.sh after the checks, the ports and the questions.
# Paths come from the variables below so a test can point them at a folder of its own.

PREFIX="${VOTE_PREFIX:-/opt/votesystem}"
ETC="${VOTE_ETC:-/etc/votesystem}"
DATA="${VOTE_DATA:-/var/lib/votesystem}"
BACKUPS="${VOTE_BACKUPS:-/var/backups/votesystem}"
UNITS="${VOTE_UNITS:-/etc/systemd/system}"
SVC_USER="${VOTE_USER:-votesystem}"
ROOT_LINK="$PREFIX/current"

SERVICES=(mariadb redis api queue scheduler web proxy)

hex() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

# render TEMPLATE KEY=VALUE...: the template with every @KEY@ replaced.
render() {
  local template="$1" sedargs=() pair
  shift
  for pair in "$@"; do
    sedargs+=(-e "s|@${pair%%=*}@|${pair#*=}|g")
  done
  sed "${sedargs[@]}" "$template"
}

# The private IPv4 address the school's computers would use to reach this server (the first one), or the host name.
server_address() {
  local ip
  ip="$(ip -o -4 addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | grep -E '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' | head -1)"
  printf '%s' "${ip:-$(hostname -f 2>/dev/null || hostname)}"
}

setup_user() {
  getent group "$SVC_USER" >/dev/null || groupadd --system "$SVC_USER"
  getent passwd "$SVC_USER" >/dev/null || \
    useradd --system --gid "$SVC_USER" --home-dir "$DATA" --no-create-home --shell /usr/sbin/nologin "$SVC_USER"
}

setup_dirs() {
  install -d -m 0755 -o root -g root "$PREFIX" "$PREFIX/releases"
  install -d -m 0750 -o root -g "$SVC_USER" "$ETC"
  install -d -m 0750 -o "$SVC_USER" -g "$SVC_USER" "$DATA" "$DATA/mariadb" "$DATA/redis" "$DATA/storage" "$DATA/bootstrap-cache" \
    "$DATA/xdg-data" "$DATA/xdg-config"
  install -d -m 0755 -o "$SVC_USER" -g "$SVC_USER" "$DATA/media"
  install -d -m 0700 -o "$SVC_USER" -g "$SVC_USER" "$BACKUPS"
}

# install_release BUNDLE_ROOT VERSION: the bundle's files under releases/VERSION, and the writable places linked in.
install_release() {
  local bundle="$1" version="$2" target="$PREFIX/releases/$2"
  [ -n "$version" ] && [ -d "$bundle" ] || return 1
  mkdir -p "$target"
  cp -a "$bundle/." "$target/"
  # What the application writes lives outside the release, so a release can be replaced as a whole.
  if [ ! -d "$DATA/storage/framework" ]; then
    cp -a "$target/api/storage/." "$DATA/storage/"
  fi
  rm -rf "${target:?}/api/storage" "${target:?}/api/bootstrap/cache"
  ln -s "$DATA/storage" "$target/api/storage"
  ln -s "$DATA/bootstrap-cache" "$target/api/bootstrap/cache"
  chown -R "$SVC_USER:$SVC_USER" "$DATA/storage" "$DATA/bootstrap-cache"
}

switch_release() {
  ln -sfn "$PREFIX/releases/$1" "$ROOT_LINK.new"
  mv -T "$ROOT_LINK.new" "$ROOT_LINK"
}

# write_env: /etc/votesystem/env from the values chosen by the installer (the variables ENV_* of the caller).
write_env() {
  local file="$ETC/env"
  umask 077
  {
    echo "# VoteSystem (school edition). Written by the installer; secrets: keep this file private."
    echo "DEPLOYMENT_MODE=school"
    echo "APP_NAME=\"$ENV_APP_NAME\""
    echo "APP_ENV=production"
    echo "APP_DEBUG=false"
    echo "APP_URL=$ENV_SITE_URL"
    echo "SITE_URL=$ENV_SITE_URL"
    echo "APP_KEY=$ENV_APP_KEY"
    echo "VOTE_AUDIT_PUBLIC_KEY=$ENV_AUDIT_PUBLIC"
    echo "CREDENTIAL_HASH_KEY=$ENV_HASH_KEY"
    echo "LOG_CHANNEL=stderr"
    echo "LOG_LEVEL=info"
    echo "SESSION_SECURE_COOKIE=false"
    echo "SESSION_COOKIE=votesystem_session"
    echo "MAIL_MAILER=log"
    echo "MAIL_FROM_ADDRESS=no-reply@localhost"
    echo "DB_HOST=127.0.0.1"
    echo "DB_PORT=$ENV_DB_PORT"
    echo "DB_DATABASE=votesystem"
    echo "DB_USERNAME=app"
    echo "DB_PASSWORD=$ENV_DB_PASSWORD"
    echo "DB_APP_USERNAME=app"
    echo "DB_MIGRATOR_USERNAME=migrator"
    echo "DB_MIGRATOR_PASSWORD=$ENV_DB_MIGRATOR_PASSWORD"
    echo "DB_ROOT_PASSWORD=$ENV_DB_ROOT_PASSWORD"
    echo "REDIS_HOST=127.0.0.1"
    echo "REDIS_PORT=$ENV_REDIS_PORT"
    echo "REDIS_PASSWORD=$ENV_REDIS_PASSWORD"
    echo "MEDIA_ROOT=$DATA/media"
    echo "WEB_PORT=$ENV_WEB_PORT"
    echo "API_PORT=$ENV_API_PORT"
    echo "API_ADMIN_PORT=$ENV_API_ADMIN_PORT"
    echo "WEB_INTERNAL_PORT=$ENV_WEB_INTERNAL_PORT"
    echo "API_INTERNAL_URL=http://127.0.0.1:$ENV_API_PORT"
    echo "PORT=$ENV_WEB_INTERNAL_PORT"
  } > "$file"
  chown "root:$SVC_USER" "$file"
  chmod 640 "$file"
}

write_configs() {
  local root="$ROOT_LINK" db_port="$ENV_DB_PORT" redis_port="$ENV_REDIS_PORT" tpl="$1"
  render "$tpl/mariadb.cnf.tmpl" "ROOT=$root" "DB_PORT=$db_port" > "$ETC/mariadb.cnf"
  render "$tpl/redis.conf.tmpl" "REDIS_PORT=$redis_port" "REDIS_PASSWORD=$ENV_REDIS_PASSWORD" > "$ETC/redis.conf"
  render "$tpl/Caddyfile.tmpl" "ROOT=$root" > "$ETC/Caddyfile"
  render "$tpl/votectl.conf.tmpl" "ROOT=$root" > "$ETC/votectl.conf"
  chown "root:$SVC_USER" "$ETC/mariadb.cnf" "$ETC/redis.conf" "$ETC/Caddyfile" "$ETC/votectl.conf"
  chmod 640 "$ETC/redis.conf"
  chmod 644 "$ETC/mariadb.cnf" "$ETC/Caddyfile" "$ETC/votectl.conf"
}

install_units() {
  local tpl="$1" f name
  for f in "$tpl"/systemd/*; do
    name="$(basename "$f")"
    render "$f" "ROOT=$ROOT_LINK" > "$UNITS/$name"
    chmod 644 "$UNITS/$name"
  done
  systemctl daemon-reload
}

# init_database: the data folder, the root password, the two application accounts and the database. The server runs
# privately (no network, a socket in a folder only the service user can enter) while the root account has no password.
init_database() {
  local root="$ROOT_LINK" private sock
  private="$(mktemp -d "$DATA/init.XXXXXX")"
  chown "$SVC_USER:$SVC_USER" "$private"
  chmod 700 "$private"
  sock="$private/sock"

  runuser -u "$SVC_USER" -- "$root/mariadb/scripts/mariadb-install-db" --no-defaults --basedir="$root/mariadb" \
    --datadir="$DATA/mariadb" --auth-root-authentication-method=normal --skip-test-db >"$private/install.log" 2>&1 || return 1

  runuser -u "$SVC_USER" -- "$root/mariadb/bin/mariadbd" --no-defaults --basedir="$root/mariadb" --datadir="$DATA/mariadb" \
    --socket="$sock" --skip-networking --pid-file="$private/pid" >"$private/server.log" 2>&1 &
  local i
  for i in $(seq 1 60); do [ -S "$sock" ] && break; sleep 1; done
  [ -S "$sock" ] || return 1

  "$root/mariadb/bin/mariadb" --no-defaults --socket="$sock" -uroot <<SQL || return 1
ALTER USER 'root'@'localhost' IDENTIFIED BY '$ENV_DB_ROOT_PASSWORD';
DELETE FROM mysql.global_priv WHERE User='';
CREATE DATABASE votesystem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'app'@'%' IDENTIFIED BY '$ENV_DB_PASSWORD';
CREATE USER 'migrator'@'%' IDENTIFIED BY '$ENV_DB_MIGRATOR_PASSWORD';
GRANT SELECT ON votesystem.* TO 'app'@'%';
GRANT ALL PRIVILEGES ON votesystem.* TO 'migrator'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
  "$root/mariadb/bin/mariadb-admin" --no-defaults --socket="$sock" -uroot -p"$ENV_DB_ROOT_PASSWORD" shutdown >/dev/null 2>&1
  for i in $(seq 1 30); do [ -S "$sock" ] || break; sleep 1; done
  rm -rf "${private:?}"
}

# artisan ARGS...: the application's own commands as the service user, with the installation's environment.
artisan() {
  (
    set -a
    # shellcheck disable=SC1091
    . "$ETC/env"
    set +a
    cd "$ROOT_LINK/api" && runuser -u "$SVC_USER" -- env PATH="$ROOT_LINK/bin:/usr/bin:/bin" HOME="$DATA" "$ROOT_LINK/bin/php" artisan "$@"
  )
}

# migrate: the schema, with the migrator account, then the table-by-table rights of the application account.
migrate() {
  (
    set -a
    . "$ETC/env"
    set +a
    export DB_USERNAME="$DB_MIGRATOR_USERNAME" DB_PASSWORD="$DB_MIGRATOR_PASSWORD"
    cd "$ROOT_LINK/api" || exit 1
    runuser -u "$SVC_USER" -- env PATH="$ROOT_LINK/bin:/usr/bin:/bin" HOME="$DATA" DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" \
      sh -c '"$0" artisan package:discover --ansi >/dev/null && "$0" artisan migrate --force && "$0" artisan db:grant-app' "$ROOT_LINK/bin/php"
  )
}

# audit_keypair: prints "PUBLIC PRIVATE" (hex); the private half is for the operator, never kept on the server.
audit_keypair() {
  "$ROOT_LINK/bin/php" -r '$k = sodium_crypto_box_keypair(); echo bin2hex(sodium_crypto_box_publickey($k)), " ", bin2hex(sodium_crypto_box_secretkey($k));'
}

wait_for_http() {
  local url="$1" tries="${2:-60}" i
  for i in $(seq 1 "$tries"); do
    curl -s -o /dev/null --max-time 3 "$url" && return 0
    sleep 1
  done
  return 1
}

enable_services() {
  local s
  for s in "${SERVICES[@]}"; do
    systemctl enable "votesystem-$s.service" >/dev/null 2>&1
  done
  systemctl enable votesystem-backup.timer >/dev/null 2>&1
}

start_services() { local s; for s in "$@"; do systemctl start "votesystem-$s.service" || return 1; done; }
