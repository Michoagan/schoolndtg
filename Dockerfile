FROM php:8.4-apache

RUN apt-get update && apt-get install -y \
    libpng-dev libonig-dev libxml2-dev zip unzip git curl default-mysql-client

RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql pgsql mbstring exif pcntl bcmath gd

RUN a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html

RUN cp .env.example .env || true

# 🔥 FIX ICI
RUN composer install --no-dev --optimize-autoloader --no-scripts

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

RUN chmod +x deploy.sh

CMD ["./deploy.sh"]
