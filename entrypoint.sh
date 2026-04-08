#!/bin/bash
# ==============================================================================
# Script d'entrée au démarrage (START COMMAND)
# ==============================================================================

set -e

echo "==> Running database migrations..."
# Les migrations doivent être faites au démarrage pour mettre à jour la BDD Neon
php artisan migrate --seed --force

echo "==> Warming up Laravel Caches..."
# Ces caches sont essentiels pour la performance sur les plans gratuits
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan optimize

echo "==> Launching Application..."
# Le serveur doit écouter sur $PORT fourni par Render
php artisan serve --host 0.0.0.0 --port $PORT
