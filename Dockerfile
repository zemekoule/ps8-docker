# defaults to Apache
FROM prestashop/prestashop:8.2-8.1

# Install Xdebug via pecl
RUN pecl install xdebug
#     \
#    && docker-php-ext-enable xdebug

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# set files owner to the host's user/group
#RUN userdel -f www-data &&\
#  if getent group www-data ; then groupdel www-data; fi &&\
#  groupadd -g ${ARG_GID} www-data &&\
#  useradd -l -u ${ARG_UID} -g www-data www-data &&\
#  install -d -m 0755 -o www-data -g www-data /home/www-data



# Purge build dependencies to keep the image small.
#RUN apt clean -y
RUN apt-get purge -y --auto-remove autoconf gcc make && \
    rm -rf /var/lib/apt/lists/*