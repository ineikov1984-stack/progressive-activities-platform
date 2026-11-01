# Multi-platform index digests verified on 2026-11-01; update deliberately through a PR.
FROM composer:2@sha256:4ed71c38c6d788eb4e4e3747b5332f5b40d5b5a6fcadd5062a1a70986a118e0a AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
# The composer image has no gd or exif; the runtime image below has both and checks them.
RUN composer install --no-interaction --prefer-dist --no-scripts \
    --ignore-platform-req=ext-gd --ignore-platform-req=ext-exif

FROM php:8.4-cli-alpine@sha256:d8784155c4d37986be9ddd7a8950df7e78998cc48e35472cd69e2b9371424a65 AS development
# pcntl: `schedule:work` in the worker container hears `docker compose stop` and lets a running send finish.
# gd (JPEG, PNG, WebP) and exif: catalog photos are decoded and encoded again without the phone's metadata
# (docs/product/teacher-content.md, 5.2). The build stops if any of them is missing.
RUN apk add --no-cache icu-dev libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev postgresql-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install exif gd intl pcntl pdo_pgsql zip \
    && php -r '$g = gd_info(); exit(function_exists("exif_read_data") && $g["JPEG Support"] && $g["PNG Support"] && $g["WebP Support"] ? 0 : 1);'
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
