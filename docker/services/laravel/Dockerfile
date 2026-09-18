FROM php:8.4-fpm-bookworm AS base

ARG UID
ARG GID

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    TZ=UTC

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libzip-dev \
        unzip \
        zip \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-install \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        pgsql \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && npm install -g pnpm \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN if getent group "${GID}" >/dev/null; then \
        usermod -g "${GID}" www-data; \
    else \
        groupmod -g "${GID}" www-data; \
    fi \
    && if ! getent passwd "${UID}" >/dev/null; then \
        usermod -u "${UID}" www-data; \
    fi

COPY docker/config/php/php.ini /usr/local/etc/php/conf.d/pulllens.ini

EXPOSE 9000

CMD ["php-fpm"]

FROM base AS production

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-scripts

# Kept root-owned on purpose: npm runs as root here, and a lockfile owned by
# another user is what turns an install into an EACCES failure. The runtime
# copy of both files comes from the full COPY below, with the right owner.
COPY package.json package-lock.json ./
RUN npm config set fetch-retries 5 \
    && npm config set fetch-retry-mintimeout 20000 \
    && npm config set fetch-retry-maxtimeout 120000 \
    && npm ci --include=dev --ignore-scripts=false

COPY --chown=www-data:www-data . .
# node_modules is a build-time dependency only: the runtime serves the compiled
# assets from public/build, so the whole tree is removed rather than pruned.
# `npm prune` also rewrites package-lock.json, which fails with EACCES whenever
# the lockfile in the image is not owned by the user npm ends up running as.
RUN php artisan package:discover --ansi \
    && npm run build \
    && rm -rf node_modules \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
