FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
# The composer image has no gd or exif; the runtime image below has both and checks them.
RUN composer install --no-interaction --prefer-dist --no-scripts \
    --ignore-platform-req=ext-gd --ignore-platform-req=ext-exif

FROM php:8.4-cli-alpine AS development
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
