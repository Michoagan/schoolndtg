#!/bin/bash
set -e

echo "Lancement des migrations..."
# Optional : attendre que la DB soit prête
sleep 10
php artisan migrate --force || true

echo "Optimisation Laravel..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

echo "Démarrage Apache..."
# 🔹 Apache doit rester en premier plan pour Render
apache2-foreground
