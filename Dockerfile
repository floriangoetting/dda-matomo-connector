FROM php:8.3.36-apache-bookworm

RUN docker-php-ext-install pdo_mysql \
  && a2enmod rewrite \
  && sed -ri \
    -e 's!/var/www/html!/var/www/html/public!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

COPY . /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/dda-connector-entrypoint
COPY docker/write-runtime-config.php /usr/local/lib/dda-connector/write-runtime-config.php

RUN chmod 0755 /usr/local/bin/dda-connector-entrypoint \
  && mkdir -p /var/www/html/storage \
  && chown -R www-data:www-data /var/www/html/storage

ENTRYPOINT ["/usr/local/bin/dda-connector-entrypoint"]
CMD ["apache2-foreground"]
