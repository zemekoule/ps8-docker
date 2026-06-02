# Per-PHP runtime image pro PHP 7.4 (PS 1.7.x). Stejný princip jako php81:
# PrestaShop core se NEpeče — instaluje se přes CLI proti src/<tag> (R4-a).
ARG PHP_BASE_TAG=7.4-apache
FROM prestashop/base:${PHP_BASE_TAG}
LABEL maintainer="Packeta dev"

# Xdebug pro PHP 7.4 — poslední řada 3.1.x (3.4.x vyžaduje PHP 8.1+).
# Konfigurace (zend_extension + nastavení) řeší sdílený mountnutý ./xdebug.ini.
ARG XDEBUG_VERSION=3.1.6

RUN pecl install xdebug-${XDEBUG_VERSION}

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN apt-get purge -y --auto-remove autoconf gcc make && \
    rm -rf /var/lib/apt/lists/*
