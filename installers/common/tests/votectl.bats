#!/usr/bin/env bats
# Runs in the test VM: bats votectl.bats. Every external command is a fake that records what it was asked.

setup() {
  ROOT="$(cd "$BATS_TEST_DIRNAME/.." && pwd)"
  T="$BATS_TEST_TMPDIR"
  mkdir -p "$T/bin" "$T/app" "$T/data/media" "$T/db" "$T/etc"
  export LOG="$T/calls.log"
  : > "$LOG"
  echo "1.4.0" > "$T/VERSION"
  echo "photo" > "$T/data/media/logo.webp"
  printf 'DB_MIGRATOR_USERNAME=migrator\nDB_MIGRATOR_PASSWORD=s3cret\nWEB_PORT=8080\n' > "$T/etc/env"

  # fake docker: records the call; a dump or a tar answers with bytes
  printf '%s\n' \
    '#!/bin/bash' \
    'echo "docker $*" >> "$LOG"' \
    'case "$*" in' \
    '  *mariadb-dump*) echo "-- dump from docker" ;;' \
    '  *"tar -C /var/www/media -czf"*) printf "fakemedia" ;;' \
    '  *"ps --format"*) echo "  api: running" ;;' \
    'esac' \
    'exit 0' > "$T/bin/docker"
  # fake php: records the working directory and the arguments
  printf '%s\n' '#!/bin/bash' 'echo "php $PWD $*" >> "$LOG"' 'exit 0' > "$T/bin/php"
  printf '%s\n' '#!/bin/bash' 'echo "mariadb-dump $*" >> "$LOG"' 'echo "-- native dump"' > "$T/db/mariadb-dump"
  printf '%s\n' '#!/bin/bash' 'echo "mariadb $*" >> "$LOG"' 'cat > /dev/null' > "$T/db/mariadb"
  printf '%s\n' '#!/bin/bash' 'echo "systemctl $*" >> "$LOG"' > "$T/bin/systemctl"
  chmod +x "$T"/bin/* "$T"/db/*
  export PATH="$T/bin:$PATH"

  {
    echo "DRIVER=native"
    echo "APP_DIR=$T/app"
    echo "PHP_BIN=$T/bin/php"
    echo "SERVICE_USER=$(id -un)"
    echo "DATA_DIR=$T/data"
    echo "BACKUP_DIR=$T/backups"
    echo "VERSION_FILE=$T/VERSION"
    echo "ENV_FILE=$T/etc/env"
    echo "DB_BIN_DIR=$T/db"
    echo "DB_SOCKET=$T/db.sock"
  } > "$T/votectl.conf"
  export VOTECTL_CONF="$T/votectl.conf"
  VOTECTL="$ROOT/votectl"
}

backup_file() { ls "$T"/backups/votesystem-backup-*.tar; }

@test "help exits 0 and an unknown command exits 1" {
  run "$VOTECTL" help
  [ "$status" -eq 0 ]
  [[ "$output" == *"votectl backup"* ]]
  run "$VOTECTL" frobnicate
  [ "$status" -eq 1 ]
}

@test "native: owner create calls the application's own command from the installed tree" {
  run "$VOTECTL" owner create dir@ecole.local --institution "Collège Les Pins" --name "Dir Pierre" --credentials-file /root/c.txt
  [ "$status" -eq 0 ]
  grep -q "php $T/app artisan owner:create dir@ecole.local --institution=Collège Les Pins --name=Dir Pierre --credentials-file=/root/c.txt" "$LOG"
}

@test "native: user reset-password, 2fa reset and user list are passed on" {
  "$VOTECTL" user reset-password prof@ecole.local
  "$VOTECTL" 2fa reset prof@ecole.local
  "$VOTECTL" user list --institution 3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa
  grep -q "artisan user:reset-password prof@ecole.local" "$LOG"
  grep -q "artisan auth:reset-two-factor prof@ecole.local" "$LOG"
  grep -q "artisan user:list --institution 3f1c0c1e" "$LOG"
}

@test "an unknown option is refused before anything runs" {
  run "$VOTECTL" owner create dir@ecole.local --institution X --frob 1
  [ "$status" -eq 1 ]
  [ ! -s "$LOG" ]
}

@test "docker: owner create goes through docker compose exec api" {
  sed -i 's/^DRIVER=.*/DRIVER=docker/' "$T/votectl.conf"
  echo "COMPOSE_FILE=$T/compose.yaml" >> "$T/votectl.conf"
  run "$VOTECTL" owner create dir@ecole.local --institution X
  [ "$status" -eq 0 ]
  grep -qE "docker compose -f $T/compose.yaml exec( -T)? api php artisan owner:create dir@ecole.local --institution=X" "$LOG"
}

