#!/bin/sh
# Runs once, when the MySQL volume is first initialised.
# Creates the database used by the test suite (docs/adr/0017-tests-run-on-mysql.md).
set -eu

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS testing;
GRANT ALL PRIVILEGES ON testing.* TO '$MYSQL_USER'@'%';
FLUSH PRIVILEGES;
SQL
