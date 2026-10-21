#!/bin/sh
# AISSeaStats container entrypoint.
#   web     migrate the database, start PHP-FPM and lighttpd (default)
#   worker  run the background jobs
set -e

case "$1" in
  web)
    php /app/bin/migrate.php
    # Until the setup wizard is completed, show its one-time code in the logs (docker compose logs app).
    code=$(php /app/bin/setup-code.php --quiet 2>/dev/null || true)
    if [ -n "$code" ]; then
      echo "[aisseastats] setup code: $code (asked by the setup wizard in the browser)"
    fi
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
