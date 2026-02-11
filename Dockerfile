FROM prestashop/base:8.1-apache
LABEL maintainer="PrestaShop Core Team <coreteam@prestashop.com>"

ENV PS_VERSION=8.2.4

# Get PrestaShop
ADD https://api.prestashop-project.org/assets/prestashop/8.2.4/prestashop.zip /tmp/prestashop.zip

# Extract
RUN mkdir -p /tmp/data-ps \
	&& unzip -q /tmp/prestashop.zip -d /tmp/data-ps/ \
	&& bash /tmp/ps-extractor.sh /tmp/data-ps \
	&& rm /tmp/prestashop.zip

# Install Xdebug via pecl
RUN pecl install xdebug
#     \
#    && docker-php-ext-enable xdebug

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Purge build dependencies to keep the image small.
#RUN apt clean -y
RUN apt-get purge -y --auto-remove autoconf gcc make && \
    rm -rf /var/lib/apt/lists/*