# -------------------------------
# 1. Image de base : PHP avec Apache
# -------------------------------
FROM php:8.4-apache

# -------------------------------
# 2. Installer les dépendances système
# -------------------------------
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    curl \
    default-mysql-client \
 && rm -rf /var/lib/apt/lists/*

# -------------------------------
# 3. Installer les extensions PHP requises par Laravel
# -------------------------------
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# -------------------------------
# 4. Activer le module Apache rewrite
# -------------------------------
RUN a2enmod rewrite

# -------------------------------
# 5. Configurer la racine web vers /public
# -------------------------------
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# -------------------------------
# 6. Installer Composer
# -------------------------------
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# -------------------------------
# 7. Définir le dossier de travail
# -------------------------------
WORKDIR /var/www/html

# -------------------------------
# 8. Copier tous les fichiers du projet
# -------------------------------
COPY . .

# -------------------------------
# 9. Vérifier que deploy.sh existe et le rendre exécutable
# -------------------------------
RUN [ -f ./deploy.sh ] && chmod +x ./deploy.sh

# -------------------------------
# 10. Installer les dépendances PHP (optimisé pour la prod)
# -------------------------------
RUN composer install --no-dev --optimize-autoloader

# -------------------------------
# 11. Permissions pour Laravel
# -------------------------------
RUN chown -R www-data:www-data storage bootstrap/cache

# -------------------------------
# 12. Exposer le port 80
# -------------------------------
EXPOSE 80

# -------------------------------
# 13. Définir deploy.sh comme point d'entrée
# -------------------------------
CMD ["./deploy.sh"]
