FROM php:8.4-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libldap2-dev \
    libicu-dev \
    gettext \
    && rm -rf /var/lib/apt/lists/*

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