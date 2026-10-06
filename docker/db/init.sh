#!/bin/sh
# Runs once, when the database volume is created. Creates the two application accounts
# and the test database. Neither account is root.
set -eu

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'app'@'%' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS 'migrator'@'%' IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}';

-- app: reads and writes data, cannot change the schema.
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_DATABASE}\`.* TO 'app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_DATABASE}_test\`.* TO 'app'@'%';

-- migrator: changes the schema, used by migrations only.
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO 'migrator'@'%';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}_test\`.* TO 'migrator'@'%';
FLUSH PRIVILEGES;
SQL
