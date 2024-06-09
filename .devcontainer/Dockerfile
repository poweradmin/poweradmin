ARG VARIANT

FROM mcr.microsoft.com/devcontainers/php:1-${VARIANT}

RUN apt-get -y update \
  && apt-get install -y git libicu-dev \
  && docker-php-ext-install intl gettext pdo pdo_mysql
