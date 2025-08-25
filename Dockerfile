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

FROM dunglas/frankenphp:latest-alpine

# Install required packages and PHP extensions
RUN apk add --no-cache --virtual .build-deps \
    gettext-dev \
    postgresql15-dev \
    icu-dev \
    && apk add --no-cache \
    icu-data-full \
    icu-libs \
    gettext \
    libintl \
    sqlite \
    openssl \
    bash \
    mariadb-client \
    mariadb-connector-c \
    postgresql15-client \
    postgresql15-dev \
    libpq \
    && install-php-extensions \
    gettext \
    intl \
    mysqli \
    pdo_mysql \
    pdo_pgsql \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

WORKDIR /app

# Copy application files
COPY . .

# Copy and set permissions for entrypoint script, create directories
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && mkdir -p /db /app/config

# Create Caddyfile for FrankenPHP
COPY <<EOF /etc/caddy/Caddyfile
{
    frankenphp
    admin off
    order php_server before file_server
}

:80 {
    root * /app
    encode gzip

    # Security: Deny access to sensitive directories
    @denied path /config* /lib* /tests* /tools* /vendor*
    @bootstrap path /vendor/twbs/bootstrap* /vendor/twbs/bootstrap-icons*

    # Allow Bootstrap files (override general vendor blocking)
    handle @bootstrap {
        file_server
    }

    # Block sensitive directories
    handle @denied {
        respond "Forbidden" 403
    }

    # Security: Deny access to hidden files and sensitive file types
    @hidden path .* *.sql *.md *.log *.yaml *.yml
    handle @hidden {
        respond "Forbidden" 403
    }

    # Static assets with caching
    @static path *.js *.css *.png *.jpg *.jpeg *.gif *.ico *.svg *.woff *.woff2 *.ttf *.eot
    handle @static {
        header Cache-Control "public, max-age=31536000"
        file_server
    }

    # API endpoints with CORS
    @api path /api*
    handle @api {
        header Access-Control-Allow-Origin "*"
        header Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
        header Access-Control-Allow-Headers "Content-Type, Authorization, X-API-Key"
        header Access-Control-Max-Age "3600"

        # Handle preflight OPTIONS requests
        handle_path /api* {
            method OPTIONS
            respond "" 204
        }

        # Let Symfony Router handle all API routing
        rewrite * /index.php{uri}
        php_server
    }

    # Clean URL routing - let Symfony Router handle all routing
    try_files {path} {path}/ /index.php{uri}

    # PHP handling with FrankenPHP
    php_server
}
EOF

# Set proper ownership for www-data user
RUN chown -R www-data:www-data /app /db \
    && mkdir -p /data/caddy/locks /config/caddy \
    && chown -R www-data:www-data /data/caddy /config/caddy

USER www-data

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
