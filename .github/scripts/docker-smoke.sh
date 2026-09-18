#!/usr/bin/env bash
#
# Smoke-tests a Poweradmin image the way an operator would use it. The publish
# workflows run the basic level (the container answers, in root and non-root
# mode); the pull request gate runs the full level, which also logs in, drives
# the v2 API, creates the admin user, initialises MySQL and PostgreSQL, reads a
# secret from a file, configures trusted proxies and survives a restart.
#
# Usage:
#   .github/scripts/docker-smoke.sh IMAGE basic [--skip-nonroot]
#   .github/scripts/docker-smoke.sh IMAGE full  [--skip-nonroot]
#
# Runs locally too: it only needs docker and curl, and cleans up after itself.

set -euo pipefail

IMAGE="${1:?usage: docker-smoke.sh IMAGE basic|full [--skip-nonroot]}"
LEVEL="${2:-basic}"
shift 2 || true
SKIP_NONROOT=false
for arg in "$@"; do
    [ "$arg" = "--skip-nonroot" ] && SKIP_NONROOT=true
done

PREFIX="pa-smoke-$$"
NET="${PREFIX}-net"
TMP="$(mktemp -d)"
FAILED=0
ADMIN_PASSWORD='Smoke-Test-12345!'
DNS_ENV=(-e DNS_HOSTMASTER=hostmaster.example.com -e DNS_NS1=ns1.example.com -e DNS_NS2=ns2.example.com)
FULL_ENV=(-e PA_CREATE_ADMIN=1 -e "PA_ADMIN_PASSWORD=${ADMIN_PASSWORD}" -e PA_API_ENABLED=true
          -e PA_API_BASIC_AUTH_ENABLED=true -e PA_HEALTH_ENABLED=true)

cleanup() {
    docker ps -aq --filter "name=^${PREFIX}" | xargs -r docker rm -f >/dev/null 2>&1 || true
    docker network rm "${NET}" >/dev/null 2>&1 || true
    rm -rf "${TMP}"
}
trap cleanup EXIT

say()  { echo "[$1] $2"; }
ok()   { say "$1" "ok   $2"; }
fail() { say "$1" "FAIL $2"; FAILED=1; }
check() { # check MODE ACTUAL EXPECTED DESCRIPTION
    if [ "$2" = "$3" ]; then ok "$1" "$4"; else fail "$1" "$4 (got '$2', want '$3')"; fi
}

host_url() { # host_url CONTAINER CONTAINER_PORT -> http://127.0.0.1:PORT
    echo "http://$(docker port "$1" "$2/tcp" | head -1 | sed 's/^0.0.0.0/127.0.0.1/; s/^\[::\]/127.0.0.1/')"
}

wait_for_http() { # wait_for_http MODE CONTAINER URL
    local i
    for i in $(seq 1 90); do
        if [ "$(docker inspect -f '{{.State.Status}}' "$2" 2>/dev/null)" != "running" ]; then
            fail "$1" "container exited"; docker logs "$2"; return 1
        fi
        if curl -sf -o /dev/null "$3"; then ok "$1" "responded after ${i}s"; return 0; fi
        sleep 1
    done
    fail "$1" "no response within 90s"; docker logs "$2"; return 1
}

wait_for_healthy() { # wait_for_healthy MODE CONTAINER (skipped when the image has no HEALTHCHECK)
    local i status
    if [ "$(docker inspect -f '{{if .State.Health}}yes{{end}}' "$2")" != "yes" ]; then
        say "$1" "skip healthcheck (image defines none)"; return 0
    fi
    for i in $(seq 1 90); do
        status="$(docker inspect -f '{{.State.Health.Status}}' "$2")"
        [ "${status}" = "healthy" ] && { ok "$1" "healthy after ${i}s"; return 0; }
        sleep 1
    done
    fail "$1" "healthcheck status '${status}' after 90s"
}

assert_clean_logs() { # assert_clean_logs MODE CONTAINER PATTERN
    local hits
    hits="$(docker logs "$2" 2>&1 | grep -E "$3" || true)"
    if [ -n "${hits}" ]; then fail "$1" "entrypoint reported problems:"; echo "${hits}"; else ok "$1" "no entrypoint errors"; fi
}

