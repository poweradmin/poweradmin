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

# Enable every locale Poweradmin ships translations for, so gettext setlocale() can succeed.
# Keep the list in sync with config/settings.defaults.php enabled_languages.
RUN for loc in \
      ar_SA bg_BG cs_CZ da_DK de_DE el_GR en_US es_ES et_EE fa_IR fi_FI fr_FR \
      ga_IE he_IL hi_IN hr_HR hu_HU id_ID it_IT ja_JP ko_KR lt_LT lv_LV ms_MY \
      nb_NO nl_NL pl_PL pt_BR pt_PT ro_RO ru_RU sk_SK sl_SI sq_AL sr_RS sv_SE \
      th_TH tr_TR uk_UA vi_VN zh_CN zh_TW; do \
      sed -i "/^# *$loc.UTF-8 UTF-8/s/^# //" /etc/locale.gen; \
    done \
  && locale-gen
