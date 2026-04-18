#!/bin/sh

echo "Installation Laravel..."

php artisan key:generate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force || true

echo "Démarrage Apache..."

apache2-foreground