start_app() { # start_app NAME PORT [docker run args...] -> starts IMAGE with the common env
    local name="$1" port="$2"; shift 2
    docker run -d --name "${name}" -p "127.0.0.1::${port}" "${DNS_ENV[@]}" "$@" "${IMAGE}" >/dev/null
}

functional_checks() { # functional_checks MODE BASE_URL
    local mode="$1" base="$2" token code zone_id
    local jar="${TMP}/${mode}.jar"
    check "${mode}" "$(curl -s -o /dev/null -w '%{http_code}' "${base}/login")" 200 "GET /login"
    token="$(curl -s -c "${jar}" "${base}/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//')"
    code="$(curl -s -b "${jar}" -c "${jar}" -o /dev/null -w '%{http_code}' -X POST "${base}/login" \
        --data-urlencode "username=admin" --data-urlencode "password=${ADMIN_PASSWORD}" \
        --data-urlencode "_token=${token}" --data-urlencode "authenticate=1")"
    check "${mode}" "${code}" 302 "POST /login"
    check "${mode}" "$(curl -s -b "${jar}" -o /dev/null -w '%{http_code}' "${base}/")" 200 "dashboard with the session cookie"
    check "${mode}" "$(curl -s -o /dev/null -w '%{http_code}' "${base}/api/health")" 200 "GET /api/health"
    zone_id="$(curl -s -u "admin:${ADMIN_PASSWORD}" -H 'Content-Type: application/json' -X POST "${base}/api/v2/zones" \
        -d '{"name":"smoke.example","type":"MASTER"}' | sed -nE 's/.*"zone_id":([0-9]+).*/\1/p')"
    if [ -n "${zone_id}" ]; then ok "${mode}" "POST /api/v2/zones (id ${zone_id})"; else fail "${mode}" "POST /api/v2/zones returned no zone_id"; return 0; fi
    check "${mode}" "$(curl -s -o /dev/null -w '%{http_code}' -u "admin:${ADMIN_PASSWORD}" -H 'Content-Type: application/json' \
        -X POST "${base}/api/v2/zones/${zone_id}/records" -d '{"name":"www.smoke.example","type":"A","content":"192.0.2.10","ttl":300}')" 201 "POST record"
    check "${mode}" "$(curl -s -u "admin:${ADMIN_PASSWORD}" "${base}/api/v2/zones/${zone_id}/records" | grep -c '192.0.2.10')" 1 "record listed"
    check "${mode}" "$(curl -s -b "${jar}" -o /dev/null -w '%{http_code}' "${base}/zones/${zone_id}/edit")" 200 "zone edit page"
    check "${mode}" "$(curl -s -o /dev/null -w '%{http_code}' -u "admin:${ADMIN_PASSWORD}" -X DELETE "${base}/api/v2/zones/${zone_id}")" 204 "DELETE zone"
}

# ---- basic: the container answers, in root and non-root mode ------------------------------
# DB_NAME is what the 3.x entrypoint reads for the SQLite path; 4.x ignores it and uses DB_FILE.
basic_root() {
    local c="${PREFIX}-root" url
    start_app "${c}" 80 -e DB_TYPE=sqlite -e DB_NAME=/db/poweradmin.db
    url="$(host_url "${c}" 80)"
    wait_for_http root "${c}" "${url}/" || return 0
    wait_for_healthy root "${c}"
    assert_clean_logs root "${c}" '\] ERROR:'
}

basic_nonroot() {
    local c="${PREFIX}-nonroot" url
    start_app "${c}" 8080 --user 82:82 -e DB_TYPE=sqlite -e DB_NAME=/db/poweradmin.db
    url="$(host_url "${c}" 8080)"
    wait_for_http nonroot "${c}" "${url}/" || return 0
    wait_for_healthy nonroot "${c}"
    assert_clean_logs nonroot "${c}" '\] ERROR:'
}

# ---- full: real usage on every backend -----------------------------------------------------
full_sqlite_root() {
    local c="${PREFIX}-sqlite" url
    start_app "${c}" 80 -e DB_TYPE=sqlite "${FULL_ENV[@]}"
    url="$(host_url "${c}" 80)"
    wait_for_http sqlite "${c}" "${url}/login" || return 0
    wait_for_healthy sqlite "${c}"
    functional_checks sqlite "${url}"
    docker restart "${c}" >/dev/null
    # The ephemeral host port is reassigned on restart
    url="$(host_url "${c}" 80)"
    wait_for_http sqlite-restart "${c}" "${url}/login" || return 0
    check sqlite-restart "$(docker logs "${c}" 2>&1 | grep -c 'Configuration file generated successfully')" 1 "config generated once, reused after restart"
    check sqlite-restart "$(docker logs "${c}" 2>&1 | grep -c "already exists, skipping creation")" 1 "admin creation skipped on restart"
    assert_clean_logs sqlite "${c}" '\] (ERROR|WARNING):'
}

