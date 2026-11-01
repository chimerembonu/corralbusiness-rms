#!/usr/bin/env bash
# Runs before php-fpm / the queue worker / the scheduler / Reverb start.
# Waits for MySQL, then (only in the "app" container) updates the database structure and caches.
set -euo pipefail

cd /var/www/html

if [ "${DB_CONNECTION:-mysql}" = "mysql" ]; then
    echo "Waiting for the database at ${DB_HOST:-db}:${DB_PORT:-3306}…"
    for i in $(seq 1 60); do
        if nc -z "${DB_HOST:-db}" "${DB_PORT:-3306}" 2>/dev/null; then break; fi
        sleep 2
    done
fi

if [ "${RMS_ROLE:-app}" = "app" ]; then
    php artisan storage:link --force >/dev/null 2>&1 || true
    php artisan migrate --force --no-interaction
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"
