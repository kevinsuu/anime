#!/bin/sh
set -e

php artisan migrate --force
php artisan config:cache
php artisan route:cache

php-fpm -D
exec nginx -g 'daemon off;'
