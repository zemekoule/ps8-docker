# Per-PHP runtime image (R4-a).
# PrestaShop core se ZÁMĚRNĚ NEpeče do image — instaluje se přes CLI proti
# pre-populovanému src/<tag> (viz plán §5 + Phase 0 analýza). Tím je image
# sdílený přes všechny PS verze na stejném PHP a přidání verze nevyžaduje build.
ARG PHP_BASE_TAG=8.1-apache
FROM prestashop/base:${PHP_BASE_TAG}
LABEL maintainer="Packeta dev"

# Xdebug pin 3.4.5 — 3.5.x segfaultuje PS admin (Symfony) na PHP 8.1.
ARG XDEBUG_VERSION=3.4.5

RUN pecl install xdebug-${XDEBUG_VERSION}
# Aktivace + konfigurace Xdebugu řeší mountnutý ./xdebug.ini (zend_extension + nastavení).

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Úklid build závislostí kvůli velikosti image.
RUN apt-get purge -y --auto-remove autoconf gcc make && \
    rm -rf /var/lib/apt/lists/*

# Entrypoint (CMD ["/tmp/docker_run.sh"]) se dědí z prestashop/base — provede
# CLI install proti /var/www/html, když chybí parameters.php (idempotence built-in).
