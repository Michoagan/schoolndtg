FROM php:8.4-apache

# Dépendances système + extensions PHP
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    default-mysql-client

RUN docker-php-ext-install pdo pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd zip

RUN a2enmod rewrite

# Laravel public folder
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# Render port fix
ENV PORT=10000
RUN sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf
RUN sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-available/000-default.conf

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# Laravel install
RUN composer install --no-dev --optimize-autoloader --no-scripts || true

# Permissions Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 10000

CMD ["apache2-foreground"]
