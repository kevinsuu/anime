#!/bin/sh
set -e

# Production migrations are owned by this entrypoint. The deploy workflow must
# not start a second migration process after bringing the containers up.
php artisan migrate --force
php artisan storage:link --force
php artisan config:cache
php artisan route:cache

php-fpm -D
exec nginx -g 'daemon off;'
