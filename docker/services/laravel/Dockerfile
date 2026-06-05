FROM php:8.4-fpm-bookworm AS base

ARG UID=1000
ARG GID=1000

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

ENV APP_ENV=production \
    APP_DEBUG=false

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-scripts

COPY --chown=www-data:www-data package.json package-lock.json ./
RUN npm ci

COPY --chown=www-data:www-data . .
RUN npm run build \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
