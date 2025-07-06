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

# Create settings.php with dynamic database configuration
RUN echo '<?php' > /app/config/settings.php
RUN echo 'return [' >> /app/config/settings.php
RUN echo '    "database" => [' >> /app/config/settings.php
RUN echo '        "type" => $_ENV["DB_TYPE"] ?? "sqlite",' >> /app/config/settings.php
RUN echo '        "host" => ($_ENV["DB_TYPE"] !== "sqlite" && !empty($_ENV["DB_HOST"])) ? $_ENV["DB_HOST"] : "",' >> /app/config/settings.php
RUN echo '        "user" => ($_ENV["DB_TYPE"] !== "sqlite" && !empty($_ENV["DB_USER"])) ? $_ENV["DB_USER"] : "",' >> /app/config/settings.php
RUN echo '        "password" => ($_ENV["DB_TYPE"] !== "sqlite" && !empty($_ENV["DB_PASS"])) ? $_ENV["DB_PASS"] : "",' >> /app/config/settings.php
RUN echo '        "name" => ($_ENV["DB_TYPE"] !== "sqlite" && !empty($_ENV["DB_NAME"])) ? $_ENV["DB_NAME"] : "",' >> /app/config/settings.php
RUN echo '        "file" => ($_ENV["DB_TYPE"] === "sqlite" && !empty($_ENV["DB_FILE"])) ? $_ENV["DB_FILE"] : "",' >> /app/config/settings.php
RUN echo '    ],' >> /app/config/settings.php
RUN echo '    "dns" => [' >> /app/config/settings.php
RUN echo '        "hostmaster" => $_ENV["DNS_HOSTMASTER"] ?? "hostmaster.example.com",' >> /app/config/settings.php
RUN echo '        "ns1" => $_ENV["DNS_NS1"] ?? "ns1.example.com",' >> /app/config/settings.php
RUN echo '        "ns2" => $_ENV["DNS_NS2"] ?? "ns2.example.com",' >> /app/config/settings.php
RUN echo '        "ns3" => $_ENV["DNS_NS3"] ?? "",' >> /app/config/settings.php
RUN echo '        "ns4" => $_ENV["DNS_NS4"] ?? "",' >> /app/config/settings.php
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
