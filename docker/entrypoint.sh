#!/bin/sh
# AISSeaStats container entrypoint.
#   web     migrate the database, start PHP-FPM and lighttpd (default)
#   worker  run the background jobs
set -e

case "$1" in
  web)
    php /app/bin/migrate.php
    php-fpm -D
    exec lighttpd -D -f /app/docker/lighttpd.conf
    ;;
  worker)
    exec php /app/bin/worker.php
    ;;
  *)
    exec "$@"
    ;;
esac
