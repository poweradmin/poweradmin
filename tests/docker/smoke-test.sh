#!/bin/bash
#
# Docker Smoke Test for Poweradmin
#
# Tests that the Docker image can connect to different databases:
# - MariaDB 11 (tests SSL verification bypass)
# - MySQL 8
# - PostgreSQL 17
# - SQLite
#
# Usage:
#   ./tests/docker/smoke-test.sh          # Run all database tests
#   ./tests/docker/smoke-test.sh mariadb  # Run only MariaDB test
#   ./tests/docker/smoke-test.sh mysql    # Run only MySQL test
#   ./tests/docker/smoke-test.sh pgsql    # Run only PostgreSQL test
#   ./tests/docker/smoke-test.sh sqlite   # Run only SQLite test
#
# Requirements:
#   - Docker
#   - Port 8888 available

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Container/network names
NETWORK_NAME="poweradmin-smoke-test"
DB_CONTAINER="poweradmin-smoke-db"
APP_CONTAINER="poweradmin-smoke-app"
IMAGE_NAME="poweradmin:smoke-test"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_header() { echo -e "\n${BLUE}=== $1 ===${NC}\n"; }

cleanup() {
    docker rm -f "$APP_CONTAINER" 2>/dev/null || true
    docker rm -f "$DB_CONTAINER" 2>/dev/null || true
    docker network rm "$NETWORK_NAME" 2>/dev/null || true
}

wait_for_container() {
    local container=$1
    local max_attempts=${2:-30}
    local attempt=0

    while [ $attempt -lt $max_attempts ]; do
        if docker inspect --format='{{.State.Health.Status}}' "$container" 2>/dev/null | grep -q "healthy"; then
            return 0
        fi
        attempt=$((attempt + 1))
        echo -n "."
        sleep 2
    done
    echo ""
    return 1
}

run_smoke_tests() {
    log_info "Running smoke tests..."

    # Test 1: HTTP response (allow 200 or 302 - login page may redirect)
    log_info "Test 1: HTTP response..."
    local http_code
    http_code=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8888/)
    if [ "$http_code" != "200" ] && [ "$http_code" != "302" ]; then
        log_error "HTTP code: $http_code (expected 200 or 302)"
        docker logs "$APP_CONTAINER"
        return 1
    fi
    log_info "  OK ($http_code)"

    # Test 2: Login page content (follow redirects)
    log_info "Test 2: Login page content..."
    if ! curl -sL http://localhost:8888/ | grep -qi "login\|password\|username\|poweradmin"; then
        log_error "Login page not found"
        docker logs "$APP_CONTAINER"
        return 1
    fi
    log_info "  OK"

    # Test 3: No database errors
    log_info "Test 3: No database errors..."
    if docker logs "$APP_CONTAINER" 2>&1 | grep -qi "database connection error\|PDOException"; then
        log_error "Database errors found:"
        docker logs "$APP_CONTAINER"
        return 1
    fi
    log_info "  OK"

    return 0
}

test_mariadb() {
    log_header "Testing MariaDB 11"
    cleanup

    docker network create "$NETWORK_NAME"

    log_info "Starting MariaDB 11..."
    docker run -d \
        --name "$DB_CONTAINER" \
        --network "$NETWORK_NAME" \
        -e MARIADB_ROOT_PASSWORD=rootpass \
        -e MARIADB_DATABASE=pdns \
        -e MARIADB_USER=pdns \
        -e MARIADB_PASSWORD=poweradmin \
        --health-cmd="healthcheck.sh --connect --innodb_initialized" \
        --health-interval=5s \
        --health-timeout=5s \
        --health-retries=10 \
        mariadb:11

    log_info "Waiting for MariaDB..."
    if ! wait_for_container "$DB_CONTAINER" 60; then
        log_error "MariaDB failed to start"
        docker logs "$DB_CONTAINER"
        return 1
    fi
    log_info "MariaDB is ready"

    log_info "Initializing PowerDNS schema..."
    if ! docker exec -i "$DB_CONTAINER" mariadb -uroot -prootpass pdns < "$PROJECT_ROOT/sql/pdns/49/schema.mysql.sql"; then
        log_error "Failed to load PowerDNS schema"
        return 1
    fi

    log_info "Initializing Poweradmin schema..."
    if ! docker exec -i "$DB_CONTAINER" mariadb -uroot -prootpass pdns < "$PROJECT_ROOT/sql/poweradmin-mysql-db-structure.sql"; then
        log_error "Failed to load Poweradmin schema"
        return 1
    fi

    log_info "Starting Poweradmin..."
    docker run -d \
        --name "$APP_CONTAINER" \
        --network "$NETWORK_NAME" \
        -p 8888:80 \
        -e DB_TYPE=mysql \
        -e DB_HOST="$DB_CONTAINER" \
        -e DB_USER=pdns \
        -e DB_PASS=poweradmin \
        -e DB_NAME=pdns \
        -e DNS_NS1=ns1.example.com \
        -e DNS_NS2=ns2.example.com \
        -e DNS_HOSTMASTER=hostmaster@example.com \
        -e PA_CREATE_ADMIN=1 \
        --health-cmd="curl -sf http://localhost:80/ -o /dev/null || curl -sf http://localhost:80/ -w '%{http_code}' | grep -q 302" \
        --health-interval=5s \
        --health-timeout=5s \
        --health-retries=10 \
        "$IMAGE_NAME"

    log_info "Waiting for Poweradmin..."
    if ! wait_for_container "$APP_CONTAINER" 60; then
        log_error "Poweradmin failed to start"
        docker logs "$APP_CONTAINER"
        return 1
    fi

    run_smoke_tests
}

