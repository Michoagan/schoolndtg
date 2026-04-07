# Utiliser une image PHP officielle
FROM php:8.4-cli

# Installer les dépendances système et les extensions PHP
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    librdkafka-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_pgsql zip bcmath

# Installer Composer (le gestionnaire de paquets PHP)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Définir le répertoire de travail dans le conteneur
WORKDIR /app

# Copier les fichiers de votre projet dans le conteneur
COPY . .

# Installer les dépendances de votre projet avec Composer
RUN composer install --no-interaction --no-plugins --no-scripts --prefer-dist --optimize-autoloader

# Copier le script de démarrage et le rendre exécutable
COPY entrypoint.sh .
RUN chmod +x entrypoint.sh

# Définir le script de démarrage comme commande d'entrée
ENTRYPOINT ["./entrypoint.sh"]
