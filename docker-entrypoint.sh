#!/bin/bash
set -e

# Default paths (CONFIG_FILE is set after secrets are processed in main())
DB_DIR="/db"

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*"
}

debug_log() {
    if [ "${DEBUG:-false}" = "true" ]; then
        echo "[$(date +'%Y-%m-%d %H:%M:%S')] DEBUG: $*"
    fi
}

# Process Docker secrets - converts *__FILE environment variables to regular variables
process_secret_files() {
    for VAR_NAME in $(env | grep '^[^=]\+__FILE=.\+' | sed -r 's/^([^=]*)__FILE=.*/\1/g'); do
        VAR_NAME_FILE="${VAR_NAME}__FILE"

        # Check if both regular and __FILE versions are set (they are exclusive)
        if [ "${!VAR_NAME}" ]; then
            log "ERROR: Both ${VAR_NAME} and ${VAR_NAME_FILE} are set but are exclusive"
            exit 1
        fi

        VAR_FILENAME="${!VAR_NAME_FILE}"
        log "Getting secret ${VAR_NAME} from ${VAR_FILENAME}"

        # Validate the secret file exists and is readable
        if [ ! -r "${VAR_FILENAME}" ]; then
            log "ERROR: ${VAR_FILENAME} does not exist or is not readable"
            exit 1
        fi

        # Read the secret file content and export as environment variable
        export "${VAR_NAME}"="$(<"${VAR_FILENAME}")"
        unset "${VAR_NAME_FILE}"
    done
}

# Install custom CA certificate for trusting self-signed certs (e.g., internal Keycloak/OIDC)
install_trusted_ca() {
    if [ -n "${TRUSTED_CA_FILE:-}" ]; then
        if [ ! -f "${TRUSTED_CA_FILE}" ]; then
            log "ERROR: TRUSTED_CA_FILE points to non-existent file: ${TRUSTED_CA_FILE}"
            exit 1
        fi
        log "Installing custom CA certificate from ${TRUSTED_CA_FILE}..."
        cp "${TRUSTED_CA_FILE}" /usr/local/share/ca-certificates/custom-ca.crt
        update-ca-certificates
        log "Custom CA certificate installed successfully"
    fi
}

# Configure Caddy trusted_proxies for correct client IP behind reverse proxies
configure_trusted_proxies() {
    if [ -z "${TRUSTED_PROXIES:-}" ]; then
        return
    fi

    local caddyfile="/etc/caddy/Caddyfile"

    if [ ! -w "${caddyfile}" ]; then
        log "WARNING: TRUSTED_PROXIES is set but ${caddyfile} is not writable - skipping"
        return
    fi

    # Skip if already configured (container restart)
    if grep -q 'trusted_proxies' "${caddyfile}"; then
        debug_log "trusted_proxies already present in Caddyfile, skipping"
        return
    fi

    # Convert comma-separated input to space-separated (Caddy format)
    local proxies
    proxies=$(echo "${TRUSTED_PROXIES}" | tr ',' ' ' | tr -s ' ')

    log "Configuring trusted proxies: ${proxies}"

    # Replace placeholder with trusted_proxies block
    # Pattern: insert servers block after 'order php_server before file_server'
    sed -i "s|order php_server before file_server|order php_server before file_server\n    servers {\n        trusted_proxies static ${proxies}\n        client_ip_headers X-Forwarded-For X-Real-IP\n    }|" "${caddyfile}"

    log "Trusted proxies configured in Caddyfile"
}

# Escape single quotes for SQL by replacing ' with ''
# NOTE: Only covers basic quoting - values are interpolated into shell strings.
# Newlines and other control characters in values may still cause issues.
# For production use, prefer parameterised queries via a PHP helper script.
escape_sql() {
    printf '%s' "$1" | sed "s/'/''/g"
}

# Escape a value for embedding inside a PHP single-quoted string literal.
# Escapes backslashes first, then single quotes.
escape_php() {
    printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/\\\\'/g"
}

# Write a temporary MySQL defaults file to avoid exposing the password in the
# process list (visible in /proc/<pid>/cmdline).
# Usage:  mysql_with_pass <host> [<port_opt>] [<ssl_opts_array_name>] <user> <db> <sql>
# Instead, callers build a temp file via make_mysql_defaults_file and pass
# --defaults-file=<path> themselves. Helper below.
make_mysql_defaults_file() {
    local tmpfile
    tmpfile=$(mktemp)
    chmod 600 "${tmpfile}"
    printf '[client]\npassword=%s\n' "${DB_PASS}" > "${tmpfile}"
    echo "${tmpfile}"
}

# Initialize SQLite database if it doesn't exist
init_sqlite_db() {
    if [ "${DB_TYPE}" = "sqlite" ] && [ ! -f "${DB_FILE:-/db/pdns.db}" ]; then
        local db_file="${DB_FILE:-/db/pdns.db}"
        local pdns_version="${PDNS_VERSION:-49}"
        log "Initializing SQLite database at ${db_file}..."

        # Create database directory if it doesn't exist
        mkdir -p "$(dirname "${db_file}")"

        # Initialize PowerDNS schema
        if [ -f "/app/sql/pdns/${pdns_version}/schema.sqlite3.sql" ]; then
            log "Using PowerDNS version ${pdns_version} schema"
            sqlite3 "${db_file}" < /app/sql/pdns/${pdns_version}/schema.sqlite3.sql
        else
            log "WARNING: PowerDNS schema file for version ${pdns_version} not found, database may not be properly initialized"
        fi

        # Initialize Poweradmin schema
        if [ -f "/app/sql/poweradmin-sqlite-db-structure.sql" ]; then
            sqlite3 "${db_file}" < /app/sql/poweradmin-sqlite-db-structure.sql
        else
            log "WARNING: Poweradmin schema file not found, database may not be properly initialized"
        fi

        log "SQLite database initialized successfully"
    fi
}

# Build MySQL SSL options array from DB_SSL / DB_SSL_VERIFY.
# Returns options as newline-separated values; callers use mapfile/read -ra to
# build a proper array so options with spaces are handled correctly.
build_mysql_ssl_opts() {
    local db_ssl
    db_ssl=$(echo "${DB_SSL:-false}" | tr '[:upper:]' '[:lower:]')
    local db_ssl_verify
    db_ssl_verify=$(echo "${DB_SSL_VERIFY:-false}" | tr '[:upper:]' '[:lower:]')

    if [ "$db_ssl" != "true" ]; then
        echo "--skip-ssl"
    elif [ "$db_ssl_verify" != "true" ]; then
        echo "--skip-ssl-verify-server-cert"
    fi
}