test_mysql() {
    log_header "Testing MySQL 8"
    cleanup

    docker network create "$NETWORK_NAME"

    log_info "Starting MySQL 8..."
    docker run -d \
        --name "$DB_CONTAINER" \
        --network "$NETWORK_NAME" \
        -e MYSQL_ROOT_PASSWORD=rootpass \
        -e MYSQL_DATABASE=pdns \
        -e MYSQL_USER=pdns \
        -e MYSQL_PASSWORD=poweradmin \
        --health-cmd="mysqladmin ping -h localhost" \
        --health-interval=5s \
        --health-timeout=5s \
        --health-retries=10 \
        mysql:8

    log_info "Waiting for MySQL..."
    if ! wait_for_container "$DB_CONTAINER" 90; then
        log_error "MySQL failed to start"
        docker logs "$DB_CONTAINER"
        return 1
    fi
    log_info "MySQL is ready"

    log_info "Initializing PowerDNS schema..."
    if ! docker exec -i "$DB_CONTAINER" mysql -uroot -prootpass pdns < "$PROJECT_ROOT/sql/pdns/49/schema.mysql.sql"; then
        log_error "Failed to load PowerDNS schema"
        return 1
    fi

    log_info "Initializing Poweradmin schema..."
    if ! docker exec -i "$DB_CONTAINER" mysql -uroot -prootpass pdns < "$PROJECT_ROOT/sql/poweradmin-mysql-db-structure.sql"; then
        log_error "Failed to load Poweradmin schema"
        return 1
    fi

    log_info "Starting Poweradmin..."
    docker run -d \
        --name "$APP_CONTAINER" \
        --network "$NETWORK_NAME" \
        -p 8888:80 \
        -e DB_TYPE=mysql \
        -e DB_HOST="$DB_CONTAINER" \
        -e DB_USER=pdns \
        -e DB_PASS=poweradmin \
        -e DB_NAME=pdns \
        -e DNS_NS1=ns1.example.com \
        -e DNS_NS2=ns2.example.com \
        -e DNS_HOSTMASTER=hostmaster@example.com \
        -e PA_CREATE_ADMIN=1 \
        --health-cmd="curl -sf http://localhost:80/ -o /dev/null || curl -sf http://localhost:80/ -w '%{http_code}' | grep -q 302" \
        --health-interval=5s \
        --health-timeout=5s \
        --health-retries=10 \
        "$IMAGE_NAME"

    log_info "Waiting for Poweradmin..."
    if ! wait_for_container "$APP_CONTAINER" 60; then
        log_error "Poweradmin failed to start"
        docker logs "$APP_CONTAINER"
        return 1
    fi

    run_smoke_tests
}

