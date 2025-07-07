# This Dockerfile is intended only for TESTING.
#
# Usage:
#   docker build --no-cache -t poweradmin .
#   docker run -d --name poweradmin -p 80:80 poweradmin
#
#   Alternatively, you can run the program with a current folder mounted:
#   docker run -d --name poweradmin -p 80:80 -v $(pwd):/app poweradmin
#
# Open your browser and navigate to "localhost", then log in using the provided username and password
# admin / testadmin

FROM dunglas/frankenphp:latest-alpine

RUN apk add --no-cache --virtual .build-deps \
    gettext-dev \
    postgresql-dev \
    icu-dev \
    && apk add --no-cache \
    icu-data-full \
    icu-libs \
    gettext \
    libintl \
    sqlite \
    && install-php-extensions \
    gettext \
    intl \
    mysqli \
    pdo_mysql \
    pdo_pgsql \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

WORKDIR /app

COPY . .

RUN mkdir -p /db /app/config

RUN sqlite3 /db/pdns.db < /app/sql/pdns/47/schema.sqlite3.sql
RUN sqlite3 /db/pdns.db < /app/sql/poweradmin-sqlite-db-structure.sql
RUN rm -rf /app/sql

# Create config directory if it doesn't exist
RUN mkdir -p /app/config

# Set default database environment variables
ENV DB_TYPE=sqlite
ENV DB_HOST=""
ENV DB_USER=""
ENV DB_PASS=""
ENV DB_NAME=""
ENV DB_FILE=/db/pdns.db

# Set default DNS nameserver environment variables
ENV DNS_NS1=ns1.example.com
ENV DNS_NS2=ns2.example.com
ENV DNS_NS3=""
ENV DNS_NS4=""
ENV DNS_HOSTMASTER=hostmaster.example.com

# Set default reCAPTCHA environment variables
ENV PA_RECAPTCHA_ENABLED=false
ENV PA_RECAPTCHA_SITE_KEY=""
ENV PA_RECAPTCHA_SECRET_KEY=""

# Set default mail configuration environment variables
ENV PA_MAIL_ENABLED=true
ENV PA_MAIL_TRANSPORT=php
ENV PA_SMTP_HOST=""
ENV PA_SMTP_PORT=587
ENV PA_SMTP_USER=""
ENV PA_SMTP_PASSWORD=""
ENV PA_SMTP_ENCRYPTION=tls
ENV PA_MAIL_FROM=""
ENV PA_MAIL_FROM_NAME=""

# Set default interface environment variables
ENV PA_APP_TITLE=Poweradmin
ENV PA_DEFAULT_LANGUAGE=en_EN

# Set default API configuration environment variables
ENV PA_API_ENABLED=false
ENV PA_API_BASIC_AUTH_ENABLED=false
ENV PA_API_DOCS_ENABLED=false

# Set default PowerDNS API environment variables
ENV PA_PDNS_API_URL=""
ENV PA_PDNS_API_KEY=""
ENV PA_PDNS_SERVER_NAME=localhost

# Set default LDAP environment variables
ENV PA_LDAP_ENABLED=false
ENV PA_LDAP_URI=""
ENV PA_LDAP_BASE_DN=""
ENV PA_LDAP_BIND_DN=""
ENV PA_LDAP_BIND_PASSWORD=""

# Set default miscellaneous environment variables
ENV PA_TIMEZONE=UTC

# Set config override behavior
ENV PA_CONFIG_PATH=""

# Create config generator script and generate settings.php
RUN cat > /tmp/generate-config.php << 'EOF'
<?php
$sessionKey = bin2hex(random_bytes(32));

