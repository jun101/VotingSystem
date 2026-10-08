# API image: PHP 8.4 on FrankenPHP, served by Laravel Octane (worker mode); also used by
# `queue` and `scheduler`, which run plain CLI commands. In development the source is mounted at /var/www/html.
FROM dunglas/frankenphp:1.13.0-php8.4.26-trixie

COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql pcntl bcmath opcache gd exif

COPY docker/api/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Where the re-encoded pictures are written (the `media` disk). A named volume mounted here
# starts with these rights, so the container user can write to it.
RUN mkdir -p /var/www/media && chmod 0777 /var/www/media

# Containers run as the host user (see compose.yaml); these must be writable by anyone.
ENV HOME=/tmp \
    COMPOSER_HOME=/tmp/composer \
    XDG_DATA_HOME=/tmp/xdg-data \
    XDG_CONFIG_HOME=/tmp/xdg-config

WORKDIR /var/www/html
EXPOSE 8000
