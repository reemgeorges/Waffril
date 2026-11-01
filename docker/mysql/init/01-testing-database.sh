#!/bin/bash
# WAFFRLI local MySQL: a SEPARATE database for automated tests, so test runs
# (which drop and recreate tables) never touch the development data.
# Runs once, when the MySQL data volume is first created.
set -euo pipefail
mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_TEST_DATABASE:-waffrli_testing}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${MYSQL_TEST_DATABASE:-waffrli_testing}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
