#!/bin/sh
set -e

# Ensure data/upload/log directories are writable by www-data
chown -R www-data:www-data /var/www/html/data /var/www/html/uploads /var/www/html/logs 2>/dev/null || true

exec "$@"
