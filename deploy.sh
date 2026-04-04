#!/bin/bash

echo "Lancement des migrations..."
php artisan migrate --force || true

echo "Optimisation Laravel..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

echo "Démarrage Apache..."
apache2-foreground
