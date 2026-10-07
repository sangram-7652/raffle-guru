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
    && php artisan package:discover --ansi \
    && php artisan filament:assets --ansi

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
# filament:assets re-published on every start, not just baked in at
# build time: dev bind-mounts apps/api over the whole image (see
# docker-compose.yml), which shadows whatever `base` published at build
# time with the host's own apps/api/public — on a truly fresh clone,
# that host directory has no public/{css,js,fonts}/filament/ at all
# (gitignored, see apps/api/.gitignore), so dev's admin panel would be
# unstyled until someone thought to run this by hand. Cheap and
# idempotent, same reasoning as `npm install &&` prefixing the web dev
# service's own command.
CMD ["sh", "-c", "php artisan filament:assets --ansi && cd public && php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]

# ---- prod: php-fpm behind Nginx, immutable image (no bind mount) ----
FROM base AS prod
EXPOSE 9000
CMD ["php-fpm"]

# ---- nginx: static Filament assets baked in at build time (P11.15) ----
# php-fpm (fastcgi) has no equivalent of PHP's built-in dev server's
# "serve the file directly if it exists on disk" fallback — every fastcgi
# request reaches index.php regardless of whether the path matches a real
# file, so Filament's published CSS/JS/fonts (apps/api/public/{css,js,
# fonts}/filament/, written by `filament:assets` above) 404 through
# Laravel's router if nginx proxies them to php-fpm the same way it does
# /admin. Kept in THIS Dockerfile, not a separate one, so it can COPY
# directly from the `base` stage above — the one place these files are
# actually produced — rather than risk a second, independently-built
# nginx image drifting out of sync with whatever `filament:assets`
# published. infrastructure/nginx/prod.conf still comes from a bind
# mount (see docker-compose.prod.yml), so the nginx config itself stays
# hot-swappable without an image rebuild; only the static files
# themselves are baked in here.
FROM nginx:1.27-alpine AS nginx
COPY --from=base /var/www/html/public/css/filament /usr/share/nginx/html/css/filament
COPY --from=base /var/www/html/public/js/filament /usr/share/nginx/html/js/filament
COPY --from=base /var/www/html/public/fonts/filament /usr/share/nginx/html/fonts/filament
