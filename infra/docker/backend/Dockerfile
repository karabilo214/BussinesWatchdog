FROM php:8.4-cli AS cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infra/docker/backend/php-uploads.ini /usr/local/etc/php/conf.d/zz-watchdog-uploads.ini

ENV COMPOSER_HOME=/tmp/composer-cache
WORKDIR /workspace/apps/backend

FROM php:8.4-fpm AS fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY infra/docker/backend/php-uploads.ini /usr/local/etc/php/conf.d/zz-watchdog-uploads.ini

WORKDIR /var/www/html


FROM node:22-bookworm-slim AS frontend

WORKDIR /build/apps/frontend
COPY apps/frontend/package.json apps/frontend/package-lock.json ./
COPY apps/frontend/packages ./packages
COPY apps/frontend/customer/package.json ./customer/package.json
COPY apps/frontend/control/package.json ./control/package.json
COPY apps/frontend/public/package.json ./public/package.json
RUN npm ci --no-audit --no-fund
COPY apps/frontend ./
RUN npm run build -w @bw/customer && npm run build -w @bw/control && npm run build -w @bw/public

FROM fpm AS production

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY apps/backend /var/www/html
COPY --from=frontend /build/apps/backend/public/site /var/www/html/public/site
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --working-dir=/var/www/html \
    && rm /usr/bin/composer \
    && mkdir -p storage/app/private storage/app/public storage/app/plugins storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

FROM caddy:2.10-alpine AS web

COPY --from=production /var/www/html/public /var/www/html/public
COPY --from=frontend /build/apps/frontend/customer/dist /srv/customer
COPY --from=frontend /build/apps/frontend/control/dist /srv/control
COPY infra/production/Caddyfile /etc/caddy/Caddyfile
