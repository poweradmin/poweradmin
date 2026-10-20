ARG VARIANT

FROM php:${VARIANT}

# Install dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libldap2-dev \
    libicu-dev \
    gettext \
    locales \
    && rm -rf /var/lib/apt/lists/*

# Enable every locale Poweradmin ships translations for, so gettext setlocale() can succeed.
# Keep the list in sync with Dockerfile and config/settings.defaults.php enabled_languages.
RUN for loc in \
      ar_SA bg_BG bs_BA cs_CZ da_DK de_DE el_GR en_US es_ES et_EE fa_IR fi_FI \
      fr_FR ga_IE he_IL hi_IN hr_HR hu_HU id_ID it_IT ja_JP ko_KR lt_LT lv_LV \
      ms_MY nb_NO nl_NL pl_PL pt_BR pt_PT ro_RO ru_RU sk_SK sl_SI sq_AL sr_RS \
      sv_SE th_TH tr_TR uk_UA vi_VN zh_CN zh_TW; do \
      sed -i "/^# *$loc.UTF-8 UTF-8/s/^# //" /etc/locale.gen; \
    done \
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