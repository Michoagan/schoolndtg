#!/bin/bash
set -e

echo "Lancement des migrations..."
php artisan migrate --force

echo "Optimisation Laravel..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Démarrage Apache..."
apache2-foreground
