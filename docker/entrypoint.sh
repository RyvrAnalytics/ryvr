#!/bin/sh
set -e

# Every container role shares this image: web (php-fpm), writer, scheduler, queue.
if [ "${RYVR_RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan ryvr:migrate --force
fi

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null
php artisan event:cache >/dev/null

exec "$@"
