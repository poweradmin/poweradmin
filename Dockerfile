# This Dockerfile is intended only for TESTING.
#
# Usage:
#   docker build --no-cache -t poweradmin .
#   docker run -d --name poweradmin -p 80:80 poweradmin
#
# Default configuration:
#   - Database: SQLite (stored in /db/pdns.db)
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
# Open your browser and navigate to "localhost", then log in using the default credentials:
# Username: admin
# Password: testadmin
# (Override with PA_ADMIN_USERNAME and PA_ADMIN_PASSWORD environment variables)

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

    # Handle root path - serve index.php directly
    @root path /
    rewrite @root /index.php

    # API Documentation specific rewrite rules
    @api_docs_json path /api/docs/json
    rewrite @api_docs_json /index.php?page=api/docs/json

    @api_docs path /api/docs
    rewrite @api_docs /index.php?page=api/docs

    # RESTful API routes
    # User verification endpoint
    @api_user_verify path /api/v1/user/verify
    rewrite @api_user_verify /index.php?page=api/v1/user_verify

    # Users individual user routes (must come before collection route)
    @api_users_individual path_regexp users ^/api/v1/users/([0-9]+)/?$
    rewrite @api_users_individual /index.php?page=api/v1/users/{re.users.1}

    # Users collection route
    @api_users_collection path_regexp ^/api/v1/users/?$
    rewrite @api_users_collection /index.php?page=api/v1/users

    # Zones individual zone routes (must come before collection route)
    @api_zones_individual path_regexp zones ^/api/v1/zones/([0-9]+)/?$
    rewrite @api_zones_individual /index.php?page=api/v1/zones/{re.zones.1}

    # Zones collection route
    @api_zones_collection path_regexp ^/api/v1/zones/?$
    rewrite @api_zones_collection /index.php?page=api/v1/zones

    # Zone records individual record routes
    @api_zone_records_individual path_regexp zone_records ^/api/v1/zones/([0-9]+)/records/([0-9]+)/?$
    rewrite @api_zone_records_individual /index.php?page=api/v1/zones_records/{re.zone_records.1}/{re.zone_records.2}

    # Zone records collection routes
    @api_zone_records_collection path_regexp zone_records_col ^/api/v1/zones/([0-9]+)/records/?$
    rewrite @api_zone_records_collection /index.php?page=api/v1/zones_records/{re.zone_records_col.1}

    # Rewrite API base paths (fallback)
    @api_fallback path_regexp api_fall ^/api(/(.*))?$
    rewrite @api_fallback /index.php?page=api/{re.api_fall.2}

    # Enable CORS for API endpoints
    @api path /api/*
    header @api {
        Access-Control-Allow-Origin "*"
        Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
        Access-Control-Allow-Headers "Content-Type, Authorization, X-API-Key"
        Access-Control-Max-Age "3600"
    }

    # Handle OPTIONS pre-flight requests
    @options method OPTIONS
    respond @options 204

    # Static assets first - before any restrictions
    @static path *.js *.css *.png *.jpg *.jpeg *.gif *.ico *.svg *.woff *.woff2 *.ttf *.eot
    file_server @static

    # Allow access to Bootstrap CSS/JS and Bootstrap Icons
    @bootstrap path /vendor/twbs/bootstrap/* /vendor/twbs/bootstrap-icons/*
    file_server @bootstrap

    # Allow access to assets directory
    @assets path /assets/*
    file_server @assets

    # Deny access to sensitive directories (except Bootstrap and assets)
    @sensitive {
        path /config/* /lib/* /tests/* /tools/* /vendor/* /.*
        not path /vendor/twbs/bootstrap/*
        not path /vendor/twbs/bootstrap-icons/*
        not path /assets/*
    }
    respond @sensitive 403

    # Deny access to sensitive file types
    @sensitive_files path *.sql *.md *.log
    respond @sensitive_files 403

    # Handle general page routing for non-files
    @not_file {
        not file
        not path /
        not path /assets/*
        not path /vendor/twbs/bootstrap/*
        not path /vendor/twbs/bootstrap-icons/*
        not path *.js
        not path *.css
        not path *.png
        not path *.jpg
        not path *.jpeg
        not path *.gif
        not path *.ico
        not path *.svg
        not path *.woff
        not path *.woff2
        not path *.ttf
        not path *.eot
    }
    rewrite @not_file /index.php?page={path}

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
