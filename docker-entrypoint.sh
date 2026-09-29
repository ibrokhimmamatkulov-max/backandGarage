#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY is not set. Generate one with 'php artisan key:generate --show' and put it in the environment." >&2
    exit 1
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force

if [ ! -f storage/oauth-private.key ] || [ ! -f storage/oauth-public.key ]; then
    php artisan passport:keys --force
fi

# public/storage -> storage/app/public. Без неё все фото объявлений
# (Storage::url на диске 'public') отдают 404.
if [ ! -L public/storage ]; then
    php artisan storage:link
fi

exec "$@"
