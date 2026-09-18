# This Dockerfile is intended only for TESTING.
#
# Usage:
#   docker build --no-cache -t poweradmin .
#   docker run -d --name poweradmin -p 80:80 poweradmin
#
# Required configuration:
#   - DB_TYPE environment variable (sqlite, mysql, pgsql)
# Default configuration:
#   - DNS servers: ns1.example.com, ns2.example.com
#   - Hostmaster: hostmaster@example.com
#
#   Alternatively, you can run the program with a current folder mounted:
#   docker run -d --name poweradmin -p 80:80 -v $(pwd):/app poweradmin
#   The image does not re-check PHP files once cached (opcache.validate_timestamps=0);
#   for live editing mount an ini that turns it back on:
#     -v $(pwd)/dev.ini:/usr/local/etc/php/conf.d/zz-dev.ini
#
# Docker Secrets Support:
#   Use environment variables with __FILE suffix to read from files:
#   docker run -d --name poweradmin -p 80:80 \
#     -e DB_PASS__FILE=/run/secrets/db_password \
#     -v /path/to/secret:/run/secrets/db_password:ro \
#     poweradmin
#
# Admin User Creation:
#   By default, no admin user is created for security reasons.
#   To create an admin user, set PA_CREATE_ADMIN=1:
#   docker run -d --name poweradmin -p 80:80 \
#     -e PA_CREATE_ADMIN=1 \
#     poweradmin
#
#   When PA_CREATE_ADMIN=1 is set:
#   - A secure random password will be generated automatically
#   - The credentials will be displayed prominently in the container logs
#   - Default username: admin (override with PA_ADMIN_USERNAME)
#
#   To specify your own password:
#   docker run -d --name poweradmin -p 80:80 \
#     -e PA_CREATE_ADMIN=1 \
#     -e PA_ADMIN_PASSWORD=your-secure-password \
#     poweradmin

FROM dunglas/frankenphp:1.12.7-php8.4-alpine@sha256:71c41d9427ecdd19efb3f427f03afebdef96e37374bb22ad9676916db222b379

LABEL org.opencontainers.image.source="https://github.com/poweradmin/poweradmin"
LABEL org.opencontainers.image.licenses="GPL-3.0-or-later"

# Update base packages and install required packages and PHP extensions
RUN apk upgrade --no-cache \
    && apk add --no-cache --virtual .build-deps \
    gettext-dev \
    postgresql-dev \
    icu-dev \
    openldap-dev \
    libxml2-dev \
    && apk add --no-cache \
    ca-certificates \
    icu-data-full \
    icu-libs \
    gettext \
    libintl \
    sqlite \
    openssl \
    bash \
    mariadb-client \
    mariadb-connector-c \
    postgresql-client \
    libpq \
    libldap \
    libxml2 \
    su-exec \
    && install-php-extensions \
    gettext \
    intl \
    ldap \
    mysqli \
    pdo_mysql \
    pdo_pgsql \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

# Run on the production ini; mount another file into conf.d/ to change the overrides
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && cat > "$PHP_INI_DIR/conf.d/zz-poweradmin.ini" <<'INIEOF'
expose_php = Off
memory_limit = 256M
upload_max_filesize = 16M
post_max_size = 20M
opcache.validate_timestamps = 0
opcache.max_accelerated_files = 20000
opcache.memory_consumption = 192
opcache.interned_strings_buffer = 16
INIEOF

WORKDIR /app

# Copy application files with their final owner; a later chown -R would duplicate the layer
COPY --chown=www-data:0 . .

# Entrypoint outside the app tree, data directory, pristine copy of the defaults
COPY --chmod=755 docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN mkdir -p /db /app/config \
    && cp /app/config/settings.defaults.php /usr/local/share/settings.defaults.php

# Create Caddyfile for FrankenPHP
# Uses RUN with single-quoted heredoc to preserve Caddy's {$ENV} syntax literally
RUN cat > /etc/caddy/Caddyfile <<'CADDYEOF'
{
    frankenphp
    admin off
    order php_server before file_server
}

:{$SERVER_PORT:80} {
    root * /app
    encode gzip

    # Security: Deny access to sensitive directories
    @denied path /config* /lib* /tests* /vendor*
    @bootstrap path /vendor/twbs/bootstrap/dist/* /vendor/twbs/bootstrap-icons/font/*

    # Allow Bootstrap files (override general vendor blocking)
    handle @bootstrap {
        file_server
    }

    # Block sensitive directories (excluding bootstrap)
    handle @denied {
        respond "Forbidden" 403
    }

    # Security: Deny access to hidden files and sensitive file types
    @hidden path /.* */.* *.sh *.sql *.sqlite *.sqlite3 *.db *.md *.log *.yaml *.yml *.po *.pot *.dat *.example
    handle @hidden {
        respond "Forbidden" 403
    }

    # Static assets with caching
    @static path *.js *.css *.png *.jpg *.jpeg *.gif *.ico *.svg *.woff *.woff2 *.ttf *.eot
    handle @static {
        header Cache-Control "public, max-age=31536000"
        file_server
    }

    # Handle OPTIONS preflight requests for CORS
    @options method OPTIONS
    handle @options {
        header Access-Control-Allow-Origin "*"
        header Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
        header Access-Control-Allow-Headers "Content-Type, Authorization, X-API-Key"
        header Access-Control-Max-Age "3600"
        respond "" 204
    }

    # API endpoints with CORS
    @api path /api*
    handle @api {
        header Access-Control-Allow-Origin "*"
        header Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
        header Access-Control-Allow-Headers "Content-Type, Authorization, X-API-Key"
        header Access-Control-Max-Age "3600"

        # Forward all API requests to index.php with proper query handling
        rewrite * /index.php{uri}
        php_server {
            env HTTP_AUTHORIZATION {http.request.header.Authorization}
        }
    }

    # Clean URL routing - let Symfony Router handle all routing
    try_files {path} {path}/ /index.php{uri}

    # PHP handling with FrankenPHP
    php_server {
        env HTTP_AUTHORIZATION {http.request.header.Authorization}
    }
}
CADDYEOF

# Move Caddy data/config to /var/caddy to free up /config for user settings
ENV XDG_CONFIG_HOME=/var/caddy
ENV XDG_DATA_HOME=/var/caddy

# Set proper ownership for writable volumes
# Group set to root (GID 0) + group-writable supports both:
#   - K8s with fsGroup (overrides group at mount time)
#   - OpenShift arbitrary UIDs (which always run as GID 0)
# Root-mode entrypoint re-asserts www-data ownership via setup_permissions()
RUN chown www-data:0 /db \
    && chmod -R g+w /app/config /db \
    && mkdir -p /var/caddy/caddy \
    && chown -R www-data:0 /var/caddy \
    && chmod -R g+w /var/caddy \
    && setcap -r /usr/local/bin/frankenphp

# Run as root initially, entrypoint will drop to www-data
# For rootless/restricted K8s: set runAsUser: 82, fsGroup: 82, container auto-switches to port 8080

EXPOSE 80 8080

# Healthcheck reads the port from file written by entrypoint (healthcheck runs
# as a separate process and does not inherit entrypoint's exported env vars)
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD ["/bin/sh", "-c", "curl -sf http://localhost:$(cat /tmp/.server_port 2>/dev/null || echo 80)/ -o /dev/null || exit 1"]

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