full_sqlite_nonroot() {
    local c="${PREFIX}-sqlite-nonroot" url
    start_app "${c}" 8080 --user 82:82 -e DB_TYPE=sqlite "${FULL_ENV[@]}"
    url="$(host_url "${c}" 8080)"
    wait_for_http sqlite-nonroot "${c}" "${url}/login" || return 0
    wait_for_healthy sqlite-nonroot "${c}"
    functional_checks sqlite-nonroot "${url}"
    assert_clean_logs sqlite-nonroot "${c}" '\] (ERROR|WARNING):'
}

# The password arrives through DB_PASS__FILE and the app starts before the server is
# ready, so this covers the secrets path and the database wait as well.
full_mysql() {
    local c="${PREFIX}-mysql" db="${PREFIX}-mysql-db" url
    printf '%s' 'sm0ke"pass' > "${TMP}/db_pass"
    docker run -d --name "${db}" --network "${NET}" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=pa \
        -e MARIADB_USER=pa -e 'MARIADB_PASSWORD=sm0ke"pass' mariadb:10.11 >/dev/null
    start_app "${c}" 80 --network "${NET}" -v "${TMP}/db_pass:/run/secrets/db_pass:ro" \
        -e DB_TYPE=mysql -e "DB_HOST=${db}" -e DB_USER=pa -e DB_PASS__FILE=/run/secrets/db_pass -e DB_NAME=pa \
        -e DB_WAIT_TIMEOUT=120 -e PA_INIT_PDNS_SCHEMA=true -e TRUSTED_PROXIES=private_ranges "${FULL_ENV[@]}"
    url="$(host_url "${c}" 80)"
    wait_for_http mysql "${c}" "${url}/login" || return 0
    check mysql "$(docker logs "${c}" 2>&1 | grep -c 'Poweradmin schema initialized successfully')" 1 "schema initialised"
    check mysql "$(docker exec "${c}" grep -c trusted_proxies /etc/caddy/Caddyfile)" 1 "trusted proxies written to the Caddyfile"
    functional_checks mysql "${url}"
    assert_clean_logs mysql "${c}" '\] (ERROR|WARNING):'
}

full_pgsql() {
    local c="${PREFIX}-pgsql" db="${PREFIX}-pgsql-db" url
    docker run -d --name "${db}" --network "${NET}" -e POSTGRES_DB=pa -e POSTGRES_USER=pa -e POSTGRES_PASSWORD=pa postgres:16 >/dev/null
    start_app "${c}" 80 --network "${NET}" \
        -e DB_TYPE=pgsql -e "DB_HOST=${db}" -e DB_USER=pa -e DB_PASS=pa -e DB_NAME=pa \
        -e DB_WAIT_TIMEOUT=120 -e PA_INIT_PDNS_SCHEMA=true "${FULL_ENV[@]}"
    url="$(host_url "${c}" 80)"
    wait_for_http pgsql "${c}" "${url}/login" || return 0
    check pgsql "$(docker logs "${c}" 2>&1 | grep -c 'Poweradmin schema initialized successfully')" 1 "schema initialised"
    functional_checks pgsql "${url}"
    assert_clean_logs pgsql "${c}" '\] (ERROR|WARNING):'
}

say smoke "image ${IMAGE}, level ${LEVEL}"
case "${LEVEL}" in
    basic)
        basic_root
        [ "${SKIP_NONROOT}" = true ] || basic_nonroot
        ;;
    full)
        docker network create "${NET}" >/dev/null
        full_sqlite_root
        [ "${SKIP_NONROOT}" = true ] || full_sqlite_nonroot
        full_mysql
        full_pgsql
        ;;
    *)
        echo "unknown level '${LEVEL}' (basic|full)"; exit 2 ;;
esac

if [ "${FAILED}" = 0 ]; then say smoke "all checks passed"; else say smoke "some checks failed"; exit 1; fi
