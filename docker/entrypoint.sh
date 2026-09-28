#!/bin/sh
# Prepares the hub before its process starts. HUB_MIGRATE=1 runs pending
# migrations first (set it on one role only, usually web).
set -e

cd /app
php artisan package:discover --ansi >/dev/null
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan event:cache >/dev/null
php artisan storage:link >/dev/null 2>&1 || true

if [ "${HUB_MIGRATE:-0}" = "1" ]; then
  php artisan migrate --force
fi

exec "$@"
