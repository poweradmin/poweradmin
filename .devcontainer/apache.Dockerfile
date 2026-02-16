FROM php:8.2-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libldap2-dev \
    libicu-dev \
    gettext \
    locales \
    && rm -rf /var/lib/apt/lists/*

# Generate locales
RUN sed -i '/^#.* cs_CZ.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* de_DE.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* en_US.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* es_ES.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* fr_FR.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* it_IT.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* ja_JP.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* lt_LT.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* nb_NO.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* nl_NL.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* pl_PL.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* pt_PT.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* ru_RU.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* tr_TR.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* zh_CN.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && locale-gen

# Enable Apache modules
RUN a2enmod rewrite

# Install PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql intl ldap gettext

# Set working directory
WORKDIR /app

# Apache configuration
COPY ./conf/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# PHP configuration
COPY ./conf/xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
COPY ./conf/error_reporting.ini /usr/local/etc/php/conf.d/error_reporting.ini

CMD ["apache2-foreground"]