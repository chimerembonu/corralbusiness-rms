# CorralBusiness – RMS store-server image.
# Stage 1 builds the front-end (Tailwind/Vite); stage 2 is the PHP app that nginx talks to.

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY app ./app
COPY public ./public
# Tailwind scans these for class names; copy the little it needs.
COPY lang ./lang
RUN npm run build

FROM php:8.3-fpm-bookworm AS app
ARG DEBIAN_FRONTEND=noninteractive

# System packages: image support (GD, for receipt logos), intl, zip, MySQL, CUPS client (USB printers), netcat (startup checks).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates cups-client netcat-openbsd \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql bcmath intl zip pcntl sockets opcache exif \
    && apt-get purge -y --auto-remove libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libicu-dev libonig-dev \
    && apt-get install -y --no-install-recommends libpng16-16 libjpeg62-turbo libfreetype6 libzip4 libicu72 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-rms.ini

WORKDIR /var/www/html

# Composer first so the layer is cached while the code changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache

ENV CUPS_SERVER=/run/cups/cups.sock
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
