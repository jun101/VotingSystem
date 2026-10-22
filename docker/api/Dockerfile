# API image: PHP 8.5 on FrankenPHP, served by Laravel Octane (worker mode); also used by
# `queue` and `scheduler`, which run plain CLI commands. In development the source is mounted at /var/www/html.
FROM dunglas/frankenphp:1.13.2-php8.5.12-trixie AS base

COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql pcntl bcmath opcache gd exif zip

COPY docker/api/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Where the re-encoded pictures are written (the `media` disk). A named volume mounted here
# starts with these rights, so the container user can write to it.
RUN mkdir -p /var/www/media && chmod 1777 /var/www/media

# Containers run as the host user (see compose.yaml); these must be writable by anyone.
ENV HOME=/tmp \
    COMPOSER_HOME=/tmp/composer \
    XDG_DATA_HOME=/tmp/xdg-data \
    XDG_CONFIG_HOME=/tmp/xdg-config

WORKDIR /var/www/html
EXPOSE 8000

# Production: the code and its packages (no development ones) are in the image, which runs as an unprivileged user.
# `compose.prod.yaml` mounts the writable folders (storage, media) as volumes. The default target stays `dev`,
# the last stage, so `docker compose build` of the development stack is unchanged.
FROM base AS prod
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY api/ ./
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && rm -rf tests storage/logs/* \
    && chmod -R a+rwX storage bootstrap/cache
USER www-data

FROM base AS dev
