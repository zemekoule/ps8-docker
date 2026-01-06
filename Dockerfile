# defaults to Apache
FROM prestashop/prestashop:8.2-8.1

# Install Xdebug via pecl
RUN pecl install xdebug
#     \
#    && docker-php-ext-enable xdebug

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Purge build dependencies to keep the image small.
#RUN apt clean -y
RUN apt-get purge -y --auto-remove autoconf gcc make && \
    rm -rf /var/lib/apt/lists/*