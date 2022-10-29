# This Dockerfile is intended only for testing.
#
# Usage:
#    docker build -t poweradmin .
#    docker run -d --name poweradmin -p 80:80 poweradmin
#
# Log in with the following username and password:
# admin / testadmin

FROM php:8.1.12-apache

ENV DEBIAN_FRONTEND noninteractive

RUN apt-get update && apt-get install -y \
    sqlite3 \
    libicu67 \
    libicu-dev \
    git

RUN docker-php-ext-configure gettext && \
    docker-php-ext-install -j$(nproc) gettext

RUN docker-php-ext-configure intl && \
    docker-php-ext-install -j$(nproc) intl

RUN git clone https://github.com/poweradmin/poweradmin.git /var/www/html

RUN sqlite3 /opt/pdns.db < /var/www/html/sql/pdns/4.7.x/schema.sqlite3.sql
RUN sqlite3 /opt/pdns.db < /var/www/html/sql/poweradmin-sqlite-db-structure.sql

RUN echo '<?php' >> /var/www/html/inc/config.inc.php
RUN echo '$db_type="sqlite";' >> /var/www/html/inc/config.inc.php
RUN echo '$db_file="/opt/pdns.db";' >> /var/www/html/inc/config.inc.php
RUN echo '$ignore_install_dir=true;' >> /var/www/html/inc/config.inc.php
RUN echo '$session_key="V@v!y(A6hZk@3NJrJ%C2PgYQmCmpspai6Vh_fo$w^^8QF@";' >> /var/www/html/inc/config.inc.php

EXPOSE 80