test_pgsql() {
    log_header "Testing PostgreSQL 17"
    cleanup

    docker network create "$NETWORK_NAME"

    log_info "Starting PostgreSQL 17..."
    docker run -d \
        --name "$DB_CONTAINER" \
        --network "$NETWORK_NAME" \
        -e POSTGRES_DB=pdns \
        -e POSTGRES_USER=pdns \
        -e POSTGRES_PASSWORD=poweradmin \
        --health-cmd="pg_isready -U pdns -d pdns" \
        --health-interval=5s \
        --health-timeout=5s \
        --health-retries=10 \
        postgres:17

    log_info "Waiting for PostgreSQL..."
    if ! wait_for_container "$DB_CONTAINER" 60; then
        log_error "PostgreSQL failed to start"
        docker logs "$DB_CONTAINER"
        return 1
    fi
    log_info "PostgreSQL is ready"

    log_info "Initializing PowerDNS schema..."
    if ! docker exec -i "$DB_CONTAINER" psql -U pdns -d pdns < "$PROJECT_ROOT/sql/pdns/49/schema.pgsql.sql"; then
        log_error "Failed to load PowerDNS schema"
        return 1
    fi

    log_info "Initializing Poweradmin schema..."
    if ! docker exec -i "$DB_CONTAINER" psql -U pdns -d pdns < "$PROJECT_ROOT/sql/poweradmin-pgsql-db-structure.sql"; then
        log_error "Failed to load Poweradmin schema"
        return 1
    fi

    log_info "Starting Poweradmin..."
    docker run -d \
        --name "$APP_CONTAINER" \
        --network "$NETWORK_NAME" \
        -p 8888:80 \
        -e DB_TYPE=pgsql \
        -e DB_HOST="$DB_CONTAINER" \
        -e DB_USER=pdns \
        -e DB_PASS=poweradmin \
        -e DB_NAME=pdns \
        -e DNS_NS1=ns1.example.com \
        -e DNS_NS2=ns2.example.com \
        -e DNS_HOSTMASTER=hostmaster@example.com \
        -e PA_CREATE_ADMIN=1 \
        --health-cmd="curl -sf http://localhost:80/ -o /dev/null || curl -sf http://localhost:80/ -w '%{http_code}' | grep -q 302" \
        --health-interval=5s \
        --health-timeout=5s \
        --health-retries=10 \
        "$IMAGE_NAME"

    log_info "Waiting for Poweradmin..."
    if ! wait_for_container "$APP_CONTAINER" 60; then
        log_error "Poweradmin failed to start"
        docker logs "$APP_CONTAINER"
        return 1
    fi

    run_smoke_tests
}

test_sqlite() {
    log_header "Testing SQLite"
    cleanup

    docker network create "$NETWORK_NAME"

    log_info "Starting Poweradmin with SQLite..."
    docker run -d \
        --name "$APP_CONTAINER" \
        --network "$NETWORK_NAME" \
        -p 8888:80 \
        -e DB_TYPE=sqlite \
        -e DB_FILE=/db/pdns.db \
        -e DNS_NS1=ns1.example.com \
        -e DNS_NS2=ns2.example.com \
        -e DNS_HOSTMASTER=hostmaster@example.com \
        -e PA_CREATE_ADMIN=1 \
        --health-cmd="curl -sf http://localhost:80/ -o /dev/null || curl -sf http://localhost:80/ -w '%{http_code}' | grep -q 302" \
        --health-interval=5s \
        --health-timeout=5s \
        --health-retries=10 \
        "$IMAGE_NAME"

    log_info "Waiting for Poweradmin..."
    if ! wait_for_container "$APP_CONTAINER" 60; then
        log_error "Poweradmin failed to start"
        docker logs "$APP_CONTAINER"
        return 1
    fi

    run_smoke_tests
}

build_image() {
    log_info "Building Docker image..."
    docker build -t "$IMAGE_NAME" "$PROJECT_ROOT"
}

trap cleanup EXIT

main() {
    cd "$PROJECT_ROOT"

    local db_type="${1:-all}"
    local failed=0
    local passed=0

    log_info "Starting Docker smoke tests..."
    log_info "Database: $db_type"

    # Build image once
    build_image

    case "$db_type" in
        mariadb)
            if test_mariadb; then ((passed++)); else ((failed++)); fi
            ;;
        mysql)
            if test_mysql; then ((passed++)); else ((failed++)); fi
            ;;
        pgsql|postgres)
            if test_pgsql; then ((passed++)); else ((failed++)); fi
            ;;
        sqlite)
            if test_sqlite; then ((passed++)); else ((failed++)); fi
            ;;
        all)
            if test_mariadb; then ((passed++)); else ((failed++)); fi
            if test_mysql; then ((passed++)); else ((failed++)); fi
            if test_pgsql; then ((passed++)); else ((failed++)); fi
            if test_sqlite; then ((passed++)); else ((failed++)); fi
            ;;
        *)
            log_error "Unknown database type: $db_type"
            echo "Usage: $0 [mariadb|mysql|pgsql|sqlite|all]"
            exit 1
            ;;
    esac

    echo ""
    log_header "Summary"
    log_info "Passed: $passed"
    if [ $failed -gt 0 ]; then
        log_error "Failed: $failed"
        exit 1
    fi
    log_info "All smoke tests passed!"
}

main "$@"
