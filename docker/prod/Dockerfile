# Imagen de producción. Dos targets: "app" (PHP-FPM con el código) y "web" (nginx con /public).
# Uso: docker compose -f compose.prod.yaml up -d --build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Las extensiones de PHP se instalan en la imagen final, no en la de composer.
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY vite.config.js ./
COPY resources ./resources
# Tailwind también busca clases en app/ (colores de las categorías de gastos).
COPY app ./app
COPY public ./public
RUN npm run build

FROM php:8.4.27-fpm AS app
COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql intl bcmath zip opcache gd exif pcntl \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/prod/opcache.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"
COPY docker/prod/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

USER www-data
RUN php artisan package:discover --ansi

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS web
COPY docker/prod/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
