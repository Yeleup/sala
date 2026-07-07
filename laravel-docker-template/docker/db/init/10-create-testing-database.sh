#!/bin/sh
set -eu

test_database="${POSTGRES_TEST_DATABASE:-}"

if [ -z "${test_database}" ]; then
    echo "POSTGRES_TEST_DATABASE is empty, skipping test database initialization."
    exit 0
fi

main_database="${POSTGRES_DB:-}"

if [ "${test_database}" = "${main_database}" ]; then
    echo "POSTGRES_TEST_DATABASE must differ from POSTGRES_DB." >&2
    exit 1
fi

psql -v ON_ERROR_STOP=1 -U "${POSTGRES_USER}" -d postgres <<SQL
CREATE DATABASE "${test_database}" OWNER "${POSTGRES_USER}";
SQL
