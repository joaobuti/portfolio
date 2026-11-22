# Multi-stage: the development target keeps Xdebug and dev dependencies, the
# production target ships neither. A single-stage image that carries Composer,
# Xdebug and the test suite into production is both slower and a larger attack
# surface.

# ---------------------------------------------------------------- base
FROM php:8.3-cli-alpine AS base

RUN apk add --no-cache \
        postgresql-dev \
        icu-dev \
        libzip-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pcntl \
        bcmath \
        intl \
        zip \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ------------------------------------------------------ vendor (cacheable)
FROM base AS vendor

# Copy only the manifests first so a code change does not invalidate the
# dependency layer. This is the difference between a 5-second rebuild and a
# 90-second one.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# --------------------------------------------------------- development
FROM base AS development

ENV APP_ENV=local
RUN apk add --no-cache --virtual .xdebug-build-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .xdebug-build-deps
COPY docker/php/development.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]

# ---------------------------------------------------------- production
FROM base AS production

ENV APP_ENV=production APP_DEBUG=false
COPY docker/php/production.ini /usr/local/etc/php/conf.d/99-app.ini
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# Never run as root in production.
RUN addgroup -g 1000 app && adduser -u 1000 -G app -s /bin/sh -D app \
    && chown -R app:app storage bootstrap/cache
USER app

COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint
ENTRYPOINT ["entrypoint"]
