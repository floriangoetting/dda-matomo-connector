#!/bin/sh

set -eu

php /usr/local/lib/dda-connector/write-runtime-config.php
chown www-data:www-data /var/www/html/config.php
chmod 0600 /var/www/html/config.php

exec docker-php-entrypoint "$@"