@test "version and status read the installation" {
  run "$VOTECTL" version
  [ "$output" = "1.4.0" ]
  run "$VOTECTL" status
  [[ "$output" == *"VoteSystem 1.4.0 (native)"* ]]
  [[ "$output" == *"web port: 8080"* ]]
  [[ "$output" == *"last backup: none"* ]]
}

@test "backup writes a private archive with its checksum, manifest, dump and pictures" {
  run "$VOTECTL" backup
  [ "$status" -eq 0 ]
  file="$(backup_file)"
  [ "$(stat -c %a "$file")" = 600 ]
  (cd "$T/backups" && sha256sum -c --quiet "$(basename "$file").sha256")
  list="$(tar -tf "$file")"
  [[ "$list" == *MANIFEST* ]]
  [[ "$list" == *db.sql.gz* ]]
  [[ "$list" == *media.tar.gz* ]]
  [[ "$list" != *env* ]]
  tar -xOf "$file" MANIFEST | grep -q '^app_version=1.4.0$'
  tar -xOf "$file" db.sql.gz | gunzip | grep -q "native dump"
}

@test "backup --with-config adds the configuration, and says to keep it private" {
  run "$VOTECTL" backup --with-config
  [ "$status" -eq 0 ]
  [[ "$output" == *"secrets"* ]]
  tar -tf "$(backup_file)" | grep -qx env
}

@test "restore puts the database and the pictures back and starts the services" {
  "$VOTECTL" backup
  file="$(backup_file)"
  rm -f "$T/data/media/logo.webp"
  : > "$LOG"
  run "$VOTECTL" restore "$file"
  [ "$status" -eq 0 ]
  [[ "$output" == *"Restored"* ]]
  grep -q "^mariadb " "$LOG"
  grep -q "systemctl stop votesystem-api" "$LOG"
  grep -q "systemctl start votesystem-api" "$LOG"
  grep -q "artisan migrate --force" "$LOG"
  [ -f "$T/data/media/logo.webp" ]
}

@test "restore refuses a damaged file and touches nothing" {
  "$VOTECTL" backup
  file="$(backup_file)"
  printf 'x' >> "$file"
  : > "$LOG"
  run "$VOTECTL" restore "$file"
  [ "$status" -eq 1 ]
  [[ "$output" == *checksum* ]]
  ! grep -q "^mariadb " "$LOG"
  ! grep -q "systemctl stop" "$LOG"
}

@test "restore refuses a backup of another version unless forced" {
  "$VOTECTL" backup
  file="$(backup_file)"
  echo "1.5.0" > "$T/VERSION"
  run "$VOTECTL" restore "$file"
  [ "$status" -eq 1 ]
  [[ "$output" == *"version 1.4.0"* ]]
  run "$VOTECTL" restore "$file" --force
  [ "$status" -eq 0 ]
}

@test "restore without a checksum file needs --force" {
  "$VOTECTL" backup
  file="$(backup_file)"
  rm "$file.sha256"
  run "$VOTECTL" restore "$file"
  [ "$status" -eq 1 ]
  run "$VOTECTL" restore "$file" --force
  [ "$status" -eq 0 ]
}

@test "ssh-key create makes a private key readable by its owner only and never overwrites" {
  run "$VOTECTL" ssh-key create --out "$T/keys" --comment "ecole-test"
  [ "$status" -eq 0 ]
  [ "$(stat -c %a "$T/keys/votesystem_ed25519")" = 600 ]
  grep -q "ecole-test" "$T/keys/votesystem_ed25519.pub"
  [[ "$output" == *Fingerprint* ]]
  run "$VOTECTL" ssh-key create --out "$T/keys"
  [ "$status" -eq 1 ]
}