# Load the Poweradmin schema into an empty MySQL database (parity with SQLite init).
# Idempotent: skips when the users table already exists, so existing data is never touched.
init_mysql_db() {
    [ "${DB_TYPE}" = "mysql" ] || return 0

    # Build SSL options as a proper array to handle options with spaces correctly
    local -a ssl_opts
    mapfile -t ssl_opts < <(build_mysql_ssl_opts)

    local -a port_opt=()
    [ -n "${DB_PORT:-}" ] && port_opt=("-P${DB_PORT}")

    # Use a temporary defaults file to avoid password exposure in process list
    local mycnf
    mycnf=$(make_mysql_defaults_file)
    # Ensure cleanup on exit
    # shellcheck disable=SC2064
    trap "rm -f '${mycnf}'" RETURN

    # '|| true' keeps a probe failure from tripping 'set -e';
    # the empty-result check below turns it into a graceful skip.
    local table_exists
    table_exists=$(mysql --defaults-file="${mycnf}" "${ssl_opts[@]}" \
        -h"${DB_HOST}" "${port_opt[@]}" -u"${DB_USER}" "${DB_NAME}" -sNe \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$(escape_sql "${DB_NAME}")' AND table_name='users';" 2>/dev/null) || true

    if [ -z "${table_exists}" ]; then
        log "WARNING: Could not reach MySQL database '${DB_NAME}' to verify schema - skipping auto-initialization"
        return 0
    fi

    # Optionally load the PowerDNS schema into an empty database.
    # Off by default. Set PA_INIT_PDNS_SCHEMA=true to opt in.
    local init_pdns_schema
    init_pdns_schema=$(echo "${PA_INIT_PDNS_SCHEMA:-false}" | tr '[:upper:]' '[:lower:]')
    if [ "${init_pdns_schema}" = "true" ] && [ -z "${PA_PDNS_DB_NAME:-}" ]; then
        local pdns_version="${PDNS_VERSION:-49}"
        local pdns_table_exists
        pdns_table_exists=$(mysql --defaults-file="${mycnf}" "${ssl_opts[@]}" \
            -h"${DB_HOST}" "${port_opt[@]}" -u"${DB_USER}" "${DB_NAME}" -sNe \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$(escape_sql "${DB_NAME}")' AND table_name='domains';" 2>/dev/null) || true
        if [ "${pdns_table_exists}" = "0" ]; then
            local pdns_schema="/app/sql/pdns/${pdns_version}/schema.mysql.sql"
            if [ ! -f "${pdns_schema}" ]; then
                log "WARNING: PowerDNS MySQL schema file for version ${pdns_version} not found, database may not be properly initialized"
            elif mysql --defaults-file="${mycnf}" "${ssl_opts[@]}" \
                    -h"${DB_HOST}" "${port_opt[@]}" -u"${DB_USER}" "${DB_NAME}" < "${pdns_schema}"; then
                log "PowerDNS schema (version ${pdns_version}) initialized successfully in MySQL database '${DB_NAME}'"
            else
                log "ERROR: Failed to initialize PowerDNS schema in MySQL database '${DB_NAME}'"
                exit 1
            fi
        fi
    fi

    if [ "${table_exists}" -gt 0 ]; then
        debug_log "Poweradmin schema already present in MySQL database '${DB_NAME}'"
        return 0
    fi

    log "Poweradmin schema not found in database '${DB_NAME}', initializing..."
    if [ ! -f "/app/sql/poweradmin-mysql-db-structure.sql" ]; then
        log "WARNING: Poweradmin MySQL schema file not found, database may not be properly initialized"
        return 0
    fi
    if mysql --defaults-file="${mycnf}" "${ssl_opts[@]}" \
            -h"${DB_HOST}" "${port_opt[@]}" -u"${DB_USER}" "${DB_NAME}" \
            < /app/sql/poweradmin-mysql-db-structure.sql; then
        log "Poweradmin schema initialized successfully in MySQL database '${DB_NAME}'"
    else
        log "ERROR: Failed to initialize Poweradmin schema in MySQL database '${DB_NAME}'"
        exit 1
    fi
}

# Load the Poweradmin schema into an empty PostgreSQL database (parity with SQLite init).
# Idempotent: skips when the users table already exists, so existing data is never touched.
init_pgsql_db() {
    [ "${DB_TYPE}" = "pgsql" ] || return 0

    local port_opt=""
    [ -n "${DB_PORT:-}" ] && port_opt="-p ${DB_PORT}"

    local table_exists
    table_exists=$(PGPASSWORD="${DB_PASS}" psql -h "${DB_HOST}" ${port_opt} -U "${DB_USER}" -d "${DB_NAME}" -tAc \
        "SELECT to_regclass('public.users') IS NOT NULL;" 2>/dev/null) || true

    if [ -z "${table_exists}" ]; then
        log "WARNING: Could not reach PostgreSQL database '${DB_NAME}' to verify schema - skipping auto-initialization"
        return 0
    fi

    local init_pdns_schema
    init_pdns_schema=$(echo "${PA_INIT_PDNS_SCHEMA:-false}" | tr '[:upper:]' '[:lower:]')
    if [ "${init_pdns_schema}" = "true" ] && [ -z "${PA_PDNS_DB_NAME:-}" ]; then
        local pdns_version="${PDNS_VERSION:-49}"
        local pdns_table_exists
        pdns_table_exists=$(PGPASSWORD="${DB_PASS}" psql -h "${DB_HOST}" ${port_opt} -U "${DB_USER}" -d "${DB_NAME}" -tAc \
            "SELECT to_regclass('public.domains') IS NOT NULL;" 2>/dev/null) || true
        if [ "${pdns_table_exists}" = "f" ]; then
            local pdns_schema="/app/sql/pdns/${pdns_version}/schema.pgsql.sql"
            if [ ! -f "${pdns_schema}" ]; then
                log "WARNING: PowerDNS PostgreSQL schema file for version ${pdns_version} not found, database may not be properly initialized"
            elif PGPASSWORD="${DB_PASS}" psql -h "${DB_HOST}" ${port_opt} -U "${DB_USER}" -d "${DB_NAME}" -v ON_ERROR_STOP=1 -q -f "${pdns_schema}"; then
                log "PowerDNS schema (version ${pdns_version}) initialized successfully in PostgreSQL database '${DB_NAME}'"
            else
                log "ERROR: Failed to initialize PowerDNS schema in PostgreSQL database '${DB_NAME}'"
                exit 1
            fi
        fi
    fi

    if [ "${table_exists}" = "t" ]; then
        debug_log "Poweradmin schema already present in PostgreSQL database '${DB_NAME}'"
        return 0
    fi

    log "Poweradmin schema not found in database '${DB_NAME}', initializing..."
    if [ ! -f "/app/sql/poweradmin-pgsql-db-structure.sql" ]; then
        log "WARNING: Poweradmin PostgreSQL schema file not found, database may not be properly initialized"
        return 0
    fi
    if PGPASSWORD="${DB_PASS}" psql -h "${DB_HOST}" ${port_opt} -U "${DB_USER}" -d "${DB_NAME}" -v ON_ERROR_STOP=1 -q -f /app/sql/poweradmin-pgsql-db-structure.sql; then
        log "Poweradmin schema initialized successfully in PostgreSQL database '${DB_NAME}'"
    else
        log "ERROR: Failed to initialize Poweradmin schema in PostgreSQL database '${DB_NAME}'"
        exit 1
    fi
}

# Validate required database configuration
validate_database_config() {
    if [ -z "${DB_TYPE}" ]; then
        log "ERROR: DB_TYPE environment variable is required. Supported types: sqlite, mysql, pgsql"
        exit 1
    fi
    debug_log "Starting database validation with DB_TYPE=${DB_TYPE}"
    case "${DB_TYPE}" in
        "sqlite")
            local db_file="${DB_FILE:-/db/pdns.db}"
            debug_log "Checking SQLite database file: ${db_file}"
            debug_log "File exists check: [ -f ${db_file} ] = $([ -f "${db_file}" ] && echo true || echo false)"
            debug_log "Directory writable check: [ -w $(dirname ${db_file}) ] = $([ -w "$(dirname "${db_file}")" ] && echo true || echo false)"
            if [ ! -f "${db_file}" ] && [ ! -w "$(dirname "${db_file}")" ]; then
                log "ERROR: SQLite database file ${db_file} doesn't exist and directory is not writable"
                exit 1
            fi
            debug_log "SQLite validation passed"
            ;;
        "mysql"|"pgsql")
            if [ -z "${DB_HOST}" ]; then
                log "ERROR: DB_HOST is required for ${DB_TYPE} database"
                exit 1
            fi
            if [ -z "${DB_USER}" ]; then
                log "ERROR: DB_USER is required for ${DB_TYPE} database"
                exit 1
            fi
            if [ -z "${DB_NAME}" ]; then
                log "ERROR: DB_NAME is required for ${DB_TYPE} database"
                exit 1
            fi
            if [ -z "${DB_PASS:-}" ]; then
                log "WARNING: DB_PASS is empty for ${DB_TYPE} database - ensure this is intentional"
            fi
            ;;
        *)
            log "ERROR: Unsupported database type: ${DB_TYPE}. Supported types: sqlite, mysql, pgsql"
            exit 1
            ;;
    esac
    debug_log "Database validation function completed"
}

# Validate DNS configuration
validate_dns_config() {
    debug_log "DNS_NS1='${DNS_NS1}'"
    debug_log "DNS_NS2='${DNS_NS2}'"
    debug_log "DNS_HOSTMASTER='${DNS_HOSTMASTER}'"
    if [ -z "${DNS_NS1}" ]; then
        log "WARNING: DNS_NS1 not set, using default: ns1.example.com"
        DNS_NS1="ns1.example.com"
    fi
    if [ -z "${DNS_NS2}" ]; then
        log "WARNING: DNS_NS2 not set, using default: ns2.example.com"
        DNS_NS2="ns2.example.com"
    fi
    if [ -z "${DNS_HOSTMASTER}" ]; then
        log "WARNING: DNS_HOSTMASTER not set, using default: hostmaster@example.com"
        DNS_HOSTMASTER="hostmaster@example.com"
    fi
    debug_log "DNS validation completed"
}

# Validate mail configuration if enabled
validate_mail_config() {
    local mail_enabled
    mail_enabled=$(echo "${PA_MAIL_ENABLED:-true}" | tr '[:upper:]' '[:lower:]')
    if [ "$mail_enabled" = "true" ] && [ "${PA_MAIL_TRANSPORT}" = "smtp" ]; then
        if [ -z "${PA_SMTP_HOST}" ]; then
            log "ERROR: PA_SMTP_HOST is required when using SMTP transport"
            exit 1
        fi
        if [ -z "${PA_MAIL_FROM}" ]; then
            log "ERROR: PA_MAIL_FROM is required when mail is enabled"
            exit 1
        fi
    fi
}

