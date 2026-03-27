FROM php:8.4-fpm

# Extensions nécessaires
RUN apt-get update && apt-get install -y libpq-dev zip unzip git \
    && docker-php-ext-install pdo pdo_pgsql opcache

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Utilisateur non-root
RUN useradd -u 1000 -m www-data || true
USER www-data

WORKDIR /var/www

CMD ["php-fpm"]
