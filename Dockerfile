# syntax=docker/dockerfile:1.7

# ---- PHP base with the extensions RYVR needs -----------------------------------------
FROM php:8.4-fpm-bookworm AS base
COPY --from=mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql redis intl zip bcmath pcntl opcache \
    && apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
WORKDIR /app

# ---- Build: front-end assets and the tracker (needs PHP for typed route helpers) -------
FROM base AS build
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY composer.json composer.lock package.json package-lock.json ./
RUN composer install --no-interaction --no-progress --no-scripts --prefer-dist \
    && npm ci --no-audit --no-fund
COPY . .
RUN cp .env.example .env && php artisan key:generate --force \
    && npm run build \
    && npm run build:tracker

# ---- Application image ----------------------------------------------------------------
FROM base AS app
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-ryvr.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-ryvr.conf
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist
COPY . .
COPY --from=build /app/public/build public/build
COPY --from=build /app/public/r.js public/r.js
RUN rm -f .env && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/geoip \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/ryvr-entrypoint
RUN chmod +x /usr/local/bin/ryvr-entrypoint
USER www-data
ENTRYPOINT ["ryvr-entrypoint"]
CMD ["php-fpm"]

# ---- Web front: Caddy serving public files and proxying PHP ---------------------------
FROM caddy:2.10-alpine AS web
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY --from=app /app/public /app/public
