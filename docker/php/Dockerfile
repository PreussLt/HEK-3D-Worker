FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    zip unzip curl \
    && docker-php-ext-install pdo pdo_pgsql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY frontend/ .

RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html
