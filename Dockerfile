# -------------------------------
# Image de base : PHP avec Apache
# -------------------------------
FROM php:8.4-apache

# -------------------------------
# 1. Installer les dépendances système
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
# 2. Installer les extensions PHP requises par Laravel
# -------------------------------
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# -------------------------------
# 3. Activer le module Apache rewrite
# -------------------------------
RUN a2enmod rewrite

# -------------------------------
# 4. Configurer la racine web vers /public
# -------------------------------
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# -------------------------------
# 5. Installer Composer
# -------------------------------
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# -------------------------------
# 6. Définir le dossier de travail
# -------------------------------
WORKDIR /var/www/html

# -------------------------------
# 7. Copier tous les fichiers du projet
# -------------------------------
COPY . .

# -------------------------------
# 8. Rendre deploy.sh exécutable
# -------------------------------
RUN chmod +x deploy.sh

# -------------------------------
# 9. Installer les dépendances PHP (optimisé pour la prod)
# -------------------------------
RUN composer install --no-dev --optimize-autoloader

# -------------------------------
# 10. Permissions pour Laravel
# -------------------------------
RUN chown -R www-data:www-data storage bootstrap/cache

# -------------------------------
# 11. Exposer le port 80
# -------------------------------
EXPOSE 80

# -------------------------------
# 12. Définir deploy.sh comme point d'entrée
# -------------------------------
CMD ["./deploy.sh"]
