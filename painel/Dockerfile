FROM php:8.4-apache

# Extensões: pgsql (banco), gd com jpeg/webp (pipeline de imagem), intl e zip (Filament).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
        libicu-dev libzip-dev unzip git \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_pgsql gd intl zip bcmath opcache \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Apache serve public/ (onde está o index.php e o .htaccess do Laravel).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .

# --no-dev: sem pacotes de teste em produção. Assets do Filament são publicados aqui.
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/start.sh /usr/local/bin/start.sh
CMD ["sh", "/usr/local/bin/start.sh"]
