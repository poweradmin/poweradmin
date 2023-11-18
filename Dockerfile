# This Dockerfile is intended only for TESTING.
#
# Usage:
#   docker build --no-cache -t poweradmin .
#   docker run -d --name poweradmin -p 80:80 poweradmin
#
#   Alternatively, you can run the program with a current folder mounted:
#   docker run -d --name poweradmin -p 80:80 -v $(pwd):/var/www/html poweradmin
#
# Open your browser and navigate to "localhost", then log in using the provided username and password
# admin / testadmin

FROM php:8.1.25-apache

ENV DEBIAN_FRONTEND noninteractive

RUN apt-get update && apt-get install -y \
    sqlite3 \
    libicu72 \
    libicu-dev \
    locales-all \
    libpq-dev \
    git

RUN docker-php-ext-configure gettext && \
    docker-php-ext-install -j$(nproc) gettext

RUN docker-php-ext-configure intl && \
    docker-php-ext-install -j$(nproc) intl

RUN docker-php-ext-configure mysqli && \
    docker-php-ext-install -j$(nproc) mysqli

RUN docker-php-ext-configure pdo && \
    docker-php-ext-install -j$(nproc) pdo

RUN docker-php-ext-configure pdo_mysql && \
    docker-php-ext-install -j$(nproc) pdo_mysql

RUN docker-php-ext-configure pdo_pgsql && \
    docker-php-ext-install -j$(nproc) pdo_pgsql

RUN git clone https://github.com/poweradmin/poweradmin.git /var/www/html

RUN sqlite3 /opt/pdns.db < /var/www/html/sql/pdns/4.7.x/schema.sqlite3.sql
RUN sqlite3 /opt/pdns.db < /var/www/html/sql/poweradmin-sqlite-db-structure.sql

RUN chown www-data:www-data /opt/pdns.db
RUN chown -R www-data:www-data /opt
RUN chmod -R 0775 /opt

RUN echo '<?php' >> /var/www/html/inc/config.inc.php
RUN echo '$db_type="sqlite";' >> /var/www/html/inc/config.inc.php
RUN echo '$db_file="/opt/pdns.db";' >> /var/www/html/inc/config.inc.php
RUN echo '$ignore_install_dir=true;' >> /var/www/html/inc/config.inc.php
RUN echo '$session_key="V@v!y(A6hZk@3NJrJ%C2PgYQmCmpspai6Vh_fo$w^^8QF@";' >> /var/www/html/inc/config.inc.php

EXPOSE 80
