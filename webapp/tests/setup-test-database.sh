#!/bin/bash
#
# Build the baseline the tests run against into the test database.
#
# The baseline (see App\DataFixtures\Test\BaselineData) is a bare install plus
# the pass-fail examples, without the example submissions, and with the admin
# user in the DOMjudge team. The test environment appends '_test' to the
# database name from etc/dbpasswords.secret. This script installs the baseline
# into that database by running dj_setup_database with APP_ENV=test, which
# leaves the regular database untouched.
#
# Usage: webapp/tests/setup-test-database.sh [-f]
#   -f  drop the test database first if it already contains tables
#
# The database server is reached like dj_setup_database does: through the
# 'mysql' client with the account of the current user, which needs to be
# allowed to create databases.

set -euo pipefail

FORCE=
while getopts ':f' OPT; do
    case "$OPT" in
        f) FORCE=1 ;;
        *) echo "Usage: $0 [-f]" >&2; exit 1 ;;
    esac
done

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SETUP="${DJ_SETUP_DATABASE:-$ROOT/sql/dj_setup_database}"
[ -x "$SETUP" ] || SETUP="$ROOT/bin/dj_setup_database"
[ -x "$SETUP" ] || { echo "Cannot find dj_setup_database, set DJ_SETUP_DATABASE." >&2; exit 1; }

# Like dj_setup_database, use the last line of the password file that is not a comment.
SECRETS="${ETCDIR:-$ROOT/etc}/dbpasswords.secret"
IFS=: read -r _ DBHOST DB _ _ DBPORT < <(grep -v -e '^#' -e '^[[:space:]]*$' "$SECRETS" | tail -n 1)
[ -n "${DB:-}" ] || { echo "Cannot read the database name from $SECRETS." >&2; exit 1; }
TESTDB="${DB}_test"
MYSQL=(mysql -h "$DBHOST" -P "${DBPORT:-3306}")

TABLES="$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$TESTDB'")"
if [ "$TABLES" -ne 0 ]; then
    if [ -z "$FORCE" ]; then
        echo "'$TESTDB' already has tables, use -f to replace it." >&2
        exit 1
    fi
    echo "Dropping '$TESTDB'..."
    "${MYSQL[@]}" -e "DROP DATABASE \`$TESTDB\`"
fi

echo "Installing the baseline into '$TESTDB'..."
export APP_ENV=test
unset TEST_TOKEN
"$SETUP" bare-install
# -S skips the example submissions.
"$SETUP" -S install-pass-fail-examples

echo "Done."
