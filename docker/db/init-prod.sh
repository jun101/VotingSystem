#!/bin/sh
# Production twin of init.sh: runs once, when the database volume is created. Creates the database and the two
# application accounts; no test database. Neither account is root. See init.sh for why it does not run again.
set -eu

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'app'@'%' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS 'migrator'@'%' IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}';

-- app reads everywhere and writes only where \`php artisan db:grant-app\` gives it the right, after the migrations.
GRANT SELECT ON \`${DB_DATABASE}\`.* TO 'app'@'%';

-- migrator changes the schema and grants table rights; given to the one-off migrate service only.
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO 'migrator'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
