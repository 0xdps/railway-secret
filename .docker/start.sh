#!/bin/sh
set -e

PORT_TO_USE=${PORT:-8080}

# Ensure storage directory exists and is writable by www-data
mkdir -p /var/www/html/storage/db
chown -R www-data:www-data /var/www/html/storage
chmod -R 770 /var/www/html/storage

# Patch nginx to listen on the Railway-assigned port
sed -i "s/listen 80;/listen ${PORT_TO_USE};/" /etc/nginx/http.d/default.conf

# Start the cron daemon (busybox crond, log level 2 = notice+, log to stdout)
crond -l 2 -L /dev/stdout

# Start PHP-FPM in the background
php-fpm -D

# Start Nginx in the foreground (keeps the container alive)
exec nginx -g 'daemon off;'
