FROM php:8.2-apache

# 1. Installer les dépendances système nécessaires
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    curl \
    default-mysql-client

# 2. Installer les extensions PHP requises par Laravel
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# 3. Activer le module de réécriture Apache (pour les routes Laravel)
RUN a2enmod rewrite

# 4. Configurer la racine d'Apache vers le dossier /public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# 5. Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 6. Définir le dossier de travail
WORKDIR /var/www/html

# 7. Copier tous les fichiers du projet
COPY . /var/www/html

# 8. Installer les dépendances PHP (Optimisé pour la prod)
RUN composer install --no-dev --optimize-autoloader

# 9. Donner les permissions au dossier storage (CRUCIAL)
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 10. Exposer le port 80 (standard pour Apache)
EXPOSE 80

# 11. Utiliser ton script deploy.sh comme point d'entrée
RUN chmod +x deploy.sh
CMD ["./deploy.sh"]