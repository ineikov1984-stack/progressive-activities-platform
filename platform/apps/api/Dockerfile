FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-scripts

FROM php:8.4-cli-alpine AS development
# pcntl: `schedule:work` in the worker container hears `docker compose stop` and lets a running send finish.
RUN apk add --no-cache icu-dev libzip-dev postgresql-dev \
    && docker-php-ext-install intl pcntl pdo_pgsql zip
COPY docker/catalog-uploads.ini /usr/local/etc/php/conf.d/catalog-uploads.ini
WORKDIR /app
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN touch .env .env.testing \
    && php artisan package:discover --ansi
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --retries=10 \
  CMD wget -qO- http://127.0.0.1:8080/up >/dev/null || exit 1
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080", "--no-reload"]
