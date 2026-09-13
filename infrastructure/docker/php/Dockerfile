# Shared image for the Laravel API, queue worker, and scheduler.
# Build context is expected to be the repository root so this can COPY
# from apps/api. Selected via `target: dev` / `target: prod` in compose.

FROM php:8.5-fpm-alpine AS base

RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        sqlite-dev \
    && docker-php-ext-install \
        pdo_mysql \
        pdo_sqlite \
        mbstring \
        bcmath \
        intl \
        zip \
        pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY apps/api .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

# UID 1000 matches the common default host user, so writes into the
# bind-mounted app dir (storage/, bootstrap/cache/) in dev don't leave
# root-owned files behind on the host.
RUN adduser -D -u 1000 appuser && chown -R appuser:appuser /var/www/html

# ---- dev: fast-iterating built-in server, source is bind-mounted over this ----
# Uses PHP's built-in server directly (not `artisan serve`) — that command
# only forwards a hardcoded allowlist of env vars to the process that
# actually handles requests, silently dropping app-specific ones like
# DB_HOST/REDIS_HOST that Docker Compose injects.
FROM base AS dev
# base installed --no-dev (correct for prod); dev additionally needs
# require-dev packages (phpunit, etc.) to actually run the test suite.
USER appuser
RUN composer install --no-interaction --prefer-dist
EXPOSE 8000
CMD ["sh", "-c", "cd public && php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]

# ---- prod: php-fpm behind Nginx, immutable image (no bind mount) ----
FROM base AS prod
EXPOSE 9000
CMD ["php-fpm"]
