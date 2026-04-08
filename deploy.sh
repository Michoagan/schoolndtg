<<<<<<< HEAD
﻿#!/bin/bash
set -e

echo "Lancement des migrations..."
php artisan migrate --force

echo "Optimisation Laravel..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Démarrage Apache..."
=======
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
>>>>>>> 86ba345f0bb0bd355b6e2eebc5f3be32c46379ee
apache2-foreground