# Validate API configuration if enabled
validate_api_config() {
    local api_enabled
    api_enabled=$(echo "${PA_API_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')
    if [ "$api_enabled" = "true" ] && [ -n "${PA_PDNS_API_URL}" ]; then
        if [ -z "${PA_PDNS_API_KEY}" ]; then
            log "ERROR: PA_PDNS_API_KEY is required when PowerDNS API URL is specified"
            exit 1
        fi
    fi

    if [ -n "${PA_PDNS_BACKEND:-}" ]; then
        log "WARNING: PA_PDNS_BACKEND is not a recognized variable - did you mean PA_DNS_BACKEND?"
    fi
}

# Validate LDAP configuration if enabled
validate_ldap_config() {
    local ldap_enabled
    ldap_enabled=$(echo "${PA_LDAP_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')
    if [ "$ldap_enabled" = "true" ]; then
        local required_ldap_vars=("PA_LDAP_URI" "PA_LDAP_BASE_DN")
        for var in "${required_ldap_vars[@]}"; do
            if [ -z "${!var}" ]; then
                log "ERROR: ${var} is required when LDAP is enabled"
                exit 1
            fi
        done
    fi
}

# Validate SAML configuration if enabled
validate_saml_config() {
    local saml_enabled
    saml_enabled=$(echo "${PA_SAML_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')
    if [ "$saml_enabled" = "true" ]; then
        local azure_enabled okta_enabled auth0_enabled keycloak_enabled generic_enabled
        azure_enabled=$(echo "${PA_SAML_AZURE_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')
        okta_enabled=$(echo "${PA_SAML_OKTA_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')
        auth0_enabled=$(echo "${PA_SAML_AUTH0_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')
        keycloak_enabled=$(echo "${PA_SAML_KEYCLOAK_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')
        generic_enabled=$(echo "${PA_SAML_GENERIC_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')

        if [ "$azure_enabled" != "true" ] && [ "$okta_enabled" != "true" ] && [ "$auth0_enabled" != "true" ] && [ "$keycloak_enabled" != "true" ] && [ "$generic_enabled" != "true" ]; then
            log "ERROR: SAML is enabled but no SAML providers are configured. Enable at least one provider (PA_SAML_*_ENABLED=true)"
            exit 1
        fi

        if [ "$azure_enabled" = "true" ]; then
            if [ -z "${PA_SAML_AZURE_X509_CERT}" ]; then
                log "ERROR: PA_SAML_AZURE_X509_CERT is required when Azure SAML is enabled"
                exit 1
            fi
        fi

        if [ "$okta_enabled" = "true" ]; then
            local required_okta_vars=("PA_SAML_OKTA_ENTITY_ID" "PA_SAML_OKTA_SSO_URL" "PA_SAML_OKTA_X509_CERT")
            for var in "${required_okta_vars[@]}"; do
                if [ -z "${!var}" ]; then
                    log "ERROR: ${var} is required when Okta SAML is enabled"
                    exit 1
                fi
            done
        fi

        if [ "$auth0_enabled" = "true" ]; then
            local required_auth0_vars=("PA_SAML_AUTH0_ENTITY_ID" "PA_SAML_AUTH0_SSO_URL" "PA_SAML_AUTH0_X509_CERT")
            for var in "${required_auth0_vars[@]}"; do
                if [ -z "${!var}" ]; then
                    log "ERROR: ${var} is required when Auth0 SAML is enabled"
                    exit 1
                fi
            done
        fi

        if [ "$keycloak_enabled" = "true" ]; then
            local required_keycloak_vars=("PA_SAML_KEYCLOAK_ENTITY_ID" "PA_SAML_KEYCLOAK_SSO_URL" "PA_SAML_KEYCLOAK_X509_CERT")
            for var in "${required_keycloak_vars[@]}"; do
                if [ -z "${!var}" ]; then
                    log "ERROR: ${var} is required when Keycloak SAML is enabled"
                    exit 1
                fi
            done
        fi

        if [ "$generic_enabled" = "true" ]; then
            local required_generic_vars=("PA_SAML_GENERIC_ENTITY_ID" "PA_SAML_GENERIC_SSO_URL" "PA_SAML_GENERIC_X509_CERT")
            for var in "${required_generic_vars[@]}"; do
                if [ -z "${!var}" ]; then
                    log "ERROR: ${var} is required when Generic SAML is enabled"
                    exit 1
                fi
            done
        fi
    fi
}

# Validate OIDC configuration if enabled
validate_oidc_config() {
    local oidc_enabled
    oidc_enabled=$(echo "${PA_OIDC_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')
    if [ "$oidc_enabled" = "true" ]; then
        local azure_enabled google_enabled generic_enabled
        azure_enabled=$(echo "${PA_OIDC_AZURE_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')
        google_enabled=$(echo "${PA_OIDC_GOOGLE_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')
        generic_enabled=$(echo "${PA_OIDC_GENERIC_ENABLED:-false}" | tr '[:upper:]' '[:lower:]')

        if [ "$azure_enabled" != "true" ] && [ "$google_enabled" != "true" ] && [ "$generic_enabled" != "true" ]; then
            log "ERROR: OIDC is enabled but no OIDC providers are configured. Enable at least one provider (PA_OIDC_*_ENABLED=true)"
            exit 1
        fi

        if [ "$azure_enabled" = "true" ]; then
            if [ -z "${PA_OIDC_AZURE_CLIENT_ID}" ] || [ -z "${PA_OIDC_AZURE_CLIENT_SECRET}" ]; then
                log "ERROR: PA_OIDC_AZURE_CLIENT_ID and PA_OIDC_AZURE_CLIENT_SECRET are required when Azure OIDC is enabled"
                exit 1
            fi
        fi

        if [ "$google_enabled" = "true" ]; then
            if [ -z "${PA_OIDC_GOOGLE_CLIENT_ID}" ] || [ -z "${PA_OIDC_GOOGLE_CLIENT_SECRET}" ]; then
                log "ERROR: PA_OIDC_GOOGLE_CLIENT_ID and PA_OIDC_GOOGLE_CLIENT_SECRET are required when Google OIDC is enabled"
                exit 1
            fi
        fi

        if [ "$generic_enabled" = "true" ]; then
            if [ -z "${PA_OIDC_GENERIC_CLIENT_ID}" ] || [ -z "${PA_OIDC_GENERIC_CLIENT_SECRET}" ]; then
                log "ERROR: PA_OIDC_GENERIC_CLIENT_ID and PA_OIDC_GENERIC_CLIENT_SECRET are required when Generic OIDC is enabled"
                exit 1
            fi

            local auto_discovery
            auto_discovery=$(echo "${PA_OIDC_GENERIC_AUTO_DISCOVERY:-false}" | tr '[:upper:]' '[:lower:]')
            if [ "$auto_discovery" = "true" ]; then
                if [ -z "${PA_OIDC_GENERIC_METADATA_URL}" ]; then
                    log "ERROR: PA_OIDC_GENERIC_METADATA_URL is required when PA_OIDC_GENERIC_AUTO_DISCOVERY is enabled"
                    exit 1
                fi
            else
                if [ -z "${PA_OIDC_GENERIC_AUTHORIZE_URL}" ] || [ -z "${PA_OIDC_GENERIC_TOKEN_URL}" ]; then
                    log "ERROR: PA_OIDC_GENERIC_AUTHORIZE_URL and PA_OIDC_GENERIC_TOKEN_URL are required when auto_discovery is disabled"
                    exit 1
                fi
            fi
        fi
    fi
}

# Create initial admin user if specified
create_admin_user() {
    local create_admin
    create_admin=$(echo "${PA_CREATE_ADMIN:-false}" | tr '[:upper:]' '[:lower:]')

    if [ "$create_admin" != "true" ] && [ "$create_admin" != "1" ] && [ "$create_admin" != "yes" ]; then
        debug_log "Admin user creation disabled"
        return 0
    fi

    local admin_username="${PA_ADMIN_USERNAME:-admin}"
    local admin_password="${PA_ADMIN_PASSWORD:-}"
    local admin_email="${PA_ADMIN_EMAIL:-admin@example.com}"
    local admin_fullname="${PA_ADMIN_FULLNAME:-Administrator}"
    local password_generated="false"

    # Generate secure random password if not provided
    if [ -z "${admin_password}" ]; then
        admin_password=$(openssl rand -base64 16 | tr -d "=+/" | cut -c1-16)
        password_generated="true"
        log "Generated secure random password for admin user"
    fi

    debug_log "Creating admin user: ${admin_username}"

    # Generate password hash using PHP (secure method with proper argument passing)
    local password_hash
    password_hash=$(php -r "echo password_hash(\$argv[1], PASSWORD_DEFAULT);" -- "${admin_password}" 2>/dev/null)

    if [ $? -ne 0 ] || [ -z "${password_hash}" ]; then
        log "ERROR: Failed to generate password hash for admin user"
        exit 1
    fi

    debug_log "Generated password hash for admin user"

    local insert_result=0

    case "${DB_TYPE}" in
        "sqlite")
            local db_file="${DB_FILE:-/db/pdns.db}"
            debug_log "Creating admin user in SQLite database: ${db_file}"

            local user_exists
            user_exists=$(sqlite3 "${db_file}" "SELECT COUNT(*) FROM users WHERE username='$(escape_sql "${admin_username}")';")

            if [ "${user_exists}" -gt 0 ]; then
                log "Admin user '${admin_username}' already exists, skipping creation"
                return 0
            fi

            # Values are escaped via escape_sql; passwords are hashed by PHP before insertion
            if ! sqlite3 "${db_file}" "INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES ('$(escape_sql "${admin_username}")', '$(escape_sql "${password_hash}")', '$(escape_sql "${admin_fullname}")', '$(escape_sql "${admin_email}")', 'System Administrator', 1, 1, 0);"; then
                insert_result=1
            fi
            ;;

        "mysql")
            debug_log "Creating admin user in MySQL database"

            local -a ssl_opts
            mapfile -t ssl_opts < <(build_mysql_ssl_opts)

            local mycnf
            mycnf=$(make_mysql_defaults_file)
            # shellcheck disable=SC2064
            trap "rm -f '${mycnf}'" RETURN

            local user_exists
            user_exists=$(mysql --defaults-file="${mycnf}" "${ssl_opts[@]}" \
                -h"${DB_HOST}" -u"${DB_USER}" "${DB_NAME}" -sNe \
                "SELECT COUNT(*) FROM users WHERE username='$(escape_sql "${admin_username}")';")

            if [ "${user_exists}" -gt 0 ]; then
                log "Admin user '${admin_username}' already exists, skipping creation"
                return 0
            fi

            if ! mysql --defaults-file="${mycnf}" "${ssl_opts[@]}" \
                    -h"${DB_HOST}" -u"${DB_USER}" "${DB_NAME}" -e \
                    "INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES ('$(escape_sql "${admin_username}")', '$(escape_sql "${password_hash}")', '$(escape_sql "${admin_fullname}")', '$(escape_sql "${admin_email}")', 'System Administrator', 1, 1, 0);"; then
                insert_result=1
            fi
            ;;

        "pgsql")
            debug_log "Creating admin user in PostgreSQL database"

            local user_exists
            user_exists=$(PGPASSWORD="${DB_PASS}" psql -h "${DB_HOST}" -U "${DB_USER}" -d "${DB_NAME}" -tAc \
                "SELECT COUNT(*) FROM users WHERE username='$(escape_sql "${admin_username}")';")

            if [ "${user_exists}" -gt 0 ]; then
                log "Admin user '${admin_username}' already exists, skipping creation"
                return 0
            fi

            if ! PGPASSWORD="${DB_PASS}" psql -h "${DB_HOST}" -U "${DB_USER}" -d "${DB_NAME}" -c \
                    "INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES ('$(escape_sql "${admin_username}")', '$(escape_sql "${password_hash}")', '$(escape_sql "${admin_fullname}")', '$(escape_sql "${admin_email}")', 'System Administrator', 1, 1, 0);"; then
                insert_result=1
            fi
            ;;
    esac

    if [ $insert_result -eq 0 ]; then
        log "Admin user '${admin_username}' created successfully"

        if [ "${password_generated}" = "true" ]; then
            log "=========================================="
            log "IMPORTANT: Admin credentials"
            log "Username: ${admin_username}"
            log "Password: ${admin_password}"
            log "=========================================="
        fi
    else
        log "ERROR: Failed to create admin user '${admin_username}'"
        exit 1
    fi

    export ADMIN_PASSWORD_GENERATED="${password_generated}"
    export ADMIN_USERNAME="${admin_username}"
    export ADMIN_PASSWORD="${admin_password}"
}

# Convert an environment variable value to a lowercase boolean string.
# Usage: to_bool "${PA_SOME_OPTION:-false}"
# Replaces ~50 identical inline subshell invocations in generate_config.
to_bool() {
    echo "${1:-false}" | tr '[:upper:]' '[:lower:]'
}

# Generate configuration file from environment variables
generate_config() {
    log "Generating configuration from environment variables..."

    local session_key="${PA_SESSION_KEY:-$(openssl rand -hex 32)}"

    # --- Boolean flags ---
    local recaptcha_enabled;          recaptcha_enabled=$(to_bool "${PA_RECAPTCHA_ENABLED:-false}")
    local mail_enabled;               mail_enabled=$(to_bool "${PA_MAIL_ENABLED:-true}")
    local api_enabled;                api_enabled=$(to_bool "${PA_API_ENABLED:-false}")
    local api_basic_auth_enabled;     api_basic_auth_enabled=$(to_bool "${PA_API_BASIC_AUTH_ENABLED:-false}")
    local api_docs_enabled;           api_docs_enabled=$(to_bool "${PA_API_DOCS_ENABLED:-false}")
    local ldap_enabled;               ldap_enabled=$(to_bool "${PA_LDAP_ENABLED:-false}")

    local show_record_id;             show_record_id=$(to_bool "${PA_SHOW_RECORD_ID:-true}")
    local position_record_form_top;   position_record_form_top=$(to_bool "${PA_POSITION_RECORD_FORM_TOP:-true}")
    local position_save_button_top;   position_save_button_top=$(to_bool "${PA_POSITION_SAVE_BUTTON_TOP:-false}")
    local show_zone_comments;         show_zone_comments=$(to_bool "${PA_SHOW_ZONE_COMMENTS:-true}")
    local show_record_comments;       show_record_comments=$(to_bool "${PA_SHOW_RECORD_COMMENTS:-false}")
    local display_serial_in_zone_list; display_serial_in_zone_list=$(to_bool "${PA_DISPLAY_SERIAL_IN_ZONE_LIST:-false}")
    local display_template_in_zone_list; display_template_in_zone_list=$(to_bool "${PA_DISPLAY_TEMPLATE_IN_ZONE_LIST:-false}")
    local display_fullname_in_zone_list; display_fullname_in_zone_list=$(to_bool "${PA_DISPLAY_FULLNAME_IN_ZONE_LIST:-false}")
    local search_group_records;       search_group_records=$(to_bool "${PA_SEARCH_GROUP_RECORDS:-false}")
    local show_pdns_status;           show_pdns_status=$(to_bool "${PA_SHOW_PDNS_STATUS:-false}")
    local add_reverse_record;         add_reverse_record=$(to_bool "${PA_ADD_REVERSE_RECORD:-true}")
    local add_domain_record;          add_domain_record=$(to_bool "${PA_ADD_DOMAIN_RECORD:-true}")
    local display_hostname_only;      display_hostname_only=$(to_bool "${PA_DISPLAY_HOSTNAME_ONLY:-false}")
    local enable_consistency_checks;  enable_consistency_checks=$(to_bool "${PA_ENABLE_CONSISTENCY_CHECKS:-false}")

    local dns_strict_tld_check;       dns_strict_tld_check=$(to_bool "${PA_DNS_STRICT_TLD_CHECK:-false}")
    local dns_top_level_tld_check;    dns_top_level_tld_check=$(to_bool "${PA_DNS_TOP_LEVEL_TLD_CHECK:-false}")
    local dns_third_level_check;      dns_third_level_check=$(to_bool "${PA_DNS_THIRD_LEVEL_CHECK:-false}")
    local dns_txt_auto_quote;         dns_txt_auto_quote=$(to_bool "${PA_DNS_TXT_AUTO_QUOTE:-false}")
    local dns_prevent_duplicate_ptr;  dns_prevent_duplicate_ptr=$(to_bool "${PA_DNS_PREVENT_DUPLICATE_PTR:-true}")

    local dnssec_enabled;             dnssec_enabled=$(to_bool "${PA_DNSSEC_ENABLED:-false}")
    local dnssec_debug;               dnssec_debug=$(to_bool "${PA_DNSSEC_DEBUG:-false}")

    local logging_database_enabled;   logging_database_enabled=$(to_bool "${PA_LOGGING_DATABASE_ENABLED:-false}")
    local logging_syslog_enabled;     logging_syslog_enabled=$(to_bool "${PA_LOGGING_SYSLOG_ENABLED:-false}")

    local password_rules_enabled;     password_rules_enabled=$(to_bool "${PA_PASSWORD_RULES_ENABLED:-true}")
    local password_require_uppercase; password_require_uppercase=$(to_bool "${PA_PASSWORD_REQUIRE_UPPERCASE:-true}")
    local password_require_lowercase; password_require_lowercase=$(to_bool "${PA_PASSWORD_REQUIRE_LOWERCASE:-true}")
    local password_require_numbers;   password_require_numbers=$(to_bool "${PA_PASSWORD_REQUIRE_NUMBERS:-true}")
    local password_require_special;   password_require_special=$(to_bool "${PA_PASSWORD_REQUIRE_SPECIAL:-false}")

    local lockout_enabled;            lockout_enabled=$(to_bool "${PA_LOCKOUT_ENABLED:-false}")
    local lockout_track_ip;           lockout_track_ip=$(to_bool "${PA_LOCKOUT_TRACK_IP:-true}")
    local lockout_clear_on_success;   lockout_clear_on_success=$(to_bool "${PA_LOCKOUT_CLEAR_ON_SUCCESS:-true}")

    local password_reset_enabled;     password_reset_enabled=$(to_bool "${PA_PASSWORD_RESET_ENABLED:-false}")
    local username_recovery_enabled;  username_recovery_enabled=$(to_bool "${PA_USERNAME_RECOVERY_ENABLED:-false}")

    local login_token_validation;     login_token_validation=$(to_bool "${PA_LOGIN_TOKEN_VALIDATION:-true}")
    local global_token_validation;    global_token_validation=$(to_bool "${PA_GLOBAL_TOKEN_VALIDATION:-true}")
    local mfa_enforced;               mfa_enforced=$(to_bool "${PA_MFA_ENFORCED:-false}")

    local notification_zone_access;   notification_zone_access=$(to_bool "${PA_NOTIFICATION_ZONE_ACCESS:-false}")

    local user_agreement_enabled;            user_agreement_enabled=$(to_bool "${PA_USER_AGREEMENT_ENABLED:-false}")
    local user_agreement_require_on_change;  user_agreement_require_on_change=$(to_bool "${PA_USER_AGREEMENT_REQUIRE_ON_CHANGE:-true}")

    local show_add_record_form;          show_add_record_form=$(to_bool "${PA_SHOW_ADD_RECORD_FORM:-false}")
    local show_record_edit_button;       show_record_edit_button=$(to_bool "${PA_SHOW_RECORD_EDIT_BUTTON:-false}")
    local show_record_delete_button;     show_record_delete_button=$(to_bool "${PA_SHOW_RECORD_DELETE_BUTTON:-false}")
    local show_forward_zone_associations; show_forward_zone_associations=$(to_bool "${PA_SHOW_FORWARD_ZONE_ASSOCIATIONS:-true}")

    local mail_auth;                  mail_auth=$(to_bool "${PA_SMTP_AUTH:-false}")
    local ldap_debug;                 ldap_debug=$(to_bool "${PA_LDAP_DEBUG:-false}")

    local display_stats;              display_stats=$(to_bool "${PA_DISPLAY_STATS:-false}")
    local record_comments_sync;       record_comments_sync=$(to_bool "${PA_RECORD_COMMENTS_SYNC:-false}")
    local display_errors;             display_errors=$(to_bool "${PA_DISPLAY_ERRORS:-false}")
    local show_generated_passwords;   show_generated_passwords=$(to_bool "${PA_SHOW_GENERATED_PASSWORDS:-true}")

    local oidc_enabled;               oidc_enabled=$(to_bool "${PA_OIDC_ENABLED:-false}")
    local oidc_auto_provision;        oidc_auto_provision=$(to_bool "${PA_OIDC_AUTO_PROVISION:-true}")
    local oidc_link_by_email;         oidc_link_by_email=$(to_bool "${PA_OIDC_LINK_BY_EMAIL:-true}")
    local oidc_sync_user_info;        oidc_sync_user_info=$(to_bool "${PA_OIDC_SYNC_USER_INFO:-true}")
    local oidc_azure_enabled;         oidc_azure_enabled=$(to_bool "${PA_OIDC_AZURE_ENABLED:-false}")
    local oidc_azure_auto_discovery;  oidc_azure_auto_discovery=$(to_bool "${PA_OIDC_AZURE_AUTO_DISCOVERY:-true}")
    local oidc_google_enabled;        oidc_google_enabled=$(to_bool "${PA_OIDC_GOOGLE_ENABLED:-false}")
    local oidc_google_auto_discovery; oidc_google_auto_discovery=$(to_bool "${PA_OIDC_GOOGLE_AUTO_DISCOVERY:-true}")
    local oidc_generic_enabled;       oidc_generic_enabled=$(to_bool "${PA_OIDC_GENERIC_ENABLED:-false}")
    local oidc_generic_auto_discovery; oidc_generic_auto_discovery=$(to_bool "${PA_OIDC_GENERIC_AUTO_DISCOVERY:-false}")

    local saml_enabled;               saml_enabled=$(to_bool "${PA_SAML_ENABLED:-false}")
    local saml_auto_provision;        saml_auto_provision=$(to_bool "${PA_SAML_AUTO_PROVISION:-true}")
    local saml_link_by_email;         saml_link_by_email=$(to_bool "${PA_SAML_LINK_BY_EMAIL:-true}")
    local saml_sync_user_info;        saml_sync_user_info=$(to_bool "${PA_SAML_SYNC_USER_INFO:-true}")
    local saml_azure_enabled;         saml_azure_enabled=$(to_bool "${PA_SAML_AZURE_ENABLED:-false}")
    local saml_okta_enabled;          saml_okta_enabled=$(to_bool "${PA_SAML_OKTA_ENABLED:-false}")
    local saml_auth0_enabled;         saml_auth0_enabled=$(to_bool "${PA_SAML_AUTH0_ENABLED:-false}")
    local saml_keycloak_enabled;      saml_keycloak_enabled=$(to_bool "${PA_SAML_KEYCLOAK_ENABLED:-false}")
    local saml_generic_enabled;       saml_generic_enabled=$(to_bool "${PA_SAML_GENERIC_ENABLED:-false}")

    local mfa_enabled;                mfa_enabled=$(to_bool "${PA_MFA_ENABLED:-false}")
    local mfa_app_enabled;            mfa_app_enabled=$(to_bool "${PA_MFA_APP_ENABLED:-true}")
    local mfa_email_enabled;          mfa_email_enabled=$(to_bool "${PA_MFA_EMAIL_ENABLED:-true}")

    local mod_csv_export_enabled;           mod_csv_export_enabled=$(to_bool "${PA_MODULE_CSV_EXPORT_ENABLED:-true}")
    local mod_zone_import_export_enabled;   mod_zone_import_export_enabled=$(to_bool "${PA_MODULE_ZONE_IMPORT_EXPORT_ENABLED:-false}")
    local mod_whois_enabled;                mod_whois_enabled=$(to_bool "${PA_MODULE_WHOIS_ENABLED:-false}")
    local mod_whois_restrict_to_admin;      mod_whois_restrict_to_admin=$(to_bool "${PA_MODULE_WHOIS_RESTRICT_TO_ADMIN:-true}")
    local mod_rdap_enabled;                 mod_rdap_enabled=$(to_bool "${PA_MODULE_RDAP_ENABLED:-false}")
    local mod_rdap_restrict_to_admin;       mod_rdap_restrict_to_admin=$(to_bool "${PA_MODULE_RDAP_RESTRICT_TO_ADMIN:-true}")
    local mod_email_previews_enabled;       mod_email_previews_enabled=$(to_bool "${PA_MODULE_EMAIL_PREVIEWS_ENABLED:-false}")
    local mod_email_previews_restrict_to_admin; mod_email_previews_restrict_to_admin=$(to_bool "${PA_MODULE_EMAIL_PREVIEWS_RESTRICT_TO_ADMIN:-true}")
    local mod_dns_wizards_enabled;          mod_dns_wizards_enabled=$(to_bool "${PA_MODULE_DNS_WIZARDS_ENABLED:-false}")

    local db_ssl;       db_ssl=$(to_bool "${DB_SSL:-false}")
    local db_ssl_verify; db_ssl_verify=$(to_bool "${DB_SSL_VERIFY:-false}")

    # --- PHP-escaped values for safe embedding in single-quoted PHP strings ---
    local esc_session_key;    esc_session_key=$(escape_php "${session_key}")
    local esc_db_pass;        esc_db_pass=$(escape_php "${DB_PASS:-}")
    local esc_smtp_password;  esc_smtp_password=$(escape_php "${PA_SMTP_PASSWORD:-}")
    local esc_ldap_bind_pass; esc_ldap_bind_pass=$(escape_php "${PA_LDAP_BIND_PASSWORD:-}")
    local esc_pdns_api_key;   esc_pdns_api_key=$(escape_php "${PA_PDNS_API_KEY:-}")
    local esc_recaptcha_secret; esc_recaptcha_secret=$(escape_php "${PA_RECAPTCHA_SECRET_KEY:-}")

    # --- DNS record type arrays ---
    local domain_record_types="null"
    if [ -n "${PA_DNS_DOMAIN_RECORD_TYPES}" ]; then
        domain_record_types="['$(echo "${PA_DNS_DOMAIN_RECORD_TYPES}" | sed "s/,/','/g")']"
    fi

    local reverse_record_types="null"
    if [ -n "${PA_DNS_REVERSE_RECORD_TYPES}" ]; then
        reverse_record_types="['$(echo "${PA_DNS_REVERSE_RECORD_TYPES}" | sed "s/,/','/g")']"
    fi

    local dns_wizards_types="['DMARC', 'SPF', 'DKIM', 'CAA', 'TLSA', 'SRV']"
    if [ -n "${PA_MODULE_DNS_WIZARDS_TYPES}" ]; then
        dns_wizards_types="['$(echo "${PA_MODULE_DNS_WIZARDS_TYPES}" | sed "s/,/','/g")']"
    fi

    local custom_tlds="[]"
    if [ -n "${PA_DNS_CUSTOM_TLDS}" ]; then
        custom_tlds="['$(echo "${PA_DNS_CUSTOM_TLDS}" | sed "s/,/','/g")']"
    fi

    # Helper: convert key=value or key:value mapping to PHP associative array
    parse_mapping() {
        local input="$1"
        echo "$input" | sed "s/'/\\\\'/g" | sed 's/ *, */,/g' | sed 's/ *= */=/g' | sed 's/ *: */:/g' | sed 's/ *| */|/g' | \
            awk -F',' '{
                for (i=1; i<=NF; i++) {
                    if (index($i, "=") > 0) {
                        split($i, a, "=")
                        key = a[1]
                        val = a[2]
                    } else {
                        idx = 0
                        for (j=1; j<=length($i); j++) {
                            if (substr($i, j, 1) == ":") idx = j
                        }
                        if (idx > 0) {
                            key = substr($i, 1, idx-1)
                            val = substr($i, idx+1)
                        } else {
                            key = $i
                            val = ""
                        }
                    }
                    if (i > 1) printf ","
                    if (index(val, "|") > 0) {
                        n = split(val, parts, "|")
                        printf "'\''%s'\'' => [", key
                        for (k=1; k<=n; k++) {
                            if (k > 1) printf ", "
                            printf "'\''%s'\''", parts[k]
                        }
                        printf "]"
                    } else {
                        printf "'\''%s'\'' => '\''%s'\''", key, val
                    }
                }
            }'
    }

    local oidc_permission_template_mapping="[]"
    if [ -n "${PA_OIDC_PERMISSION_TEMPLATE_MAPPING}" ]; then
        oidc_permission_template_mapping="[$(parse_mapping "${PA_OIDC_PERMISSION_TEMPLATE_MAPPING}")]"
    fi

    local oidc_group_mapping="[]"
    if [ -n "${PA_OIDC_GROUP_MAPPING}" ]; then
        oidc_group_mapping="[$(parse_mapping "${PA_OIDC_GROUP_MAPPING}")]"
    fi

    local saml_permission_template_mapping="[]"
    if [ -n "${PA_SAML_PERMISSION_TEMPLATE_MAPPING}" ]; then
        saml_permission_template_mapping="[$(parse_mapping "${PA_SAML_PERMISSION_TEMPLATE_MAPPING}")]"
    fi

    local saml_group_mapping="[]"
    if [ -n "${PA_SAML_GROUP_MAPPING}" ]; then
        saml_group_mapping="[$(parse_mapping "${PA_SAML_GROUP_MAPPING}")]"
    fi

    local whois_custom_servers="[]"
    if [ -n "${PA_MODULE_WHOIS_CUSTOM_SERVERS:-}" ]; then
        whois_custom_servers="[$(parse_mapping "${PA_MODULE_WHOIS_CUSTOM_SERVERS}")]"
    fi

    local rdap_custom_servers="[]"
    if [ -n "${PA_MODULE_RDAP_CUSTOM_SERVERS:-}" ]; then
        rdap_custom_servers="[$(parse_mapping "${PA_MODULE_RDAP_CUSTOM_SERVERS}")]"
    fi

    mkdir -p "$(dirname "${CONFIG_FILE}")"

    cat > "${CONFIG_FILE}" << EOF
<?php

return [
    'database' => [
        'type' => '${DB_TYPE}',
        'host' => '${DB_HOST:-}',
        'port' => '${DB_PORT:-}',
        'user' => '${DB_USER:-}',
        'password' => '${esc_db_pass}',
        'name' => '${DB_NAME:-}',
        'file' => '${DB_FILE:-/db/pdns.db}',
        'pdns_db_name' => '${PA_PDNS_DB_NAME:-}',
        'ssl' => ${db_ssl},
        'ssl_verify' => ${db_ssl_verify},
        'ssl_ca' => '${DB_SSL_CA:-}',
        'ssl_key' => '${DB_SSL_KEY:-}',
        'ssl_cert' => '${DB_SSL_CERT:-}',
    ],
    'dns' => [
        'backend' => '${PA_DNS_BACKEND:-sql}',
        'hostmaster' => '${DNS_HOSTMASTER:-hostmaster.example.com}',
        'ns1' => '${DNS_NS1:-ns1.example.com}',
        'ns2' => '${DNS_NS2:-ns2.example.com}',
        'ns3' => '${DNS_NS3:-}',
        'ns4' => '${DNS_NS4:-}',
        'ttl' => ${PA_DNS_TTL:-86400},
        'soa_refresh' => ${PA_DNS_SOA_REFRESH:-28800},
        'soa_retry' => ${PA_DNS_SOA_RETRY:-7200},
        'soa_expire' => ${PA_DNS_SOA_EXPIRE:-604800},
        'soa_minimum' => ${PA_DNS_SOA_MINIMUM:-86400},
        'zone_type_default' => '${PA_DNS_ZONE_TYPE_DEFAULT:-MASTER}',
        'strict_tld_check' => ${dns_strict_tld_check},
        'top_level_tld_check' => ${dns_top_level_tld_check},
        'third_level_check' => ${dns_third_level_check},
        'txt_auto_quote' => ${dns_txt_auto_quote},
        'prevent_duplicate_ptr' => ${dns_prevent_duplicate_ptr},
        'custom_tlds' => ${custom_tlds},
        'domain_record_types' => ${domain_record_types},
        'reverse_record_types' => ${reverse_record_types},
    ],
    'dnssec' => [
        'enabled' => ${dnssec_enabled},
        'debug' => ${dnssec_debug},
    ],
    'security' => [
        'session_key' => '${esc_session_key}',
        'password_encryption' => '${PA_PASSWORD_ENCRYPTION:-bcrypt}',
        'password_cost' => ${PA_PASSWORD_COST:-12},
        'login_token_validation' => ${login_token_validation},
        'global_token_validation' => ${global_token_validation},
        'password_policy' => [
            'enable_password_rules' => ${password_rules_enabled},
            'min_length' => ${PA_PASSWORD_MIN_LENGTH:-6},
            'require_uppercase' => ${password_require_uppercase},
            'require_lowercase' => ${password_require_lowercase},
            'require_numbers' => ${password_require_numbers},
            'require_special' => ${password_require_special},
        ],
        'account_lockout' => [
            'enable_lockout' => ${lockout_enabled},
            'lockout_attempts' => ${PA_LOCKOUT_ATTEMPTS:-5},
            'lockout_duration' => ${PA_LOCKOUT_DURATION:-15},
            'track_ip_address' => ${lockout_track_ip},
            'clear_attempts_on_success' => ${lockout_clear_on_success},
        ],
        'mfa' => [
            'enabled' => ${mfa_enabled},
            'enforced' => ${mfa_enforced},
            'app_enabled' => ${mfa_app_enabled},
            'email_enabled' => ${mfa_email_enabled},
            'recovery_codes' => ${PA_MFA_RECOVERY_CODES:-8},
            'recovery_code_length' => ${PA_MFA_RECOVERY_CODE_LENGTH:-10},
        ],
        'password_reset' => [
            'enabled' => ${password_reset_enabled},
            'token_lifetime' => ${PA_PASSWORD_RESET_TOKEN_LIFETIME:-3600},
            'rate_limit_attempts' => ${PA_PASSWORD_RESET_RATE_LIMIT_ATTEMPTS:-5},
            'rate_limit_window' => ${PA_PASSWORD_RESET_RATE_LIMIT_WINDOW:-3600},
            'min_time_between_requests' => ${PA_PASSWORD_RESET_MIN_TIME_BETWEEN:-60},
        ],
        'username_recovery' => [
            'enabled' => ${username_recovery_enabled},
            'rate_limit_attempts' => ${PA_USERNAME_RECOVERY_RATE_LIMIT_ATTEMPTS:-5},
            'rate_limit_window' => ${PA_USERNAME_RECOVERY_RATE_LIMIT_WINDOW:-3600},
            'min_time_between_requests' => ${PA_USERNAME_RECOVERY_MIN_TIME_BETWEEN:-60},
        ],
        'recaptcha' => [
            'enabled' => ${recaptcha_enabled},
            'site_key' => '${PA_RECAPTCHA_SITE_KEY:-}',
            'secret_key' => '${esc_recaptcha_secret}',
            'version' => '${PA_RECAPTCHA_VERSION:-v3}',
            'v3_threshold' => ${PA_RECAPTCHA_V3_THRESHOLD:-0.5},
        ],
    ],
    'mail' => [
        'enabled' => ${mail_enabled},
        'transport' => '${PA_MAIL_TRANSPORT:-php}',
        'host' => '${PA_SMTP_HOST:-}',
        'port' => ${PA_SMTP_PORT:-587},
        'username' => '${PA_SMTP_USER:-}',
        'password' => '${esc_smtp_password}',
        'encryption' => '${PA_SMTP_ENCRYPTION:-tls}',
        'from' => '${PA_MAIL_FROM:-}',
        'from_name' => '${PA_MAIL_FROM_NAME:-}',
        'return_path' => '${PA_MAIL_RETURN_PATH:-poweradmin@example.com}',
        'auth' => ${mail_auth},
        'sendmail_path' => '${PA_SENDMAIL_PATH:-/usr/sbin/sendmail -bs}',
    ],
    'notifications' => [
        'zone_access_enabled' => ${notification_zone_access},
    ],
    'interface' => [
        'title' => '${PA_APP_TITLE:-Poweradmin}',
        'base_url' => '${PA_BASE_URL:-}',
        'language' => '${PA_DEFAULT_LANGUAGE:-en_EN}',
        'enabled_languages' => '${PA_ENABLED_LANGUAGES:-cs_CZ,de_DE,en_EN,es_ES,fr_FR,it_IT,ja_JP,lt_LT,nb_NO,nl_NL,pl_PL,pt_PT,ru_RU,tr_TR,zh_CN}',
        'session_timeout' => ${PA_SESSION_TIMEOUT:-1800},
        'rows_per_page' => ${PA_ROWS_PER_PAGE:-10},
        'theme' => '${PA_THEME:-default}',
        'style' => '${PA_STYLE:-light}',
        'theme_base_path' => '${PA_THEME_BASE_PATH:-templates}',
        'base_url_prefix' => '${PA_BASE_URL_PREFIX:-}',
        'application_url' => '${PA_APPLICATION_URL:-}',
        'show_record_id' => ${show_record_id},
        'show_add_record_form' => ${show_add_record_form},
        'show_record_edit_button' => ${show_record_edit_button},
        'show_record_delete_button' => ${show_record_delete_button},
        'position_record_form_top' => ${position_record_form_top},
        'position_save_button_top' => ${position_save_button_top},
        'show_zone_comments' => ${show_zone_comments},
        'show_record_comments' => ${show_record_comments},
        'display_serial_in_zone_list' => ${display_serial_in_zone_list},
        'display_template_in_zone_list' => ${display_template_in_zone_list},
        'display_fullname_in_zone_list' => ${display_fullname_in_zone_list},
        'search_group_records' => ${search_group_records},
        'reverse_zone_sort' => '${PA_REVERSE_ZONE_SORT:-natural}',
        'show_pdns_status' => ${show_pdns_status},
        'add_reverse_record' => ${add_reverse_record},
        'add_domain_record' => ${add_domain_record},
        'display_hostname_only' => ${display_hostname_only},
        'enable_consistency_checks' => ${enable_consistency_checks},
        'show_forward_zone_associations' => ${show_forward_zone_associations},
    ],
    'api' => [
        'enabled' => ${api_enabled},
        'basic_auth_enabled' => ${api_basic_auth_enabled},
        'basic_auth_realm' => '${PA_API_BASIC_AUTH_REALM:-Poweradmin API}',
        'docs_enabled' => ${api_docs_enabled},
        'max_keys_per_user' => ${PA_API_MAX_KEYS_PER_USER:-5},
    ],
    'user_agreement' => [
        'enabled' => ${user_agreement_enabled},
        'current_version' => '${PA_USER_AGREEMENT_VERSION:-1.0}',
        'require_on_version_change' => ${user_agreement_require_on_change},
    ],
    'pdns_api' => [
        'display_name' => '${PA_PDNS_DISPLAY_NAME:-PowerDNS}',
        'url' => '${PA_PDNS_API_URL:-}',
        'key' => '${esc_pdns_api_key}',
        'server_name' => '${PA_PDNS_SERVER_NAME:-localhost}',
        'webserver_username' => '${PA_PDNS_WEBSERVER_USERNAME:-}',
        'webserver_password' => '${PA_PDNS_WEBSERVER_PASSWORD:-}',
    ],
    'ldap' => [
        'enabled' => ${ldap_enabled},
        'debug' => ${ldap_debug},
        'uri' => '${PA_LDAP_URI:-}',
        'base_dn' => '${PA_LDAP_BASE_DN:-}',
        'bind_dn' => '${PA_LDAP_BIND_DN:-}',
        'bind_password' => '${esc_ldap_bind_pass}',
        'user_attribute' => '${PA_LDAP_USER_ATTRIBUTE:-uid}',
        'protocol_version' => ${PA_LDAP_PROTOCOL_VERSION:-3},
        'search_filter' => '${PA_LDAP_SEARCH_FILTER:-}',
        'session_cache_timeout' => ${PA_LDAP_SESSION_CACHE_TIMEOUT:-300},
    ],
    'logging' => [
        'type' => '${PA_LOGGING_TYPE:-null}',
        'level' => '${PA_LOGGING_LEVEL:-info}',
        'database_enabled' => ${logging_database_enabled},
        'syslog_enabled' => ${logging_syslog_enabled},
        'syslog_identity' => '${PA_LOGGING_SYSLOG_IDENTITY:-poweradmin}',
        'syslog_facility' => ${PA_LOGGING_SYSLOG_FACILITY:-LOG_USER},
    ],
    'misc' => [
        'display_stats' => ${display_stats},
        'timezone' => '${PA_TIMEZONE:-UTC}',
        'record_comments_sync' => ${record_comments_sync},
        'edit_conflict_resolution' => '${PA_EDIT_CONFLICT_RESOLUTION:-last_writer_wins}',
        'display_errors' => ${display_errors},
        'show_generated_passwords' => ${show_generated_passwords},
    ],
    'oidc' => [
        'enabled' => ${oidc_enabled},
        'auto_provision' => ${oidc_auto_provision},
        'link_by_email' => ${oidc_link_by_email},
        'sync_user_info' => ${oidc_sync_user_info},
        'default_permission_template' => '${PA_OIDC_DEFAULT_PERMISSION_TEMPLATE:-}',
        'permission_template_mapping' => ${oidc_permission_template_mapping},
        'group_mapping' => ${oidc_group_mapping},
        'providers' => [
EOF

    if [ "${oidc_azure_enabled}" = "true" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'azure' => [
                'name' => '${PA_OIDC_AZURE_NAME:-Microsoft Azure AD}',
                'display_name' => '${PA_OIDC_AZURE_DISPLAY_NAME:-Sign in with Microsoft}',
                'client_id' => '${PA_OIDC_AZURE_CLIENT_ID:-}',
                'client_secret' => '$(escape_php "${PA_OIDC_AZURE_CLIENT_SECRET:-}")',
                'tenant' => '${PA_OIDC_AZURE_TENANT:-common}',
                'auto_discovery' => ${oidc_azure_auto_discovery},
                'metadata_url' => 'https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'email',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                    'subject' => 'sub',
                ],
            ],
EOF
    fi

    if [ "${oidc_google_enabled}" = "true" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'google' => [
                'name' => '${PA_OIDC_GOOGLE_NAME:-Google}',
                'display_name' => '${PA_OIDC_GOOGLE_DISPLAY_NAME:-Sign in with Google}',
                'client_id' => '${PA_OIDC_GOOGLE_CLIENT_ID:-}',
                'client_secret' => '$(escape_php "${PA_OIDC_GOOGLE_CLIENT_SECRET:-}")',
                'auto_discovery' => ${oidc_google_auto_discovery},
                'metadata_url' => 'https://accounts.google.com/.well-known/openid-configuration',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'email',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                ],
            ],
EOF
    fi

    if [ "${oidc_generic_enabled}" = "true" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'generic' => [
                'name' => '${PA_OIDC_GENERIC_NAME:-Generic OIDC}',
                'display_name' => '${PA_OIDC_GENERIC_DISPLAY_NAME:-Sign in with OIDC}',
                'client_id' => '${PA_OIDC_GENERIC_CLIENT_ID:-}',
                'client_secret' => '$(escape_php "${PA_OIDC_GENERIC_CLIENT_SECRET:-}")',
                'auto_discovery' => ${oidc_generic_auto_discovery},
                'metadata_url' => '${PA_OIDC_GENERIC_METADATA_URL:-}',
                'authorize_url' => '${PA_OIDC_GENERIC_AUTHORIZE_URL:-}',
                'token_url' => '${PA_OIDC_GENERIC_TOKEN_URL:-}',
                'userinfo_url' => '${PA_OIDC_GENERIC_USERINFO_URL:-}',
                'logout_url' => '${PA_OIDC_GENERIC_LOGOUT_URL:-}',
                'scopes' => '${PA_OIDC_GENERIC_SCOPES:-openid profile email}',
                'user_mapping' => [
                    'username' => '${PA_OIDC_GENERIC_USERNAME_ATTR:-preferred_username}',
                    'email' => '${PA_OIDC_GENERIC_EMAIL_ATTR:-email}',
                    'first_name' => '${PA_OIDC_GENERIC_FIRST_NAME_ATTR:-given_name}',
                    'last_name' => '${PA_OIDC_GENERIC_LAST_NAME_ATTR:-family_name}',
                    'display_name' => '${PA_OIDC_GENERIC_DISPLAY_NAME_ATTR:-name}',
                    'groups' => '${PA_OIDC_GENERIC_GROUPS_ATTR:-groups}',
                ],
            ],
EOF
    fi

    cat >> "${CONFIG_FILE}" << EOF
        ],
    ],
    'saml' => [
        'enabled' => ${saml_enabled},
        'auto_provision' => ${saml_auto_provision},
        'link_by_email' => ${saml_link_by_email},
        'sync_user_info' => ${saml_sync_user_info},
        'default_permission_template' => '${PA_SAML_DEFAULT_PERMISSION_TEMPLATE:-}',
        'permission_template_mapping' => ${saml_permission_template_mapping},
        'group_mapping' => ${saml_group_mapping},

        // Service Provider (SP) Settings - Your PowerAdmin instance
        'sp' => [
EOF

    if [ -n "${PA_SAML_SP_ENTITY_ID}" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'entity_id' => '${PA_SAML_SP_ENTITY_ID}',
EOF
    elif [ -n "${PA_BASE_URL}" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'entity_id' => '${PA_BASE_URL}/saml/metadata',
EOF
    fi

    if [ -n "${PA_SAML_SP_ACS_URL}" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'assertion_consumer_service_url' => '${PA_SAML_SP_ACS_URL}',
EOF
    elif [ -n "${PA_BASE_URL}" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'assertion_consumer_service_url' => '${PA_BASE_URL}/saml/acs',
EOF
    fi

    if [ -n "${PA_SAML_SP_SLS_URL}" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'single_logout_service_url' => '${PA_SAML_SP_SLS_URL}',
EOF
    elif [ -n "${PA_BASE_URL}" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'single_logout_service_url' => '${PA_BASE_URL}/saml/sls',
EOF
    fi

    cat >> "${CONFIG_FILE}" << EOF
            'name_id_format' => '${PA_SAML_SP_NAME_ID_FORMAT:-urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress}',
            'x509cert' => '${PA_SAML_SP_X509_CERT:-}',
            'private_key' => '$(escape_php "${PA_SAML_SP_PRIVATE_KEY:-}")',
        ],

        // Provider configurations
        'providers' => [
EOF

    if [ "${saml_azure_enabled}" = "true" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'azure' => [
                'enabled' => true,
                'name' => '${PA_SAML_AZURE_NAME:-Microsoft Azure AD SAML}',
                'display_name' => '${PA_SAML_AZURE_DISPLAY_NAME:-Sign in with Microsoft (SAML)}',
                'entity_id' => '${PA_SAML_AZURE_ENTITY_ID:-https://sts.windows.net/\{tenant\}/}',
                'sso_url' => '${PA_SAML_AZURE_SSO_URL:-https://login.microsoftonline.com/\{tenant\}/saml2}',
                'slo_url' => '${PA_SAML_AZURE_SLO_URL:-https://login.microsoftonline.com/\{tenant\}/saml2}',
                'x509cert' => '${PA_SAML_AZURE_X509_CERT:-}',
                'user_mapping' => [
                    'username' => '${PA_SAML_AZURE_USERNAME_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress}',
                    'email' => '${PA_SAML_AZURE_EMAIL_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress}',
                    'first_name' => '${PA_SAML_AZURE_FIRST_NAME_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname}',
                    'last_name' => '${PA_SAML_AZURE_LAST_NAME_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname}',
                    'display_name' => '${PA_SAML_AZURE_DISPLAY_NAME_ATTR:-http://schemas.microsoft.com/identity/claims/displayname}',
                    'groups' => '${PA_SAML_AZURE_GROUPS_ATTR:-http://schemas.microsoft.com/ws/2008/06/identity/claims/groups}',
                ],
            ],
EOF
    fi

    if [ "${saml_okta_enabled}" = "true" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'okta' => [
                'enabled' => true,
                'name' => '${PA_SAML_OKTA_NAME:-Okta}',
                'display_name' => '${PA_SAML_OKTA_DISPLAY_NAME:-Sign in with Okta (SAML)}',
                'entity_id' => '${PA_SAML_OKTA_ENTITY_ID:-}',
                'sso_url' => '${PA_SAML_OKTA_SSO_URL:-}',
                'slo_url' => '${PA_SAML_OKTA_SLO_URL:-}',
                'x509cert' => '${PA_SAML_OKTA_X509_CERT:-}',
                'user_mapping' => [
                    'username' => '${PA_SAML_OKTA_USERNAME_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name}',
                    'email' => '${PA_SAML_OKTA_EMAIL_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress}',
                    'first_name' => '${PA_SAML_OKTA_FIRST_NAME_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname}',
                    'last_name' => '${PA_SAML_OKTA_LAST_NAME_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname}',
                    'display_name' => '${PA_SAML_OKTA_DISPLAY_NAME_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/displayname}',
                    'groups' => '${PA_SAML_OKTA_GROUPS_ATTR:-groups}',
                ],
            ],
EOF
    fi

    if [ "${saml_auth0_enabled}" = "true" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'auth0' => [
                'enabled' => true,
                'name' => '${PA_SAML_AUTH0_NAME:-Auth0}',
                'display_name' => '${PA_SAML_AUTH0_DISPLAY_NAME:-Sign in with Auth0 (SAML)}',
                'entity_id' => '${PA_SAML_AUTH0_ENTITY_ID:-}',
                'sso_url' => '${PA_SAML_AUTH0_SSO_URL:-}',
                'slo_url' => '${PA_SAML_AUTH0_SLO_URL:-}',
                'x509cert' => '${PA_SAML_AUTH0_X509_CERT:-}',
                'user_mapping' => [
                    'username' => '${PA_SAML_AUTH0_USERNAME_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/nameidentifier}',
                    'email' => '${PA_SAML_AUTH0_EMAIL_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress}',
                    'first_name' => '${PA_SAML_AUTH0_FIRST_NAME_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname}',
                    'last_name' => '${PA_SAML_AUTH0_LAST_NAME_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname}',
                    'display_name' => '${PA_SAML_AUTH0_DISPLAY_NAME_ATTR:-http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name}',
                    'groups' => '${PA_SAML_AUTH0_GROUPS_ATTR:-groups}',
                ],
            ],
EOF
    fi

    if [ "${saml_keycloak_enabled}" = "true" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'keycloak' => [
                'enabled' => true,
                'name' => '${PA_SAML_KEYCLOAK_NAME:-Keycloak}',
                'display_name' => '${PA_SAML_KEYCLOAK_DISPLAY_NAME:-Sign in with Keycloak (SAML)}',
                'entity_id' => '${PA_SAML_KEYCLOAK_ENTITY_ID:-}',
                'sso_url' => '${PA_SAML_KEYCLOAK_SSO_URL:-}',
                'slo_url' => '${PA_SAML_KEYCLOAK_SLO_URL:-}',
                'x509cert' => '${PA_SAML_KEYCLOAK_X509_CERT:-}',
                'user_mapping' => [
                    'username' => '${PA_SAML_KEYCLOAK_USERNAME_ATTR:-username}',
                    'email' => '${PA_SAML_KEYCLOAK_EMAIL_ATTR:-email}',
                    'first_name' => '${PA_SAML_KEYCLOAK_FIRST_NAME_ATTR:-given_name}',
                    'last_name' => '${PA_SAML_KEYCLOAK_LAST_NAME_ATTR:-family_name}',
                    'display_name' => '${PA_SAML_KEYCLOAK_DISPLAY_NAME_ATTR:-name}',
                    'groups' => '${PA_SAML_KEYCLOAK_GROUPS_ATTR:-groups}',
                ],
            ],
EOF
    fi

    if [ "${saml_generic_enabled}" = "true" ]; then
        cat >> "${CONFIG_FILE}" << EOF
            'generic' => [
                'enabled' => true,
                'name' => '${PA_SAML_GENERIC_NAME:-Generic SAML IdP}',
                'display_name' => '${PA_SAML_GENERIC_DISPLAY_NAME:-Sign in with SAML}',
                'entity_id' => '${PA_SAML_GENERIC_ENTITY_ID:-}',
                'sso_url' => '${PA_SAML_GENERIC_SSO_URL:-}',
                'slo_url' => '${PA_SAML_GENERIC_SLO_URL:-}',
                'x509cert' => '${PA_SAML_GENERIC_X509_CERT:-}',
                'user_mapping' => [
                    'username' => '${PA_SAML_GENERIC_USERNAME_ATTR:-uid}',
                    'email' => '${PA_SAML_GENERIC_EMAIL_ATTR:-email}',
                    'first_name' => '${PA_SAML_GENERIC_FIRST_NAME_ATTR:-firstName}',
                    'last_name' => '${PA_SAML_GENERIC_LAST_NAME_ATTR:-lastName}',
                    'display_name' => '${PA_SAML_GENERIC_DISPLAY_NAME_ATTR:-displayName}',
                    'groups' => '${PA_SAML_GENERIC_GROUPS_ATTR:-groups}',
                ],
            ],
EOF
    fi

    cat >> "${CONFIG_FILE}" << EOF
        ],
    ],
    'modules' => [
        'csv_export' => [
            'enabled' => ${mod_csv_export_enabled},
        ],
        'zone_import_export' => [
            'enabled' => ${mod_zone_import_export_enabled},
            'auto_ttl_value' => ${PA_MODULE_ZONE_IMPORT_EXPORT_AUTO_TTL:-300},
            'max_file_size' => ${PA_MODULE_ZONE_IMPORT_EXPORT_MAX_FILE_SIZE:-1048576},
        ],
        'whois' => [
            'enabled' => ${mod_whois_enabled},
            'default_server' => '${PA_MODULE_WHOIS_DEFAULT_SERVER:-}',
            'custom_servers' => ${whois_custom_servers},
            'socket_timeout' => ${PA_MODULE_WHOIS_SOCKET_TIMEOUT:-10},
            'restrict_to_admin' => ${mod_whois_restrict_to_admin},
        ],
        'rdap' => [
            'enabled' => ${mod_rdap_enabled},
            'default_server' => '${PA_MODULE_RDAP_DEFAULT_SERVER:-}',
            'custom_servers' => ${rdap_custom_servers},
            'request_timeout' => ${PA_MODULE_RDAP_REQUEST_TIMEOUT:-10},
            'restrict_to_admin' => ${mod_rdap_restrict_to_admin},
        ],
        'email_previews' => [
            'enabled' => ${mod_email_previews_enabled},
            'restrict_to_admin' => ${mod_email_previews_restrict_to_admin},
        ],
        'dns_wizards' => [
            'enabled' => ${mod_dns_wizards_enabled},
            'available_types' => ${dns_wizards_types},
        ],
    ],
];
EOF

    if [ "$IS_ROOT" = true ]; then
        chmod 644 "${CONFIG_FILE}"
        chown www-data:www-data "${CONFIG_FILE}"
    fi

    log "Configuration file generated successfully"
}

# Print configuration summary (with redacted secrets)
print_config_summary() {
    log "=== Poweradmin Configuration Summary ==="

    if [ -n "${PA_CONFIG_PATH}" ]; then
        log "Configuration: Custom configuration file at ${CONFIG_FILE}"
        log "Configuration details are managed by the custom config file."
    else
        log "Database Type: ${DB_TYPE}"
        if [ "${DB_TYPE}" != "sqlite" ]; then
            log "Database Host: ${DB_HOST:-}"
            log "Database Name: ${DB_NAME:-}"
            log "Database User: ${DB_USER:-}"
        else
            log "Database File: ${DB_FILE:-/db/pdns.db}"
            log "PowerDNS Schema Version: ${PDNS_VERSION:-49}"
        fi
        log "DNS NS1: ${DNS_NS1:-ns1.example.com}"
        log "DNS NS2: ${DNS_NS2:-ns2.example.com}"
        log "DNS Hostmaster: ${DNS_HOSTMASTER:-hostmaster.example.com}"
        log "App Title: ${PA_APP_TITLE:-Poweradmin}"
        log "Default Language: ${PA_DEFAULT_LANGUAGE:-en_EN}"
        log "Mail Enabled: ${PA_MAIL_ENABLED:-true}"
        log "API Enabled: ${PA_API_ENABLED:-false}"
        log "DNS Backend: ${PA_DNS_BACKEND:-sql}"
        log "LDAP Enabled: ${PA_LDAP_ENABLED:-false}"
        log "Timezone: ${PA_TIMEZONE:-UTC}"
        log "Logging Type: ${PA_LOGGING_TYPE:-null}"
        log "Logging Level: ${PA_LOGGING_LEVEL:-info}"
        log "Database Logging: ${PA_LOGGING_DATABASE_ENABLED:-false}"
        log "Syslog Enabled: ${PA_LOGGING_SYSLOG_ENABLED:-false}"
        log "Account Lockout: ${PA_LOCKOUT_ENABLED:-false}"
        log "Password Reset: ${PA_PASSWORD_RESET_ENABLED:-false}"
        log "Username Recovery: ${PA_USERNAME_RECOVERY_ENABLED:-false}"
        log "Modules: csv_export=${PA_MODULE_CSV_EXPORT_ENABLED:-true}, zone_import_export=${PA_MODULE_ZONE_IMPORT_EXPORT_ENABLED:-false}, whois=${PA_MODULE_WHOIS_ENABLED:-false}, rdap=${PA_MODULE_RDAP_ENABLED:-false}, email_previews=${PA_MODULE_EMAIL_PREVIEWS_ENABLED:-false}, dns_wizards=${PA_MODULE_DNS_WIZARDS_ENABLED:-false}"
    fi

    log "Admin User Creation: ${PA_CREATE_ADMIN:-false}"
    if [ "${PA_CREATE_ADMIN:-false}" = "true" ]; then
        log "Admin Username: ${PA_ADMIN_USERNAME:-admin}"
        log "Admin Email: ${PA_ADMIN_EMAIL:-admin@example.com}"
    fi
    log "======================================="
}

# Set up proper file permissions for writable directories
setup_permissions() {
    log "Setting up file permissions..."

    if [ -d "${DB_DIR}" ]; then
        chown -R www-data:www-data "${DB_DIR}"
    fi

    config_dir=$(dirname "${CONFIG_FILE}")
    if [ "${config_dir}" != "/app/config" ] && [ -d "${config_dir}" ]; then
        chown -R www-data:www-data "${config_dir}"
    fi

    if [ -d "/var/caddy" ]; then
        chown -R www-data:www-data /var/caddy 2>/dev/null || true
    fi

    log "File permissions set successfully"
}

main() {
    log "Poweradmin Docker Container Starting..."

    log "Processing Docker secrets..."
    process_secret_files

    if [ "$(id -u)" = '0' ]; then
        IS_ROOT=true
        log "Running as root - will drop privileges after setup"
    else
        IS_ROOT=false
        log "Running as non-root (UID $(id -u)) - skipping privilege operations"

        if [ -z "${SERVER_PORT:-}" ]; then
            export SERVER_PORT=8080
            log "Auto-configured SERVER_PORT=8080 for non-root execution"
        fi
    fi

    echo "${SERVER_PORT:-80}" > /tmp/.server_port

    if [ "$IS_ROOT" = true ]; then
        install_trusted_ca
    elif [ -n "${TRUSTED_CA_FILE:-}" ]; then
        log "WARNING: TRUSTED_CA_FILE is set but container is not running as root - cannot install CA certificate"
    fi

    configure_trusted_proxies

    CONFIG_FILE="${PA_CONFIG_PATH:-/app/config/settings.php}"

    init_sqlite_db

    config_dir=$(dirname "${CONFIG_FILE}")
    defaults_file="${config_dir}/settings.defaults.php"
    if [ ! -f "${defaults_file}" ] && [ -f "/usr/local/share/settings.defaults.php" ]; then
        log "Restoring settings.defaults.php into config directory..."
        if [ "$IS_ROOT" = true ]; then
            cp /usr/local/share/settings.defaults.php "${defaults_file}"
            chown www-data:www-data "${defaults_file}"
        else
            cp /usr/local/share/settings.defaults.php "${defaults_file}" 2>/dev/null || \
                log "WARNING: Could not restore settings.defaults.php - directory may not be writable"
        fi
    fi

    if [ -f "${CONFIG_FILE}" ] && [ -s "${CONFIG_FILE}" ]; then
        log "Using configuration file: ${CONFIG_FILE}"
    else
        log "No custom config found. Generating settings.php from environment variables..."

        debug_log "Starting configuration validation..."
        validate_database_config
        debug_log "Database validation completed successfully"
        validate_dns_config
        debug_log "DNS validation completed successfully"
        validate_mail_config
        debug_log "Mail validation completed successfully"
        validate_api_config
        debug_log "API validation completed successfully"
        validate_ldap_config
        debug_log "LDAP validation completed successfully"
        validate_saml_config
        debug_log "SAML validation completed successfully"
        validate_oidc_config
        debug_log "OIDC validation completed successfully"
        log "Configuration validation completed successfully"

        generate_config
    fi

    init_mysql_db
    init_pgsql_db

    create_admin_user

    print_config_summary

    log "Configuration loaded successfully"
    log "Starting Poweradmin..."

    if [ "$IS_ROOT" = true ]; then
        setup_permissions
        if [ "$1" = "frankenphp" ]; then
            if ! setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp 2>/dev/null; then
                if [ -n "${SERVER_PORT:-}" ] && [ "${SERVER_PORT}" -lt 1024 ] 2>/dev/null; then
                    log "ERROR: Cannot bind port ${SERVER_PORT} - setcap failed (read-only rootfs or CAP_SETFCAP dropped). Use SERVER_PORT=8080 or grant CAP_SETFCAP."
                    exit 1
                elif [ -z "${SERVER_PORT:-}" ]; then
                    export SERVER_PORT=8080
                    echo "${SERVER_PORT}" > /tmp/.server_port
                    log "WARNING: Could not restore port binding capability - falling back to port 8080"
                fi
            fi
        fi
        exec su-exec www-data "$@"
    else
        exec "$@"
    fi
}

main "$@"