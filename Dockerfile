# One image for every role of the hub, picked by the command:
#   web (default)  FrankenPHP serving public/ on :80
#   reverb         php artisan reverb:start --host=0.0.0.0 --port=8080
#   scheduler      php artisan schedule:work
# Published as ghcr.io/comitiva-dev/hub.

FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions pdo_pgsql pcntl intl bcmath zip opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV SERVER_NAME=:80 \
    COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

# Development: the source is mounted, dependencies installed in the container.
FROM base AS dev
RUN install-php-extensions pcov \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM base AS production
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/hub-entrypoint
ENTRYPOINT ["hub-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
EXPOSE 80 8080