$config = [
    'database' => [
        'type' => $_ENV['DB_TYPE'] ?? 'sqlite',
        'host' => ($_ENV['DB_TYPE'] !== 'sqlite' && !empty($_ENV['DB_HOST'])) ? $_ENV['DB_HOST'] : '',
        'user' => ($_ENV['DB_TYPE'] !== 'sqlite' && !empty($_ENV['DB_USER'])) ? $_ENV['DB_USER'] : '',
        'password' => ($_ENV['DB_TYPE'] !== 'sqlite' && !empty($_ENV['DB_PASS'])) ? $_ENV['DB_PASS'] : '',
        'name' => ($_ENV['DB_TYPE'] !== 'sqlite' && !empty($_ENV['DB_NAME'])) ? $_ENV['DB_NAME'] : '',
        'file' => ($_ENV['DB_TYPE'] === 'sqlite' && !empty($_ENV['DB_FILE'])) ? $_ENV['DB_FILE'] : '',
    ],
    'dns' => [
        'hostmaster' => $_ENV['DNS_HOSTMASTER'] ?? 'hostmaster.example.com',
        'ns1' => $_ENV['DNS_NS1'] ?? 'ns1.example.com',
        'ns2' => $_ENV['DNS_NS2'] ?? 'ns2.example.com',
        'ns3' => $_ENV['DNS_NS3'] ?? '',
        'ns4' => $_ENV['DNS_NS4'] ?? '',
    ],
    'security' => [
        'session_key' => $sessionKey,
        'recaptcha' => [
            'enabled' => filter_var($_ENV['PA_RECAPTCHA_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'site_key' => $_ENV['PA_RECAPTCHA_SITE_KEY'] ?? '',
            'secret_key' => $_ENV['PA_RECAPTCHA_SECRET_KEY'] ?? '',
        ],
    ],
    'mail' => [
        'enabled' => filter_var($_ENV['PA_MAIL_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
        'transport' => $_ENV['PA_MAIL_TRANSPORT'] ?? 'php',
        'host' => $_ENV['PA_SMTP_HOST'] ?? '',
        'port' => (int)($_ENV['PA_SMTP_PORT'] ?? '587'),
        'username' => $_ENV['PA_SMTP_USER'] ?? '',
        'password' => $_ENV['PA_SMTP_PASSWORD'] ?? '',
        'encryption' => $_ENV['PA_SMTP_ENCRYPTION'] ?? 'tls',
        'from' => $_ENV['PA_MAIL_FROM'] ?? '',
        'from_name' => $_ENV['PA_MAIL_FROM_NAME'] ?? '',
    ],
    'interface' => [
        'title' => $_ENV['PA_APP_TITLE'] ?? 'Poweradmin',
        'language' => $_ENV['PA_DEFAULT_LANGUAGE'] ?? 'en_EN',
    ],
    'api' => [
        'enabled' => filter_var($_ENV['PA_API_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        'basic_auth_enabled' => filter_var($_ENV['PA_API_BASIC_AUTH_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        'docs_enabled' => filter_var($_ENV['PA_API_DOCS_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
    ],
    'pdns_api' => [
        'url' => $_ENV['PA_PDNS_API_URL'] ?? '',
        'key' => $_ENV['PA_PDNS_API_KEY'] ?? '',
        'server_name' => $_ENV['PA_PDNS_SERVER_NAME'] ?? 'localhost',
    ],
    'ldap' => [
        'enabled' => filter_var($_ENV['PA_LDAP_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        'uri' => $_ENV['PA_LDAP_URI'] ?? '',
        'base_dn' => $_ENV['PA_LDAP_BASE_DN'] ?? '',
        'bind_dn' => $_ENV['PA_LDAP_BIND_DN'] ?? '',
        'bind_password' => $_ENV['PA_LDAP_BIND_PASSWORD'] ?? '',
    ],
    'misc' => [
        'timezone' => $_ENV['PA_TIMEZONE'] ?? 'UTC',
    ],
];

file_put_contents('/app/config/settings.php', "<?php\n\nreturn " . var_export($config, true) . ";\n");
chmod('/app/config/settings.php', 0644);
EOF

# Create entrypoint script for hybrid config management
RUN cat > /usr/local/bin/docker-entrypoint.sh << 'EOF'
#!/bin/sh
set -e

echo "Poweradmin Docker Container Starting..."

# Configuration Priority:
# 1. PA_CONFIG_PATH (custom config file) - highest priority
# 2. Individual environment variables (fallback)

if [ -n "${PA_CONFIG_PATH}" ] && [ -f "${PA_CONFIG_PATH}" ]; then
    echo "Using custom configuration from: ${PA_CONFIG_PATH}"
    cp "${PA_CONFIG_PATH}" /app/config/settings.php
    chmod 644 /app/config/settings.php
    chown www-data:www-data /app/config/settings.php
elif [ -f "/app/config/settings.php" ]; then
    echo "Using existing settings.php (generated from environment variables)"
else
    echo "No custom config found. Generating settings.php from environment variables..."
    php /usr/local/bin/generate-config.php
fi

echo "Configuration loaded successfully"

# Execute the command
exec "$@"
EOF

# Move config generator to permanent location and set permissions
RUN mv /tmp/generate-config.php /usr/local/bin/generate-config.php \
    && chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/generate-config.php

# Generate initial config from current environment (build-time defaults)
RUN php /usr/local/bin/generate-config.php \
    && chown -R www-data:www-data /db /app \
    && chmod -R 755 /db /app

# Create Caddyfile for FrankenPHP
RUN cat > /etc/caddy/Caddyfile << 'EOF'
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

USER www-data

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
