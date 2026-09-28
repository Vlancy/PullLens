FROM php:8.4-fpm-bookworm AS base

# Defaulted so the image builds standalone, without compose supplying the host's own
# ids. The published image is built with these defaults; the remapping below only earns
# its keep for a source build on a host whose user is not 1000.
ARG UID=1000
ARG GID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    TZ=UTC

WORKDIR /var/www/html

# `upgrade` before `install`: the base image's own Debian packages are frozen at
# whenever it was published, and several carry fixes that Debian has since shipped.
# Without this the final image inherits them even though a patch exists upstream.
RUN apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libzip-dev \
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
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# gitleaks: the free, MIT-licensed secret scanner behind "PullLens / Secrets".
# Pinned and checksum-verified so a compromised release asset cannot slip in.
ARG GITLEAKS_VERSION=8.28.0
ARG GITLEAKS_SHA256_X64=a65b5253807a68ac0cafa4414031fd740aeb55f54fb7e55f386acb52e6a840eb
ARG GITLEAKS_SHA256_ARM64=eff65261156100e5d94a6b3dec313d532fddfe19ae1590bf7a2b4f2699128356
ARG TARGETARCH
RUN set -eux; \
    case "${TARGETARCH:-amd64}" in \
        amd64) arch=x64; sum="${GITLEAKS_SHA256_X64}" ;; \
        arm64) arch=arm64; sum="${GITLEAKS_SHA256_ARM64}" ;; \
        *) echo "unsupported arch ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/gitleaks.tar.gz \
        "https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_linux_${arch}.tar.gz"; \
    echo "${sum}  /tmp/gitleaks.tar.gz" | sha256sum -c -; \
    tar -xzf /tmp/gitleaks.tar.gz -C /usr/local/bin gitleaks; \
    rm /tmp/gitleaks.tar.gz; \
    gitleaks version

RUN if getent group "${GID}" >/dev/null; then \
        usermod -g "${GID}" www-data; \
    else \
        groupmod -g "${GID}" www-data; \
    fi \
    && if ! getent passwd "${UID}" >/dev/null; then \
        usermod -u "${UID}" www-data; \
    fi

COPY docker/config/php/php.ini /usr/local/etc/php/conf.d/pulllens.ini
COPY docker/config/php-fpm/zz-pulllens.conf /usr/local/etc/php-fpm.d/zz-pulllens.conf

COPY docker/services/laravel/bin/ /usr/local/bin/
RUN chmod +x /usr/local/bin/pulllens-*

# The entrypoint reconciles the storage volume's ownership before anything runs. A
# compose `command:` arrives as its "$@", so the workers keep overriding CMD as before.
ENTRYPOINT ["/usr/local/bin/pulllens-entrypoint"]

EXPOSE 9000

CMD ["php-fpm"]

# ---------------------------------------------------------------------------------
# The toolchain that produces vendor/ and public/build, and which the runtime must not
# inherit. Node, npm, git and Composer are needed to build PullLens and are useless to
# a running one - and every one of them is attack surface in an image anyone can pull.
# Keeping them in a stage that is thrown away is what lets the published image carry no
# vulnerabilities its own base does not already have.
# ---------------------------------------------------------------------------------
FROM base AS build

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        gnupg \
        unzip \
        zip \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-scripts

# Kept root-owned on purpose: npm runs as root here, and a lockfile owned by another
# user is what turns an install into an EACCES failure.
COPY package.json package-lock.json ./
RUN npm config set fetch-retries 5 \
    && npm config set fetch-retry-mintimeout 20000 \
    && npm config set fetch-retry-maxtimeout 120000 \
    && npm ci --include=dev --ignore-scripts=false

COPY . .

RUN php artisan package:discover --ansi \
    && npm run build

# ---------------------------------------------------------------------------------
# The published image: PHP, the application, and the assets the build stage compiled.
# ---------------------------------------------------------------------------------
FROM base AS production

# Stamped by the release workflow from the git tag. Deliberately NOT named
# PULLLENS_VERSION: that one is a Compose variable living in .env, which reaches the
# container through env_file - and a real environment variable beats an image ENV, so
# reusing the name would make every instance report whatever tag selector was in .env
# rather than what it actually is.
ARG PULLLENS_RELEASE=source
ENV PULLLENS_RELEASE=${PULLLENS_RELEASE}

COPY --chown=www-data:www-data . .
COPY --from=build --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=build --chown=www-data:www-data /var/www/html/public/build ./public/build

# Composer is borrowed for one command and not kept: the autoloader has to be rebuilt
# because the application files arrived after `composer install` ran in the other
# stage. package:discover is re-run here for the same reason - its manifest names
# paths inside this image.
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan package:discover --ansi \
    && mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
