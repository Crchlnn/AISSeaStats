# AISSeaStats — web application image (lighttpd + PHP-FPM).
# The same image runs the background worker (command: worker).
FROM php:8.3-fpm-alpine

RUN apk add --no-cache lighttpd tzdata \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache \
 && rm -rf /tmp/pear

COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-aisseastats.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-aisseastats.ini

WORKDIR /app
COPY bin ./bin
COPY lang ./lang
COPY public ./public
COPY sql ./sql
COPY src ./src
COPY docker ./docker
COPY LICENSE README.md ./

# Debug capture files: written by PHP-FPM (www-data); a new named volume copies this ownership.
RUN mkdir -p /data/debug && chown www-data:www-data /data/debug

RUN chmod 755 /app/docker/entrypoint.sh \
 && lighttpd -tt -f /app/docker/lighttpd.conf

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/health.php || exit 1

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["web"]
