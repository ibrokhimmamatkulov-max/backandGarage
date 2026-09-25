#!/bin/sh
set -e

cd /var/www/html

# Образ собран для docker-compose, где процесс всегда идёт под www:www
# (см. основной Dockerfile). Render запускает контейнер под своим
# пользователем вне зависимости от USER в Dockerfile — писать в storage/
# и bootstrap/cache (логи, кэш, сгенерированные ключи Passport) от чужого
# владельца нельзя. Не гадаем, какой именно UID даст Render — открываем
# запись всем.
chmod -R 777 storage bootstrap/cache

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY is not set. Generate one with 'php artisan key:generate --show' and put it in the environment." >&2
    exit 1
fi

# Render не даёт постоянный порт заранее — присылает его через $PORT при
# каждом запуске контейнера. nginx свою конфигурацию из переменных не читает,
# поэтому подставляем значение в файл прямо перед стартом.
: "${PORT:=8080}"
# Пишем целиком nginx.conf, а не файл в conf.d/: apk-пакет nginx на Alpine
# по умолчанию подключает /etc/nginx/http.d/*.conf, а не conf.d/ (в отличие
# от официального Docker-образа nginx) — обычный snippet в conf.d/ тихо
# не подключался бы вообще.
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

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
