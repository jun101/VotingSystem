#!/bin/sh
# Runs once, when the database volume is created. Creates the two application accounts
# and the test database. Neither account is root.
#
# It does not run again: a database volume that already exists keeps the accounts and
# passwords it was created with. If `.env` changes a database password or the database's
# name, the volume cannot start with the new values; remove this project's volumes
# (`docker compose down -v`) and run `make setup` again (README.md).
set -eu

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'app'@'%' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS 'migrator'@'%' IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}';

-- app: reads everywhere (a table grant alone would not even let it open the database
-- before the first table exists). It can write only where the table-by-table step
-- gives it the right: \`php artisan db:grant-app\`, run by the \`migrate\` service after
-- the migrations. No right of the whole database can be narrowed per table, so none
-- that changes data is given here (docs/design/database.md section 6).
GRANT SELECT ON \`${DB_DATABASE}\`.* TO 'app'@'%';
GRANT SELECT ON \`${DB_DATABASE}_test\`.* TO 'app'@'%';

-- migrator: changes the schema and grants table rights; used by the migrate service only.
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO 'migrator'@'%' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}_test\`.* TO 'migrator'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
