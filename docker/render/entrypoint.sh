#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY is not set. Generate one with 'php artisan key:generate --show' and put it in the environment." >&2
    exit 1
fi

# Render не даёт постоянный порт заранее — присылает его через $PORT при
# каждом запуске контейнера. nginx свою конфигурацию из переменных не читает,
# поэтому подставляем значение в файл прямо перед стартом.
: "${PORT:=8080}"
envsubst '${PORT}' < /etc/nginx/conf.d/default.conf.template > /etc/nginx/conf.d/default.conf

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force

if [ ! -f storage/oauth-private.key ] || [ ! -f storage/oauth-public.key ]; then
    php artisan passport:keys --force
fi

# php-fpm в фоне, nginx — процесс на переднем плане: именно за ним следит
# Render, по нему решает, жив ли контейнер.
php-fpm -D
exec nginx -g 'daemon off;'
