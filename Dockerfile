# Public demo image: SQLite, bundled Mock CRM, no external services. See docs/DEPLOY_DEMO.md.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

FROM php:8.3-cli-bookworm
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions mbstring bcmath pdo_sqlite opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && touch database/demo.sqlite \
    && chmod +x docker/entrypoint.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_NAME=Routewise \
    ROUTEWISE_PUBLIC_DEMO=true \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/html/database/demo.sqlite \
    SESSION_DRIVER=file \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    LOG_CHANNEL=stderr

EXPOSE 10000
CMD ["docker/entrypoint.sh"]
