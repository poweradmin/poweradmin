ARG VARIANT

FROM mcr.microsoft.com/devcontainers/php:1-${VARIANT}

RUN apt-get -y update \
  && apt-get install -y git libicu-dev locales \
  && docker-php-ext-install intl gettext pdo pdo_mysql \
  && sed -i '/^#.* cs_CZ.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* de_DE.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* fr_FR.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* ja_JP.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* lt_LT.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* nb_NO.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* nl_NL.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* pl_PL.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* ru_RU.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* tr_TR.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && sed -i '/^#.* zh_CN.UTF-8 UTF-8/s/^# //' /etc/locale.gen \
  && locale-gen
