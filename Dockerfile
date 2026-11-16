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

FROM php:8.2-cli-alpine@sha256:95ca190829adff6ca54122aa866c5e9d6165fa0e68f92f6ef777dba180ee8b69

# Update base packages to fix known security vulnerabilities and install dependencies
# hadolint ignore=DL3018
RUN apk upgrade --no-cache \
    && apk add --no-cache --virtual .build-deps \
    icu-data-full \
    gettext \
    gettext-dev \
    libintl \
    postgresql-dev \
    sqlite \
    && docker-php-ext-install -j"$(nproc)" \
    gettext \
    intl \
    mysqli \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    && rm -rf /var/cache/apk/*

WORKDIR /app

COPY . .

# Setup database and configuration
# hadolint ignore=SC2016
RUN mkdir -p /db /app/inc \
    && sqlite3 /db/pdns.db < /app/sql/pdns/47/schema.sqlite3.sql \
    && sqlite3 /db/pdns.db < /app/sql/poweradmin-sqlite-db-structure.sql \
    && php -r '$d=new PDO("sqlite:/db/pdns.db"); $d->prepare("INSERT INTO users (id, username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES (1, ?, ?, ?, ?, ?, 1, 1, 0)")->execute(["admin", password_hash("testadmin", PASSWORD_DEFAULT), "Administrator", "admin@example.net", "Administrator with full rights."]);' \
    && rm -rf /app/sql \
    && echo '<?php' > /app/inc/config.inc.php \
    && echo '$db_type="sqlite";' >> /app/inc/config.inc.php \
    && echo '$db_file="/db/pdns.db";' >> /app/inc/config.inc.php \
    && php -r 'echo bin2hex(random_bytes(32));' > /tmp/session_key.txt \
    && echo "\$session_key=\"$(cat /tmp/session_key.txt)\";" >> /app/inc/config.inc.php \
    && chown -R www-data:www-data /db /app \
    && chmod -R 755 /db /app

# www-data is uid/gid 82 in the alpine base; the numeric form is used because a
# user name may not resolve on the host (hadolint DL3066).
USER 82:82

EXPOSE 80

ENTRYPOINT ["php", "-S", "0.0.0.0:80", "-t", "/app"]
