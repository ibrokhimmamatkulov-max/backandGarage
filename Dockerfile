# syntax=docker/dockerfile:1

########################################
# 1. Install composer dependencies + build autoloader
########################################
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs \
    --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && composer run-script post-autoload-dump --no-dev

########################################
# 2. PHP-FPM runtime (application container)
########################################
FROM php:8.3-fpm-alpine AS app

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl \
    opcache

RUN addgroup -g 1000 www && adduser -G www -u 1000 -D -H www

WORKDIR /var/www/html

COPY --from=vendor --chown=www:www /app /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R www:www storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

USER www

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

########################################
# 3. Nginx (serves public/, proxies *.php to app:9000)
########################################
FROM nginx:1.27-alpine AS nginx

COPY --from=vendor /app/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

########################################
# 4. Render — nginx и php-fpm в одном контейнере
########################################
# Render запускает один контейнер на сервис, а docker-compose.yml выше
# рассчитан на два (app + nginx, общаются по имени app:9000). Отдельный
# слой вместо адаптации основного — чтобы не трогать боевую сборку compose.
#
# Render не умеет собирать конкретный --target многоступенчатого Dockerfile
# (открытый запрос ещё с 2021 года) — берёт последний FROM в файле. Поэтому
# этот слой обязан оставаться самым последним: допишете что-то ниже — Render
# станет собирать его, а не render.
FROM app AS render

USER root

RUN apk add --no-cache nginx gettext

COPY docker/render/nginx.conf.template /etc/nginx/conf.d/default.conf.template
COPY docker/render/entrypoint.sh /usr/local/bin/render-entrypoint.sh
RUN chmod +x /usr/local/bin/render-entrypoint.sh

# Render сам присылает порт через $PORT — не фиксируем EXPOSE на конкретное число.
ENTRYPOINT ["render-entrypoint.sh"]
CMD []
