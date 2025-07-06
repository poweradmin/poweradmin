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

FROM php:8.4-cli-alpine

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
    && docker-php-ext-install -j$(nproc) \
    gettext \
    intl \
    mysqli \
    pdo \
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

# Create settings.php with SQLite configuration
RUN echo '<?php' > /app/config/settings.php
RUN echo 'return [' >> /app/config/settings.php
RUN echo '    "database" => [' >> /app/config/settings.php
RUN echo '        "type" => "sqlite",' >> /app/config/settings.php
RUN echo '        "file" => "/db/pdns.db",' >> /app/config/settings.php
RUN echo '    ],' >> /app/config/settings.php

# Generate random session key and complete config
RUN php -r 'echo bin2hex(random_bytes(32));' > /tmp/session_key.txt \
    && echo '    "security" => [' >> /app/config/settings.php \
    && echo '        "session_key" => "'"$(cat /tmp/session_key.txt)"'",' >> /app/config/settings.php \
    && echo '    ],' >> /app/config/settings.php \
    && echo '];' >> /app/config/settings.php \
    && rm /tmp/session_key.txt \
    && chown -R www-data:www-data /db /app \
    && chmod -R 755 /db /app

USER www-data

EXPOSE 80

ENTRYPOINT ["php", "-S", "0.0.0.0:80", "-t", "/app"]
