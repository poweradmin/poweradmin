#!/usr/bin/env bash
#
# Applies the repo's sql/poweradmin-{db}-update-to-*.sql scripts to the
# devcontainer databases so their schema matches the checked-out branch.
#
# Use after switching branches instead of a destructive --clean reimport.
# --from is required: pass the first version the databases do not have yet.
# Do not replay older scripts: the SQLite 4.1.0-4.3.0 updates rebuild tables
# and drop columns added after them, which can empty zones on the API database.
#
# Usage:
#   ./apply-schema-updates.sh --from 4.6.0              # all databases
#   ./apply-schema-updates.sh --from 4.6.0 --dbs mysql  # one database

set -u

DEVCONTAINER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT_SQL_DIR="$DEVCONTAINER_DIR/../sql"

MYSQL_USER="${MYSQL_USER:-pdns}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-poweradmin}"
MYSQL_DATABASE="${MYSQL_DATABASE:-poweradmin}"
MYSQL_CONTAINER="${MYSQL_CONTAINER:-mariadb}"
# The API-backend instance has its own databases; both get the same updates
MYSQL_API_DATABASE="${MYSQL_API_DATABASE:-poweradmin_api}"

PGSQL_USER="${PGSQL_USER:-pdns}"
PGSQL_PASSWORD="${PGSQL_PASSWORD:-poweradmin}"
PGSQL_DATABASE="${PGSQL_DATABASE:-pdns}"
PGSQL_CONTAINER="${PGSQL_CONTAINER:-postgres}"
PGSQL_API_DATABASE="${PGSQL_API_DATABASE:-pdns_api}"

SQLITE_CONTAINER="${SQLITE_CONTAINER:-sqlite}"
SQLITE_DB_PATH="${SQLITE_DB_PATH:-/data/pdns.db}"
# The API-backend instance has its own file; both get the same updates
SQLITE_API_DB_PATH="${SQLITE_API_DB_PATH:-/data/pdns-api.db}"

DBS="mysql,pgsql,sqlite"
FROM_VERSION=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dbs) DBS="$2"; shift 2 ;;
        --from) FROM_VERSION="$2"; shift 2 ;;
        -h|--help)
            grep '^#' "$0" | sed 's/^# \{0,1\}//' | head -13
            exit 0 ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

if [[ -z "$FROM_VERSION" ]]; then
    echo "--from <version> is required, e.g. --from 4.6.0 (see --help)"
    exit 1
fi

version_ge() { # $1 >= $2
    [[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -1)" == "$2" ]]
}

apply_script() { # $1=db $2=file -> prints "errors:N"
    local db="$1" file="$2" errfile
    errfile=$(mktemp)
    case "$db" in
        mysql)
            docker exec -i "$MYSQL_CONTAINER" mysql --force -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < "$file" 2>"$errfile"
            docker exec -i "$MYSQL_CONTAINER" mysql --force -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_API_DATABASE" < "$file" 2>>"$errfile" ;;
        pgsql)
            docker exec -i -e PGPASSWORD="$PGSQL_PASSWORD" "$PGSQL_CONTAINER" psql -U "$PGSQL_USER" -d "$PGSQL_DATABASE" < "$file" >/dev/null 2>"$errfile"
            docker exec -i -e PGPASSWORD="$PGSQL_PASSWORD" "$PGSQL_CONTAINER" psql -U "$PGSQL_USER" -d "$PGSQL_API_DATABASE" < "$file" >/dev/null 2>>"$errfile" ;;
        sqlite)
            docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_DB_PATH" < "$file" 2>"$errfile"
            docker exec -i "$SQLITE_CONTAINER" sqlite3 "$SQLITE_API_DB_PATH" < "$file" 2>>"$errfile" ;;
    esac
    local errors
    errors=$(grep -ci 'error' "$errfile" || true)
    if [[ "$errors" -gt 0 ]]; then
        echo "    warnings ($errors, usually already-applied statements):"
        head -3 "$errfile" | sed 's/^/      /'
    fi
    rm -f "$errfile"
}

for db in ${DBS//,/ }; do
    case "$db" in
        mysql) container="$MYSQL_CONTAINER" ;;
        pgsql) container="$PGSQL_CONTAINER" ;;
        sqlite) container="$SQLITE_CONTAINER" ;;
        *) echo "Unknown db: $db"; exit 1 ;;
    esac
    if ! docker ps --format '{{.Names}}' | grep -qx "$container"; then
        echo "== $db: container '$container' not running, skipping"
        continue
    fi
    echo "== $db"
    found=0
    for file in $(ls "$ROOT_SQL_DIR"/poweradmin-"$db"-update-to-*.sql 2>/dev/null | sort -V); do
        version=$(basename "$file" .sql | sed 's/.*-update-to-//')
        version_ge "$version" "$FROM_VERSION" || continue
        found=1
        echo "  applying $(basename "$file")"
        apply_script "$db" "$file"
    done
    [[ "$found" == 1 ]] || echo "  no update scripts >= $FROM_VERSION"
done
echo "Done."
