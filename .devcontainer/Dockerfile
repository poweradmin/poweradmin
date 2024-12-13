ARG VARIANT

FROM php:${VARIANT}

RUN apt-get -y update

RUN apt-get install -y git sqlite3 libpq-dev libldap2-dev libicu-dev locales
RUN docker-php-ext-install intl gettext pdo pdo_mysql pdo_pgsql ldap
#RUN pecl install xdebug && docker-php-ext-enable xdebug

RUN echo "error_reporting = E_ALL" >> /usr/local/etc/php/conf.d/error_reporting.ini \
    && echo "xdebug.mode=develop,debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

RUN sed -i '/^#.* cs_CZ.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* de_DE.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* fr_FR.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* it_IT.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* ja_JP.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* lt_LT.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* nb_NO.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* nl_NL.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* pl_PL.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* ru_RU.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* tr_TR.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* zh_CN.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && locale-gen
